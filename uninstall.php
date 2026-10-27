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
delete_option( 'plugin_hub_supporters' );
delete_metadata( 'user', 0, 'plugin_hub_hide_supporters', '', true );
delete_option( 'plugin_hub_last_notification_hash' );
delete_option( 'plugin_hub_mu_autoupdate' );
delete_option( 'plugin_hub_mu_last_known' );
delete_option( 'plugin_hub_mu_versions' );
delete_option( 'plugin_hub_mu_descriptions' );

/**
 * Delete plugin transients.
 */
delete_transient( 'plugin_hub_catalog_cache_v1' );
delete_transient( 'plugin_hub_catalog_retry_v1' );
delete_transient( 'plugin_hub_csv_cache' );
delete_transient( 'plugin_hub_csv_cache_v2' );
delete_transient( 'plugin_hub_rate_limit' );
delete_transient( 'plugin_hub_mu_catalog_v1' );
delete_transient( 'plugin_hub_mu_retry_v1' );
delete_transient( 'plugin_hub_mu_fetch_retry_v1' );
delete_transient( 'plugin_hub_mu_lock_v1' );
delete_transient( 'plugin_hub_mu_descriptions_v1' );
delete_transient( 'plugin_hub_mu_descriptions_retry_v1' );
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

/**
 * Remove stored previous versions of MU plugins (our own folder in uploads).
 */
$plugin_hub_uploads = wp_upload_dir( null, false );
if ( is_array( $plugin_hub_uploads ) && ! empty( $plugin_hub_uploads['basedir'] ) ) {
	$plugin_hub_backups = $plugin_hub_uploads['basedir'] . '/plugin-hub-mu-backups';
	if ( is_dir( $plugin_hub_backups ) ) {
		foreach ( (array) glob( $plugin_hub_backups . '/{*.prev.php,index.php,.htaccess}', GLOB_BRACE ) as $plugin_hub_item ) {
			wp_delete_file( $plugin_hub_item );
		}
		rmdir( $plugin_hub_backups ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir -- Remove our own backup folder.
	}
}
