<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Models\Event;
use EventMesh\Services\ArtistMap;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class ProviderEnricherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('sanitize_key')->alias(static fn ($value) => strtolower((string) $value));
        Functions\when('esc_url_raw')->alias(static fn ($value) => $value);
        Functions\when('term_exists')->justReturn(['term_id' => 1]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
    }

    private function enricher(): ProviderEnricher
    {
        return new ProviderEnricher(new ArtistMap(), new Logger());
    }

    public function testWritesProviderLinksParsedDirectlyFromTheEvent(): void
    {
        Functions\when('get_option')->justReturn('{}');

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(
            sourceId: 'holvi',
            externalId: 'ext-1',
            title: 'Some Band',
            providers: ['spotify' => 'https://open.spotify.com/artist/xyz']
        );

        $this->enricher()->enrich(42, $event);

        self::assertSame('https://open.spotify.com/artist/xyz', $metaWrites['_eventmesh_provider_spotify'] ?? null);
    }

    public function testArtistMapFillsInProvidersHolviDidNotMention(): void
    {
        Functions\when('get_option')->justReturn(
            json_encode(['Some Band' => ['mixcloud' => 'https://mixcloud.com/someband']])
        );

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(
            sourceId: 'holvi',
            externalId: 'ext-2',
            title: 'Some Band',
            providers: ['spotify' => 'https://open.spotify.com/artist/xyz']
        );

        $this->enricher()->enrich(42, $event);

        self::assertSame('https://open.spotify.com/artist/xyz', $metaWrites['_eventmesh_provider_spotify'] ?? null);
        self::assertSame('https://mixcloud.com/someband', $metaWrites['_eventmesh_provider_mixcloud'] ?? null);
    }

    public function testHolviParsedProviderWinsOverTheArtistMapOnConflict(): void
    {
        Functions\when('get_option')->justReturn(
            json_encode(['Some Band' => ['spotify' => 'https://open.spotify.com/artist/stale']])
        );

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(
            sourceId: 'holvi',
            externalId: 'ext-3',
            title: 'Some Band',
            providers: ['spotify' => 'https://open.spotify.com/artist/fresh']
        );

        $this->enricher()->enrich(42, $event);

        self::assertSame('https://open.spotify.com/artist/fresh', $metaWrites['_eventmesh_provider_spotify'] ?? null);
    }

    public function testWritesNothingWhenThereIsNothingToEnrichWith(): void
    {
        Functions\when('get_option')->justReturn('{}');

        $wrote = false;
        Functions\when('update_post_meta')->alias(
            static function () use (&$wrote) {
                $wrote = true;

                return true;
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-4', title: 'Untracked Band');

        $this->enricher()->enrich(42, $event);

        self::assertFalse($wrote, 'No provider meta should be written when nothing was found and the artist map has nothing either.');
    }

    public function testDoesNotBlankOutAProviderTheCurrentFetchDidNotMention(): void
    {
        Functions\when('get_option')->justReturn('{}');

        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        // Only Spotify was found this time - a previously manually-entered
        // Mixcloud link must survive untouched, since it's simply absent
        // from this event's providers() rather than explicitly emptied.
        $event = new Event(
            sourceId: 'holvi',
            externalId: 'ext-5',
            title: 'Some Band',
            providers: ['spotify' => 'https://open.spotify.com/artist/xyz']
        );

        $this->enricher()->enrich(42, $event);

        self::assertArrayNotHasKey('_eventmesh_provider_mixcloud', $metaWrites);
    }
}
