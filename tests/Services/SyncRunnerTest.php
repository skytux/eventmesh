<?php

declare(strict_types=1);

namespace EventMesh\Tests\Services;

use Brain\Monkey\Functions;
use EventMesh\Contracts\ConnectorInterface;
use EventMesh\Core\ConnectorRegistry;
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

    private function runner(?ConnectorManager $connectors = null): SyncRunner
    {
        $logger = new Logger();

        return new SyncRunner(
            $connectors ?? new ConnectorManager(new ConnectorRegistry()),
            new EventSynchronizer(
                $logger,
                new EventMediaEnricher($logger),
                new ProviderEnricher($logger),
                new ProviderEmbedEnricher($logger)
            ),
            $logger,
            new SourceSettings()
        );
    }

    public function testRunArchivesEventsOfADisabledSourceWithoutFetchingIt(): void
    {
        // Lock free, plus the usual transient/option plumbing.
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);

        // The source is registered but disabled in the settings.
        Functions\when('get_option')->alias(
            static fn (string $name, $default = false) => 'eventmesh_source_settings' === $name
                ? ['disabled-src' => false]
                : $default
        );

        $connectors = new ConnectorManager(new ConnectorRegistry());
        $connectors->register(
            new class implements ConnectorInterface {
                public function id(): string
                {
                    return 'disabled-src';
                }

                public function label(): string
                {
                    return 'Disabled Source';
                }

                public function enabledByDefault(): bool
                {
                    return true;
                }

                public function fetch(): array
                {
                    TestCase::fail('A disabled source must never be fetched.');
                }

                public function fetchErrors(): int
                {
                    return 0;
                }
            }
        );

        // pruneStale()'s WP_Query returns two published posts owned by the
        // source; with an empty seen-list both must be drafted.
        $this->queueQueryResults([11, 12]);
        Functions\when('get_post_meta')->alias(
            static fn (int $postId, string $key = '', bool $single = false) => 'ext-' . $postId
        );

        $drafted = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postData) use (&$drafted): int {
                if (($postData['post_status'] ?? '') === 'draft') {
                    $drafted[] = (int) $postData['ID'];
                }

                return (int) $postData['ID'];
            }
        );

        $summary = $this->runner($connectors)->run();

        self::assertSame([11, 12], $drafted, 'Every published event of the disabled source must be drafted.');
        self::assertSame(2, $summary['archived']);
        self::assertSame(0, $summary['processed']);
        self::assertSame('disabled-src', $summary['connectors'][0]['id']);
        self::assertSame(2, $summary['connectors'][0]['archived']);
    }

    public function testRunArchivesEventsWhoseConnectorIsNoLongerRegistered(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('is_wp_error')->justReturn(false);

        // Events from a connector that no longer exists (e.g. the test
        // connector after its toggle was switched off), plus one manually
        // created event with no source id that must be left alone.
        $this->queueQueryResults([21, 22]);
        Functions\when('get_post_meta')->alias(
            static fn (int $postId) => 21 === $postId ? 'ghost' : ''
        );

        $drafted = [];
        Functions\when('wp_update_post')->alias(
            static function (array $postData) use (&$drafted): int {
                $drafted[] = (int) $postData['ID'];

                return (int) $postData['ID'];
            }
        );

        $summary = $this->runner()->run();

        self::assertSame([21], $drafted);
        self::assertSame(1, $summary['archived']);
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

    public function testRunReclaimsAStaleLockAndProceeds(): void
    {
        $transients = ['eventmesh_sync_lock' => time() - (10 * HOUR_IN_SECONDS)];

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

        $this->runner()->run([]);

        self::assertArrayHasKey(
            'eventmesh_last_sync_attempt_at',
            $updatedOptions,
            'A lock older than its TTL must be reclaimed so a crashed run cannot block cron forever.'
        );
        self::assertArrayNotHasKey(
            'eventmesh_sync_lock',
            $transients,
            'The reclaimed lock must be released after the run.'
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
