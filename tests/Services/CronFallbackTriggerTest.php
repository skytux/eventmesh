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

    private bool $syncRan = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];
        $this->transients = [];
        $this->syncRan = false;

        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_cron')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('get_option')->alias(
            fn (string $name, $default = false) => $this->options[$name] ?? $default
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
        Functions\when('wp_generate_password')->justReturn('test-token');
        Functions\when('apply_filters')->alias(static fn ($tag, $value) => $value);
        Functions\when('admin_url')->alias(static fn (string $path) => 'https://example.test/wp-admin/' . $path);
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

        $remoteCalled = false;
        Functions\when('wp_remote_post')->alias(
            function () use (&$remoteCalled) {
                $remoteCalled = true;

                return [];
            }
        );

        $this->trigger()->maybeTriggerFallback();

        self::assertFalse($remoteCalled);
    }

    public function testDoesNothingWhenNotOverdue(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time();

        $remoteCalled = false;
        Functions\when('wp_remote_post')->alias(
            function () use (&$remoteCalled) {
                $remoteCalled = true;

                return [];
            }
        );

        $this->trigger()->maybeTriggerFallback();

        self::assertFalse($remoteCalled);
    }

    public function testFiresANonBlockingLoopbackWhenOverdue(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);

        $capturedArgs = null;
        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$capturedArgs) {
                $capturedArgs = $args;

                return [];
            }
        );

        $this->trigger()->maybeTriggerFallback();

        self::assertNotNull($capturedArgs);
        self::assertFalse($capturedArgs['blocking']);
        self::assertSame('eventmesh_run_fallback_sync', $capturedArgs['body']['action']);
    }

    public function testDoesNothingWhenASyncIsAlreadyLocked(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);
        $this->transients['eventmesh_sync_lock'] = time();

        $remoteCalled = false;
        Functions\when('wp_remote_post')->alias(
            function () use (&$remoteCalled) {
                $remoteCalled = true;

                return [];
            }
        );

        $this->trigger()->maybeTriggerFallback();

        self::assertFalse($remoteCalled);
    }

    public function testDoesNothingWhenRateLimitGateIsAlreadySet(): void
    {
        $this->options['eventmesh_last_sync_attempt_at'] = time() - (3 * HOUR_IN_SECONDS);
        $this->transients['eventmesh_cron_fallback_gate'] = true;

        $remoteCalled = false;
        Functions\when('wp_remote_post')->alias(
            function () use (&$remoteCalled) {
                $remoteCalled = true;

                return [];
            }
        );

        $this->trigger()->maybeTriggerFallback();

        self::assertFalse($remoteCalled);
    }

    public function testHandleFallbackRequestRejectsAnInvalidToken(): void
    {
        $this->transients['eventmesh_cron_fallback_token'] = 'the-real-token';
        $_POST['token'] = 'wrong-token';

        Functions\when('sanitize_text_field')->alias(static fn ($value) => $value);
        Functions\when('wp_unslash')->alias(static fn ($value) => $value);

        $died = null;
        Functions\when('wp_die')->alias(
            function (...$args) use (&$died) {
                $died = $args;

                throw new \RuntimeException('wp_die called');
            }
        );

        try {
            $this->trigger()->handleFallbackRequest();
            self::fail('Expected wp_die to be called for an invalid token.');
        } catch (\RuntimeException $exception) {
            self::assertSame('wp_die called', $exception->getMessage());
        }

        self::assertSame(['response' => 403], $died[2] ?? null);

        unset($_POST['token']);
    }
}
