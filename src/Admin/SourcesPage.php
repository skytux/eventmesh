<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Services\ConnectorManager;
use EventMesh\Services\HolviSourceManager;

final class SourcesPage
{
    public function __construct(
        private readonly View $view,
        private readonly ConnectorManager $connectors,
        private readonly HolviSourceManager $holviSourceManager
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'sources',
            [
                'source_rows' => $this->connectors->sourceRows(),
                'holvi_sources' => $this->holviSourceManager->all(),
            ]
        );
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save sources.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_save_sources');

        $holviSources = isset($_POST['eventmesh_holvi_sources'])
            ? (array) $_POST['eventmesh_holvi_sources']
            : [];

        $this->holviSourceManager->save($holviSources);

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-sources'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}
