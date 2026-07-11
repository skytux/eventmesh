<?php

declare(strict_types=1);

namespace EventMesh\Tests\Admin;

use Brain\Monkey\Functions;
use EventMesh\Admin\Admin;
use EventMesh\Core\Container;
use EventMesh\Tests\TestCase;

final class AdminCronScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('__')->returnArg(1);
    }

    private function admin(): Admin
    {
        return new Admin(new Container());
    }

    public function testRegisterCronSchedulesAddsCustomIntervals(): void
    {
        $schedules = $this->admin()->registerCronSchedules(['hourly' => ['interval' => 3600, 'display' => 'Hourly']]);

        self::assertArrayHasKey('eventmesh_15min', $schedules);
        self::assertArrayHasKey('eventmesh_30min', $schedules);
        self::assertSame(15 * 60, $schedules['eventmesh_15min']['interval']);
        self::assertSame(30 * 60, $schedules['eventmesh_30min']['interval']);
        self::assertArrayHasKey('hourly', $schedules, 'Existing schedules should be preserved, not replaced.');
    }

    public function testConfiguredSyncIntervalDefaultsToHourlyWhenUnset(): void
    {
        Functions\when('get_option')->justReturn('hourly');

        self::assertSame('hourly', $this->admin()->configuredSyncInterval());
    }

    public function testConfiguredSyncIntervalReturnsAStoredValidValue(): void
    {
        Functions\when('get_option')->justReturn('eventmesh_15min');

        self::assertSame('eventmesh_15min', $this->admin()->configuredSyncInterval());
    }

    public function testConfiguredSyncIntervalFallsBackToHourlyForAnUnrecognizedValue(): void
    {
        Functions\when('get_option')->justReturn('not-a-real-schedule');

        self::assertSame('hourly', $this->admin()->configuredSyncInterval());
    }

    public function testScheduleBackgroundSyncSchedulesWhenNothingIsScheduledYet(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('get_option')->justReturn('hourly');
        Functions\when('wp_get_scheduled_event')->justReturn(false);

        $scheduled = [];
        Functions\when('wp_schedule_event')->alias(
            static function ($timestamp, $recurrence, $hook) use (&$scheduled) {
                $scheduled[] = [$recurrence, $hook];

                return true;
            }
        );

        $this->admin()->scheduleBackgroundSync();

        self::assertSame([['hourly', 'eventmesh/background_sync']], $scheduled);
    }

    public function testScheduleBackgroundSyncDoesNothingWhenAlreadyScheduledWithTheConfiguredInterval(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('get_option')->justReturn('hourly');
        Functions\when('wp_get_scheduled_event')->justReturn(
            (object) ['schedule' => 'hourly', 'timestamp' => time() + 100]
        );
        Functions\when('wp_unschedule_event')->alias(
            static function () {
                self::fail('Should not unschedule when the interval has not changed.');
            }
        );
        Functions\when('wp_schedule_event')->alias(
            static function () {
                self::fail('Should not reschedule when the interval has not changed.');
            }
        );

        $this->admin()->scheduleBackgroundSync();

        self::assertTrue(true); // no exception/failure means the guards above held.
    }

    public function testScheduleBackgroundSyncReschedulesWhenTheConfiguredIntervalChanged(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('get_option')->justReturn('eventmesh_30min');
        Functions\when('wp_get_scheduled_event')->justReturn(
            (object) ['schedule' => 'hourly', 'timestamp' => 12345]
        );

        $unscheduled = [];
        Functions\when('wp_unschedule_event')->alias(
            static function ($timestamp, $hook) use (&$unscheduled) {
                $unscheduled[] = [$timestamp, $hook];

                return true;
            }
        );

        $scheduled = [];
        Functions\when('wp_schedule_event')->alias(
            static function ($timestamp, $recurrence, $hook) use (&$scheduled) {
                $scheduled[] = $recurrence;

                return true;
            }
        );

        $this->admin()->scheduleBackgroundSync();

        self::assertSame([[12345, 'eventmesh/background_sync']], $unscheduled);
        self::assertSame(['eventmesh_30min'], $scheduled);
    }

    public function testScheduleBackgroundSyncDoesNothingOutsideAdminOrCron(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_get_scheduled_event')->alias(
            static function () {
                self::fail('Should not even check the schedule outside admin/cron context.');
            }
        );

        $this->admin()->scheduleBackgroundSync();

        self::assertTrue(true);
    }

}
