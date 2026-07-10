<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\SyncRunner;
use EventMesh\Sync\EventSynchronizer;

final class DashboardPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors,
        private readonly EventSynchronizer $synchronizer,
        private readonly SyncRunner $syncRunner
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'dashboard',
            [
                'connector_count' => $this->connectors->count(),
                'event_count' => wp_count_posts(EventPostType::NAME)->publish ?? 0,
                'kernel_status' => __('Running', 'eventmesh'),
                'version' => EVENTMESH_VERSION,
                'last_sync' => $this->lastSyncSummary(),
            ]
        );
    }

    /**
     * @return array{success: bool, synced: int, message: string}
     */
    public function syncHolvi(): array
    {
        $connector = $this->connectors->get('holvi');

        if (null === $connector) {
            return [
                'success' => false,
                'synced' => 0,
                'message' => __('No Holvi connector is registered.', 'eventmesh'),
            ];
        }

        $result = $this->syncRunner->run(['holvi']);
        $synced = $result['created'] + $result['updated'];

        $this->persistSyncSummary(
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
            ],
            $synced
        );

        if (0 === $result['processed']) {
            return [
                'success' => true,
                'synced' => 0,
                'message' => __('No Holvi events were found.', 'eventmesh'),
            ];
        }

        return [
            'success' => true,
            'synced' => $synced,
            'message' => sprintf(
                __('Created %1$d, updated %2$d, failed %3$d, skipped %4$d.', 'eventmesh'),
                $result['created'],
                $result['updated'],
                $result['failed'],
                $result['skipped']
            ),
        ];
    }

    /**
     * @param array{created: int, updated: int, failed: int} $result
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
                'synced' => $synced,
                'timestamp' => time(),
            ],
            24 * HOUR_IN_SECONDS
        );
    }

    /**
     * @return array{created: int, updated: int, failed: int, skipped: int, synced: int, timestamp: int}|null
     */
    private function lastSyncSummary(): ?array
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
            'synced' => (int) ($summary['synced'] ?? 0),
            'timestamp' => (int) ($summary['timestamp'] ?? 0),
        ];
    }
}
