<?php

declare(strict_types=1);

namespace EventMesh\Tests\Support;

use Brain\Monkey\Functions;
use EventMesh\Support\FactoryReset;
use EventMesh\Tests\TestCase;

final class FactoryResetTest extends TestCase
{
    public function testDeletesAllEventsAndReturnsCount(): void
    {
        $this->queueQueryResults([101, 102, 103]);

        $deletedPostIds = [];
        Functions\when('wp_delete_post')->alias(
            static function (int $postId) use (&$deletedPostIds) {
                $deletedPostIds[] = $postId;

                return true;
            }
        );

        Functions\when('is_wp_error')->justReturn(false);

        Functions\when('delete_option')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(2);

        $result = FactoryReset::run();

        self::assertSame([101, 102, 103], $deletedPostIds);
        self::assertSame(['deleted_events' => 3], $result);
    }

    public function testUnschedulesTheBackgroundSyncHook(): void
    {
        $this->queueQueryResults([]);

        Functions\when('delete_option')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);

        $unscheduledHooks = [];
        Functions\when('wp_clear_scheduled_hook')->alias(
            static function (string $hook) use (&$unscheduledHooks) {
                $unscheduledHooks[] = $hook;

                return 1;
            }
        );

        FactoryReset::run();

        self::assertSame(['eventmesh/background_sync'], $unscheduledHooks);
    }

    public function testCountsOnlySuccessfulDeletionsWhenSomeFail(): void
    {
        $this->queueQueryResults([201, 202]);

        Functions\when('wp_delete_post')->alias(static fn (int $postId) => 201 === $postId);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_clear_scheduled_hook')->justReturn(1);

        $result = FactoryReset::run();

        self::assertSame(1, $result['deleted_events']);
    }
}
