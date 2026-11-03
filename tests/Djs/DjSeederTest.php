<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Djs\Dj;
use EventMesh\Djs\DjSeeder;
use EventMesh\Tests\TestCase;

final class DjSeederTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('remove_accents')->alias(
            static fn (string $text): string => strtr($text, ['ä' => 'a', 'Ä' => 'A', 'é' => 'e'])
        );
    }

    public function testNameIsThePartAfterTheSlash(): void
    {
        self::assertSame(['Esimerkki'], DjSeeder::namesFromTitle('ECSTATIC DANCE 14.11.2026 / Esimerkki'));
    }

    public function testTitleWithoutSlashHasNoNames(): void
    {
        self::assertSame([], DjSeeder::namesFromTitle('ECSTATIC DANCE 14.11.2026'));
        self::assertSame([], DjSeeder::namesFromTitle('AC/DC night'));
    }

    public function testSplitsOnAmpersandAfterDecodingEntities(): void
    {
        self::assertSame(['Sun', 'Moon'], DjSeeder::namesFromTitle('Dance / Sun &amp; Moon'));
        self::assertSame(['Sun', 'Moon', 'Star'], DjSeeder::namesFromTitle('Dance / Sun & Moon&Star'));
    }

    public function testOneDjEventGivesItsLinksAndTheNewestEventWins(): void
    {
        $rows = DjSeeder::buildRows(
            [
                ['title' => 'Dance 2.1. / Ana', 'links' => ['spotify' => 'https://open.spotify.com/artist/new']],
                [
                    'title' => 'Dance 1.1. / ANA',
                    'links' => [
                        'spotify' => 'https://open.spotify.com/artist/old',
                        'soundcloud' => 'https://soundcloud.com/ana',
                    ],
                ],
            ]
        );

        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]->id);
        self::assertSame('Ana', $rows[0]->name);
        self::assertSame('https://open.spotify.com/artist/new', $rows[0]->link('spotify'));
        self::assertSame('https://soundcloud.com/ana', $rows[0]->link('soundcloud'));
        self::assertSame('', $rows[0]->link('mixcloud'));
    }

    public function testSeveralDjEventAddsNamesWithoutLinks(): void
    {
        $rows = DjSeeder::buildRows(
            [
                ['title' => 'Dance / Sun &amp; Moon', 'links' => ['spotify' => 'https://open.spotify.com/artist/x']],
            ]
        );

        self::assertSame(['Sun', 'Moon'], array_map(static fn (Dj $dj): string => $dj->name, $rows));
        self::assertSame([], $rows[0]->links);
        self::assertSame([], $rows[1]->links);
    }

    public function testSeveralDjEventNameLaterGetsLinksFromAOneDjEvent(): void
    {
        $rows = DjSeeder::buildRows(
            [
                ['title' => 'Dance / Sun & Moon', 'links' => ['spotify' => 'https://open.spotify.com/artist/x']],
                ['title' => 'Dance / Sun', 'links' => ['mixcloud' => 'https://www.mixcloud.com/sun/']],
            ]
        );

        self::assertSame('Sun', $rows[0]->name);
        self::assertSame(['mixcloud' => 'https://www.mixcloud.com/sun/'], $rows[0]->links);
    }

    public function testSkipsNamesAlreadyInTheTableAsNameOrAlias(): void
    {
        $rows = DjSeeder::buildRows(
            [
                ['title' => 'Dance / ESIMERKKI', 'links' => ['spotify' => 'https://open.spotify.com/artist/x']],
                ['title' => 'Dance / Miracle', 'links' => []],
                ['title' => 'Dance / New One', 'links' => []],
            ],
            [new Dj(5, 'Esimerkki', ['miracle'])]
        );

        self::assertSame(['New One'], array_map(static fn (Dj $dj): string => $dj->name, $rows));
    }

    public function testEventsWithoutANameAddNothing(): void
    {
        self::assertSame([], DjSeeder::buildRows([['title' => 'Workshop', 'links' => ['spotify' => 'x']]]));
    }
}
