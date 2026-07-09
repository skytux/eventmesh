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
}
