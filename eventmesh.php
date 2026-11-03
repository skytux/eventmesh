<?php
/**
 * Plugin Name: EventMesh
 * Description: Synchronize external event sources such as Holvi into native WordPress content.
 * Version: 1.9.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Lou H
 * License: GPL-2.0
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eventmesh
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

define('EVENTMESH_VERSION', '1.9.0');
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

// The translations ship in languages/ (eventmesh-fi.mo, ...). Loaded first
// thing on init, before any of the plugin's own init callbacks translate a
// label; nothing in EventMesh translates before init. WordPress picks the
// locale per request, so a Polylang page in Finnish gets the Finnish texts.
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain('eventmesh', false, dirname(plugin_basename(EVENTMESH_PLUGIN_FILE)) . '/languages');
	},
	0
);

register_activation_hook(
	EVENTMESH_PLUGIN_FILE,
	static function (): void {
		$post_type = new EventMesh\Content\EventPostType();
		$post_type->register();

		// The DJ table. An update by upload does not run this hook; for that
		// case DjSetup creates or updates the table on the next admin page.
		( new EventMesh\Djs\DjTable() )->install();

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
