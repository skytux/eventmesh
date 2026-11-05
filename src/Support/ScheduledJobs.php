<?php

declare(strict_types=1);

namespace EventMesh\Support;

use EventMesh\Djs\DjDescriptions;
use EventMesh\Inference\EventFieldQueue;
use EventMesh\Naming\EventNameQueue;
use EventMesh\Translation\TranslationQueue;

/**
 * Every WP-Cron hook EventMesh schedules. Deactivating the plugin removes
 * them all, so WordPress does not keep the jobs of a plugin that is off.
 *
 * On activation nothing has to be added back: the background sync is
 * scheduled again on the next page load (Admin::scheduleBackgroundSync()),
 * and each queue schedules its own run when work is added to it.
 */
final class ScheduledJobs
{
    public const BACKGROUND_SYNC_HOOK = 'eventmesh/background_sync';

    /**
     * @return array<int, string>
     */
    public static function hooks(): array
    {
        return [
            self::BACKGROUND_SYNC_HOOK,
            TranslationQueue::HOOK,
            EventNameQueue::HOOK,
            EventFieldQueue::HOOK,
            DjDescriptions::HOOK,
        ];
    }

    /**
     * Removes every scheduled run of every EventMesh hook, whatever its
     * arguments.
     */
    public static function clearAll(): void
    {
        foreach (self::hooks() as $hook) {
            wp_unschedule_hook($hook);
        }
    }
}
