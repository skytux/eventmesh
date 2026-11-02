<?php

declare(strict_types=1);

namespace EventMesh\Tests\Translation;

use Brain\Monkey\Functions;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;
use EventMesh\Translation\LlmClient;
use EventMesh\Translation\LlmSettings;
use EventMesh\Translation\TimeBudget;
use EventMesh\Translation\TranslationQueue;
use EventMesh\Translation\TranslationStore;
use EventMesh\Translation\Translator;

/**
 * The whole run with an in-memory database and stubbed HTTP replies.
 */
final class TranslationQueueTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $meta = [];

    /** @var array<int, string> */
    private array $replies = [];

    private int $requests = 0;

    /** @var array<int, int> */
    private array $scheduled = [];

    private \WP_Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->meta = [];
        $this->replies = [];
        $this->requests = 0;
        $this->scheduled = [];

        $this->post = new class (7, 'eventmesh_event', 'Tanssi-ilta', '<p>Liput 15 €</p>') extends \WP_Post {
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
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('wp_trim_words')->alias(static fn ($text) => trim(strip_tags((string) $text)));
        Functions\when('wp_strip_all_tags')->alias(static fn ($text) => strip_tags((string) $text));
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(
            function (int $time): bool {
                $this->scheduled[] = $time - time();

                return true;
            }
        );
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_json_encode')->alias(static fn ($data) => json_encode($data));
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_post')->alias(
            function () {
                ++$this->requests;

                return (string) array_shift($this->replies);
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn ($reply) => json_encode(['choices' => [['message' => ['content' => $reply]]]])
        );

        LlmSettings::save('https://llm.example/v1', 'test-model', 'sk-test');
        LlmSettings::setEnabled(true);
        LlmSettings::saveTargetLanguages(['fi', 'en']);
    }

    private function queue(): TranslationQueue
    {
        return new TranslationQueue(
            new Logger(),
            static fn () => new Translator(new LlmClient('https://llm.example/v1', 'test-model', 'sk-test'))
        );
    }

    private static function budget(): TimeBudget
    {
        return new TimeBudget(1040.0, static fn (): float => 1000.0);
    }

    public function testTranslatesAQueuedEventIntoTheOtherLanguage(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = ['{"lang":"fi"}', 'Dance night', '<p>Tickets 15 €</p>'];

        $result = $queue->run(self::budget());

        self::assertSame(1, $result['translated']);
        self::assertSame(0, $result['left']);
        self::assertSame([], $queue->queue());
        self::assertSame('fi', $this->meta[7][TranslationStore::META_ORIGINAL_LANG]);

        $stored = TranslationStore::read(7, 'en');
        self::assertSame('Dance night', $stored['title']);
        self::assertSame('<p>Tickets 15 €</p>', $stored['content']);
        self::assertSame('auto', $stored['status']);
        self::assertSame(TranslationStore::originalHash($this->post), $stored['hash']);
        self::assertSame('test-model', $stored['model']);
        self::assertSame('', TranslationStore::read(7, 'fi')['title'], 'never translated into its own language');
    }

    public function testUnchangedEventIsNotTranslatedAgain(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);
        $this->replies = ['{"lang":"fi"}', 'Dance night', '<p>Tickets 15 €</p>'];
        $queue->run(self::budget());

        $queue->enqueue([7]);
        $result = $queue->run(self::budget());

        self::assertSame(3, $this->requests);
        self::assertSame(0, $result['translated']);
    }

    public function testEditedTranslationIsLeftAlone(): void
    {
        $this->meta[7] = [
            TranslationStore::META_ORIGINAL_LANG => 'fi',
            TranslationStore::META_ORIGINAL_LANG_HASH => TranslationStore::originalHash($this->post),
            '_eventmesh_tr_en_title' => 'My own title',
            '_eventmesh_tr_en_hash' => 'an older original',
            '_eventmesh_tr_en_status' => 'edited',
        ];

        $queue = $this->queue();
        $queue->enqueue([7]);
        $result = $queue->run(self::budget());

        self::assertSame(0, $this->requests);
        self::assertSame(0, $result['translated']);
        self::assertSame('My own title', $this->meta[7]['_eventmesh_tr_en_title']);
        self::assertSame([], $queue->queue());
    }

    public function testFailedCheckIsCountedAndGivenUpAfterThreeAttempts(): void
    {
        $queue = $this->queue();
        $queue->enqueue([7]);

        $this->replies = ['{"lang":"fi"}', 'Dance night', '<p>Tickets 20 €</p>'];
        $result = $queue->run(self::budget());
        self::assertSame(1, $result['failed']);
        self::assertSame([7], $queue->queue(), 'stays queued for another attempt');
        self::assertSame('', TranslationStore::read(7, 'en')['title'], 'nothing saved');

        $this->replies = ['Dance night', '<p>Tickets 20 €</p>'];
        $queue->run(self::budget());
        $this->replies = ['Dance night', '<p>Tickets 20 €</p>'];
        $queue->run(self::budget());

        $failure = TranslationStore::failure(7, TranslationStore::failureKey('en'));
        self::assertSame(3, $failure['count']);
        self::assertSame('numbers', $failure['kind']);
        self::assertSame([], $queue->queue(), 'given up for this original');

        $requests = $this->requests;
        $queue->enqueue([7]);
        $queue->run(self::budget());
        self::assertSame($requests, $this->requests, 'no further attempts until the original changes');
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
        self::assertNull(TranslationStore::failure(7, TranslationStore::META_ORIGINAL_LANG_FAIL), 'not counted');
        self::assertSame([TranslationQueue::RETRY_AFTER_ERROR], $this->scheduled);
    }

    public function testDoesNothingWhenSwitchedOff(): void
    {
        LlmSettings::setEnabled(false);

        $queue = $this->queue();
        $queue->enqueue([7]);
        $result = $queue->run(self::budget());

        self::assertFalse($result['ready']);
        self::assertSame(0, $this->requests);
        self::assertSame([], $this->scheduled);
        self::assertSame([7], $queue->queue());
    }
}
