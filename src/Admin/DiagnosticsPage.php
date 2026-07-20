<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Support\Integrations;
use EventMesh\Support\Logger;

final class DiagnosticsPage
{
    private const CRON_HOOK = 'eventmesh/background_sync';
    private const SYNC_LOCK_TRANSIENT = 'eventmesh_sync_lock';

    public function __construct(
        private readonly View $view,
        private readonly Logger $logger
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'diagnostics',
            [
                'php_version' => PHP_VERSION,
                'plugin_version' => EVENTMESH_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'sync_health' => $this->syncHealth(),
                'recent_logs' => $this->logger->recent(),
                'integrations' => Integrations::all(),
            ]
        );
    }

    /**
     * Turns the otherwise-opaque background-sync state into plain facts, plus
     * a concrete recommendation when the schedule is stuck - the common cause
     * on this host being loopback requests blocked while DISABLE_WP_CRON is
     * left unset, so WordPress keeps trying (and failing) to spawn its own
     * cron instead of deferring to an external trigger.
     *
     * @return array{
     *     background_sync_enabled: bool,
     *     wp_cron_disabled: bool,
     *     next_scheduled: int|false,
     *     is_overdue: bool,
     *     last_attempt: int,
     *     last_sync: int,
     *     lock_held: bool,
     *     lock_age: int,
     *     fastcgi_available: bool,
     *     recommendation: string|null
     * }
     */
    public function syncHealth(): array
    {
        $enabled = '1' === (string) get_option('eventmesh_enable_background_sync', '1');
        $cronDisabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $nextScheduled = wp_next_scheduled(self::CRON_HOOK);
        $now = time();

        $isOverdue = false === $nextScheduled || $nextScheduled < $now;

        $lock = get_transient(self::SYNC_LOCK_TRANSIENT);
        $lockHeld = false !== $lock;

        $lastSync = get_transient('eventmesh_last_sync');
        $lastSyncTime = is_array($lastSync) ? (int) ($lastSync['timestamp'] ?? 0) : 0;

        return [
            'background_sync_enabled' => $enabled,
            'wp_cron_disabled' => $cronDisabled,
            'next_scheduled' => $nextScheduled,
            'is_overdue' => $isOverdue,
            'last_attempt' => (int) get_option('eventmesh_last_sync_attempt_at', 0),
            'last_sync' => $lastSyncTime,
            'lock_held' => $lockHeld,
            'lock_age' => $lockHeld ? max(0, $now - (int) $lock) : 0,
            'fastcgi_available' => function_exists('fastcgi_finish_request'),
            'recommendation' => $this->recommendation($enabled, $isOverdue, $cronDisabled),
        ];
    }

    private function recommendation(bool $enabled, bool $isOverdue, bool $cronDisabled): ?string
    {
        if (! $enabled || ! $isOverdue) {
            return null;
        }

        if (! $cronDisabled) {
            // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
            return __('The background sync is overdue. WordPress runs it via a loopback request, which this host appears to block. Add define(\'DISABLE_WP_CRON\', true); to wp-config.php and trigger wp-cron.php from a system cron (or external scheduler) instead.', 'eventmesh');
        }

        // phpcs:ignore Generic.Files.LineLength.TooLong -- single gettext literal; splitting it breaks extraction.
        return __('The background sync is overdue even though DISABLE_WP_CRON is set, so nothing is triggering wp-cron.php. Confirm your system cron (or external scheduler) is calling your-site/wp-cron.php on the interval you expect.', 'eventmesh');
    }
}
