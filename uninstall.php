<?php
/**
 * Plugin Hub Uninstall
 *
 * Uninstalling Plugin Hub deletes plugin options and transients.
 *
 * @package    PluginHub
 * @subpackage PluginHub/uninstall
 * @since      1.1.0
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options.
 */
delete_option( 'plugin_hub_github_plugins' );
delete_option( 'plugin_hub_show_beta' );
delete_option( 'plugin_hub_github_token' );
delete_option( 'plugin_hub_activity_log' );
delete_option( 'plugin_hub_autoupdate_plugins' );
delete_option( 'plugin_hub_last_known_repos' );

/**
 * Delete plugin transients.
 */
delete_transient( 'plugin_hub_csv_cache' );
delete_transient( 'plugin_hub_csv_cache_v2' );
delete_transient( 'plugin_hub_rate_limit' );
wp_clear_scheduled_hook( 'plugin_hub_daily_update_check' );

/**
 * Delete disabled plugin options.
 *
 * Get all options that start with 'plugin_hub_disabled_' and delete them.
 */
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup for dynamically named options.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'plugin_hub_disabled_' ) . '%',
		$wpdb->esc_like( '_transient_plugin_hub_release_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_plugin_hub_release_' ) . '%'
	)
);
