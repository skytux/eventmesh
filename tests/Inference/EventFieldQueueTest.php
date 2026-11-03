<?php

declare(strict_types=1);

namespace EventMesh\Tests\Inference;

use Brain\Monkey\Functions;
use EventMesh\Inference\EventFieldInferrer;
use EventMesh\Inference\EventFieldQueue;
use EventMesh\Inference\InferredFields;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TimeBudget;

/**
 * The whole run with an in-memory database and a fake LLM server (stubbed
 * HTTP replies behind a real LlmClient).
 */
final class EventFieldQueueTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $meta = [];

    /** @var array<int, string> */
    private array $replies = [];

    /** @var array<int, array<string, mixed>> */
    private array $sent = [];

    private \WP_Post $post;

    private string $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->replies = [];
        $this->sent = [];
        // Next year, so the dates stay inside the accepted window whenever
        // the tests run.
        $this->year = (string) ((int) date('Y') + 1);

        $this->post = new class (7, 'eventmesh_event', 'Ecstatic Dance') extends \WP_Post {
            public string $post_status = 'publish';
        };

        // What the sync stored: the source text, and no start, end, venue or
        // price (the parser found none).
        $this->meta = [
            7 => [
                '_eventmesh_source_title' => 'Ecstatic Dance',
                '_eventmesh_source_content' => '<p>Lauantaina 14.11. Ovet auki 18.30, loppupiiri 21.30.</p>'
                    . '<p>Sydäntila, Fredrikinkatu 61, Helsinki</p><p>Liput 15 €</p>',
                '_eventmesh_starts_at' => '',
                '_eventmesh_ends_at' => '',
            ],
        ];

        Functions\when('wp_salt')->justReturn('salt');
        Functions\when('wp_timezone')->justReturn(new \DateTimeZone('Europe/Helsinki'));
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
        Functions\when('wp_strip_all_tags')->alias(static fn ($value) => strip_tags((string) $value));
        Functions\when('sanitize_text_field')->alias(static fn ($value) => trim(strip_tags((string) $value)));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->justReturn(true);
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

    private function queue(): EventFieldQueue
    {
        return new EventFieldQueue(
            new Logger(),
            static fn () => new EventFieldInferrer(new LlmClient('https://llm.example/v1', 'test-model', 'sk-test'))
        );
    }

    private static function budget(): TimeBudget
    {
        return new TimeBudget(1040.0, static fn (): float => 1000.0);
    }

    private function fullReply(): string
    {
        return '```json' . "\n" . json_encode(
            [
                'starts_at' => $this->year . '-11-14T18:30',
                'ends_at' => $this->year . '-11-14T21:30',
                'venue_name' => ' Sydäntila ',
                'venue_address' => 'Fredrikinkatu 61, Helsinki',
                'price' => '15 €',
            ],
            JSON_UNESCAPED_UNICODE
        ) . "\n```";
    }

    private function userMessage(int $request = 0): string
    {
        return (string) $this->sent[$request]['messages'][1]['content'];
    }

    public function testFillsEveryEmptyFieldAndMarksItInferred(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = [$this->fullReply()];

        $result = $queue->run(self::budget());

        self::assertSame(5, $result['filled']);
        self::assertSame([], $queue->queue());
        self::assertCount(1, $this->sent);
        self::assertSame(EventFieldInferrer::PROMPT, $this->sent[0]['messages'][0]['content']);
        self::assertStringContainsString('Find: starts_at, ends_at, venue_name, venue_address, price', $this->userMessage());
        self::assertStringContainsString("Ovet auki 18.30, loppupiiri 21.30.\nSydäntila", $this->userMessage());

        self::assertSame($this->year . '-11-14T18:30:00', $this->meta[7]['_eventmesh_starts_at']);
        self::assertSame($this->year . '-11-14T21:30:00', $this->meta[7]['_eventmesh_ends_at']);
        self::assertSame('Sydäntila', $this->meta[7]['_eventmesh_venue_name']);
        self::assertSame('Fredrikinkatu 61, Helsinki', $this->meta[7]['_eventmesh_venue_address']);
        self::assertSame('15 €', $this->meta[7]['_eventmesh_price']);

        $hash = InferredFields::hashFor(7);
        self::assertSame(
            array_fill_keys(InferredFields::FIELDS, $hash),
            $this->meta[7][InferredFields::META_FIELDS]
        );
    }

    public function testKeepsWhatTheParserFoundAndDoesNotAskForIt(): void
    {
        $this->meta[7]['_eventmesh_starts_at'] = $this->year . '-11-14T18:30:00';
        $this->meta[7]['_eventmesh_venue_name'] = 'Sydäntila (Holvi)';
        $this->meta[7]['_eventmesh_price'] = '15,00 €';

        $queue = $this->queue();
        $queue->onEventSynced(7);
        // The model answers for every field anyway.
        $this->replies = [
            json_encode(
                [
                    'starts_at' => $this->year . '-11-14T19:00',
                    'ends_at' => $this->year . '-11-14T21:30',
                    'venue_name' => 'Somewhere else',
                    'venue_address' => 'Fredrikinkatu 61, Helsinki',
                    'price' => '20 €',
                ]
            ),
        ];
        $queue->run(self::budget());

        self::assertStringContainsString('Find: ends_at, venue_address', $this->userMessage());
        self::assertStringContainsString('Known starts_at: ' . $this->year . '-11-14T18:30:00', $this->userMessage());

        self::assertSame($this->year . '-11-14T18:30:00', $this->meta[7]['_eventmesh_starts_at']);
        self::assertSame('Sydäntila (Holvi)', $this->meta[7]['_eventmesh_venue_name']);
        self::assertSame('15,00 €', $this->meta[7]['_eventmesh_price']);
        self::assertSame($this->year . '-11-14T21:30:00', $this->meta[7]['_eventmesh_ends_at']);
        self::assertSame('Fredrikinkatu 61, Helsinki', $this->meta[7]['_eventmesh_venue_address']);
        self::assertSame(['ends_at', 'venue_address'], array_keys($this->meta[7][InferredFields::META_FIELDS]));
    }

    public function testNothingIsQueuedWhenTheParserFoundEveryField(): void
    {
        $this->meta[7]['_eventmesh_starts_at'] = $this->year . '-11-14T18:30:00';
        $this->meta[7]['_eventmesh_ends_at'] = $this->year . '-11-14T21:30:00';
        $this->meta[7]['_eventmesh_venue_name'] = 'Sydäntila';
        $this->meta[7]['_eventmesh_venue_address'] = 'Fredrikinkatu 61';
        $this->meta[7]['_eventmesh_price'] = '15 €';

        $queue = $this->queue();
        $queue->onEventSynced(7);

        self::assertSame([], $queue->queue());
    }

    public function testBadJsonWritesNothingAndIsRetried(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = ['The event starts at half past six.'];

        $result = $queue->run(self::budget());

        self::assertSame(0, $result['filled']);
        self::assertSame(1, $result['failed']);
        self::assertSame([7], $queue->queue(), 'Stays queued for another attempt.');
        self::assertSame('', $this->meta[7]['_eventmesh_starts_at']);
        self::assertArrayNotHasKey(InferredFields::META_FIELDS, $this->meta[7]);
        self::assertArrayNotHasKey(InferredFields::META_ASKED, $this->meta[7]);
        self::assertSame('bad_reply', $this->meta[7][InferredFields::META_FAIL]['kind']);
    }

    public function testAJsonListIsRejectedAsBadJson(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = ['["' . $this->year . '-11-14T18:30"]'];

        $result = $queue->run(self::budget());

        self::assertSame(1, $result['failed']);
        self::assertSame('', $this->meta[7]['_eventmesh_starts_at']);
    }

    public function testGivesUpAfterTheMaximumAttempts(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = ['nope', 'nope', 'nope'];

        $queue->run(self::budget());
        $queue->run(self::budget());
        $queue->run(self::budget());

        self::assertSame([], $queue->queue());
        self::assertSame(3, $this->meta[7][InferredFields::META_FAIL]['count']);

        // Synced again with the same text: not asked again.
        $queue->onEventSynced(7);
        $queue->run(self::budget());

        self::assertCount(3, $this->sent);
    }

    public function testInvalidValuesAreDropped(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = [
            json_encode(
                [
                    'starts_at' => 'Saturday evening',
                    'ends_at' => $this->year . '-11-14T21:30',
                    'venue_name' => 'unknown',
                    'venue_address' => 'https://maps.example/sydantila',
                    'price' => 'pay what you like',
                ]
            ),
        ];

        $result = $queue->run(self::budget());

        self::assertSame(0, $result['filled']);
        self::assertSame([], $queue->queue(), 'An answer with nothing usable is still an answer.');
        self::assertSame('', $this->meta[7]['_eventmesh_starts_at']);
        self::assertSame('', $this->meta[7]['_eventmesh_ends_at'], 'An end without a start is dropped.');
        self::assertArrayNotHasKey('_eventmesh_venue_name', $this->meta[7]);
        self::assertArrayNotHasKey('_eventmesh_venue_address', $this->meta[7]);
        self::assertArrayNotHasKey('_eventmesh_price', $this->meta[7]);
        self::assertArrayNotHasKey(InferredFields::META_FIELDS, $this->meta[7]);
    }

    public function testValidateChecksDatesOrderAndLength(): void
    {
        $year = $this->year;
        $fields = InferredFields::FIELDS;

        $badDates = EventFieldInferrer::validate(
            ['starts_at' => $year . '-02-30T18:30', 'ends_at' => $year . '-11-14T25:00'],
            $fields,
            []
        );
        self::assertSame([], $badDates);

        $tooFarAway = EventFieldInferrer::validate(['starts_at' => '1999-11-14T18:30'], $fields, []);
        self::assertSame([], $tooFarAway);

        $endBeforeStart = EventFieldInferrer::validate(
            ['starts_at' => $year . '-11-14T18:30', 'ends_at' => $year . '-11-14T17:00'],
            $fields,
            []
        );
        self::assertSame(['starts_at' => $year . '-11-14T18:30:00'], $endBeforeStart);

        $endBeforeKnownStart = EventFieldInferrer::validate(
            ['ends_at' => $year . '-11-14T17:00'],
            ['ends_at'],
            ['starts_at' => $year . '-11-14T18:30:00']
        );
        self::assertSame([], $endBeforeKnownStart);

        $notAString = EventFieldInferrer::validate(['venue_name' => ['Sydäntila'], 'price' => 15], $fields, []);
        self::assertSame([], $notAString);

        $notAsked = EventFieldInferrer::validate(['venue_name' => 'Sydäntila'], ['price'], []);
        self::assertSame([], $notAsked);

        $long = EventFieldInferrer::validate(
            ['venue_name' => "  <b>Sydäntila</b>\n " . str_repeat('x', 300)],
            $fields,
            []
        );
        self::assertSame(EventFieldInferrer::MAX_LENGTH['venue_name'], mb_strlen($long['venue_name']));
        self::assertStringStartsWith('Sydäntila x', $long['venue_name']);
    }

    public function testTheSameTextIsNotAskedAgainButAChangedTextIs(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = [$this->fullReply()];
        $queue->run(self::budget());

        // A re-sync with the same text: the inferred values are already there.
        $queue->onEventSynced(7);
        self::assertSame([], $queue->queue());

        // The source text changed: the inferred fields are asked about again.
        $this->meta[7]['_eventmesh_source_content'] .= '<p>Liput 20 €</p>';
        $queue->onEventSynced(7);
        $this->replies = [json_encode(['price' => '20 €'], JSON_UNESCAPED_UNICODE)];
        $queue->run(self::budget());

        self::assertCount(2, $this->sent);
        self::assertSame('20 €', $this->meta[7]['_eventmesh_price']);
        self::assertSame(
            $this->year . '-11-14T18:30:00',
            $this->meta[7]['_eventmesh_starts_at'],
            'A field the new answer left out keeps its earlier value.'
        );

        // And that new text is not asked about a third time.
        $queue->onEventSynced(7);
        self::assertSame([], $queue->queue());
    }

    public function testAFieldTheModelCouldNotFindIsNotAskedAgainForTheSameText(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = [json_encode(['starts_at' => $this->year . '-11-14T18:30'])];
        $queue->run(self::budget());

        $queue->onEventSynced(7);

        self::assertSame([], $queue->queue());
        self::assertCount(1, $this->sent);
    }

    public function testDoesNothingWhenTheLlmIsNotReady(): void
    {
        LlmSettings::setEnabled(false);

        $queue = $this->queue();
        $queue->onEventSynced(7);
        $queue->enqueue([7]);

        self::assertSame([], $queue->queue());

        $this->options[EventFieldQueue::OPTION_QUEUE] = [7];
        $result = $queue->run(self::budget());

        self::assertFalse($result['ready']);
        self::assertSame([], $this->sent);
        self::assertSame('', $this->meta[7]['_eventmesh_starts_at']);
    }

    public function testAServerErrorKeepsTheEventQueuedAndWritesNothing(): void
    {
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);

        $queue = $this->queue();
        $queue->onEventSynced(7);
        $result = $queue->run(self::budget());

        self::assertSame('http_503', $result['error']);
        self::assertSame([7], $queue->queue());
        self::assertArrayNotHasKey(InferredFields::META_FAIL, $this->meta[7]);
    }

    public function testTheApiKeyIsNeverInTheRequestBodyOrTheLog(): void
    {
        $queue = $this->queue();
        $queue->onEventSynced(7);
        $this->replies = ['not json'];
        $queue->run(self::budget());

        self::assertStringNotContainsString('sk-test', (string) json_encode($this->sent));
        self::assertStringNotContainsString('sk-test', (string) json_encode($this->options['eventmesh_recent_logs'] ?? []));
    }
}
