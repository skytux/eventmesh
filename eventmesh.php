<?php
/**
 * Plugin Name: EventMesh
 * Description: Synchronize external event sources into native WordPress content.
 * Version: 0.1.0-alpha
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: You
 * License: GPL-2.0-or-later
 * Text Domain: eventmesh
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('EVENTMESH_VERSION', '0.1.0-alpha');
define('EVENTMESH_PLUGIN_FILE', __FILE__);
define('EVENTMESH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EVENTMESH_PLUGIN_URL', plugin_dir_url(__FILE__));

require EVENTMESH_PLUGIN_DIR . 'src/Support/Autoloader.php';

EventMesh\Support\Autoloader::register();

require EVENTMESH_PLUGIN_DIR . 'src/Connectors/Holvi/register.php';

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
