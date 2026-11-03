<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Djs\Dj;
use EventMesh\Djs\DjLinkApplier;
use EventMesh\Djs\DjRepository;
use EventMesh\Tests\TestCase;

final class DjLinkApplierTest extends TestCase
{
    private const SPOTIFY_TABLE = 'https://open.spotify.com/artist/table';
    private const SPOTIFY_HAND = 'https://open.spotify.com/artist/hand';
    private const SPOTIFY_SOURCE = 'https://open.spotify.com/artist/source';
    private const SOUNDCLOUD_TABLE = 'https://soundcloud.com/table';
    private const MIXCLOUD_TABLE = 'https://www.mixcloud.com/table/';

    private mixed $savedWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('remove_accents')->alias(static fn (string $text): string => $text);
        $this->savedWpdb = $GLOBALS['wpdb'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->savedWpdb;
        parent::tearDown();
    }

    private static function allLinks(): Dj
    {
        return new Dj(
            1,
            'Ana',
            [],
            ['spotify' => self::SPOTIFY_TABLE, 'soundcloud' => self::SOUNDCLOUD_TABLE, 'mixcloud' => self::MIXCLOUD_TABLE]
        );
    }

    public function testAddsLinksForEveryServiceTheEventHasNone(): void
    {
        $result = DjLinkApplier::decide([self::allLinks()], [], [], []);

        $expected = [
            'spotify' => self::SPOTIFY_TABLE,
            'soundcloud' => self::SOUNDCLOUD_TABLE,
            'mixcloud' => self::MIXCLOUD_TABLE,
        ];

        self::assertSame($expected, $result['writes']);
        self::assertSame($expected, $result['added']);
    }

    public function testOnlyMissingServicesAreAdded(): void
    {
        $result = DjLinkApplier::decide(
            [self::allLinks()],
            ['soundcloud' => 'https://soundcloud.com/hand'],
            ['spotify' => self::SPOTIFY_SOURCE],
            []
        );

        self::assertSame(['mixcloud' => self::MIXCLOUD_TABLE], $result['writes']);
        self::assertSame(['mixcloud' => self::MIXCLOUD_TABLE], $result['added']);
    }

    public function testAHandTypedLinkIsNeverReplaced(): void
    {
        $result = DjLinkApplier::decide([self::allLinks()], ['spotify' => self::SPOTIFY_HAND], [], []);

        self::assertArrayNotHasKey('spotify', $result['writes']);
        self::assertArrayNotHasKey('spotify', $result['added']);
    }

    public function testATableLinkUnderANewHandTypedLinkIsRemoved(): void
    {
        $result = DjLinkApplier::decide(
            [self::allLinks()],
            ['spotify' => self::SPOTIFY_HAND],
            ['spotify' => self::SPOTIFY_TABLE],
            ['spotify' => self::SPOTIFY_TABLE]
        );

        self::assertSame('', $result['writes']['spotify']);
        self::assertArrayNotHasKey('spotify', $result['added']);
    }

    public function testASourceLinkIsNeverReplaced(): void
    {
        $result = DjLinkApplier::decide(
            [self::allLinks()],
            [],
            ['spotify' => self::SPOTIFY_SOURCE],
            ['spotify' => self::SPOTIFY_TABLE]
        );

        self::assertArrayNotHasKey('spotify', $result['writes']);
        self::assertArrayNotHasKey('spotify', $result['added']);
    }

    public function testAChangedTableLinkUpdatesTheLinkTheTableWrote(): void
    {
        $result = DjLinkApplier::decide(
            [new Dj(1, 'Ana', [], ['spotify' => 'https://open.spotify.com/artist/changed'])],
            [],
            ['spotify' => self::SPOTIFY_TABLE],
            ['spotify' => self::SPOTIFY_TABLE]
        );

        self::assertSame(['spotify' => 'https://open.spotify.com/artist/changed'], $result['writes']);
        self::assertSame(['spotify' => 'https://open.spotify.com/artist/changed'], $result['added']);
    }

