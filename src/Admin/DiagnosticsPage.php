<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Support\Logger;

final class DiagnosticsPage
{
    public function __construct(
        private readonly View $view,
        private readonly Logger $logger
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'diagnostics',
            [
                'php_version' => PHP_VERSION,
                'plugin_version' => EVENTMESH_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'recent_logs' => $this->logger->recent(),
            ]
        );
    }
}
