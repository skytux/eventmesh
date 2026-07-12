<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Admin\DashboardPage;
use EventMesh\Support\Logger;

/**
 * Backstop for when WP-Cron never fires - DISABLE_WP_CRON, a host that
 * blocks WordPress's own loopback requests, or anything else that silently
 * stops the background_sync schedule.
 *
 * Deliberately does NOT use a loopback HTTP request (unlike WP core's own
 * spawn_cron(), and unlike an earlier version of this class): a host that
 * blocks loopback requests would break that mechanism for exactly the same
 * reason it broke WP-Cron's own. Instead, on an ordinary front-end page
 * view, if the sync pipeline looks overdue, this runs the sync directly, in
 * the same PHP process, with no new HTTP request involved at all - so it
 * works regardless of whether loopback requests are blocked.
 * fastcgi_finish_request() (available under PHP-FPM, the same technique
 * wp-cron.php itself uses) flushes the response to the visitor first when
 * available, so there's no perceived delay; without it (e.g. mod_php), the
 * one rate-limited visitor's request simply takes a bit longer to finish -
 * still far better than the sync never running at all.
 */
final class CronFallbackTrigger
{
    private const RATE_LIMIT_TRANSIENT = 'eventmesh_cron_fallback_gate';
    private const RATE_LIMIT_MIN_SECONDS = 300;
    private const SYNC_LOCK_TRANSIENT = 'eventmesh_sync_lock';

    /**
     * Mirrors SyncRunner::LOCK_TTL_SECONDS - a lock older than this is one
     * SyncRunner would itself reclaim, so we don't treat it as "running".
     */
    private const SYNC_LOCK_TTL_SECONDS = 300;
    private const CRON_HOOK = 'eventmesh/background_sync';

    public function __construct(
        private readonly Logger $logger,
        private readonly SyncRunner $syncRunner,
        private readonly DashboardPage $dashboardPage
    ) {
    }

    public function boot(): void
    {
        add_action('shutdown', [$this, 'maybeRunFallbackSync']);
    }

    public function maybeRunFallbackSync(): void
    {
        if (! $this->isEligibleRequest()) {
            return;
        }

        if ($this->isRateLimited()) {
            return;
        }

        if ('1' !== (string) get_option('eventmesh_enable_background_sync', '1')) {
            return;
        }

        if (! $this->isOverdue()) {
            return;
        }

        if ($this->syncIsGenuinelyRunning()) {
            // A sync is already in flight (cron fired just fine, or another
            // visitor's fallback beat us to it) - nothing to do. A *stale*
            // lock, though, must not block us: the fallback exists precisely
            // for when cron is broken, so if we deferred to a leftover lock
            // forever nothing would ever reclaim it. SyncRunner::run() does
            // the actual reclaiming; here we just decline to defer to it.
            return;
        }

        $this->runInline();
    }

    private function isEligibleRequest(): bool
    {
        if (is_admin() || wp_doing_cron() || wp_doing_ajax()) {
            return false;
        }

        return ! (defined('REST_REQUEST') && REST_REQUEST);
    }

    private function isRateLimited(): bool
    {
        if (get_transient(self::RATE_LIMIT_TRANSIENT)) {
            return true;
        }

        set_transient(
            self::RATE_LIMIT_TRANSIENT,
            true,
            max(self::RATE_LIMIT_MIN_SECONDS, (int) ($this->configuredIntervalSeconds() / 2))
        );

        return false;
    }

    private function syncIsGenuinelyRunning(): bool
    {
        $lock = get_transient(self::SYNC_LOCK_TRANSIENT);

        if (false === $lock) {
            return false;
        }

        return (time() - (int) $lock) < self::SYNC_LOCK_TTL_SECONDS;
    }

    private function isOverdue(): bool
    {
        $lastAttempt = (int) get_option('eventmesh_last_sync_attempt_at', 0);
        $interval = $this->configuredIntervalSeconds();

        // One full missed cycle of grace before treating it as overdue,
        // rather than firing the moment a normal scheduling jitter occurs.
        return time() >= $lastAttempt + (2 * $interval);
    }

    private function configuredIntervalSlug(): string
    {
        $configured = (string) get_option('eventmesh_sync_interval', 'hourly');

        return array_key_exists($configured, wp_get_schedules()) ? $configured : 'hourly';
    }

    private function configuredIntervalSeconds(): int
    {
        $schedules = wp_get_schedules();

        return (int) ($schedules[$this->configuredIntervalSlug()]['interval'] ?? HOUR_IN_SECONDS);
    }

    private function runInline(): void
    {
        $canFinishRequest = function_exists('fastcgi_finish_request');

        $this->logger->info(
            sprintf(
                'Background sync looked overdue - running a fallback sync inline ' .
                '(fastcgi_finish_request available: %s).',
                $canFinishRequest ? 'yes' : 'no, this request will take a little longer to finish'
            )
        );

        if ($canFinishRequest) {
            fastcgi_finish_request();
        }

        $result = $this->syncRunner->run();

        // Record the outcome the same way the dashboard's own "Sync now" and
        // the WP-Cron handler do, so the dashboard's "Last sync" reflects a
        // fallback run too (previously it silently didn't), and push the next
        // scheduled run forward so "Next scheduled sync" stops showing an
        // ever-overdue time and WP-Cron doesn't immediately re-fire.
        $this->dashboardPage->persistSyncSummary(
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'archived' => $result['archived'],
            ],
            $result['created'] + $result['updated']
        );

        $this->rescheduleNextRun();

        $this->logger->info(
            sprintf(
                'Fallback sync completed (next run rescheduled): ' .
                'created=%d updated=%d failed=%d skipped=%d archived=%d.',
                $result['created'],
                $result['updated'],
                $result['failed'],
                $result['skipped'],
                $result['archived']
            )
        );
    }

    /**
     * Advances the recurring background_sync event past the occurrence this
     * fallback just covered. WP-Cron reschedules itself when IT fires the
     * event, but a fallback run happens entirely outside that machinery, so
     * without this the schedule would stay stuck in the past.
     */
    private function rescheduleNextRun(): void
    {
        $existing = wp_next_scheduled(self::CRON_HOOK);

        if (false !== $existing) {
            wp_unschedule_event($existing, self::CRON_HOOK);
        }

        wp_schedule_event(
            time() + $this->configuredIntervalSeconds(),
            $this->configuredIntervalSlug(),
            self::CRON_HOOK
        );
    }
}
