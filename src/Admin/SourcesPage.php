<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Services\ConnectorManager;

final class SourcesPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'sources',
            [
                'source_rows' => $this->connectors->sourceRows(),
            ]
        );
    }
}
