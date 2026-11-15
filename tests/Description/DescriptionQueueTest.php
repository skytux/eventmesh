<?php

declare(strict_types=1);

namespace EventMesh\Tests\Description;

use Brain\Monkey\Functions;
use EventMesh\Description\DescriptionFilters;
use EventMesh\Description\DescriptionQueue;
use EventMesh\Description\DescriptionStore;
use EventMesh\Description\DescriptionWriter;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationQueue;
use EventMesh\Translation\Translator;

/**
 * The whole run with an in-memory database and stubbed LLM replies: the
 * Finnish text first, then its translations, and the door code never sent
 * to the model nor kept.
 */
final class DescriptionQueueTest extends TestCase
{
    private const CONTENT = '<p>Tervetuloa tanssimaan Esimerkkisaliin!</p>'
        . '<p>Ovikoodi: 7302</p>'
        . '<p>Soittajana Esimerkki, puhelin 040 123 4567.</p>'
        . '<p>Lisätietoja info@example.com tai https://example.com/tapahtuma</p>';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $meta = [];

    /** @var array<int, string> */
    private array $replies = [];

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    /** @var array<int, \WP_Post> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->meta = [];
        $this->replies = [];
        $this->sent = [];

        $this->posts[7] = $this->event(7, '+10 days');

        Functions\when('wp_salt')->justReturn('salt');
        Functions\when('get_option')->alias(
            fn (string $name, $default = false) => array_key_exists($name, $this->options)
                ? $this->options[$name]
                : $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('get_post')->alias(fn (int $id) => $this->posts[$id] ?? null);
        Functions\when('get_post_meta')->alias(
            fn (int $id, string $key) => $this->meta[$id][$key] ?? ''
        );
        Functions\when('update_post_meta')->alias(
            function (int $id, string $key, $value): bool {
                $this->meta[$id][$key] = $value;

                return true;
            }
        );
        Functions\when('delete_post_meta')->alias(
            function (int $id, string $key): bool {
                unset($this->meta[$id][$key]);

                return true;
            }
        );
        Functions\when('wp_slash')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(static fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('sanitize_text_field')->alias(static fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('sanitize_textarea_field')->alias(static fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) {
                $this->sent[] = json_decode($args['body'], true);

                $reply = (string) ($this->replies[0] ?? '');
                $this->replies = array_slice($this->replies, 1);

                return $reply;
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn ($reply) => json_encode(['choices' => [['message' => ['content' => $reply]]]])
        );

        LlmSettings::save('https://llm.example/v1', 'test-model', 'sk-test');
        LlmSettings::setEnabled(true);
        LlmSettings::saveTargetLanguages(['en', 'sv']);
    }

    private function event(int $id, string $when): \WP_Post
    {
        $post = new class ($id, 'eventmesh_event', 'Ecstatic Dance') extends \WP_Post {
            public string $post_status = 'publish';
            public string $post_content = '';
        };
        $post->post_content = self::CONTENT;
        $starts = (new \DateTimeImmutable($when, new \DateTimeZone('Europe/Helsinki')))->setTime(19, 0);
        $this->meta[$id]['_eventmesh_starts_at'] = $starts->format('Y-m-d\TH:i:s');
        $this->meta[$id]['_eventmesh_venue_name'] = 'Esimerkkisali';

        return $post;
    }

    private function queue(): DescriptionQueue
    {
        return new DescriptionQueue(
            new Logger(),
            static function (): DescriptionWriter {
                $client = new LlmClient('https://llm.example/v1', 'test-model', 'sk-test');

                return new DescriptionWriter($client, new Translator($client));
            }
        );
    }

    private static function budget(): TimeBudget
    {
        return new TimeBudget(1040.0, static fn (): float => 1000.0);
    }

    private static function fi(string $text): string
    {
        return '```json' . "\n" . json_encode(['text' => $text]) . "\n```";
    }

    public function testWritesFinnishFirstThenTranslatesIt(): void
    {
        $queue = $this->queue();
        $queue->onEventChanged(7);
        $this->replies = [
            self::fi('Tule tanssimaan Esimerkkisaliin! Esimerkki soittaa lämmintä musiikkia.'),
            'Come and dance at Esimerkkisali! Esimerkki plays warm music.',
            'Kom och dansa på Esimerkkisali! Esimerkki spelar varm musik.',
        ];

        $result = $queue->run(self::budget());

        self::assertSame(1, $result['written']);
        self::assertSame(2, $result['translated']);
        self::assertSame([], $queue->queue());
        self::assertCount(3, $this->sent);
        self::assertStringStartsWith('Tule tanssimaan Esimerkkisaliin!', DescriptionStore::text(7, 'fi'));
        self::assertStringStartsWith('Come and dance at Esimerkkisali!', DescriptionStore::text(7, 'en'));
        self::assertStringStartsWith('Kom och dansa', DescriptionStore::text(7, 'sv'));
        self::assertSame(DescriptionStore::STATUS_AUTO, DescriptionStore::status(7, 'en'));
        self::assertFalse(DescriptionStore::needsWork(7));
        self::assertFalse($this->sent[0]['chat_template_kwargs']['enable_thinking']);
        self::assertStringContainsString('in Finnish', $this->sent[0]['messages'][0]['content']);
        self::assertStringContainsString('Translate', $this->sent[1]['messages'][0]['content']);
    }

    public function testTheDoorCodeAndContactsAreNeverSentToTheModel(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = [self::fi('Tule tanssimaan!'), 'Come and dance!', 'Kom och dansa!'];

        $queue->run(self::budget());

        $sent = json_encode($this->sent, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('7302', $sent);
        self::assertStringNotContainsStringIgnoringCase('ovikoodi', $sent);
        self::assertStringNotContainsString('4567', $sent);
        self::assertStringNotContainsString('info@example.com', $sent);
        self::assertStringNotContainsString('example.com/tapahtuma', $sent);
        $input = $this->sent[0]['messages'][1]['content'];
        self::assertStringContainsString('Tervetuloa tanssimaan Esimerkkisaliin!', $input);
    }

    public function testATextWithTheDoorCodeIsNeverKept(): void
    {
        // Even when the model knows the code from elsewhere.
        $replies = ['Tule tanssimaan! Ovella koodi 7302.', 'Tule tanssimaan! Numero 7302 avaa oven.', 'Katso www.example.com'];
        $facts = array_flip(['_eventmesh_starts_at', '_eventmesh_venue_name']);

        foreach ($replies as $reply) {
            $this->meta[7] = array_intersect_key($this->meta[7], $facts);
            $this->options[DescriptionQueue::OPTION_QUEUE] = [];
            $queue = $this->queue();
            $queue->enqueue([7]);
            $this->replies = [self::fi($reply)];

            $result = $queue->run(self::budget());

            self::assertSame(0, $result['written'], $reply);
            self::assertSame(1, $result['failed'], $reply);
            self::assertSame('', DescriptionStore::text(7, 'fi'), $reply);
            self::assertSame([7], $queue->queue(), 'tried again later');
        }
    }

    public function testATranslationWithANewNumberIsNotKept(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = [self::fi('Tule tanssimaan!'), 'Come and dance! Code 7302.', 'Kom och dansa!'];

        $result = $queue->run(self::budget());

        self::assertSame('', DescriptionStore::text(7, 'en'));
        self::assertSame('Kom och dansa!', DescriptionStore::text(7, 'sv'));
        self::assertSame(1, $result['failed']);
    }

    public function testGivesUpAfterThreeFailedAttempts(): void
    {
        $queue = $this->queue();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $queue->enqueue([7]);
            $this->replies = ['not json'];
            $queue->run(self::budget());
        }

        self::assertSame([], $queue->queue());
        self::assertSame('gave_up', DescriptionStore::decide(7, 'fi'));
        self::assertFalse(DescriptionStore::needsWork(7));
        self::assertSame('bad_reply', DescriptionStore::all(7)['fi']['failed']);
    }

    public function testAnExistingTextIsNeverWrittenAgain(): void
    {
        DescriptionStore::writeAuto(7, 'fi', 'Vanha teksti.', 'test-model');
        DescriptionStore::writeEdited(7, 'en', 'My own text.');
        $queue = $this->queue();
        $queue->onEventChanged(7);
        $this->replies = ['Gammal text.'];

        $result = $queue->run(self::budget());

        self::assertSame(0, $result['written']);
        self::assertSame(1, $result['translated']);
        self::assertCount(1, $this->sent);
        self::assertSame('Vanha teksti.', DescriptionStore::text(7, 'fi'));
        self::assertSame('My own text.', DescriptionStore::text(7, 'en'));
        self::assertSame('Gammal text.', DescriptionStore::text(7, 'sv'));

        $this->sent = [];
        $queue->onEventChanged(7);
        self::assertSame([], $queue->queue(), 'nothing left to do');
    }

    public function testAnEditedFinnishTextIsTranslatedAgainButEditedTranslationsStay(): void
    {
        DescriptionStore::writeAuto(7, 'fi', 'Vanha teksti.', 'test-model');
        DescriptionStore::writeAuto(7, 'en', 'Old text.', 'test-model', DescriptionStore::hashOf('Vanha teksti.'));
        DescriptionStore::writeEdited(7, 'sv', 'Min egen text.');
        $queue = $this->queue();
        $filters = new DescriptionFilters($queue);

        $typed = ['fi' => 'Uusi teksti.', 'en' => 'Old text.', 'sv' => 'Min egen text.'];
        self::assertTrue($filters->save(false, 7, $typed));
        self::assertSame(DescriptionStore::STATUS_EDITED, DescriptionStore::status(7, 'fi'));
        self::assertSame([7], $queue->queue());

        $this->replies = ['New text.'];
        $queue->run(self::budget());

        self::assertSame('Uusi teksti.', DescriptionStore::text(7, 'fi'));
        self::assertSame('New text.', DescriptionStore::text(7, 'en'));
        self::assertSame('Min egen text.', DescriptionStore::text(7, 'sv'));
        self::assertCount(1, $this->sent);
    }

    public function testAnEmptiedFieldGoesBackToTheAi(): void
    {
        DescriptionStore::writeAuto(7, 'fi', 'Vanha teksti.', 'test-model');
        DescriptionStore::writeEdited(7, 'en', 'My own text.');
        DescriptionStore::writeEdited(7, 'sv', 'Min egen text.');
        $queue = $this->queue();

        (new DescriptionFilters($queue))->save(false, 7, ['en' => '']);
        $this->replies = ['Old text.'];
        $queue->run(self::budget());

        self::assertSame('Old text.', DescriptionStore::text(7, 'en'));
        self::assertSame(DescriptionStore::STATUS_AUTO, DescriptionStore::status(7, 'en'));
        self::assertSame('Min egen text.', DescriptionStore::text(7, 'sv'));
    }

    public function testWriteAgainReplacesTheFinnishAndAutomaticTextsOnly(): void
    {
        DescriptionStore::writeEdited(7, 'fi', 'Oma teksti.');
        DescriptionStore::writeAuto(7, 'en', 'Own text.', 'test-model', DescriptionStore::hashOf('Oma teksti.'));
        DescriptionStore::writeEdited(7, 'sv', 'Min egen text.');
        $queue = $this->queue();

        self::assertTrue((new DescriptionFilters($queue))->regenerate(false, 7));
        self::assertSame('Oma teksti.', DescriptionStore::text(7, 'fi'), 'shown until the new one is written');

        $this->replies = [self::fi('Uusi tekoälyn teksti.'), 'New AI text.'];
        $queue->run(self::budget());

        self::assertSame('Uusi tekoälyn teksti.', DescriptionStore::text(7, 'fi'));
        self::assertSame(DescriptionStore::STATUS_AUTO, DescriptionStore::status(7, 'fi'));
        self::assertSame('New AI text.', DescriptionStore::text(7, 'en'));
        self::assertSame('Min egen text.', DescriptionStore::text(7, 'sv'));
        self::assertFalse(DescriptionStore::regenerateAsked(7));
    }

    public function testWaitsWhileTheEventIsStillBeingTranslated(): void
    {
        $this->options[TranslationQueue::OPTION_QUEUE] = [7];
        $queue = $this->queue();
        $queue->enqueue([7]);

        $result = $queue->run(self::budget());

        self::assertSame(0, $result['written']);
        self::assertSame([], $this->sent);
        self::assertSame([7], $queue->queue());
    }

    public function testPastEventsAreNotQueued(): void
    {
        $this->posts[8] = $this->event(8, '-3 days');
        $queue = $this->queue();

        $queue->onEventChanged(8);

        self::assertSame([], $queue->queue());
    }

    public function testTheFilterGivesTheTextAndTheState(): void
    {
        DescriptionStore::writeAuto(7, 'fi', 'Teksti.', 'test-model');
        $filters = new DescriptionFilters($this->queue());

        self::assertSame('Teksti.', $filters->text('', 7, 'fi'));
        self::assertSame('', $filters->text('', 7, 'en'));
        self::assertSame('', $filters->text('', 99, 'fi'), 'not an event');

        $state = $filters->state(null, 7);
        self::assertSame(['fi', 'en', 'sv'], $state['languages']);
        self::assertSame('Teksti.', $state['texts']['fi']['text']);
        self::assertSame('', $state['texts']['en']['text']);
    }
}
