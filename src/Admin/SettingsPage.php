<?php

declare(strict_types=1);

namespace EventMesh\Admin;

final class SettingsPage
{
    public function __construct(
        private readonly View $view
    ) {
    }

    public function render(): void
    {
        $this->view->render(
            'settings',
            [
                'holvi_source_urls' => get_option('eventmesh_holvi_source_urls', ''),
            ]
        );
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save this setting.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_settings');

        $urls = isset($_POST['eventmesh_holvi_source_urls'])
            ? wp_unslash((string) $_POST['eventmesh_holvi_source_urls'])
            : '';

        update_option('eventmesh_holvi_source_urls', $urls);

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh-settings'],
                admin_url('admin.php')
            )
        );
        exit;
    }
}
