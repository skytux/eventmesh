<?php

declare(strict_types=1);

use EventMesh\Connectors\Dummy\DummyConnector;
use EventMesh\Services\ConnectorManager;

if (! defined('ABSPATH')) {
    exit;
}

// Inert in production - opt in on a dev/staging site only, by adding
// define('EVENTMESH_ENABLE_TEST_CONNECTOR', true); to wp-config.php.
if (! defined('EVENTMESH_ENABLE_TEST_CONNECTOR') || ! EVENTMESH_ENABLE_TEST_CONNECTOR) {
    return;
}

add_action(
    'eventmesh/register_connectors',
    static function (ConnectorManager $connectors): void {
        $connectors->register(new DummyConnector());
    }
);
