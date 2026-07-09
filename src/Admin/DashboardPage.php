<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Content\EventPostType;
use EventMesh\Services\ConnectorManager;
use EventMesh\Sync\EventSynchronizer;

final class DashboardPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors,
        private readonly EventSynchronizer $synchronizer
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

        $events = $connector->fetch();

        if ([] === $events) {
            return [
                'success' => true,
                'synced' => 0,
                'message' => __('No Holvi events were found.', 'eventmesh'),
            ];
        }

        $result = $this->synchronizer->syncMany($events);
        $synced = $result['created'] + $result['updated'];

        $this->persistSyncSummary($result, $synced);

        return [
            'success' => true,
            'synced' => $synced,
            'message' => sprintf(
                _n('Synced %d event.', 'Synced %d events.', $synced, 'eventmesh'),
                $synced
            ),
        ];
    }

    /**
     * @param array{created: int, updated: int, failed: int} $result
     */
    private function persistSyncSummary(array $result, int $synced): void
    {
        set_transient(
            'eventmesh_last_sync',
            [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
                'synced' => $synced,
                'timestamp' => time(),
            ],
            24 * HOUR_IN_SECONDS
        );
    }

    /**
     * @return array{created: int, updated: int, failed: int, synced: int, timestamp: int}|null
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
            'synced' => (int) ($summary['synced'] ?? 0),
            'timestamp' => (int) ($summary['timestamp'] ?? 0),
        ];
    }
}
