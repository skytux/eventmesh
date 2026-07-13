<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\EventMeta;
use EventMesh\Tests\TestCase;

final class EventMetaTest extends TestCase
{
    /**
     * @param array<string, mixed> $meta keyed by full meta key
     */
    private function stubMeta(array $meta): void
    {
        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key = '', bool $single = false) use ($meta) {
                if ('' === $key) {
                    return array_map(static fn ($value) => [$value], $meta);
                }

                return $meta[$key] ?? '';
            }
        );
    }

    public function testResolvePrefersTheManualValueOverTheScrapedOne(): void
    {
        $this->stubMeta([
            '_eventmesh_price' => '€39',
            '_eventmesh_manual_price' => '€25',
        ]);

        self::assertSame('€25', EventMeta::resolve(1, 'price'));
    }

    public function testResolveFallsBackToScrapedWhenNoManualValue(): void
    {
        $this->stubMeta(['_eventmesh_price' => '€39', '_eventmesh_manual_price' => '']);

        self::assertSame('€39', EventMeta::resolve(1, 'price'));
    }

    public function testResolveReturnsEmptyStringWhenNeitherIsSet(): void
    {
        $this->stubMeta([]);

        self::assertSame('', EventMeta::resolve(1, 'venue_name'));
    }

    public function testIsSoldOutManualForcesOnAndOff(): void
    {
        $this->stubMeta(['_eventmesh_sold_out' => '1', '_eventmesh_manual_sold_out' => '0']);
        self::assertFalse(EventMeta::isSoldOut(1), 'A manual "available" overrides a scraped sold-out.');

        $this->stubMeta(['_eventmesh_sold_out' => '', '_eventmesh_manual_sold_out' => '1']);
        self::assertTrue(EventMeta::isSoldOut(1), 'A manual "sold out" overrides a scraped available.');
    }

    public function testIsSoldOutFollowsTheSourceWhenNoManualOverride(): void
    {
        $this->stubMeta(['_eventmesh_sold_out' => '1', '_eventmesh_manual_sold_out' => '']);

        self::assertTrue(EventMeta::isSoldOut(1));
    }

    public function testResolvedProvidersMergesManualOverScraped(): void
    {
        $this->stubMeta([
            '_eventmesh_provider_spotify' => 'https://open.spotify.com/artist/scraped',
            '_eventmesh_provider_youtube' => 'https://youtube.com/@scraped',
            '_eventmesh_manual_provider_spotify' => 'https://open.spotify.com/artist/manual',
            '_eventmesh_manual_provider_mixcloud' => 'https://mixcloud.com/manual',
        ]);

        self::assertSame(
            [
                'spotify' => 'https://open.spotify.com/artist/manual',
                'youtube' => 'https://youtube.com/@scraped',
                'mixcloud' => 'https://mixcloud.com/manual',
            ],
            EventMeta::resolvedProviders(1)
        );
    }

    public function testResolvedProvidersDropsEmptyValues(): void
    {
        $this->stubMeta([
            '_eventmesh_provider_spotify' => '',
            '_eventmesh_manual_provider_youtube' => 'https://youtube.com/@x',
        ]);

        self::assertSame(['youtube' => 'https://youtube.com/@x'], EventMeta::resolvedProviders(1));
    }
}
