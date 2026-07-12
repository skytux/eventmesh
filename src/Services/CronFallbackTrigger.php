<?php

declare(strict_types=1);

namespace EventMesh\Services;

use EventMesh\Support\Logger;

/**
 * Backstop for when WP-Cron never fires - DISABLE_WP_CRON, a host that
 * blocks WordPress's own loopback, or anything else that silently stops the
 * background_sync schedule. On an ordinary front-end page view, if the sync
 * pipeline looks overdue, fires a non-blocking loopback request (the same
 * blocking=false/short-timeout pattern WP core's own spawn_cron() uses) that
 * runs a sync in the background - the visitor's page is never delayed.
 */
final class CronFallbackTrigger
{
    private const RATE_LIMIT_TRANSIENT = 'eventmesh_cron_fallback_gate';
    private const RATE_LIMIT_MIN_SECONDS = 300;
    private const TOKEN_TRANSIENT = 'eventmesh_cron_fallback_token';
    private const TOKEN_TTL_SECONDS = 30;
    private const AJAX_ACTION = 'eventmesh_run_fallback_sync';
    private const SYNC_LOCK_TRANSIENT = 'eventmesh_sync_lock';

    public function __construct(
        private readonly Logger $logger,
        private readonly SyncRunner $syncRunner
    ) {
    }

    public function boot(): void
    {
        add_action('shutdown', [$this, 'maybeTriggerFallback']);
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [$this, 'handleFallbackRequest']);
    }

    public function maybeTriggerFallback(): void
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
            // fallback loopback beat us to it) - nothing to do.
            return;
        }

        $this->fireLoopback();
    }

    public function handleFallbackRequest(): void
    {
        // Not a user-submitted form, so a wp_verify_nonce()-style nonce
        // doesn't apply here (this request is unauthenticated/nopriv, fired
        // by our own server-side loopback) - the single-use, hash_equals()
        // -checked transient token below is this endpoint's actual
        // authentication, verified immediately after this read.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash((string) $_POST['token'])) : '';
        $stored = get_transient(self::TOKEN_TRANSIENT);

        if ('' === $token || ! is_string($stored) || '' === $stored || ! hash_equals($stored, $token)) {
            wp_die('', '', ['response' => 403]);
        }

        delete_transient(self::TOKEN_TRANSIENT);

        $this->logger->info('Cron fallback triggered a sync run (WP-Cron looked overdue).');

        $this->syncRunner->run();

        wp_die('', '', ['response' => 200]);
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

    private function fireLoopback(): void
    {
        $token = wp_generate_password(32, false);
        set_transient(self::TOKEN_TRANSIENT, $token, self::TOKEN_TTL_SECONDS);

        wp_remote_post(
            admin_url('admin-ajax.php'),
            [
                'timeout' => 0.01,
                'blocking' => false,
                'sslverify' => apply_filters('https_local_ssl_verify', false),
                'body' => [
                    'action' => self::AJAX_ACTION,
                    'token' => $token,
                ],
            ]
        );
    }
}
