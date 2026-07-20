<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use DateTimeZone;
use EventMesh\Support\LocalTime;
use EventMesh\Tests\TestCase;

final class LocalTimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_timezone')->justReturn(new DateTimeZone('Europe/Helsinki'));
    }

    public function testParsesABareDatetimeAsTheSiteTimezone(): void
    {
        $moment = LocalTime::parse('2026-08-01T20:00:00');

        self::assertNotNull($moment);
        self::assertSame('2026-08-01T20:00:00+03:00', $moment->format('Y-m-d\TH:i:sP'));
    }

    public function testGarbageReturnsNullRatherThanThrowing(): void
    {
        self::assertNull(LocalTime::parse('not a date'));
        self::assertNull(LocalTime::parse(''));
    }

    /**
     * The core promise: what gets stored carries no timezone. A moment with a
     * +03:00 offset is written as the bare wall-clock, so nothing downstream
     * can re-apply an offset and drift the time. This is exactly what stopped
     * every Holvi event landing hours off.
     */
    public function testStoreWritesTheWallClockWithoutAnyTimezone(): void
    {
        $moment = LocalTime::parse('2026-08-01T20:00:00');

        self::assertNotNull($moment);
        self::assertSame('2026-08-01T20:00:00', LocalTime::store($moment));
    }

    public function testStoreOfNullIsAnEmptyString(): void
    {
        self::assertSame('', LocalTime::store(null));
    }

    /**
     * Even a source value that did carry an offset is stored as its own
     * wall-clock, never converted - "ignore the timezone" taken literally.
     */
    public function testStoreKeepsTheWallClockOfAnOffsetInput(): void
    {
        $moment = new \DateTimeImmutable('2026-08-01T20:00:00+03:00');

        self::assertSame('2026-08-01T20:00:00', LocalTime::store($moment));
    }

    public function testFromDateBuildsMidnightInTheSiteTimezone(): void
    {
        $moment = LocalTime::fromDate(2026, 8, 1);

        self::assertNotNull($moment);
        self::assertSame('2026-08-01T00:00:00+03:00', $moment->format('Y-m-d\TH:i:sP'));
    }

    public function testFromDateRejectsAnImpossibleCalendarDate(): void
    {
        self::assertNull(LocalTime::fromDate(2026, 2, 30));
    }

    /**
     * Not UTC. Between midnight and 02:00 Helsinki the UTC date is still
     * yesterday, so a year inferred from a UTC clock can pick the wrong one
     * on New Year's Eve - the exact edge case HolviHtmlParser::buildDate()
     * depends on this for.
     */
    public function testNowUsesTheSiteTimezone(): void
    {
        self::assertSame('Europe/Helsinki', LocalTime::now()->getTimezone()->getName());
    }

    /**
     * Winter and summer are both exercised because Europe/Helsinki's offset
     * only stays +03:00 while DST is in effect - a fixed "+03:00" fallback
     * would be wrong for half the year, which is exactly the failure mode
     * documented against siteTimezone().
     */
    public function testReflectsSummerAndWinterOffsetsCorrectly(): void
    {
        self::assertSame('+03:00', LocalTime::parse('2026-07-01T12:00:00')?->format('P'));
        self::assertSame('+02:00', LocalTime::parse('2026-01-01T12:00:00')?->format('P'));
    }

    // siteTimezone()'s UTC fallback (function_exists('wp_timezone') === false)
    // is not covered here: once any test in the suite stubs wp_timezone(),
    // Brain\Monkey/Patchwork defines it as a real global function for the
    // rest of the process, so function_exists() can never observe "false"
    // again within the same test run. The branch is a one-line guard and is
    // exercised in practice by every WordPress-absent context this plugin
    // itself never runs in - it isn't worth a second PHPUnit process just to
    // prove a single `if`.
}
