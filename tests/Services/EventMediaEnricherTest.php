<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Models\Event;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class EventMediaEnricherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('is_wp_error')->alias(static fn ($thing) => $thing instanceof \WP_Error);
        Functions\when('get_option')->justReturn([]);
        Functions\when('update_option')->justReturn(true);
    }

    private function enricher(): EventMediaEnricher
    {
        return new EventMediaEnricher(new Logger());
    }

    public function testEnrichLeavesAnExistingFeaturedImageUntouched(): void
    {
        Functions\when('has_post_thumbnail')->justReturn(true);

        $sideloaded = false;
        Functions\when('media_sideload_image')->alias(
            static function () use (&$sideloaded) {
                $sideloaded = true;

                return 7;
            }
        );

        $result = $this->enricher()->enrich(
            42,
            new Event(sourceId: 'holvi', externalId: 'ext', title: 'Band', imageUrl: 'https://img.example/a.jpg')
        );

        self::assertFalse($result, 'A manual (or previously synced) featured image must not be replaced by a normal sync.');
        self::assertFalse($sideloaded, 'No image should be sideloaded when the post already has a thumbnail.');
    }

    public function testReapplyFromSourceReplacesTheThumbnailWithTheSourceImage(): void
    {
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => '_eventmesh_image_url' === $key
                ? 'https://img.example/source.jpg'
                : ''
        );
        Functions\when('get_the_title')->justReturn('Band');

        $deleted = false;
        Functions\when('delete_post_thumbnail')->alias(
            static function () use (&$deleted) {
                $deleted = true;

                return true;
            }
        );

        $sideloadedUrl = '';
        Functions\when('media_sideload_image')->alias(
            static function (string $url) use (&$sideloadedUrl) {
                $sideloadedUrl = $url;

                return 15;
            }
        );

        $thumbnailSet = 0;
        Functions\when('set_post_thumbnail')->alias(
            static function (int $postId, int $attachmentId) use (&$thumbnailSet) {
                $thumbnailSet = $attachmentId;

                return true;
            }
        );

        $result = $this->enricher()->reapplyFromSource(42);

        self::assertTrue($result);
        self::assertTrue($deleted, 'The current featured image must be cleared before re-fetching the source image.');
        self::assertSame('https://img.example/source.jpg', $sideloadedUrl);
        self::assertSame(15, $thumbnailSet);
    }

    public function testReapplyFromSourceDoesNothingWhenTheSourceHasNoImage(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $deleted = false;
        Functions\when('delete_post_thumbnail')->alias(
            static function () use (&$deleted) {
                $deleted = true;

                return true;
            }
        );

        self::assertFalse($this->enricher()->reapplyFromSource(42));
        self::assertFalse($deleted, 'With no source image to fall back to, the existing thumbnail must be left in place.');
    }
}
