<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class ProviderEmbedEnricherTest extends TestCase
{
    private const SPOTIFY_HTML = '<iframe src="https://open.spotify.com/embed/track/abc" ' .
        'width="300" height="352"></iframe>';
    private const MIXCLOUD_HTML = '<iframe src="https://player-widget.mixcloud.com/widget/iframe/?feed=x" ' .
        'width="480" height="120"></iframe>';

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    private function enricher(): ProviderEmbedEnricher
    {
        return new ProviderEmbedEnricher(new Logger());
    }

    /**
     * @param array<string, string> $meta postId-agnostic meta key => value map used by every test post.
     */
    private function stubPostMeta(array $meta, ?array &$writes = null): void
    {
        $writes ??= [];
        $store = $meta;

        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) use (&$store) {
                return $store[$key] ?? '';
            }
        );

        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$store, &$writes) {
                $store[$key] = $value;
                $writes[$key] = $value;

                return true;
            }
        );
    }

    public function testPicksHighestPriorityProviderAndCachesTheEmbed(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_provider_spotify' => 'https://open.spotify.com/track/abc',
                '_eventmesh_provider_mixcloud' => 'https://www.mixcloud.com/someone/mix/',
            ],
            $writes
        );

        $requestedUrls = [];
        Functions\when('wp_remote_get')->alias(
            static function (string $url) use (&$requestedUrls) {
                $requestedUrls[] = $url;

                return ['__body' => json_encode(['html' => self::SPOTIFY_HTML])];
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->alias(static fn ($r) => 200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $this->enricher()->enrich(42);

        self::assertCount(1, $requestedUrls);
        self::assertStringContainsString('open.spotify.com/oembed', $requestedUrls[0]);
        self::assertStringContainsString('open.spotify.com', $writes['_eventmesh_embed_html'] ?? '');
        self::assertSame('https://open.spotify.com/track/abc', $writes['_eventmesh_embed_source_url'] ?? null);
    }

    public function testDoesNothingWhenNoneOfTheThreeProvidersArePresent(): void
    {
        $writes = [];
        $this->stubPostMeta(['_eventmesh_provider_instagram' => 'https://instagram.com/someone'], $writes);

        Functions\when('wp_remote_get')->alias(
            static function () {
                self::fail('Should never fetch when no eligible provider is present.');
            }
        );

        $this->enricher()->enrich(42);

        self::assertSame([], $writes);
    }

    public function testClearsCachedEmbedWhenNoEligibleProviderRemains(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_embed_html' => '<iframe src="https://open.spotify.com/embed/track/old"></iframe>',
                '_eventmesh_embed_source_url' => 'https://open.spotify.com/track/old',
            ],
            $writes
        );

        $this->enricher()->enrich(42);

        self::assertSame('', $writes['_eventmesh_embed_html'] ?? null);
        self::assertSame('', $writes['_eventmesh_embed_source_url'] ?? null);
    }

    public function testSkipsRefetchWhenCachedUrlMatchesTheCurrentOne(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_provider_spotify' => 'https://open.spotify.com/track/abc',
                '_eventmesh_embed_html' => self::SPOTIFY_HTML,
                '_eventmesh_embed_source_url' => 'https://open.spotify.com/track/abc',
            ],
            $writes
        );

        Functions\when('wp_remote_get')->alias(
            static function () {
                self::fail('Should not re-fetch when the cached source URL is unchanged.');
            }
        );

        $this->enricher()->enrich(42);

        self::assertSame([], $writes);
    }

    public function testRefetchesWhenTheProviderUrlChanged(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_provider_spotify' => 'https://open.spotify.com/track/new',
                '_eventmesh_embed_html' => self::SPOTIFY_HTML,
                '_eventmesh_embed_source_url' => 'https://open.spotify.com/track/old',
            ],
            $writes
        );

        Functions\when('wp_remote_get')->justReturn(
            ['__body' => json_encode(['html' => self::SPOTIFY_HTML])]
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $this->enricher()->enrich(42);

        self::assertSame('https://open.spotify.com/track/new', $writes['_eventmesh_embed_source_url'] ?? null);
    }

    public function testClampsHeightAndWidthOfTheReturnedEmbed(): void
    {
        $writes = [];
        $this->stubPostMeta(['_eventmesh_provider_mixcloud' => 'https://www.mixcloud.com/someone/mix/'], $writes);

        Functions\when('wp_remote_get')->justReturn(
            ['__body' => json_encode(['html' => self::MIXCLOUD_HTML])]
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $this->enricher()->enrich(42);

        self::assertStringContainsString('height="70"', $writes['_eventmesh_embed_html']);
        self::assertStringContainsString('width="100%"', $writes['_eventmesh_embed_html']);
        self::assertStringNotContainsString('height="120"', $writes['_eventmesh_embed_html']);
    }

    public function testClearsCacheAndLogsOnAWpErrorResponse(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_provider_spotify' => 'https://open.spotify.com/track/broken',
                '_eventmesh_embed_html' => self::SPOTIFY_HTML,
                '_eventmesh_embed_source_url' => 'https://open.spotify.com/track/old',
            ],
            $writes
        );

        Functions\when('wp_remote_get')->justReturn(new \WP_Error('timeout', 'Request timed out'));

        $this->enricher()->enrich(42);

        self::assertSame('', $writes['_eventmesh_embed_html'] ?? null);
        self::assertSame('', $writes['_eventmesh_embed_source_url'] ?? null);
    }

    public function testClearsCacheOnANonOkHttpStatus(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_provider_spotify' => 'https://open.spotify.com/track/gone',
                '_eventmesh_embed_html' => self::SPOTIFY_HTML,
                '_eventmesh_embed_source_url' => 'https://open.spotify.com/track/old',
            ],
            $writes
        );

        Functions\when('wp_remote_get')->justReturn(['__body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(404);

        $this->enricher()->enrich(42);

        self::assertSame('', $writes['_eventmesh_embed_html'] ?? null);
    }

    public function testClearsCacheOnAMalformedOembedResponse(): void
    {
        $writes = [];
        $this->stubPostMeta(
            [
                '_eventmesh_provider_spotify' => 'https://open.spotify.com/track/weird',
                '_eventmesh_embed_html' => self::SPOTIFY_HTML,
                '_eventmesh_embed_source_url' => 'https://open.spotify.com/track/old',
            ],
            $writes
        );

        Functions\when('wp_remote_get')->justReturn(
            ['__body' => json_encode(['html' => '<p>Not an iframe</p>'])]
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $this->enricher()->enrich(42);

        self::assertSame('', $writes['_eventmesh_embed_html'] ?? null);
    }

    public function testRespectsThePerRunFetchBudget(): void
    {
        $writes = [];
        $store = [];

        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) use (&$store) {
                if ('_eventmesh_provider_spotify' === $key) {
                    return 'https://open.spotify.com/track/' . $postId;
                }

                return $store[$postId][$key] ?? '';
            }
        );
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, mixed $value) use (&$store, &$writes) {
                $store[$postId][$key] = $value;
                $writes[$postId][$key] = $value;

                return true;
            }
        );

        $requestCount = 0;
        Functions\when('wp_remote_get')->alias(
            static function () use (&$requestCount) {
                ++$requestCount;

                return ['__body' => json_encode(['html' => self::SPOTIFY_HTML])];
            }
        );
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->alias(static fn ($r) => $r['__body'] ?? '');

        $enricher = $this->enricher();

        for ($postId = 1; $postId <= 20; ++$postId) {
            $enricher->enrich($postId);
        }

        self::assertSame(15, $requestCount, 'Only MAX_EMBED_FETCHES_PER_RUN fetches should happen in a single run.');
        self::assertArrayNotHasKey(20, $writes, 'Events beyond the budget should be left untouched, not cleared.');
    }
}
