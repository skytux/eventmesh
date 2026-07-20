<?php

declare(strict_types=1);

namespace EventMesh\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds dates in the site's timezone.
 *
 * Every source this plugin scrapes (Holvi included) publishes a date with no
 * timezone information at all - not in the JSON-LD, not in the microdata, not
 * in the prose. That's fine, because the time always means "local time where
 * the event happens," which is the same as the WordPress site's own
 * configured timezone for every install this plugin runs on.
 *
 * PHP's DateTimeImmutable, left to its own devices, falls back to the process
 * default timezone for a string with no offset, and WordPress forces that
 * default to UTC. So "2026-10-01T18:00" - a Finnish venue's nine-in-the-
 * evening - was being read as 18:00 UTC, which is 21:00 Helsinki in summer:
 * every event landed three hours late (two in winter). Routing every date
 * construction through here instead is the fix.
 */
final class LocalTime
{
    /**
     * How an event date is stored: a naive wall-clock string with no offset
     * and no zone name. A Holvi event at "18:00" is stored as "…T18:00:00"
     * and shown back as 18:00, full stop - the source states no timezone, so
     * none is invented, stored, or converted. Keeping the offset out of the
     * stored value is the whole point: an offset stored here only ever gets
     * re-applied on display and drifts the time.
     */
    public const STORAGE_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * Serializes an event date for storage, dropping any timezone. Formatting
     * happens in the object's own zone, so the wall-clock a source published
     * is exactly what lands in the database.
     */
    public static function store(?DateTimeImmutable $moment): string
    {
        return $moment?->format(self::STORAGE_FORMAT) ?? '';
    }

    /**
     * Parses a date string in the site's timezone.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, self::siteTimezone());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Builds a naive Y-m-d in the site's timezone.
     */
    public static function fromDate(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        try {
            return new DateTimeImmutable(
                sprintf('%04d-%02d-%02d', $year, $month, $day),
                self::siteTimezone()
            );
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * "Now", in the site's timezone rather than UTC.
     *
     * Used where a parsed date has no year and the current one is inferred:
     * between midnight and 02:00 Helsinki the UTC date is still yesterday, so
     * reading the year from a UTC clock can pick the wrong one on New Year's
     * Eve. Small window, real bug.
     */
    public static function now(string $modifier = 'now'): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($modifier, self::siteTimezone());
        } catch (\Exception) {
            return new DateTimeImmutable();
        }
    }

    /**
     * The timezone WordPress is configured with.
     *
     * Note this is only DST-correct when the site is set to a named city
     * ("Helsinki"), not a fixed "UTC+3" offset - a manual offset has no
     * summer time and will be an hour out for half the year. WordPress says
     * as much on Settings → General, and there is nothing this code can do
     * about it beyond preferring the named zone when one is set.
     */
    public static function siteTimezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }

        return new DateTimeZone('UTC');
    }
}
