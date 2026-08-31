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
	 * Initialize the class.
	 *
	 * @since 1.2.0
	 * @param API|null $api Optional API instance for dependency injection.
	 */
	public function __construct( $api = null ) {
		$this->api = $api ?? new API();
	}

	/**
	 * Add the plugin admin page to the WordPress menu.
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_plugins_page(
			esc_html__( 'Plugin Hub', 'plugin-hub' ),
			esc_html__( 'Plugin Hub', 'plugin-hub' ),
			'manage_options',
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
		if ( 'plugins_page_plugin-hub' !== $hook ) {
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
		if ( 'plugins_page_plugin-hub' !== $hook ) {
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
				'i18n'       => array(
					'installing'           => __( 'Installing...', 'plugin-hub' ),
					'installed'            => __( 'Installed', 'plugin-hub' ),
					'install_failed'       => __( 'Install Failed', 'plugin-hub' ),
					'updating'             => __( 'Updating...', 'plugin-hub' ),
					'updated'              => __( 'Updated', 'plugin-hub' ),
					'update_failed'        => __( 'Update Failed', 'plugin-hub' ),
					'activating'           => __( 'Activating...', 'plugin-hub' ),
					'activated'            => __( 'Activated', 'plugin-hub' ),
					'activation_failed'    => __( 'Activation Failed', 'plugin-hub' ),
					'deactivating'         => __( 'Deactivating...', 'plugin-hub' ),
					'deactivated'          => __( 'Deactivated', 'plugin-hub' ),
					'deactivation_failed'  => __( 'Deactivation Failed', 'plugin-hub' ),
					'deleting'             => __( 'Deleting...', 'plugin-hub' ),
					'deleted'              => __( 'Deleted', 'plugin-hub' ),
					'delete_failed'        => __( 'Delete Failed', 'plugin-hub' ),
					'saving'               => __( 'Saving...', 'plugin-hub' ),
					'save_token'           => __( 'Save token', 'plugin-hub' ),
					'processing'           => __( 'Processing...', 'plugin-hub' ),
					'done'                 => __( 'Done', 'plugin-hub' ),
					'failed'               => __( 'Failed', 'plugin-hub' ),
					'error_occurred'       => __( 'An error occurred. Please try again.', 'plugin-hub' ),
					'delete_confirm'       => __( 'Are you sure you want to delete this plugin?', 'plugin-hub' ),
					'bulk_delete_confirm'  => __( 'Are you sure you want to delete the selected plugins?', 'plugin-hub' ),
					'select_action_plugin' => __( 'Please select an action and at least one plugin.', 'plugin-hub' ),
					'no_inactive_selected' => __( 'No inactive plugins selected for deletion. Active plugins cannot be deleted.', 'plugin-hub' ),
					'verify_error'         => __( 'Failed to verify update. Please refresh the page and check the plugin version.', 'plugin-hub' ),
					'loading_changelog'    => __( 'Loading changelog…', 'plugin-hub' ),
					'changelog'            => __( 'Changelog', 'plugin-hub' ),
					'no_changelog'         => __( 'No changelog available for this version.', 'plugin-hub' ),
					'whats_new'            => __( "What's new?", 'plugin-hub' ),
					'versions'             => __( 'Versions', 'plugin-hub' ),
					'loading_versions'     => __( 'Loading…', 'plugin-hub' ),
					/* translators: %s: Version number. */
					'rollback_confirm'     => __( 'Roll back to version %s?', 'plugin-hub' ),
					'current_version'      => __( 'current', 'plugin-hub' ),
					'rolling_back'         => __( 'Rolling back…', 'plugin-hub' ),
					'rolled_back'          => __( 'Rolled back', 'plugin-hub' ),
					'rollback_failed'      => __( 'Rollback Failed', 'plugin-hub' ),
					'clear_log_confirm'    => __( 'Clear the entire activity log?', 'plugin-hub' ),
					'clearing'             => __( 'Clearing…', 'plugin-hub' ),
					'clear_log'            => __( 'Clear Log', 'plugin-hub' ),
				),
			)
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

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to refresh the cache.', 'plugin-hub' ) );
			}

			$this->api->refresh_csv_cache();
			wp_safe_redirect( admin_url( 'plugins.php?page=plugin-hub&cache_refreshed=1' ) );
			exit;
		}
	}

	/**
	 * Display the plugin admin page.
	 *
	 * @since 1.0.0
	 */
	public function display_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
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

		$api   = $this->api;
		$repos = $api->get_org_repos();

		// This is a read-only view filter and does not require a nonce.
		$filter          = isset( $_GET['filter'] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$allowed_filters = array( 'all', 'active', 'inactive', 'update', 'beta', 'activity' );
		$filter          = in_array( $filter, $allowed_filters, true ) ? $filter : 'all';
		$counts          = $this->get_plugin_counts( $repos );

		// This is a read-only search query and does not require a nonce.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$per_page       = 25;
		$filtered_repos = 'activity' === $filter ? array() : $this->get_filtered_sorted_repos( $repos, $filter, $search );
		$total_items    = count( $filtered_repos );
		$total_pages    = max( 1, (int) ceil( $total_items / $per_page ) );

		// This is a read-only pagination cursor and does not require a nonce.
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = min( max( $paged, 1 ), $total_pages );

		$repos = array_slice( $filtered_repos, ( $paged - 1 ) * $per_page, $per_page );

		$autoupdate_plugins       = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$activity_log             = $api->get_activity_log();
		$github_token_from_config = is_github_token_managed_by_config();
		$has_github_token         = '' !== get_github_token();

		include PLUGIN_HUB_PLUGIN_DIR . 'includes/admin-display.php';
	}

	/**
	 * Get plugin counts for filter tabs.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  array $repos Array of repository data.
	 * @return array        Array of plugin counts by status.
	 */
	private function get_plugin_counts( $repos ) {
		$counts = array(
			'all'      => 0,
			'active'   => 0,
			'inactive' => 0,
			'update'   => 0,
			'beta'     => 0,
		);

		$show_beta = get_option( 'plugin_hub_show_beta', false );

		foreach ( $repos as $repo ) {
			$is_installed      = $this->api->is_plugin_installed( $repo['name'] );
			$is_active         = $this->api->is_plugin_active( $repo['name'] );
			$installed_version = $this->api->get_installed_plugin_version( $repo['name'] );
			$update_available  = $this->api->is_update_available( $repo, $installed_version );
			$is_beta           = ! empty( $repo['available'] ) && version_compare( $repo['version'], '1.0.0', '<' );

			if ( ! $show_beta && $is_beta ) {
				continue;
			}

			++$counts['all'];

			if ( $is_active ) {
				++$counts['active'];
			} elseif ( $is_installed ) {
				++$counts['inactive'];
			}

			if ( $update_available ) {
				++$counts['update'];
			}

			if ( $is_beta ) {
				++$counts['beta'];
			}
		}

		return $counts;
	}

	/**
	 * Filter repositories by the active tab and search term, then sort alphabetically.
	 *
	 * @since  1.4.2
	 * @access private
	 * @param  array  $repos  Array of repository data.
	 * @param  string $filter Active filter/tab.
	 * @param  string $search Search term to match against name/description.
	 * @return array          Filtered, alphabetically sorted repository data.
	 */
	private function get_filtered_sorted_repos( $repos, $filter, $search = '' ) {
		$show_beta   = get_option( 'plugin_hub_show_beta', false );
		$search_term = strtolower( trim( $search ) );
		$filtered    = array();

		foreach ( $repos as $repo ) {
			$is_installed      = $this->api->is_plugin_installed( $repo['name'] );
			$is_active         = $this->api->is_plugin_active( $repo['name'] );
			$installed_version = $this->api->get_installed_plugin_version( $repo['name'] );
			$update_available  = $this->api->is_update_available( $repo, $installed_version );
			$is_beta           = ! empty( $repo['available'] ) && version_compare( $repo['version'], '1.0.0', '<' );

			if (
				( 'active' === $filter && ! $is_active ) ||
				( 'inactive' === $filter && ( ! $is_installed || $is_active ) ) ||
				( 'update' === $filter && ! $update_available ) ||
				( 'beta' === $filter && ! $is_beta ) ||
				( ! $show_beta && $is_beta )
			) {
				continue;
			}

			if ( '' !== $search_term
				&& false === stripos( $repo['display_name'], $search_term )
				&& false === stripos( $repo['description'], $search_term )
			) {
				continue;
			}

			$filtered[] = $repo;
		}

		usort(
			$filtered,
			static function ( $a, $b ) {
				return strcasecmp( $a['display_name'], $b['display_name'] );
			}
		);

		return $filtered;
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
}