    public function testALinkTheTableWroteIsRemovedWhenNoDjMatchesAnyMore(): void
    {
        $result = DjLinkApplier::decide([], [], ['spotify' => self::SPOTIFY_TABLE], ['spotify' => self::SPOTIFY_TABLE]);

        self::assertSame(['spotify' => ''], $result['writes']);
        self::assertSame([], $result['added']);
    }

    public function testNothingIsWrittenWhenTheLinkIsAlreadyThere(): void
    {
        $result = DjLinkApplier::decide(
            [new Dj(1, 'Ana', [], ['spotify' => self::SPOTIFY_TABLE])],
            [],
            ['spotify' => self::SPOTIFY_TABLE],
            ['spotify' => self::SPOTIFY_TABLE]
        );

        self::assertSame([], $result['writes']);
        self::assertSame(['spotify' => self::SPOTIFY_TABLE], $result['added']);
    }

    public function testTheFirstNamedDjWithALinkOnTheServiceGivesIt(): void
    {
        $result = DjLinkApplier::decide(
            [
                new Dj(1, 'First', [], ['soundcloud' => 'https://soundcloud.com/first']),
                new Dj(2, 'Second', [], ['spotify' => 'https://open.spotify.com/artist/second', 'soundcloud' => 'https://soundcloud.com/second']),
            ],
            [],
            [],
            []
        );

        self::assertSame(
            ['spotify' => 'https://open.spotify.com/artist/second', 'soundcloud' => 'https://soundcloud.com/first'],
            $result['writes']
        );
    }

    public function testApplyWritesMissingLinksAndRecordsThem(): void
    {
        $this->useDjRows(
            [['id' => '1', 'name' => 'Ana', 'aliases' => "Ana B", 'spotify_url' => self::SPOTIFY_TABLE, 'soundcloud_url' => self::SOUNDCLOUD_TABLE, 'mixcloud_url' => '']]
        );

        Functions\when('get_option')->justReturn('1');
        Functions\when('get_post')->justReturn(new \WP_Post(7, 'eventmesh_event', 'Dance / ANA B', ''));

        $meta = [
            '_eventmesh_manual_provider_spotify' => self::SPOTIFY_HAND,
        ];

        Functions\when('get_post_meta')->alias(
            static function (int $postId, string $key) use (&$meta): string {
                return $meta[$key] ?? '';
            }
        );
        Functions\when('update_post_meta')->alias(
            static function (int $postId, string $key, string $value) use (&$meta): bool {
                $meta[$key] = $value;

                return true;
            }
        );
        Functions\when('delete_post_meta')->alias(
            static function (int $postId, string $key) use (&$meta): bool {
                unset($meta[$key]);

                return true;
            }
        );
        Functions\when('wp_json_encode')->alias(static fn (mixed $value): string => (string) json_encode($value));

        $applier = new DjLinkApplier(new DjRepository());

        self::assertTrue($applier->apply(7));
        self::assertSame(self::SPOTIFY_HAND, $meta['_eventmesh_manual_provider_spotify']);
        self::assertArrayNotHasKey('_eventmesh_provider_spotify', $meta);
        self::assertSame(self::SOUNDCLOUD_TABLE, $meta['_eventmesh_provider_soundcloud']);
        self::assertSame(
            ['soundcloud' => self::SOUNDCLOUD_TABLE],
            json_decode($meta[DjLinkApplier::ADDED_META], true)
        );

        // A second run finds nothing to change.
        self::assertFalse($applier->apply(7));
    }

    public function testApplyIgnoresPostsThatAreNotEvents(): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post(7, 'post', 'Dance / Ana', ''));

        self::assertFalse((new DjLinkApplier(new DjRepository()))->apply(7));
    }

    /**
     * Gives DjRepository these rows from its SELECT.
     *
     * @param array<int, array<string, string>> $rows
     */
    private function useDjRows(array $rows): void
    {
        if (! defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        $GLOBALS['wpdb'] = new class ($rows) {
            public string $prefix = 'wp_';

            /**
             * @param array<int, array<string, string>> $rows
             */
            public function __construct(private readonly array $rows)
            {
            }

            /**
             * @return array<int, array<string, string>>
             */
            public function get_results(string $query, string $output): array
            {
                return $this->rows;
            }
        };
    }
}
