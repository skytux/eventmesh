<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\DiagnosticsPage;
use EventMesh\Admin\View;
use EventMesh\Support\Logger;
use EventMesh\Tests\TestCase;

final class DiagnosticsPageTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = ['eventmesh_enable_background_sync' => '1'];
        $this->transients = [];

        Functions\when('get_option')->alias(
            fn (string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('get_transient')->alias(
            fn (string $name) => $this->transients[$name] ?? false
        );
        Functions\when('__')->returnArg();
    }

    private function page(): DiagnosticsPage
    {
        return new DiagnosticsPage(new View(), new Logger());
    }

    public function testHealthyWhenTheNextRunIsStillInTheFuture(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(time() + 600);

        $health = $this->page()->syncHealth();

        self::assertFalse($health['is_overdue']);
        self::assertNull($health['recommendation']);
    }

    public function testRecommendsDisablingWpCronWhenOverdueAndLoopbackDriven(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(time() - 3600);

        $health = $this->page()->syncHealth();

        self::assertTrue($health['is_overdue']);
        self::assertIsString($health['recommendation']);
        self::assertStringContainsString('DISABLE_WP_CRON', $health['recommendation']);
    }

    public function testTreatsAnUnscheduledEventAsOverdue(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);

        $health = $this->page()->syncHealth();

        self::assertFalse($health['next_scheduled']);
        self::assertTrue($health['is_overdue']);
        self::assertNotNull($health['recommendation']);
    }

    public function testReportsHeldLockWithItsAge(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(time() + 600);
        $this->transients['eventmesh_sync_lock'] = time() - 42;

        $health = $this->page()->syncHealth();

        self::assertTrue($health['lock_held']);
        self::assertGreaterThanOrEqual(42, $health['lock_age']);
    }

    public function testNoRecommendationWhenBackgroundSyncIsDisabled(): void
    {
        $this->options['eventmesh_enable_background_sync'] = '0';
        Functions\when('wp_next_scheduled')->justReturn(time() - 3600);

        $health = $this->page()->syncHealth();

        self::assertFalse($health['background_sync_enabled']);
        self::assertNull($health['recommendation']);
    }
}
