<?php

declare(strict_types=1);

namespace EventMesh\Tests\Naming;

use Brain\Monkey\Functions;
use EventMesh\Naming\EventNamer;
use EventMesh\Naming\EventNameQueue;
use EventMesh\Naming\EventNameStore;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationQueue;
use EventMesh\Translation\TranslationStore;

/**
 * The whole run with an in-memory database and stubbed HTTP replies.
 */
final class EventNameQueueTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $meta = [];

    /** @var array<int, string> */
    private array $replies = [];

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    /** @var array<int, int> */
    private array $scheduled = [];

    private \WP_Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->meta = [];
        $this->replies = [];
        $this->sent = [];
        $this->scheduled = [];

        $this->post = new class (7, 'eventmesh_event', 'ECSTATIC DANCE 14.11.2026 / Ihmeidentekijä') extends \WP_Post {
            public string $post_status = 'publish';
        };

        Functions\when('wp_salt')->justReturn('salt');
        Functions\when('get_option')->alias(
            fn (string $name, $default = false) => array_key_exists($name, $this->options) ? $this->options[$name] : $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('get_post')->alias(fn (int $id) => 7 === $id ? $this->post : null);
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
        Functions\when('sanitize_text_field')->alias(static fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(
            function (int $time): bool {
                $this->scheduled[] = $time - time();

                return true;
            }
        );
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
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
    }

    private function queue(): EventNameQueue
    {
        return new EventNameQueue(
            new Logger(),
            static fn () => new EventNamer(new LlmClient('https://llm.example/v1', 'test-model', 'sk-test'))
        );
    }

    private static function budget(): TimeBudget
    {
        return new TimeBudget(1040.0, static fn (): float => 1000.0);
    }

    public function testWritesTheNameAndTheDjNamesInOneRequest(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = ['```json' . "\n" . '{"name":"ECSTATIC DANCE","name_fi":"","djs":["Ihmeidentekijä"]}' . "\n```"];

        $result = $queue->run(self::budget());

        self::assertSame(1, $result['named']);
        self::assertSame([], $queue->queue());
        self::assertCount(1, $this->sent);
        self::assertSame(EventNamer::PROMPT, $this->sent[0]['messages'][0]['content']);
        self::assertSame('Title: ECSTATIC DANCE 14.11.2026 / Ihmeidentekijä', $this->sent[0]['messages'][1]['content']);

        self::assertSame('Ecstatic Dance', $this->meta[7]['_eventmesh_name']);
        self::assertSame('', $this->meta[7]['_eventmesh_name_fi']);
        self::assertSame(['Ihmeidentekijä'], $this->meta[7]['_eventmesh_dj_names']);
        self::assertSame(
            EventNameStore::sourceHash('ECSTATIC DANCE 14.11.2026 / Ihmeidentekijä', ''),
            $this->meta[7]['_eventmesh_name_hash']
        );
    }

    public function testSendsTheFinnishTitleWhenTheEventHasACurrentFinnishTranslation(): void
    {
        $this->meta[7] = [
            TranslationStore::META_ORIGINAL_LANG => 'en',
            '_eventmesh_tr_fi_title' => 'EKSTAATTINEN TANSSI 14.11.2026 / Ihmeidentekijä',
            '_eventmesh_tr_fi_hash' => TranslationStore::originalHash($this->post),
        ];

        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = ['{"name":"Ecstatic Dance","name_fi":"Ekstaattinen tanssi","djs":["Ihmeidentekijä"]}'];
        $queue->run(self::budget());

        self::assertSame(
            "Title: ECSTATIC DANCE 14.11.2026 / Ihmeidentekijä\nFinnish title: EKSTAATTINEN TANSSI 14.11.2026 / Ihmeidentekijä",
            $this->sent[0]['messages'][1]['content']
        );
        self::assertSame('Ekstaattinen tanssi', $this->meta[7]['_eventmesh_name_fi']);
    }

    public function testUnchangedTitleIsNeverNamedAgain(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = ['{"name":"Ecstatic Dance","name_fi":"","djs":["Ihmeidentekijä"]}'];
        $queue->run(self::budget());

        $queue->enqueue([7]);
        $result = $queue->run(self::budget());

        self::assertCount(1, $this->sent);
        self::assertSame(0, $result['named']);
        self::assertSame([], $queue->queue());
    }

    public function testChangedTitleIsNamedAgain(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = ['{"name":"Ecstatic Dance","name_fi":"","djs":["Ihmeidentekijä"]}'];
        $queue->run(self::budget());

        $this->post->post_title = 'CACAO ECSTATIC DANCE 12.12.2026 / Milla Linna &amp; Ty Alexander';
        $queue->enqueue([7]);
        $this->replies = ['{"name":"Cacao Ecstatic Dance","name_fi":"","djs":["Milla Linna","Ty Alexander"]}'];
        $result = $queue->run(self::budget());

        self::assertCount(2, $this->sent);
        self::assertSame(
            'Title: CACAO ECSTATIC DANCE 12.12.2026 / Milla Linna & Ty Alexander',
            $this->sent[1]['messages'][1]['content'],
            'the model gets the plain text, not HTML entities'
        );
        self::assertSame(1, $result['named']);
        self::assertSame('Cacao Ecstatic Dance', $this->meta[7]['_eventmesh_name']);
        self::assertSame(['Milla Linna', 'Ty Alexander'], $this->meta[7]['_eventmesh_dj_names']);
    }

    public function testRejectedAnswerStoresNothingAndIsGivenUpAfterThreeAttempts(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $invented = '{"name":"Ecstatic Dance Party","name_fi":"","djs":[]}';

        $this->replies = [$invented];
        $result = $queue->run(self::budget());
        self::assertSame(1, $result['failed']);
        self::assertSame([7], $queue->queue(), 'stays queued for another attempt');
        self::assertArrayNotHasKey('_eventmesh_name', $this->meta[7] ?? []);

        $this->replies = [$invented];
        $queue->run(self::budget());
        $this->replies = [$invented];
        $queue->run(self::budget());

        $failure = TranslationStore::failure(7, EventNameStore::META_FAIL);
        self::assertSame(3, $failure['count']);
        self::assertSame('name_words', $failure['kind']);
        self::assertSame([], $queue->queue());

        $queue->enqueue([7]);
        $queue->run(self::budget());
        self::assertCount(3, $this->sent, 'no further attempts until the title changes');
    }

    public function testWaitsWhileTheEventIsWaitingForItsTranslation(): void
    {
        $this->options[TranslationQueue::OPTION_QUEUE] = [7];

        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->scheduled = [];
        $result = $queue->run(self::budget());

        self::assertSame([], $this->sent);
        self::assertSame(0, $result['named']);
        self::assertSame([7], $queue->queue());
        self::assertSame([EventNameQueue::WAIT_FOR_TRANSLATION], $this->scheduled);
    }

    public function testServerErrorStopsTheRunAndRetriesLater(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);

        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->scheduled = [];
        $result = $queue->run(self::budget());

        self::assertSame('http_503', $result['error']);
        self::assertSame([7], $queue->queue());
        self::assertNull(TranslationStore::failure(7, EventNameStore::META_FAIL), 'not counted');
        self::assertSame([EventNameQueue::RETRY_AFTER_ERROR], $this->scheduled);
    }

    public function testDoesNothingWhenTheLlmIsOff(): void
    {
        LlmSettings::setEnabled(false);

        $queue = $this->queue();
        $queue->enqueue([7]);
        $result = $queue->run(self::budget());

        self::assertFalse($result['ready']);
        self::assertSame([], $this->sent);
        self::assertSame([], $this->scheduled);
        self::assertArrayNotHasKey(7, $this->meta);
    }

    public function testDoesNothingWhenTheLlmIsNotSetUp(): void
    {
        LlmSettings::save('', '', '');

        $queue = $this->queue();
        $queue->enqueue([7]);
        $result = $queue->run(self::budget());

        self::assertFalse($result['ready']);
        self::assertSame([], $this->sent);
    }
}
