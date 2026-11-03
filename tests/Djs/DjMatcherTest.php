<?php

declare(strict_types=1);

namespace EventMesh\Tests\Djs;

use Brain\Monkey\Functions;
use EventMesh\Djs\Dj;
use EventMesh\Djs\DjMatcher;
use EventMesh\Tests\TestCase;

final class DjMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The container has no intl, and WordPress is not loaded: a small
        // stand-in for remove_accents() that covers the letters used here.
        Functions\when('remove_accents')->alias(
            static fn (string $text): string => strtr(
                $text,
                ['ä' => 'a', 'Ä' => 'A', 'ö' => 'o', 'Ö' => 'O', 'é' => 'e', 'É' => 'E', 'å' => 'a', 'Å' => 'A']
            )
        );
    }

    public function testMatchesTheNameIgnoringCase(): void
    {
        $dj = new Dj(1, 'Toby One');

        self::assertSame([$dj], DjMatcher::matches([$dj], 'ECSTATIC DANCE / TOBY ONE'));
        self::assertSame([$dj], DjMatcher::matches([$dj], 'ecstatic dance with toby one'));
    }

    public function testMatchesIgnoringAccentsBothWays(): void
    {
        $accented = new Dj(1, 'Esimerkki');
        $plain = new Dj(2, 'Rene');

        self::assertSame([$accented], DjMatcher::matches([$accented], 'Ecstatic Dance / ESIMERKKI'));
        self::assertSame([$plain], DjMatcher::matches([$plain], 'Ecstatic Dance / René'));
    }

    public function testMatchesOnlyWholeWords(): void
    {
        $dj = new Dj(1, 'Ana');

        self::assertSame([], DjMatcher::matches([$dj], 'Banana dance'));
        self::assertSame([], DjMatcher::matches([$dj], 'Anabel plays'));
        self::assertSame([], DjMatcher::matches([$dj], 'Ana2 plays'));
        self::assertSame([$dj], DjMatcher::matches([$dj], 'Dance with Ana, all night'));
        self::assertSame([$dj], DjMatcher::matches([$dj], '(Ana)'));
    }

    public function testAWordInsideAnAccentedWordIsNotAWholeWord(): void
    {
        $dj = new Dj(1, 'Kija');

        self::assertSame([], DjMatcher::matches([$dj], 'Esimerkki'));
    }

    public function testMatchesAnAlias(): void
    {
        $dj = new Dj(1, 'Esimerkki', ['The Miracle Maker']);

        self::assertSame([$dj], DjMatcher::matches([$dj], 'Tonight: the miracle maker'));
    }

    public function testEmptyAliasesAreIgnored(): void
    {
        $dj = new Dj(1, 'Ana', ['', '  ']);

        self::assertSame([], DjMatcher::matches([$dj], 'A dance with nobody named'));
    }

    public function testDecodesEntitiesAndStripsTags(): void
    {
        $first = new Dj(1, 'Sun');
        $second = new Dj(2, 'Moon');

        self::assertSame(
            [$first, $second],
            DjMatcher::matches([$first, $second], 'Dance / <strong>Sun</strong> &amp; Moon')
        );
    }

    public function testCollapsesWhiteSpaceInNameAndText(): void
    {
        $dj = new Dj(1, 'Toby  One');

        self::assertSame([$dj], DjMatcher::matches([$dj], "Toby\u{00A0}One\nplays"));
    }

    public function testOrdersMatchesByWhereTheyFirstAppear(): void
    {
        $first = new Dj(1, 'Alpha');
        $second = new Dj(2, 'Beta', ['Bee']);

        self::assertSame(
            [$second, $first],
            DjMatcher::matches([$first, $second], 'Bee opens, then Alpha')
        );
    }

    public function testEmptyTextMatchesNothing(): void
    {
        self::assertSame([], DjMatcher::matches([new Dj(1, 'Ana')], '   '));
    }
}
