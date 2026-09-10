<?php
/**
 * Plugin Name:             Plugin Hub
 * Plugin URI:              https://github.com/Open-WP-Club/plugin-hub
 * Description:             Installs, updates, and manages plugins published by Open WP Club through GitHub.
 * Version:                 1.4.3
 * Author:                  Open WP Club
 * Author URI:              https://openwpclub.com
 * License:                 GPL-2.0+
 * License URI:             http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:             plugin-hub
 * Domain Path:             /languages
 * Requires at least:       6.0
 * Requires PHP:            8.0
 * Tested up to:            7.1
 * Update URI:              https://github.com/Open-WP-Club/plugin-hub
 *
 * @package PluginHub
 */

namespace PluginHub;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'PLUGIN_HUB_VERSION', '1.4.3' );
define( 'PLUGIN_HUB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PLUGIN_HUB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIN_HUB_ORGANIZATION', 'Open-WP-Club' );

/**
 * Return the configured GitHub token.
 *
 * A wp-config.php constant takes precedence over the database option so
 * production credentials do not need to be stored in wp_options.
 *
 * @since 1.4.0
 * @return string
 */
function get_github_token() {
	if ( defined( 'PLUGIN_HUB_GITHUB_TOKEN' ) && is_string( PLUGIN_HUB_GITHUB_TOKEN ) && '' !== trim( PLUGIN_HUB_GITHUB_TOKEN ) ) {
		return trim( PLUGIN_HUB_GITHUB_TOKEN );
	}

	$token = get_option( 'plugin_hub_github_token', '' );
	return is_string( $token ) ? trim( $token ) : '';
}

/**
 * Check whether the GitHub token is managed in wp-config.php.
 *
 * @since 1.4.0
 * @return bool
 */
function is_github_token_managed_by_config() {
	return defined( 'PLUGIN_HUB_GITHUB_TOKEN' ) && is_string( PLUGIN_HUB_GITHUB_TOKEN ) && '' !== trim( PLUGIN_HUB_GITHUB_TOKEN );
}

// Include the main class.
require_once PLUGIN_HUB_PLUGIN_DIR . 'includes/main.php';

/**
 * Schedule the daily update-check cron event on activation.
 *
 * @since 1.3.0
 */
function activate_plugin_hub() {
	if ( ! wp_next_scheduled( 'plugin_hub_daily_update_check' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'plugin_hub_daily_update_check' );
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
	new Main();
}

run_plugin_hub();
