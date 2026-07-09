<?php

declare(strict_types=1);

namespace EventMesh\Admin;

final class DiagnosticsPage
{
    public function __construct(
        private readonly View $view
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
            ]
        );
    }
}
