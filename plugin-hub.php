<?php

/**
 * Plugin Name:             Plugin Hub
 * Plugin URI:              https://github.com/Open-WP-Club/plugin-hub
 * Description:             Manages WordPress plugins from GitHub repositories, focusing on Open-WP-Club
 * Version:                 1.3.0
 * Author:                  Open WP Club
 * Author URI:              https://openwpclub.com
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

define( 'PLUGIN_HUB_VERSION', '1.3.0' );
define( 'PLUGIN_HUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_HUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIN_HUB_ORGANIZATION', 'Open-WP-Club' );

// Include the main class.
require_once PLUGIN_HUB_PLUGIN_DIR . 'includes/class-main.php';

/**
 * Schedule the daily update-check cron event on activation.
 *
 * @since 1.3.0
 */
function activate_plugin_hub() {
	if ( ! wp_next_scheduled( 'plugin_hub_daily_update_check' ) ) {
		wp_schedule_event( time(), 'daily', 'plugin_hub_daily_update_check' );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\activate_plugin_hub' );

/**
 * Remove the cron event on deactivation.
 *
 * @since 1.3.0
 */
function deactivate_plugin_hub() {
	wp_clear_scheduled_hook( 'plugin_hub_daily_update_check' );
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate_plugin_hub' );

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
