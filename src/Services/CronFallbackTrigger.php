<?php

declare(strict_types=1);

namespace EventMesh\Services;

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

    public function __construct(
        private readonly Logger $logger,
        private readonly SyncRunner $syncRunner
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

        if (get_transient(self::SYNC_LOCK_TRANSIENT)) {
            // A sync is already running (cron fired just fine, or another
            // visitor's fallback beat us to it) - nothing to do.
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

    private function isOverdue(): bool
    {
        $lastAttempt = (int) get_option('eventmesh_last_sync_attempt_at', 0);
        $interval = $this->configuredIntervalSeconds();

        // One full missed cycle of grace before treating it as overdue,
        // rather than firing the moment a normal scheduling jitter occurs.
        return time() >= $lastAttempt + (2 * $interval);
    }

    private function configuredIntervalSeconds(): int
    {
        $configured = (string) get_option('eventmesh_sync_interval', 'hourly');
        $schedules = wp_get_schedules();

        return (int) ($schedules[$configured]['interval'] ?? HOUR_IN_SECONDS);
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

        $this->logger->info(
            sprintf(
                'Fallback sync completed: created=%d updated=%d failed=%d skipped=%d archived=%d.',
                $result['created'],
                $result['updated'],
                $result['failed'],
                $result['skipped'],
                $result['archived']
            )
        );
    }
}
