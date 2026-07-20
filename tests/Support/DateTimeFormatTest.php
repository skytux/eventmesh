<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use DateTimeZone;
use EventMesh\Support\DateTimeFormat;
use EventMesh\Tests\TestCase;

final class DateTimeFormatTest extends TestCase
{
    public function testAZeroTimestampReadsAsNever(): void
    {
        Functions\when('__')->returnArg(1);

        self::assertSame('Never', DateTimeFormat::format(0));
    }

    /**
     * The log/sync fix: these timestamps are true UTC instants, and must be
     * rendered in the site's timezone. Under a Helsinki site the shared
     * wp_date stub applies the +03:00 offset, so an 09:00 UTC instant shows
     * as 12:00 - which date_i18n() failed to do, leaving them hours early.
     */
    public function testFormatsAUtcInstantInTheSiteTimezone(): void
    {
        Functions\when('get_option')->alias(
            static fn (string $name): string => 'date_format' === $name ? 'Y-m-d' : 'H:i'
        );

        // 2026-06-01 09:00:00 UTC. Rendered in Helsinki (summer, +03:00) this
        // is 12:00. The test overrides the base wp_date stub to pass the site
        // zone explicitly, mirroring what real wp_date() does with no zone arg.
        Functions\when('wp_date')->alias(
            static function (string $format, int $timestamp): string {
                return (new \DateTimeImmutable('@' . $timestamp))
                    ->setTimezone(new DateTimeZone('Europe/Helsinki'))
                    ->format($format);
            }
        );

        $instant = (new \DateTimeImmutable('2026-06-01T09:00:00', new DateTimeZone('UTC')))->getTimestamp();

        self::assertSame('2026-06-01 12:00', DateTimeFormat::format($instant));
    }
}
