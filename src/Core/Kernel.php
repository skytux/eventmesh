<?php

declare(strict_types=1);

namespace EventMesh\Core;

use EventMesh\Admin\Admin;
use EventMesh\Admin\DashboardPage;
use EventMesh\Admin\DiagnosticsPage;
use EventMesh\Admin\SettingsPage;
use EventMesh\Admin\SourcesPage;
use EventMesh\Admin\View;
use EventMesh\Services\ConnectorManager;
use EventMesh\Support\Logger;

final class Kernel
{
    private Container $container;

    public function __construct()
    {
        $this->container = new Container();
    }

    public function boot(): void
    {
        $this->registerServices();

        $admin = $this->container->get(Admin::class);
        $admin->boot();

        do_action(
            'eventmesh/register_connectors',
            $this->container->get(ConnectorManager::class)
        );

        do_action(
            'eventmesh/boot',
            $this->container
        );
    }

    private function registerServices(): void
    {
        $this->container->singleton(
            Logger::class,
            fn () => new Logger()
        );

        $this->container->singleton(
            ConnectorRegistry::class,
            fn () => new ConnectorRegistry()
        );

        $this->container->singleton(
            ConnectorManager::class,
            fn (Container $container) => new ConnectorManager(
                $container->get(ConnectorRegistry::class)
            )
        );

        $this->container->singleton(
            View::class,
            fn () => new View()
        );

        $this->container->singleton(
            DashboardPage::class,
            fn (Container $container) => new DashboardPage(
                $container->get(View::class),
                $container->get(ConnectorManager::class)
            )
        );

        $this->container->singleton(
            SourcesPage::class,
            fn (Container $container) => new SourcesPage(
                $container->get(View::class),
                $container->get(ConnectorManager::class)
            )
        );

        $this->container->singleton(
            DiagnosticsPage::class,
            fn (Container $container) => new DiagnosticsPage(
                $container->get(View::class)
            )
        );

        $this->container->singleton(
            SettingsPage::class,
            fn (Container $container) => new SettingsPage(
                $container->get(View::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}
