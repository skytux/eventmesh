<?php

declare(strict_types=1);

namespace EventMesh\Core;

use EventMesh\Admin\Admin;
use EventMesh\Admin\DashboardPage;
use EventMesh\Admin\DiagnosticsPage;
use EventMesh\Admin\EventListBlock;
use EventMesh\Admin\SettingsPage;
use EventMesh\Admin\SourcesPage;
use EventMesh\Admin\View;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Content\EventPostType;
use EventMesh\Content\EventQuery;
use EventMesh\Content\PerformerTaxonomy;
use EventMesh\Content\SingleEventTemplate;
use EventMesh\Services\ArtistMap;
use EventMesh\Services\ConnectorManager;
use EventMesh\Services\CronFallbackTrigger;
use EventMesh\Services\EventMediaEnricher;
use EventMesh\Services\HolviSourceManager;
use EventMesh\Services\ProviderEmbedEnricher;
use EventMesh\Services\ProviderEnricher;
use EventMesh\Services\SourceSettings;
use EventMesh\Services\SyncRunner;
use EventMesh\Sync\EventSynchronizer;
use EventMesh\Support\BlockAppearanceTools;
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
        $this->validateServicesWhenDebugging();

        $admin = $this->container->get(Admin::class);
        $admin->boot();

        $eventPostType = $this->container->get(EventPostType::class);
        $eventPostType->boot();

        $eventQuery = $this->container->get(EventQuery::class);
        $eventQuery->boot();

        $singleEventTemplate = $this->container->get(SingleEventTemplate::class);
        $singleEventTemplate->boot();

        $cronFallbackTrigger = $this->container->get(CronFallbackTrigger::class);
        $cronFallbackTrigger->boot();

        $blockAppearanceTools = $this->container->get(BlockAppearanceTools::class);
        $blockAppearanceTools->boot();

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

    /**
     * A missed or misordered binding among ~20 manually-wired singletons
     * would otherwise only fail lazily, on whatever admin page happens to
     * be the first to touch it, in production. Eagerly resolving every
     * registered service right after wiring converts that into an
     * immediate, logged failure at plugins_loaded - but only under
     * WP_DEBUG, since eagerly constructing every service (including ones
     * nothing on the current request needs) has no reason to happen on a
     * production request that will never hit the broken one anyway.
     */
    private function validateServicesWhenDebugging(): void
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        foreach ($this->container->registeredIds() as $id) {
            try {
                $this->container->get($id);
            } catch (\Throwable $exception) {
                $this->logBootFailure($id, $exception);
            }
        }
    }

    private function logBootFailure(string $id, \Throwable $exception): void
    {
        $message = sprintf(
            'EventMesh: service "%s" failed to resolve during boot: %s',
            $id,
            $exception->getMessage()
        );

        try {
            $this->container->get(Logger::class)->error($message);
        } catch (\Throwable) {
            error_log($message);
        }
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
            ProviderEmbedEnricher::class,
            fn (Container $container) => new ProviderEmbedEnricher(
                $container->get(Logger::class)
            )
        );

        $this->container->singleton(
            EventPostType::class,
            fn (Container $container) => new EventPostType(
                $container->get(ProviderEmbedEnricher::class)
            )
        );

        $this->container->singleton(
            EventQuery::class,
            fn () => new EventQuery()
        );

        $this->container->singleton(
            SingleEventTemplate::class,
            fn () => new SingleEventTemplate()
        );

        $this->container->singleton(
            HolviHtmlParser::class,
            fn () => new HolviHtmlParser()
        );

        $this->container->singleton(
            EventListBlock::class,
            fn (Container $container) => new EventListBlock(
                $container->get(EventQuery::class),
                $container->get(HolviHtmlParser::class)
            )
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
                $container->get(ProviderEnricher::class),
                $container->get(ProviderEmbedEnricher::class)
            )
        );

        $this->container->singleton(
            SourceSettings::class,
            fn () => new SourceSettings()
        );

        $this->container->singleton(
            HolviSourceManager::class,
            fn () => new HolviSourceManager()
        );

        $this->container->singleton(
            SyncRunner::class,
            fn (Container $container) => new SyncRunner(
                $container->get(ConnectorManager::class),
                $container->get(EventSynchronizer::class),
                $container->get(Logger::class),
                $container->get(SourceSettings::class)
            )
        );

        $this->container->singleton(
            CronFallbackTrigger::class,
            fn (Container $container) => new CronFallbackTrigger(
                $container->get(Logger::class),
                $container->get(SyncRunner::class),
                $container->get(DashboardPage::class)
            )
        );

        $this->container->singleton(
            BlockAppearanceTools::class,
            fn () => new BlockAppearanceTools()
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
                $container->get(EventSynchronizer::class),
                $container->get(SyncRunner::class)
            )
        );

        $this->container->singleton(
            SourcesPage::class,
            fn (Container $container) => new SourcesPage(
                $container->get(View::class),
                $container->get(ConnectorManager::class),
                $container->get(HolviSourceManager::class)
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
                $container->get(ArtistMap::class),
                $container->get(SourceSettings::class),
                $container->get(Admin::class),
                $container->get(ConnectorManager::class)
            )
        );

        $this->container->singleton(
            Admin::class,
            fn (Container $container) => new Admin($container)
        );
    }
}
