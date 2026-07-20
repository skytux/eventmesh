<?php
/**
 * Plugin Name: EventMesh
 * Description: Synchronize external event sources such as Holvi into native WordPress content.
 * Version: 1.1.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lou H
 * License: GPL-2.0
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eventmesh
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('EVENTMESH_VERSION', '1.1.0');
define('EVENTMESH_PLUGIN_FILE', __FILE__);
define('EVENTMESH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVENTMESH_PLUGIN_URL', plugin_dir_url(__FILE__));

require EVENTMESH_PLUGIN_DIR . 'src/Support/Autoloader.php';

EventMesh\Support\Autoloader::register();

// Each connector's own register.php hooks eventmesh/register_connectors -
// dropping in a new connector directory is enough, no edit needed here.
foreach (glob(EVENTMESH_PLUGIN_DIR . 'src/Connectors/*/register.php') ?: [] as $connector_register_file) {
	require $connector_register_file;
}

register_activation_hook(
	EVENTMESH_PLUGIN_FILE,
	static function (): void {
		$post_type = new EventMesh\Content\EventPostType();
		$post_type->register();

		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	EVENTMESH_PLUGIN_FILE,
	static function (): void {
		flush_rewrite_rules();
	}
);

EventMesh\Core\Plugin::boot();
