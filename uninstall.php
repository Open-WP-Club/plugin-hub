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

/**
 * Delete plugin transients.
 */
delete_transient( 'plugin_hub_csv_cache' );

/**
 * Delete disabled plugin options.
 *
 * Get all options that start with 'plugin_hub_disabled_' and delete them.
 */
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'plugin_hub_disabled_' ) . '%'
	)
);
