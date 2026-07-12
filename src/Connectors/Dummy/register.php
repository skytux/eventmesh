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
        // Off by default so a production install never ships sample data.
        // Enable it either way that fits the site: the wp-config constant
        // (locked-down or CI setups) or the Settings-page toggle (a dev/
        // staging admin who can't - or would rather not - edit wp-config).
        $viaConstant = defined('EVENTMESH_ENABLE_TEST_CONNECTOR') && EVENTMESH_ENABLE_TEST_CONNECTOR;
        $viaOption = '1' === (string) get_option('eventmesh_enable_test_connector', '0');

        if (! $viaConstant && ! $viaOption) {
            return;
        }

        $connectors->register(new DummyConnector());
    }
);
