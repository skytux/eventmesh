<?php

declare(strict_types=1);

namespace EventMesh\Admin;

use EventMesh\Core\Container;

final class Admin
{
    public function __construct(
        private readonly Container $container
    ) {
    }

    public function boot(): void
    {
        add_action(
            'admin_menu',
            [$this, 'registerMenus']
        );

        add_action(
            'admin_post_eventmesh_sync_holvi',
            [$this, 'handleHolviSync']
        );

        add_action(
            'admin_notices',
            [$this, 'renderSyncNotice']
        );

        add_action(
            'admin_post_eventmesh_save_settings',
            [$this->container->get(SettingsPage::class), 'save']
        );
    }

    public function registerMenus(): void
    {
        add_menu_page(
            __('EventMesh', 'eventmesh'),
            __('EventMesh', 'eventmesh'),
            'manage_options',
            'eventmesh',
            [$this->container->get(DashboardPage::class), 'render'],
            'dashicons-share',
            56
        );

        add_submenu_page(
            'eventmesh',
            __('Dashboard', 'eventmesh'),
            __('Dashboard', 'eventmesh'),
            'manage_options',
            'eventmesh',
            [$this->container->get(DashboardPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Sources', 'eventmesh'),
            __('Sources', 'eventmesh'),
            'manage_options',
            'eventmesh-sources',
            [$this->container->get(SourcesPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Diagnostics', 'eventmesh'),
            __('Diagnostics', 'eventmesh'),
            'manage_options',
            'eventmesh-diagnostics',
            [$this->container->get(DiagnosticsPage::class), 'render']
        );

        add_submenu_page(
            'eventmesh',
            __('Settings', 'eventmesh'),
            __('Settings', 'eventmesh'),
            'manage_options',
            'eventmesh-settings',
            [$this->container->get(SettingsPage::class), 'render']
        );
    }

    public function handleHolviSync(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run this action.', 'eventmesh'));
        }

        check_admin_referer('eventmesh_sync_holvi');

        $result = $this->container->get(DashboardPage::class)->syncHolvi();

        set_transient(
            'eventmesh_sync_notice',
            [
                'type' => $result['success'] ? 'success' : 'error',
                'message' => $result['message'],
            ],
            60
        );

        wp_safe_redirect(
            add_query_arg(
                ['page' => 'eventmesh'],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function renderSyncNotice(): void
    {
        $notice = get_transient('eventmesh_sync_notice');

        if (! is_array($notice)) {
            return;
        }

        delete_transient('eventmesh_sync_notice');

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr((string) ($notice['type'] ?? 'info')),
            esc_html((string) ($notice['message'] ?? ''))
        );
    }
}
