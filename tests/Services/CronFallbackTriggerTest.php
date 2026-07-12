<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Core\ConnectorRegistry;
use EventMesh\Services\ArtistMap;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\CronFallbackTrigger;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Services\SourceSettings;
use EventMesh\Services\SyncRunner;
use EventMesh\Support\Logger;
use EventMesh\Sync\EventSynchronizer;
use EventMesh\Tests\TestCase;

final class CronFallbackTriggerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->transients = [];

        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('get_option')->alias(
            fn (string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value) {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('get_transient')->alias(
            fn (string $name) => $this->transients[$name] ?? false
        );
        Functions\when('set_transient')->alias(
            function (string $name, $value) {
                $this->transients[$name] = $value;

                return true;
            }
        );
        Functions\when('delete_transient')->alias(
            function (string $name) {
                unset($this->transients[$name]);

                return true;
            }
        );
        Functions\when('wp_get_schedules')->justReturn(['hourly' => ['interval' => 3600]]);
    }

    private function trigger(): CronFallbackTrigger
    {
        return new CronFallbackTrigger(new Logger(), $this->syncRunner());
    }

    /**
     * A real instance rather than a mock, since SyncRunner is final - safe
     * to use here because these tests register no connectors, so run()
     * always no-ops without needing wp_remote_get or any real sync stubbed.
     */
    private function syncRunner(): SyncRunner
    {
        $logger = new Logger();

        return new SyncRunner(
            new ConnectorManager(new ConnectorRegistry()),
            new EventSynchronizer(
                $logger,
                new EventMediaEnricher($logger),
                new ProviderEnricher(new ArtistMap(), $logger),
                new ProviderEmbedEnricher($logger)
            ),
            $logger,
            new SourceSettings()
        );
    }

    public function testDoesNothingOnAnAdminRequest(): void
    {
        Functions\when('is_admin')->justReturn(true);

        $this->trigger()->maybeRunFallbackSync();

        self::assertArrayNotHasKey('eventmesh_last_sync_attempt_at', $this->options);
    }

    public function testDoesNothingWhenNotOverdue(): void
    {
        $recentAttempt = time() - 10;
        $this->options['eventmesh_last_sync_attempt_at'] = $recentAttempt;

        $this->trigger()->maybeRunFallbackSync();

        self::assertSame(
            $recentAttempt,
            $this->options['eventmesh_last_sync_attempt_at'],
            'SyncRunner::run() (which updates this) must not have been called.'
        );
    }

    public function testDoesNothingWhenASyncIsAlreadyLocked(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);
        $this->transients['eventmesh_sync_lock'] = time();

        $this->trigger()->maybeRunFallbackSync();

        self::assertSame(
            time() - (3 * HOUR_IN_SECONDS),
            $this->options['eventmesh_last_sync_attempt_at'],
            'Must not have run a second sync on top of the one already locked.'
        );
    }

    public function testDoesNothingWhenRateLimitGateIsAlreadySet(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);
        $this->transients['eventmesh_cron_fallback_gate'] = true;

        $this->trigger()->maybeRunFallbackSync();

        self::assertSame(time() - (3 * HOUR_IN_SECONDS), $this->options['eventmesh_last_sync_attempt_at']);
    }

    public function testRunsTheSyncInlineWhenOverdueWithoutFastcgiFinishRequest(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);

        // Deliberately not stubbing fastcgi_finish_request: in the real CLI
        // test environment it genuinely doesn't exist, exercising the
        // "not available" branch truthfully rather than by assertion alone.
        $this->trigger()->maybeRunFallbackSync();

        self::assertGreaterThan(
            time() - 5,
            $this->options['eventmesh_last_sync_attempt_at'] ?? 0,
            'SyncRunner::run() must have actually run and updated the last-attempt option.'
        );
    }

    public function testCallsFastcgiFinishRequestFirstWhenAvailable(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);

        $called = false;
        Functions\when('fastcgi_finish_request')->alias(
            function () use (&$called): void {
                $called = true;
            }
        );

        $this->trigger()->maybeRunFallbackSync();

        self::assertTrue($called, 'fastcgi_finish_request() must be called when available, to avoid delaying the visitor.');
        self::assertGreaterThan(time() - 5, $this->options['eventmesh_last_sync_attempt_at'] ?? 0);
    }
}
