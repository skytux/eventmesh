<?php

declare(strict_types=1);

namespace EventMesh\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        \WP_Query::$nextResults = [];
        \WP_Query::$lastArgs = [];

        // A faithful stand-in for wp_date(): render a UTC instant in the
        // given timezone (UTC when none is passed, matching how the event
        // date code calls it with an explicit UTC zone to reproduce the
        // stored wall-clock). Shared here because the date/query code now
        // reaches it from many tests, and its whole reason for existing over
        // date_i18n is that it honours the timezone argument.
        Functions\when('wp_date')->alias(
            static function (string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null): string {
                $moment = new \DateTimeImmutable('@' . ($timestamp ?? time()));

                return $moment->setTimezone($timezone ?? new \DateTimeZone('UTC'))->format($format);
            }
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Queue the posts the next `new WP_Query()` call should return.
     *
     * @param array<int, mixed> $posts
     */
    protected function queueQueryResults(array $posts): void
    {
        \WP_Query::$nextResults[] = $posts;
    }
}
