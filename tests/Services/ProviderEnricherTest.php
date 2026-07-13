<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Models\Event;
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
        // The enricher logs a summary after writing, which reads/writes the
        // recent-logs option via Logger.
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    private function enricher(): ProviderEnricher
    {
        return new ProviderEnricher(new Logger());
    }

    public function testWritesProviderLinksParsedDirectlyFromTheEvent(): void
    {
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

    public function testWritesNothingWhenTheEventCarriesNoProviders(): void
    {
        $wrote = false;
        Functions\when('update_post_meta')->alias(
            static function () use (&$wrote) {
                $wrote = true;

                return true;
            }
        );

        $event = new Event(sourceId: 'holvi', externalId: 'ext-4', title: 'Untracked Band');

        $this->enricher()->enrich(42, $event);

        self::assertFalse($wrote, 'No provider meta should be written when the event carries no provider links.');
    }

    public function testDoesNotBlankOutAProviderTheCurrentFetchDidNotMention(): void
    {
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

    public function testSkipsEmptyProviderUrls(): void
    {
        $metaWrites = [];
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$metaWrites) {
                $metaWrites[$key] = $value;

                return true;
            }
        );

        $event = new Event(
            sourceId: 'holvi',
            externalId: 'ext-6',
            title: 'Some Band',
            providers: ['spotify' => 'https://open.spotify.com/artist/xyz', 'youtube' => '   ']
        );

        $this->enricher()->enrich(42, $event);

        self::assertArrayHasKey('_eventmesh_provider_spotify', $metaWrites);
        self::assertArrayNotHasKey('_eventmesh_provider_youtube', $metaWrites);
    }
}
