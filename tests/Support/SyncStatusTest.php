<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\SyncStatus;
use EventMesh\Tests\TestCase;

final class SyncStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
    }

    public function testMapsKnownStatusesToReadableLabels(): void
    {
        self::assertSame('Idle', SyncStatus::label('idle'));
        self::assertSame('Running', SyncStatus::label('running'));
        self::assertSame('Completed', SyncStatus::label('completed'));
        self::assertSame('Error', SyncStatus::label('error'));
    }

    public function testCompletedWithErrorsIsHumanizedRatherThanShownRaw(): void
    {
        // Regression guard: the dashboard and the [eventmesh_status] shortcode
        // both render this status through label(), so it must never surface as
        // the raw "completed_with_errors" key.
        self::assertSame('Completed with errors', SyncStatus::label('completed_with_errors'));
    }

    public function testUnknownStatusFallsBackToUcfirst(): void
    {
        self::assertSame('Mystery', SyncStatus::label('mystery'));
    }
}
