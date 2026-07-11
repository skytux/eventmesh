<?php

declare(strict_types=1);

namespace EventMesh\Tests;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        \WP_Query::$nextResults = [];
        \WP_Query::$lastArgs = [];
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
