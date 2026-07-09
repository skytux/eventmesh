<?php

declare(strict_types=1);

use EventMesh\Connectors\Holvi\HolviConnector;
use EventMesh\Connectors\Holvi\HolviHtmlParser;
use EventMesh\Core\Container;
use EventMesh\Services\ConnectorManager;
use EventMesh\Support\Logger;

if (! defined('ABSPATH')) {
    exit;
}

add_action(
    'eventmesh/register_connectors',
    static function (ConnectorManager $connectors, Container $container): void {
        $connectors->register(
            new HolviConnector(
                new HolviHtmlParser(),
                $container->get(Logger::class)
            )
        );
    },
    10,
    2
);
