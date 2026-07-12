<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Core\ConnectorRegistry;
use EventMesh\Services\ArtistMap;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Services\SourceSettings;
use EventMesh\Services\SyncRunner;
use EventMesh\Support\Logger;
use EventMesh\Sync\EventSynchronizer;
use EventMesh\Tests\TestCase;

final class SyncRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn([]);
    }

    private function runner(): SyncRunner
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

    public function testRunSkipsEntirelyWhenALockIsAlreadyHeld(): void
    {
        Functions\when('get_transient')->justReturn(time());
        $updatedOptions = [];
        Functions\when('update_option')->alias(
            function (string $name, $value) use (&$updatedOptions): bool {
                $updatedOptions[$name] = $value;

                return true;
            }
        );

        $summary = $this->runner()->run([]);

        self::assertSame(0, $summary['processed']);
        self::assertArrayNotHasKey(
            'eventmesh_last_sync_attempt_at',
            $updatedOptions,
            'A skipped-due-to-lock run must not count as an attempt.'
        );
    }

    public function testRunAcquiresAndReleasesTheLockAndRecordsTheAttemptTime(): void
    {
        $transients = [];

        Functions\when('get_transient')->alias(
            static fn (string $name) => $transients[$name] ?? false
        );
        Functions\when('set_transient')->alias(
            function (string $name, $value) use (&$transients): bool {
                $transients[$name] = $value;

                return true;
            }
        );
        Functions\when('delete_transient')->alias(
            function (string $name) use (&$transients): bool {
                unset($transients[$name]);

                return true;
            }
        );

        $updatedOptions = [];
        Functions\when('update_option')->alias(
            function (string $name, $value) use (&$updatedOptions): bool {
                $updatedOptions[$name] = $value;

                return true;
            }
        );

        $summary = $this->runner()->run([]);

        self::assertSame(0, $summary['processed']);
        self::assertArrayNotHasKey('eventmesh_sync_lock', $transients, 'The lock must be released after the run.');
        self::assertArrayHasKey('eventmesh_last_sync_attempt_at', $updatedOptions);
    }
}
