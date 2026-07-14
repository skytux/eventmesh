<?php

declare(strict_types=1);

namespace EventMesh\Tests\Sync;

use Brain\Monkey\Functions;
use EventMesh\Models\Event;
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
        // No existing WP_Post by default: the title/description divergence check
        // then finds no fingerprint and writes the source values, exactly as
        // sync did before this tracking existed.
        Functions\when('get_post')->justReturn(null);
        Functions\when('wp_kses')->returnArg(1);
    }

    private function synchronizer(): EventSynchronizer
    {
        $logger = new Logger();

        return new EventSynchronizer(
            $logger,
            new EventMediaEnricher($logger),
            new ProviderEnricher($logger),
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

    public function testPruneOrphanedSourcesDraftsPostsOfUnregisteredConnectors(): void
    {
        $this->queueQueryResults([10, 20, 30]);

        Functions\when('get_post_meta')->alias(
            static function (int $postId) {
                return match ($postId) {
                    10 => 'holvi',
                    20 => 'ghost-connector',
                    30 => 'holvi',
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

        $archived = $this->synchronizer()->pruneOrphanedSources(['holvi']);

        self::assertSame(1, $archived);
        self::assertSame([20], $draftedIds);
    }

    public function testPruneOrphanedSourcesLeavesManuallyCreatedEventsAlone(): void
    {
        $this->queueQueryResults([40]);

        // No _eventmesh_source_id at all: a hand-made event, never owned by
        // any connector - it must not be archived by connector cleanup.
        Functions\when('get_post_meta')->justReturn('');

        Functions\when('wp_update_post')->alias(
            static function (): void {
                TestCase::fail('A manually created event (no source id) must never be archived.');
            }
        );

        self::assertSame(0, $this->synchronizer()->pruneOrphanedSources(['holvi']));
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

    public function testSyncWritesPriceMetaWhenPresentButNotWhenEmpty(): void
    {
        $this->queueQueryResults([]);
        Functions\when('wp_insert_post')->justReturn(91);

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $this->synchronizer()->sync(
            new Event(sourceId: 'holvi', externalId: 'ext-priced', title: 'Priced Event', price: '€15')
        );

        self::assertSame('€15', $metaWrites['_eventmesh_price'] ?? null);

        // A later fetch (e.g. a detail page) that finds no price must not blank
        // a price a previous fetch already recorded.
        $metaWrites = [];
        $this->synchronizer()->sync(
            new Event(sourceId: 'holvi', externalId: 'ext-priced', title: 'Priced Event', price: '')
        );

        self::assertArrayNotHasKey('_eventmesh_price', $metaWrites);
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

    public function testSyncKeepsAManuallyEditedTitleAndDescription(): void
    {
        $this->queueQueryResults([61]);

        $existing = new \WP_Post();
        $existing->ID = 61;
        $existing->post_title = 'My custom headline';
        $existing->post_content = 'My custom body';
        Functions\when('get_post')->justReturn($existing);

        // The fingerprints of what the sync last wrote differ from the post's
        // current title/body, i.e. a person edited both fields by hand.
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => match ($key) {
                '_eventmesh_synced_title' => 'Source Title',
                '_eventmesh_synced_content_hash' => md5('Source body'),
                default => '',
            }
        );

        $written = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postarr) use (&$written) {
                $written = $postarr;

                return (int) $postarr['ID'];
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-edited', title: 'Source Title', description: 'Source body');
        $this->synchronizer()->sync($event);

        self::assertArrayNotHasKey('post_title', $written, 'A hand-edited title must survive a later sync.');
        self::assertArrayNotHasKey('post_content', $written, 'A hand-edited description must survive a later sync.');
    }

    public function testSyncUpdatesTitleAndDescriptionThatStillFollowTheSource(): void
    {
        $this->queueQueryResults([62]);

        $existing = new \WP_Post();
        $existing->ID = 62;
        $existing->post_title = 'Old Source Title';
        $existing->post_content = 'Old source body';
        Functions\when('get_post')->justReturn($existing);

        // The post still matches what the sync last wrote: nobody has edited it,
        // so the sync stays in control and adopts the new source values.
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => match ($key) {
                '_eventmesh_synced_title' => 'Old Source Title',
                '_eventmesh_synced_content_hash' => md5('Old source body'),
                default => '',
            }
        );

        $written = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postarr) use (&$written) {
                $written = $postarr;

                return (int) $postarr['ID'];
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-follow', title: 'New Source Title', description: 'New source body');
        $this->synchronizer()->sync($event);

        self::assertSame('New Source Title', $written['post_title'] ?? null);
        self::assertSame('New source body', $written['post_content'] ?? null);
    }

    public function testAWordPressReEncodedFieldStillCountsAsFollowingTheSource(): void
    {
        $this->queueQueryResults([63]);

        // WordPress stored the source title "Rock & Roll" as "Rock &amp; Roll";
        // the post and the fingerprint both hold that stored form, so nothing
        // has been edited by hand and the sync must stay in control.
        $existing = new \WP_Post();
        $existing->ID = 63;
        $existing->post_title = 'Rock &amp; Roll';
        $existing->post_content = 'Body';
        Functions\when('get_post')->justReturn($existing);
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => match ($key) {
                '_eventmesh_synced_title' => 'Rock &amp; Roll',
                '_eventmesh_synced_content_hash' => md5('Body'),
                default => '',
            }
        );

        $written = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postarr) use (&$written) {
                $written = $postarr;

                return (int) $postarr['ID'];
            }
        );

        $this->synchronizer()->sync(
            new Event(sourceId: 'holvi', externalId: 'ext-amp', title: 'Rock & Roll', description: 'Body')
        );

        self::assertSame(
            'Rock & Roll',
            $written['post_title'] ?? null,
            'A field WordPress merely re-encoded on save must still follow the source, not be mistaken for a manual edit.'
        );
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
