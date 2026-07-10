<?php

declare(strict_types=1);

namespace EventMesh\Core;

use EventMesh\Admin\Admin;
use EventMesh\Admin\DashboardPage;
use EventMesh\Admin\DiagnosticsPage;
use EventMesh\Admin\SettingsPage;
use EventMesh\Admin\SourcesPage;
use EventMesh\Admin\View;
use EventMesh\Content\EventPostType;
use EventMesh\Content\PerformerTaxonomy;
use EventMesh\Services\ArtistMap;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Services\SyncRunner;
use EventMesh\Sync\EventSynchronizer;
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

        $eventPostType = $this->container->get(EventPostType::class);
        $eventPostType->boot();

        $performerTaxonomy = $this->container->get(PerformerTaxonomy::class);
        $performerTaxonomy->boot();

        do_action(
            'eventmesh/register_connectors',
            $this->container->get(ConnectorManager::class),
            $this->container
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
            EventPostType::class,
            fn () => new EventPostType()
        );

        $this->container->singleton(
            ArtistMap::class,
            fn () => new ArtistMap()
        );

        $this->container->singleton(
            EventMediaEnricher::class,
            fn (Container $container) => new EventMediaEnricher(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            ProviderEnricher::class,
            fn (Container $container) => new ProviderEnricher(
                $container->get(ArtistMap::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            EventSynchronizer::class,
            fn (Container $container) => new EventSynchronizer(
                $container->get(Logger::class),
                $container->get(EventMediaEnricher::class),
                $container->get(ProviderEnricher::class)
            )
        );

        $this->container->singleton(
            SyncRunner::class,
            fn (Container $container) => new SyncRunner(
                $container->get(ConnectorManager::class),
                $container->get(EventSynchronizer::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            PerformerTaxonomy::class,
            fn () => new PerformerTaxonomy()
        );

        $this->container->singleton(
            View::class,
            fn () => new View()
        );

        $this->container->singleton(
            DashboardPage::class,
            fn (Container $container) => new DashboardPage(
                $container->get(View::class),
                $container->get(ConnectorManager::class),
                $container->get(EventSynchronizer::class)
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
                $container->get(View::class),
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            SettingsPage::class,
            fn (Container $container) => new SettingsPage(
                $container->get(View::class),
                $container->get(ArtistMap::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}
