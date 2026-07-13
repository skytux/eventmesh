<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\SyncRunner;
use EventMesh\Support\DateTimeFormat;
use EventMesh\Support\Logger;
use EventMesh\Support\SyncStatus;

final class DashboardPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors,
        private readonly SyncRunner $syncRunner,
        private readonly Logger $logger
    ) {
    }

    public function render(): void
    {
        $recentLogs = array_reverse($this->logger->recent());

        $this->view->render(
            'dashboard',
            [
                'panel' => $this->panel($recentLogs),
                'logs' => $this->recentLogs($recentLogs),
            ]
        );
    }

    /**
     * @return array{success: bool, synced: int, message: string}
     */
    public function runSync(): array
    {
        if (0 === $this->connectors->count()) {
            $this->markSyncState('error', __('No connectors are registered.', 'eventmesh'));

            return [
                'success' => false,
                'synced' => 0,
                'message' => __('No connectors are registered.', 'eventmesh'),
            ];
        }

        $this->markSyncState('running', __('Sync in progress…', 'eventmesh'));

        $result = $this->syncRunner->run();
        $synced = $result['created'] + $result['updated'];

        $this->persistSyncSummary(
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'archived' => $result['archived'],
            ],
            $synced
        );

        if (0 === $result['processed']) {
            $this->markSyncState('completed', __('No events were found.', 'eventmesh'));

            return [
                'success' => true,
                'synced' => 0,
                'message' => __('No events were found.', 'eventmesh'),
            ];
        }

        $message = sprintf(
            /* translators: 1: created count, 2: updated count, 3: failed count, 4: skipped count, 5: archived count */
            __('Created %1$d, updated %2$d, failed %3$d, skipped %4$d, archived %5$d.', 'eventmesh'),
            $result['created'],
            $result['updated'],
            $result['failed'],
            $result['skipped'],
            $result['archived']
        );

        $status = $result['failed'] > 0 ? 'completed_with_errors' : 'completed';
        $this->markSyncState($status, $message);

        return [
            'success' => true,
            'synced' => $synced,
            'message' => $message,
        ];
    }

    /**
     * Runs a sync and returns everything the dashboard's JS needs to refresh
     * the status panel and log list in place, without a full page reload.
     *
     * @return array{
     *     panel: array<string, mixed>,
     *     logs: array<int, array{time: string, level: string, message: string}>
     * }
     */
    public function ajaxSyncResponse(): array
    {
        $this->runSync();

        $recentLogs = array_reverse($this->logger->recent());

        return [
            'panel' => $this->panel($recentLogs),
            'logs' => $this->recentLogs($recentLogs),
        ];
    }

    /**
     * @param array{created: int, updated: int, failed: int, skipped: int, archived?: int} $result
     */
    public function persistSyncSummary(array $result, int $synced): void
    {
        set_transient(
            'eventmesh_last_sync',
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'archived' => $result['archived'] ?? 0,
                'synced' => $synced,
                'timestamp' => time(),
            ],
            24 * HOUR_IN_SECONDS
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function renderStatusShortcode(array $attributes = []): string
    {
        $syncState = $this->syncState();
        $lastSync = $this->lastSyncSummary();
        $nextRun = wp_next_scheduled('eventmesh/background_sync');
        $enabled = '1' === (string) get_option('eventmesh_enable_background_sync', '1');

        ob_start();

        include EVENTMESH_PLUGIN_DIR . 'templates/frontend/sync-status.php';

        return (string) ob_get_clean();
    }

    /**
     * The full view model for the dashboard status panel, as display-ready
     * strings so both the initial render and the AJAX refresh format things
     * the same way.
     *
     * @param array<int, array<string, mixed>>|null $recentLogs Most-recent-first
     *        log entries, passed in so a single render doesn't re-read them.
     *
     * @return array{
     *     status: string,
     *     status_message: string,
     *     last_sync_text: string,
     *     last_error: string,
     *     auto_sync_enabled: bool,
     *     next_sync_text: string,
     *     event_count: int
     * }
     */
    public function panel(?array $recentLogs = null): array
    {
        $recentLogs ??= array_reverse($this->logger->recent());

        $state = $this->syncState();
        $lastSync = $this->lastSyncSummary();
        $lastError = $this->latestError($recentLogs);
        $nextScheduled = wp_next_scheduled('eventmesh/background_sync');

        return [
            'status' => null === $state ? __('Idle', 'eventmesh') : SyncStatus::label($state['status']),
            'status_message' => null === $state ? '' : $state['message'],
            'last_sync_text' => null === $lastSync
                ? __('No sync yet.', 'eventmesh')
                : sprintf(
                    /* translators: 1: created, 2: updated, 3: failed, 4: skipped, 5: archived, 6: date/time */
                    __('Created %1$d, updated %2$d, failed %3$d, skipped %4$d, archived %5$d — %6$s', 'eventmesh'),
                    $lastSync['created'],
                    $lastSync['updated'],
                    $lastSync['failed'],
                    $lastSync['skipped'],
                    $lastSync['archived'],
                    DateTimeFormat::format($lastSync['timestamp'])
                ),
            'last_error' => null === $lastError
                ? ''
                : sprintf('%s — %s', DateTimeFormat::format($lastError['timestamp']), $lastError['message']),
            'auto_sync_enabled' => '1' === (string) get_option('eventmesh_enable_background_sync', '1'),
            'next_sync_text' => false === $nextScheduled
                ? __('Not scheduled', 'eventmesh')
                : DateTimeFormat::format((int) $nextScheduled),
            'event_count' => (int) (wp_count_posts(EventPostType::NAME)->publish ?? 0),
        ];
    }

    /**
     * Most-recent-first, formatted for direct display.
     *
     * @param array<int, array<string, mixed>>|null $recentLogs Most-recent-first
     *        log entries, passed in so a single render doesn't re-read them.
     *
     * @return array<int, array{time: string, level: string, message: string}>
     */
    public function recentLogs(?array $recentLogs = null, int $limit = 8): array
    {
        $recentLogs ??= array_reverse($this->logger->recent());
        $entries = array_slice($recentLogs, 0, $limit);

        return array_map(
            fn (array $entry): array => [
                'time' => DateTimeFormat::format((int) $entry['timestamp']),
                'level' => (string) $entry['level'],
                'message' => (string) $entry['message'],
            ],
            $entries
        );
    }

    /**
     * @param array<int, array<string, mixed>> $recentLogs Most-recent-first log entries.
     *
     * @return array{message: string, timestamp: int}|null
     */
    private function latestError(array $recentLogs): ?array
    {
        foreach ($recentLogs as $entry) {
            if ('ERROR' === strtoupper((string) $entry['level'])) {
                return [
                    'message' => (string) $entry['message'],
                    'timestamp' => (int) $entry['timestamp'],
                ];
            }
        }

        return null;
    }

    /**
     * @return array{status: string, message: string, timestamp: int}|null
     */
    public function syncState(): ?array
    {
        $state = get_transient('eventmesh_sync_status');

        if (! is_array($state)) {
            return null;
        }

        return [
            'status' => (string) ($state['status'] ?? 'idle'),
            'message' => (string) ($state['message'] ?? ''),
            'timestamp' => (int) ($state['timestamp'] ?? 0),
        ];
    }

    private function markSyncState(string $status, string $message): void
    {
        set_transient(
            'eventmesh_sync_status',
            [
                'status' => $status,
                'message' => $message,
                'timestamp' => time(),
            ],
            2 * HOUR_IN_SECONDS
        );
    }

    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     failed: int,
     *     skipped: int,
     *     archived: int,
     *     synced: int,
     *     timestamp: int
     * }|null
     */
    public function lastSyncSummary(): ?array
    {
        $summary = get_transient('eventmesh_last_sync');

        if (! is_array($summary)) {
            return null;
        }

        return [
            'created' => (int) ($summary['created'] ?? 0),
            'updated' => (int) ($summary['updated'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
            'skipped' => (int) ($summary['skipped'] ?? 0),
            'archived' => (int) ($summary['archived'] ?? 0),
            'synced' => (int) ($summary['synced'] ?? 0),
            'timestamp' => (int) ($summary['timestamp'] ?? 0),
        ];
    }
}
