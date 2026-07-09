<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Services\ConnectorManager;

final class DashboardPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'dashboard',
            [
                'connector_count' => $this->connectors->count(),
                'kernel_status' => __('Running', 'eventmesh'),
                'version' => EVENTMESH_VERSION,
            ]
        );
    }
}
