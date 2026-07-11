<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Services\ArtistMap;
use EventMesh\Tests\TestCase;

final class ArtistMapTest extends TestCase
{
    public function testReadsAndNormalizesStoredJson(): void
    {
        Functions\when('get_option')->justReturn(
            json_encode(
                [
                    'Some Artist' => ['spotify' => 'https://spotify.example/some-artist'],
                    'invalid-entry' => 'not-an-array',
                ]
            )
        );

        $map = new ArtistMap();

        self::assertSame(
            ['spotify' => 'https://spotify.example/some-artist'],
            $map->forArtist('Some Artist')
        );
        self::assertSame([], $map->forArtist('Unknown Artist'));
        self::assertArrayNotHasKey('invalid-entry', $map->all());
    }

    public function testEmptyStoredOptionFallsBackToConfigFile(): void
    {
        Functions\when('get_option')->justReturn('');

        // Falls through to config/artist-map.json via EVENTMESH_PLUGIN_DIR;
        // just assert it doesn't error and returns an array shape.
        self::assertIsArray((new ArtistMap())->all());
    }
}
