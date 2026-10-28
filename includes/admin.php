<?php
/**
 * The admin-specific functionality of the plugin.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.0.0
 */

namespace PluginHub;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- The page_url() helper is shared with list tables and templates.

/**
 * Build a Plugin Hub admin page URL for the current (site or network) admin.
 *
 * @since 1.8.0
 * @param array $args Query arguments.
 * @return string
 */
function page_url( array $args = array() ) {
	$base = is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' );
	return add_query_arg( array_merge( array( 'page' => 'plugin-hub' ), $args ), $base );
}

/**
 * Admin class.
 *
 * Handles all admin-related functionality including menu registration,
 * script/style enqueuing, and admin page display.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * The API instance.
	 *
	 * @since  1.2.0
	 * @access private
	 * @var    API
	 */
	private $api;

	/**
	 * The MU plugin manager instance.
	 *
	 * @since  1.6.0
	 * @access private
	 * @var    MU_Plugins
	 */
	private $mu;

	/**
	 * Initialize the class.
	 *
	 * @since 1.2.0
	 * @param API|null        $api Optional API instance for dependency injection.
	 * @param MU_Plugins|null $mu  Optional MU plugin manager for dependency injection.
	 */
	public function __construct( $api = null, $mu = null ) {
		$this->api = $api ?? new API();
		$this->mu  = $mu ?? new MU_Plugins( $this->api );
	}

	/**
	 * Hook names of the Plugin Hub screen on a site and in the network admin.
	 *
	 * @since 1.8.0
	 * @var string[]
	 */
	const SCREEN_HOOKS = array( 'plugins_page_plugin-hub', 'plugins_page_plugin-hub-network' );

	/**
	 * Register every admin-side hook.
	 *
	 * @since 1.8.0
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'network_admin_menu', array( $this, 'add_network_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'handle_refresh_cache' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'wp_network_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		add_action( 'admin_post_plugin_hub_export_config', array( $this, 'handle_export_config' ) );
		add_action( 'admin_post_plugin_hub_export_activity', array( $this, 'handle_export_activity' ) );
		add_action( 'wp_ajax_save_hub_settings', array( $this, 'ajax_save_hub_settings' ) );
		add_action( 'wp_ajax_save_plugin_pin', array( $this, 'ajax_save_plugin_pin' ) );
		add_action( 'wp_ajax_delete_backups', array( $this, 'ajax_delete_backups' ) );
		add_action( 'wp_ajax_restore_backup', array( $this, 'ajax_restore_backup' ) );
		add_action( 'wp_ajax_delete_activity_entries', array( $this, 'ajax_delete_activity_entries' ) );
		add_action( 'wp_ajax_import_hub_config', array( $this, 'ajax_import_hub_config' ) );
		add_action( 'wp_ajax_send_test_notification', array( $this, 'ajax_send_test_notification' ) );
	}

	/**
	 * Whether the current user may use the Plugin Hub screen.
	 *
	 * @since 1.8.0
	 * @return bool
	 */
	private function can_manage() {
		return is_network_admin() ? current_user_can( 'manage_network_plugins' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Add the plugin admin page to the WordPress menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		$pending = $this->api->count_pending_updates();
		$title   = esc_html__( 'Plugin Hub', 'plugin-hub' );

		if ( $pending > 0 ) {
			$title .= ' <span class="update-plugins count-' . absint( $pending ) . '"><span class="plugin-count">' . esc_html( number_format_i18n( $pending ) ) . '</span></span>';
		}

		add_plugins_page(
			esc_html__( 'Plugin Hub', 'plugin-hub' ),
			$title,
			'manage_options',
			'plugin-hub',
			array( $this, 'display_admin_page' )
		);
	}

	/**
	 * Add the page to the multisite network admin.
	 *
	 * @since 1.8.0
	 */
	public function add_network_admin_menu() {
		add_submenu_page(
			'plugins.php',
			esc_html__( 'Plugin Hub', 'plugin-hub' ),
			esc_html__( 'Plugin Hub', 'plugin-hub' ),
			'manage_network_plugins',
			'plugin-hub',
			array( $this, 'display_admin_page' )
		);
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @since 1.0.0
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_styles( $hook ) {
		if ( ! in_array( $hook, self::SCREEN_HOOKS, true ) ) {
			return;
		}
		wp_enqueue_style( 'plugin-hub-style', PLUGIN_HUB_PLUGIN_URL . 'assets/css/style.css', array(), PLUGIN_HUB_VERSION );
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @since 1.0.0
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, self::SCREEN_HOOKS, true ) ) {
			return;
		}
		wp_enqueue_script( 'plugin-hub-script', PLUGIN_HUB_PLUGIN_URL . 'assets/js/script.js', array( 'jquery' ), PLUGIN_HUB_VERSION, true );
		$rate_limit = get_transient( 'plugin_hub_rate_limit' );

		wp_localize_script(
			'plugin-hub-script',
			'pluginHubAjax',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'plugin-hub-nonce' ),
				'rate_limit' => $rate_limit ? $rate_limit : array(),
				'has_token'  => '' !== get_github_token(),
				'is_network' => is_network_admin(),
				'i18n'       => $this->get_script_strings(),
			)
		);
	}

	/**
	 * Translatable strings for the admin script.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	private function get_script_strings() {
		return array(
			'installing'             => __( 'Installing...', 'plugin-hub' ),
			'installed'              => __( 'Installed', 'plugin-hub' ),
			'install_failed'         => __( 'Install Failed', 'plugin-hub' ),
			'updating'               => __( 'Updating...', 'plugin-hub' ),
			'updated'                => __( 'Updated', 'plugin-hub' ),
			'update_failed'          => __( 'Update Failed', 'plugin-hub' ),
			'activating'             => __( 'Activating...', 'plugin-hub' ),
			'activated'              => __( 'Activated', 'plugin-hub' ),
			'activation_failed'      => __( 'Activation Failed', 'plugin-hub' ),
			'deactivating'           => __( 'Deactivating...', 'plugin-hub' ),
			'deactivated'            => __( 'Deactivated', 'plugin-hub' ),
			'deactivation_failed'    => __( 'Deactivation Failed', 'plugin-hub' ),
			'deleting'               => __( 'Deleting...', 'plugin-hub' ),
			'deleted'                => __( 'Deleted', 'plugin-hub' ),
			'delete_failed'          => __( 'Delete Failed', 'plugin-hub' ),
			'saving'                 => __( 'Saving...', 'plugin-hub' ),
			'save_token'             => __( 'Save token', 'plugin-hub' ),
			'processing'             => __( 'Processing...', 'plugin-hub' ),
			'done'                   => __( 'Done', 'plugin-hub' ),
			'failed'                 => __( 'Failed', 'plugin-hub' ),
			'error_occurred'         => __( 'An error occurred. Please try again.', 'plugin-hub' ),
			'delete_confirm'         => __( 'Are you sure you want to delete this plugin?', 'plugin-hub' ),
			'bulk_delete_confirm'    => __( 'Are you sure you want to delete the selected plugins?', 'plugin-hub' ),
			'select_action_plugin'   => __( 'Please select an action and at least one item.', 'plugin-hub' ),
			'verify_error'           => __( 'Failed to verify update. Please refresh the page and check the plugin version.', 'plugin-hub' ),
			'loading_changelog'      => __( 'Loading changelog…', 'plugin-hub' ),
			'changelog'              => __( 'Changelog', 'plugin-hub' ),
			'no_changelog'           => __( 'No changelog available for this version.', 'plugin-hub' ),
			'whats_new'              => __( "What's new?", 'plugin-hub' ),
			'versions'               => __( 'Versions', 'plugin-hub' ),
			'loading_versions'       => __( 'Loading…', 'plugin-hub' ),
			/* translators: %s: Version number. */
			'rollback_confirm'       => __( 'Roll back to version %s?', 'plugin-hub' ),
			'current_version'        => __( 'current', 'plugin-hub' ),
			'rolling_back'           => __( 'Rolling back…', 'plugin-hub' ),
			'rolled_back'            => __( 'Rolled back', 'plugin-hub' ),
			'rollback_failed'        => __( 'Rollback Failed', 'plugin-hub' ),
			'clear_log_confirm'      => __( 'Clear the entire activity log?', 'plugin-hub' ),
			'clearing'               => __( 'Clearing…', 'plugin-hub' ),
			'clear_log'              => __( 'Clear Log', 'plugin-hub' ),
			'mu_installing'          => __( 'Installing...', 'plugin-hub' ),
			'mu_rolling_back'        => __( 'Restoring...', 'plugin-hub' ),
			'mu_rollback_confirm'    => __( 'Restore the previous version of this MU plugin? Automatic updates will be switched off for it.', 'plugin-hub' ),
			'mu_restored'            => __( 'Restored', 'plugin-hub' ),
			'mu_update_all_confirm'  => __( 'Update all MU plugins that have a newer version? The previous version of each replaced file is kept so you can roll it back.', 'plugin-hub' ),
			'mu_install_confirm'     => __( 'Install this MU plugin? MU plugins load automatically on every request.', 'plugin-hub' ),
			'update_to'              => __( 'Update to', 'plugin-hub' ),
			'update_now'             => __( 'Update now', 'plugin-hub' ),
			'cancel'                 => __( 'Cancel', 'plugin-hub' ),
			/* translators: %s: Plugin name. */
			'update_modal_title'     => __( 'Update %s', 'plugin-hub' ),
			/* translators: %s: Comma-separated plugin names. */
			'dependents_confirm'     => __( 'These active plugins depend on it: %s. Continue anyway?', 'plugin-hub' ),
			'pin_label'              => __( 'Pin', 'plugin-hub' ),
			'pin_none'               => __( 'No pin (follow every update)', 'plugin-hub' ),
			/* translators: %s: Version number. */
			'pin_hold'               => __( 'Hold at %s (never update)', 'plugin-hub' ),
			/* translators: %s: Version constraint such as 1.x. */
			'pin_stay'               => __( 'Stay on %s', 'plugin-hub' ),
			'save'                   => __( 'Save', 'plugin-hub' ),
			'pin_saved'              => __( 'Pin saved.', 'plugin-hub' ),
			'settings_saved'         => __( 'Settings saved.', 'plugin-hub' ),
			'backup_restore_confirm' => __( 'Restore this backup? The current plugin files will be replaced.', 'plugin-hub' ),
			'backup_delete_confirm'  => __( 'Delete the selected backups?', 'plugin-hub' ),
			'log_delete_confirm'     => __( 'Delete the selected activity entries?', 'plugin-hub' ),
			'restoring'              => __( 'Restoring…', 'plugin-hub' ),
			'sending'                => __( 'Sending…', 'plugin-hub' ),
			'test_sent'              => __( 'Test sent.', 'plugin-hub' ),
			'import_empty'           => __( 'Choose a configuration file first.', 'plugin-hub' ),
			'import_missing'         => __( 'Some plugins from this file are not installed yet.', 'plugin-hub' ),
			'install_missing'        => __( 'Install missing', 'plugin-hub' ),
			/* translators: 1: Processed items, 2: Total items. */
			'bulk_status'            => __( 'Processing %1$s of %2$s', 'plugin-hub' ),
			/* translators: 1: Completed items, 2: Total items. */
			'bulk_result'            => __( '%1$s of %2$s completed', 'plugin-hub' ),
			'skipped'                => __( 'skipped', 'plugin-hub' ),
		);
	}

	/**
	 * Handle cache refresh action.
	 *
	 * @since 1.0.0
	 */
	public function handle_refresh_cache() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'refresh_cache' === $action ) {
			check_admin_referer( 'plugin_hub_refresh_cache' );

			if ( ! $this->can_manage() ) {
				wp_die( esc_html__( 'You do not have permission to refresh the cache.', 'plugin-hub' ) );
			}

			$this->api->refresh_csv_cache();
			$this->mu->refresh_catalog();
			wp_safe_redirect( page_url( array( 'cache_refreshed' => 1 ) ) );
			exit;
		}
	}

	/**
	 * Display the plugin admin page.
	 *
	 * @since 1.0.0
	 */
	public function display_admin_page() {
		if ( ! $this->can_manage() ) {
			return;
		}

		// The flag only controls a success notice and does not change state.
		if ( isset( $_GET['cache_refreshed'] ) && '1' === $_GET['cache_refreshed'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				'plugin_hub_messages',
				'plugin_hub_message',
				esc_html__( 'Plugin list refreshed successfully.', 'plugin-hub' ),
				'updated'
			);
		}

		if ( isset( $_POST['plugin_hub_supporters_visibility'] ) && is_string( $_POST['plugin_hub_supporters_visibility'] ) ) {
			check_admin_referer( 'plugin_hub_supporters_visibility' );
			$hidden = 'hide' === sanitize_key( wp_unslash( $_POST['plugin_hub_supporters_visibility'] ) );
			update_user_meta( get_current_user_id(), 'plugin_hub_hide_supporters', $hidden ? '1' : '0' );
		}

		require_once PLUGIN_HUB_PLUGIN_DIR . 'includes/list-tables.php';

		$api = $this->api;
		$mu  = $this->mu;

		// These are read-only view selectors and do not require a nonce.
		$filter       = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plugin_views = array( 'all', 'active', 'inactive', 'installed', 'available', 'update', 'beta', 'pinned' );
		$other_tabs   = array( 'mu', 'backups', 'activity', 'settings' );
		if ( ! in_array( $filter, array_merge( $plugin_views, $other_tabs ), true ) ) {
			$filter = 'all';
		}
		$tab = in_array( $filter, $other_tabs, true ) ? $filter : 'plugins';

		$table        = null;
		$mu_data      = array();
		$mu_view      = 'installed';
		$backup_usage = array();
		$settings     = Settings::all();

		switch ( $tab ) {
			case 'plugins':
				$table = new Plugins_List_Table( $api, $filter );
				break;
			case 'mu':
				$mu_data = $mu->get_view_data();
				update_option( 'plugin_hub_mu_update_count', (int) $mu_data['update_count'], false );
				$mu_view = isset( $_GET['mu_view'] ) && 'available' === sanitize_key( wp_unslash( $_GET['mu_view'] ) ) ? 'available' : 'installed'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$table   = 'available' === $mu_view
					? new MU_Available_List_Table( $mu_data )
					: new MU_Installed_List_Table( $mu_data, isset( $_GET['mu_filter'] ) && 'update' === sanitize_key( wp_unslash( $_GET['mu_filter'] ) ) ? 'update' : 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				break;
			case 'activity':
				$table = new Activity_List_Table( $api );
				break;
			case 'backups':
				$table        = new Backups_List_Table();
				$backup_usage = Backups::usage();
				break;
		}

		if ( $table ) {
			$table->prepare_items();
		}

		$github_token_from_config = is_github_token_managed_by_config();
		$has_github_token         = '' !== get_github_token();

		$supporters_feed = array(
			'schemaVersion' => 1,
			'maxPositions'  => 3,
			'supporters'    => get_option( 'plugin_hub_supporters', array() ),
		);
		$supporters      = Sponsorship::active( Sponsorship::validate( $supporters_feed ) ?? array(), time() );
		$hide_supporters = '1' === get_user_meta( get_current_user_id(), 'plugin_hub_hide_supporters', true ) || ( defined( 'PLUGIN_HUB_HIDE_SUPPORTERS' ) && PLUGIN_HUB_HIDE_SUPPORTERS );

		include PLUGIN_HUB_PLUGIN_DIR . 'includes/admin-display.php';
	}

	/**
	 * Save GitHub token via AJAX.
	 *
	 * @since 1.3.0
	 */
	public function ajax_save_github_token() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to change this setting.', 'plugin-hub' ) );
		}

		if ( is_github_token_managed_by_config() ) {
			wp_send_json_error( esc_html__( 'The GitHub token is managed in wp-config.php and cannot be changed here.', 'plugin-hub' ) );
		}

		$clear_token = isset( $_POST['clear'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['clear'] ) );
		$token       = isset( $_POST['token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';

		if ( $clear_token ) {
			delete_option( 'plugin_hub_github_token' );
		} elseif ( empty( $token ) ) {
			wp_send_json_error( esc_html__( 'Enter a token or use Remove Token.', 'plugin-hub' ) );
		} else {
			update_option( 'plugin_hub_github_token', $token, false );
		}

		// Clear rate limit transient so it refreshes with new auth state.
		delete_transient( 'plugin_hub_rate_limit' );

		if ( $clear_token ) {
			wp_send_json_success( esc_html__( 'Token removed.', 'plugin-hub' ) );
		}

		wp_send_json_success( esc_html__( 'Token saved successfully.', 'plugin-hub' ) );
	}

	/**
	 * Toggle beta plugins visibility via AJAX.
	 *
	 * @since 1.0.0
	 */
	public function ajax_toggle_beta_plugins() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to change this setting.', 'plugin-hub' ) );
		}

		$show_beta = isset( $_POST['show_beta'] ) ? filter_var( wp_unslash( $_POST['show_beta'] ), FILTER_VALIDATE_BOOLEAN ) : false;
		update_option( 'plugin_hub_show_beta', $show_beta );

		wp_send_json_success( esc_html__( 'Setting updated successfully.', 'plugin-hub' ) );
	}

	/**
	 * Toggle auto-update for a single plugin via AJAX.
	 *
	 * @since 1.3.0
	 */
	public function ajax_save_autoupdate_setting() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to change this setting.', 'plugin-hub' ) );
		}

		$repo_name  = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$is_enabled = isset( $_POST['enabled'] ) ? filter_var( wp_unslash( $_POST['enabled'] ), FILTER_VALIDATE_BOOLEAN ) : false;

		if ( empty( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$autoupdate_plugins = get_option( 'plugin_hub_autoupdate_plugins', array() );

		if ( $is_enabled ) {
			$autoupdate_plugins[] = $repo_name;
			$autoupdate_plugins   = array_unique( $autoupdate_plugins );
		} else {
			$autoupdate_plugins = array_values( array_diff( $autoupdate_plugins, array( $repo_name ) ) );
		}

		update_option( 'plugin_hub_autoupdate_plugins', $autoupdate_plugins );
		wp_send_json_success( esc_html__( 'Setting updated.', 'plugin-hub' ) );
	}

	/**
	 * Clear the activity log via AJAX.
	 *
	 * @since 1.3.0
	 */
	public function ajax_clear_activity_log() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission.', 'plugin-hub' ) );
		}

		update_option( 'plugin_hub_activity_log', array(), false );
		wp_send_json_success( esc_html__( 'Activity log cleared.', 'plugin-hub' ) );
	}

	/**
	 * Verify the AJAX nonce and a capability for the new 1.8 handlers.
	 *
	 * @since 1.8.0
	 * @param string $capability Required capability.
	 */
	private function ajax_guard( $capability ) {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to do this.', 'plugin-hub' ) );
		}
	}

	/**
	 * Read a list of identifiers from POST data.
	 *
	 * @since 1.8.0
	 * @param string $key POST key.
	 * @return string[]
	 */
	private function posted_ids( $key ) {
		// Nonce verified in ajax_guard().
		$raw = isset( $_POST[ $key ] ) ? map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		return array_values( array_filter( array_map( 'strval', (array) $raw ) ) );
	}

	/**
	 * Save the Plugin Hub settings form.
	 *
	 * @since 1.8.0
	 */
	public function ajax_save_hub_settings() {
		$this->ajax_guard( 'manage_options' );

		// Nonce verified in ajax_guard(); values are sanitized and clamped by Settings::sanitize().
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? map_deep( wp_unslash( $_POST['settings'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		// An empty set of checkboxes is not submitted by browsers.
		if ( isset( $input['window_enabled'] ) && ! isset( $input['window_days'] ) ) {
			$input['window_days'] = array();
		}

		Settings::save( $input );
		wp_send_json_success( esc_html__( 'Settings saved.', 'plugin-hub' ) );
	}

	/**
	 * Save or clear the version pin of a plugin.
	 *
	 * @since 1.8.0
	 */
	public function ajax_save_plugin_pin() {
		$this->ajax_guard( 'update_plugins' );

		$repo = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pin  = isset( $_POST['pin'] ) ? sanitize_text_field( wp_unslash( $_POST['pin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $repo || ! preg_match( '/^[a-zA-Z0-9_-]+$/', $repo ) || ! Policy::is_valid_pin( $pin ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$result = $this->api->set_pin( $repo, $pin );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( esc_html__( 'Pin saved.', 'plugin-hub' ) );
	}

	/**
	 * Delete local plugin backups.
	 *
	 * @since 1.8.0
	 */
	public function ajax_delete_backups() {
		$this->ajax_guard( 'manage_options' );

		$deleted = Backups::delete( $this->posted_ids( 'ids' ) );
		/* translators: %d: Number of backups. */
		wp_send_json_success( sprintf( esc_html__( '%d backup(s) deleted.', 'plugin-hub' ), $deleted ) );
	}

	/**
	 * Restore a plugin from a local backup.
	 *
	 * @since 1.8.0
	 */
	public function ajax_restore_backup() {
		$this->ajax_guard( 'update_plugins' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $id ) {
			wp_send_json_error( esc_html__( 'Invalid backup.', 'plugin-hub' ) );
		}

		$backup = null;
		foreach ( Backups::all() as $row ) {
			if ( $row['id'] === $id ) {
				$backup = $row;
				break;
			}
		}
		if ( ! $backup ) {
			wp_send_json_error( esc_html__( 'The backup no longer exists.', 'plugin-hub' ) );
		}

		$result = Backups::restore( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$this->api->log_activity( 'backup_restore', $backup['repo'], $backup['version'] );
		/* translators: 1: Plugin name, 2: Version number. */
		wp_send_json_success( sprintf( esc_html__( '%1$s restored to version %2$s.', 'plugin-hub' ), $backup['repo'], $backup['version'] ) );
	}

	/**
	 * Delete selected activity log entries.
	 *
	 * @since 1.8.0
	 */
	public function ajax_delete_activity_entries() {
		$this->ajax_guard( 'manage_options' );

		$deleted = $this->api->delete_activity_entries( $this->posted_ids( 'ids' ) );
		/* translators: %d: Number of log entries. */
		wp_send_json_success( sprintf( esc_html__( '%d entries deleted.', 'plugin-hub' ), $deleted ) );
	}

	/**
	 * Import a configuration file.
	 *
	 * @since 1.8.0
	 */
	public function ajax_import_hub_config() {
		$this->ajax_guard( 'manage_options' );

		// The JSON is parsed and validated by Config::validate().
		$json = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data = Config::validate( is_string( $json ) ? $json : '' );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( $data->get_error_message() );
		}

		// Config::apply() records the import in the activity log.
		$result = Config::apply( $data, $this->api, $this->mu );

		$missing   = isset( $result['missing'] ) && is_array( $result['missing'] ) ? array_values( $result['missing'] ) : array();
		$mu_missing = isset( $result['mu_missing'] ) && is_array( $result['mu_missing'] ) ? array_values( $result['mu_missing'] ) : array();

		wp_send_json_success(
			array(
				'message'    => esc_html__( 'Configuration imported.', 'plugin-hub' ),
				'missing'    => $missing,
				'mu_missing' => $mu_missing,
			)
		);
	}

	/**
	 * Send a test notification through the configured channels.
	 *
	 * @since 1.8.0
	 */
	public function ajax_send_test_notification() {
		$this->ajax_guard( 'manage_options' );

		$result  = Notifier::send_test();
		$parts   = array();
		$success = false;

		$parts[] = $result['mail'] ? esc_html__( 'Email sent.', 'plugin-hub' ) : esc_html__( 'Email failed.', 'plugin-hub' );
		if ( $result['mail'] ) {
			$success = true;
		}
		if ( null !== $result['webhook'] ) {
			$parts[] = $result['webhook'] ? esc_html__( 'Webhook delivered.', 'plugin-hub' ) : esc_html__( 'Webhook failed.', 'plugin-hub' );
			if ( $result['webhook'] ) {
				$success = true;
			}
		}

		if ( $success ) {
			wp_send_json_success( implode( ' ', $parts ) );
		}
		wp_send_json_error( implode( ' ', $parts ) );
	}

	/**
	 * Download the portable configuration file.
	 *
	 * @since 1.8.0
	 */
	public function handle_export_config() {
		check_admin_referer( 'plugin_hub_export_config' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the configuration.', 'plugin-hub' ) );
		}

		$json = Config::to_json( Config::export( $this->api, $this->mu ) );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="plugin-hub-config-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
		exit;
	}

	/**
	 * Download the (filtered) activity log as CSV.
	 *
	 * @since 1.8.0
	 */
	public function handle_export_activity() {
		check_admin_referer( 'plugin_hub_export_activity' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the activity log.', 'plugin-hub' ) );
		}

		$read    = static function ( $key ) {
			return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above.
		};
		$filters = array(
			'action' => sanitize_key( $read( 'log_action' ) ),
			'user'   => $read( 'log_user' ),
			'search' => $read( 's' ),
			'from'   => $read( 'log_from' ),
			'to'     => $read( 'log_to' ),
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="plugin-hub-activity-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming download.
		fputcsv( $out, array( 'time', 'action', 'plugin', 'version', 'user' ) );
		foreach ( $this->api->get_activity_log( $filters ) as $entry ) {
			$cells = array(
				gmdate( 'Y-m-d H:i:s', (int) $entry['time'] ),
				$entry['action'],
				$entry['plugin'],
				$entry['version'],
				$entry['user'],
			);
			// Neutralize spreadsheet formula injection.
			$cells = array_map(
				static function ( $cell ) {
					$cell = (string) $cell;
					return '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ? "'" . $cell : $cell;
				},
				$cells
			);
			fputcsv( $out, $cells );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming download.
		exit;
	}

	/**
	 * Register the dashboard widget.
	 *
	 * @since 1.8.0
	 */
	public function add_dashboard_widget() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'plugin_hub_dashboard', esc_html__( 'Plugin Hub', 'plugin-hub' ), array( $this, 'render_dashboard_widget' ) );
	}

	/**
	 * Render the dashboard widget.
	 *
	 * @since 1.8.0
	 */
	public function render_dashboard_widget() {
		require_once PLUGIN_HUB_PLUGIN_DIR . 'includes/list-tables.php';

		$managed = 0;
		$updates = array();

		foreach ( $this->api->get_cached_repos() as $repo ) {
			if ( ! $this->api->is_plugin_installed( $repo['name'] ) ) {
				continue;
			}
			++$managed;
			$installed = $this->api->get_installed_plugin_version( $repo['name'] );
			if ( $this->api->is_update_allowed( $repo, $installed ) ) {
				$updates[] = array(
					'name' => $repo['display_name'],
					'from' => $installed,
					'to'   => $repo['version'],
				);
			}
		}

		$mu_updates = (int) get_option( 'plugin_hub_mu_update_count', 0 );
		$recent     = array_slice( $this->api->get_activity_log(), 0, 5 );

		echo '<p>';
		echo esc_html(
			sprintf(
				/* translators: %d: Number of installed plugins. */
				_n( '%d Open WP Club plugin installed.', '%d Open WP Club plugins installed.', $managed, 'plugin-hub' ),
				$managed
			)
		);
		echo '</p>';

		if ( empty( $updates ) && 0 === $mu_updates ) {
			echo '<p>' . esc_html__( 'Everything is up to date.', 'plugin-hub' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Updates available', 'plugin-hub' ) . '</h3><ul>';
			foreach ( $updates as $update ) {
				echo '<li>' . esc_html( $update['name'] . ': ' . $update['from'] . ' → ' . $update['to'] ) . '</li>';
			}
			if ( $mu_updates > 0 ) {
				/* translators: %d: Number of MU plugin updates. */
				echo '<li>' . esc_html( sprintf( _n( '%d MU plugin update', '%d MU plugin updates', $mu_updates, 'plugin-hub' ), $mu_updates ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $recent ) {
			$labels = Activity_List_Table::action_labels();
			echo '<h3>' . esc_html__( 'Recent activity', 'plugin-hub' ) . '</h3><ul>';
			foreach ( $recent as $entry ) {
				$label = $labels[ $entry['action'] ] ?? $entry['action'];
				echo '<li>' . esc_html( wp_date( get_option( 'date_format' ), (int) $entry['time'] ) . ' — ' . $label . ': ' . $entry['plugin'] . ' ' . $entry['version'] ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<p><a class="button button-primary" href="' . esc_url( page_url( array( 'filter' => $updates ? 'update' : 'all' ) ) ) . '">' . esc_html__( 'Open Plugin Hub', 'plugin-hub' ) . '</a></p>';
	}
}
