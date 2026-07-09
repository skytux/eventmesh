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

        return [
            'success' => true,
            'synced' => $synced,
            'message' => sprintf(
                _n('Synced %d event.', 'Synced %d events.', $synced, 'eventmesh'),
                $synced
            ),
        ];
    }
}
