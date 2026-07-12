<?php

declare(strict_types=1);

namespace EventMesh\Tests\Sync;

use Brain\Monkey\Functions;
use EventMesh\Models\Event;
use EventMesh\Services\ArtistMap;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Support\Logger;
use EventMesh\Sync\EventSynchronizer;
use EventMesh\Tests\TestCase;

final class EventSynchronizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('get_option')->justReturn('{}');
        Functions\when('update_option')->justReturn(true);
        Functions\when('has_post_thumbnail')->justReturn(true);
        Functions\when('wp_trim_words')->alias(static fn ($text) => $text);
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);
        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('update_post_meta')->justReturn(true);
        // ProviderEmbedEnricher reads _eventmesh_provider_* meta directly on
        // every sync() call; '' means "nothing to embed" so it no-ops
        // without needing wp_remote_get stubbed in tests that don't care.
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_kses')->returnArg(1);
    }

    private function synchronizer(): EventSynchronizer
    {
        $logger = new Logger();

        return new EventSynchronizer(
            $logger,
            new EventMediaEnricher($logger),
            new ProviderEnricher(new ArtistMap(), $logger),
            new ProviderEmbedEnricher($logger)
        );
    }

    private function event(string $externalId, string $title = 'Some Event'): Event
    {
        return new Event('holvi', $externalId, $title);
    }

    public function testSyncInsertsNewPostWhenNoneExists(): void
    {
        $this->queueQueryResults([]);

        Functions\when('wp_insert_post')->justReturn(501);

        $postId = $this->synchronizer()->sync($this->event('ext-new'));

        self::assertSame(501, $postId);
    }

    public function testSyncUpdatesExistingPost(): void
    {
        $this->queueQueryResults([55]);

        Functions\when('wp_update_post')->alias(static fn (array $postarr) => (int) $postarr['ID']);

        $postId = $this->synchronizer()->sync($this->event('ext-existing'));

        self::assertSame(55, $postId);
    }

    public function testSyncReturnsZeroAndLogsWhenInsertFails(): void
    {
        $this->queueQueryResults([]);

        Functions\when('wp_insert_post')->justReturn(new \WP_Error('db', 'insert failed'));

        $postId = $this->synchronizer()->sync($this->event('ext-fail'));

        self::assertSame(0, $postId);
    }

    public function testSyncManyCreatesUpdatesSkipsAndCountsFailures(): void
    {
        // Each looked-up event triggers WP_Query twice: once in syncMany()'s
        // pre-check and once inside sync() itself.
        $this->queueQueryResults([]);   // "New Event" via syncMany
        $this->queueQueryResults([]);   // "New Event" via sync()
        $this->queueQueryResults([99]); // "Existing Event" via syncMany
        $this->queueQueryResults([99]); // "Existing Event" via sync()
        $this->queueQueryResults([]);   // "Failing Event" via syncMany
        $this->queueQueryResults([]);   // "Failing Event" via sync()

        Functions\when('wp_insert_post')->alias(
            static function (array $postarr) {
                if ('Failing Event' === $postarr['post_title']) {
                    return new \WP_Error('db', 'insert failed');
                }

                return 201;
            }
        );
        Functions\when('wp_update_post')->alias(static fn (array $postarr) => (int) $postarr['ID']);

        $events = [
            $this->event('ext-empty-title', ''),
            $this->event('ext-created', 'New Event'),
            $this->event('ext-updated', 'Existing Event'),
            $this->event('ext-failed', 'Failing Event'),
        ];

        $result = $this->synchronizer()->syncMany($events);

        self::assertSame(
            [
                'created' => 1,
                'updated' => 1,
                'failed' => 1,
                'skipped' => 1,
            ],
            $result
        );
    }

    public function testPruneStaleDraftsPostsMissingFromLatestFetch(): void
    {
        $this->queueQueryResults([10, 20, 30]);

        Functions\when('get_post_meta')->alias(
            static function (int $postId) {
                return match ($postId) {
                    10 => 'seen-a',
                    20 => 'stale-b',
                    30 => 'seen-c',
                    default => '',
                };
            }
        );

        $draftedIds = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postarr) use (&$draftedIds) {
                $draftedIds[] = $postarr['ID'];

                return $postarr['ID'];
            }
        );

        $archived = $this->synchronizer()->pruneStale('holvi', ['seen-a', 'seen-c']);

        self::assertSame(1, $archived);
        self::assertSame([20], $draftedIds);
    }

    public function testSyncDoesNotOverwriteAnExistingVenueWhenTheFetchFoundNone(): void
    {
        $this->queueQueryResults([77]);
        Functions\when('wp_update_post')->alias(static fn (array $postarr) => (int) $postarr['ID']);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-no-venue', title: 'No Venue Event', venueName: '');
        $this->synchronizer()->sync($event);

        self::assertArrayNotHasKey(
            '_eventmesh_venue_name',
            $metaWrites,
            'A manually-set (or previously found) venue must survive a sync that finds no venue this time.'
        );
    }

    public function testSyncOverwritesVenueWhenTheFetchFoundOne(): void
    {
        $this->queueQueryResults([78]);
        Functions\when('wp_update_post')->alias(static fn (array $postarr) => (int) $postarr['ID']);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-with-venue', title: 'Venue Event', venueName: 'The Basement Club');
        $this->synchronizer()->sync($event);

        self::assertSame('The Basement Club', $metaWrites['_eventmesh_venue_name'] ?? null);
    }

    public function testSyncWritesSoldOutMeta(): void
    {
        $this->queueQueryResults([]);
        Functions\when('wp_insert_post')->justReturn(88);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-sold-out', title: 'Sold Out Event', soldOut: true);
        $this->synchronizer()->sync($event);

        self::assertSame('1', $metaWrites['_eventmesh_sold_out'] ?? null);
    }

    public function testSyncWritesEmptySoldOutMetaWhenNotSoldOut(): void
    {
        $this->queueQueryResults([]);
        Functions\when('wp_insert_post')->justReturn(89);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-available', title: 'Available Event', soldOut: false);
        $this->synchronizer()->sync($event);

        self::assertSame('', $metaWrites['_eventmesh_sold_out'] ?? null);
    }

    public function testSyncTriggersProviderEmbedEnrichment(): void
    {
        $this->queueQueryResults([]);
        Functions\when('wp_insert_post')->justReturn(90);

        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => '_eventmesh_provider_spotify' === $key
                ? 'https://open.spotify.com/track/abc'
                : ''
        );

        $requested = [];
        Functions\when('wp_remote_get')->alias(
            static function (string $url) use (&$requested) {
                $requested[] = $url;

                return ['__body' => json_encode(['html' => '<iframe src="https://open.spotify.com/embed/track/abc"></iframe>'])];
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $event = new Event(sourceId: 'holvi', externalId: 'ext-embed', title: 'Embed Event');
        $this->synchronizer()->sync($event);

        self::assertCount(1, $requested, 'sync() should trigger ProviderEmbedEnricher, which fetches the oEmbed for the found provider link.');
        self::assertStringContainsString('open.spotify.com/oembed', $requested[0]);
    }

    public function testPruneStaleReturnsZeroWhenNothingIsMissing(): void
    {
        $this->queueQueryResults([10, 20]);

        Functions\when('get_post_meta')->alias(
            static function (int $postId) {
                return match ($postId) {
                    10 => 'seen-a',
                    20 => 'seen-b',
                    default => '',
                };
            }
        );

        Functions\when('wp_update_post')->justReturn(0);

        $archived = $this->synchronizer()->pruneStale('holvi', ['seen-a', 'seen-b']);

        self::assertSame(0, $archived);
    }
}
