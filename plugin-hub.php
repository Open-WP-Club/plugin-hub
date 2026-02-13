<?php

/**
 * Plugin Name:             Plugin Hub
 * Plugin URI:              https://github.com/Open-WP-Club/plugin-hub
 * Description:             Manages WordPress plugins from GitHub repositories, focusing on Open-WP-Club
 * Version:                 1.2.0
 * Author:                  Gabriel Kanev
 * Author URI:              https://gkanev.com
 * License:                 GPL-2.0+
 * License URI:             http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:             plugin-hub
 * Domain Path:             /languages
 * Requires at least:       6.0
 * Requires PHP:            8.0
 * Tested up to:            6.9
 * Update URI:              https://github.com/Open-WP-Club/plugin-hub
 */

namespace PluginHub;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'PLUGIN_HUB_VERSION', '1.2.0' );
define( 'PLUGIN_HUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_HUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include the main class.
require_once PLUGIN_HUB_PLUGIN_DIR . 'includes/class-main.php';

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function run_plugin_hub() {
	$plugin = new Main();
	$plugin->run();
}

run_plugin_hub();
