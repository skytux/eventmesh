<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// uninstall.php is loaded standalone by WordPress - eventmesh.php (which
// normally defines this) never runs, so the autoloader has no plugin
// directory to resolve class files against without this.
if (! defined('EVENTMESH_PLUGIN_DIR')) {
    define('EVENTMESH_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

require __DIR__ . '/src/Support/Autoloader.php';

EventMesh\Support\Autoloader::register();
EventMesh\Support\FactoryReset::run();