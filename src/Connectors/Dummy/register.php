<?php

declare(strict_types=1);

use EventMesh\Connectors\Dummy\DummyConnector;
use EventMesh\Services\ConnectorManager;

if (! defined('ABSPATH')) {
    exit;
}

add_action(
    'eventmesh/register_connectors',
    static function (ConnectorManager $connectors): void {
        // Always registered so it appears in the Sources table like any other
        // connector, but disabled by default (DummyConnector::enabledByDefault)
        // so a production install never syncs sample data until an admin ticks
        // it on there - the single enable control for this source.
        $connectors->register(new DummyConnector());
    }
);
