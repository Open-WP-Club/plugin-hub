<?php
/**
 * The API functionality of the plugin.
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
 * API class.
 *
 * Handles all GitHub API interactions, plugin installations,
 * updates, and WordPress plugin management.
 *
 * @since 1.0.0
 */
class API {

	/**
	 * The GitHub organization name.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $organization = 'Open-WP-Club';

	/**
	 * The CSV URL for plugin data.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $csv_url = 'https://raw.githubusercontent.com/Open-WP-Club/.github/main/plugins.csv';

	/**
	 * GitHub plugins array.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    array
	 */
	private $github_plugins = array();

	/**
	 * Cache key for transients.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $cache_key = 'plugin_hub_csv_cache';

	/**
	 * Cache expiration time in seconds.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    int
	 */
	private $cache_expiration = DAY_IN_SECONDS;

	/**
	 * Log a debug message if WP_DEBUG is enabled.
	 *
	 * @since  1.2.0
	 * @access private
	 * @param  string $message The message to log.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Plugin Hub: ' . $message );
		}
	}

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->load_github_plugins();
	}

	/**
	 * Load GitHub plugins from database.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function load_github_plugins() {
		$this->github_plugins = get_option( 'plugin_hub_github_plugins', array() );
	}

	/**
	 * Get organization repositories from CSV.
	 *
	 * @since  1.0.0
	 * @return array Array of repository data.
	 */
	public function get_org_repos() {
		$cached_data = get_transient( $this->cache_key );
		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$response = wp_remote_get( $this->csv_url );
		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching CSV file: ' . $response->get_error_message() );
			return array();
		}

		$csv_content = wp_remote_retrieve_body( $response );
		$repos       = $this->parse_csv_content( $csv_content );

		set_transient( $this->cache_key, $repos, $this->cache_expiration );

		return $repos;
	}

	/**
	 * Parse CSV content into repository array.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $csv_content The CSV content to parse.
	 * @return array               Array of repository data.
	 */
	private function parse_csv_content( $csv_content ) {
		$lines = explode( "\n", trim( $csv_content ) );
		$repos = array();

		// Remove the header row.
		array_shift( $lines );

		foreach ( $lines as $line ) {
			$data = str_getcsv( $line );
			if ( count( $data ) >= 5 ) {
				$repos[] = array(
					'name'         => trim( $data[0] ),
					'display_name' => trim( $data[1] ),
					'description'  => trim( $data[2] ),
					'version'      => trim( $data[3] ),
					'repo_url'     => trim( $data[4] ),
				);
			}
		}

		return $repos;
	}

	/**
	 * Refresh the CSV cache.
	 *
	 * @since  1.0.0
	 * @return array Array of repository data.
	 */
	public function refresh_csv_cache() {
		delete_transient( $this->cache_key );
		return $this->get_org_repos();
	}

	/**
	 * Check if a plugin is installed.
	 *
	 * @since  1.0.0
	 * @param  string $plugin_name The plugin name/slug.
	 * @return bool                True if installed, false otherwise.
	 */
	public function is_plugin_installed( $plugin_name ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( 0 === strpos( $plugin_file, $plugin_name . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a plugin is active.
	 *
	 * @since  1.0.0
	 * @param  string $plugin_name The plugin name/slug.
	 * @return bool                True if active, false otherwise.
	 */
	public function is_plugin_active( $plugin_name ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_file = $this->get_plugin_file( $plugin_name );
		return $plugin_file && is_plugin_active( $plugin_file );
	}

	/**
	 * Check if a plugin is disabled via Plugin Hub.
	 *
	 * @since  1.0.0
	 * @param  string $plugin_name The plugin name/slug.
	 * @return bool                True if disabled, false otherwise.
	 */
	public function is_plugin_disabled( $plugin_name ) {
		return get_option( "plugin_hub_disabled_{$plugin_name}", false );
	}

	/**
	 * Get the plugin file path.
	 *
	 * @since  1.0.0
	 * @param  string      $plugin_name The plugin name/slug.
	 * @return string|bool              Plugin file path or false if not found.
	 */
	public function get_plugin_file( $plugin_name ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			if ( 0 === strpos( $plugin_file, $plugin_name . '/' ) ) {
				return $plugin_file;
			}
		}
		return false;
	}

	/**
	 * Check if an update is available for a plugin.
	 *
	 * @since  1.0.0
	 * @param  array  $repo              Repository data.
	 * @param  string $installed_version Currently installed version.
	 * @return bool                      True if update available, false otherwise.
	 */
	public function is_update_available( $repo, $installed_version ) {
		return version_compare( $repo['version'], $installed_version, '>' );
	}

	/**
	 * Get the installed plugin version.
	 *
	 * @since  1.0.0
	 * @param  string $plugin_name The plugin name/slug.
	 * @return string              Version number or 'Not Installed'.
	 */
	public function get_installed_plugin_version( $plugin_name ) {
		$plugin_file = $this->get_plugin_file( $plugin_name );
		if ( $plugin_file ) {
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
			return $plugin_data['Version'];
		}
		return 'Not Installed';
	}

	/**
	 * Check for plugin updates.
	 *
	 * @since  1.0.0
	 * @param  object $transient The update_plugins transient.
	 * @return object            Modified transient.
	 */
	public function check_for_plugin_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$repos = $this->get_org_repos();

		foreach ( $repos as $repo ) {
			$plugin_file = $this->get_plugin_file( $repo['name'] );
			if ( ! $plugin_file ) {
				continue;
			}

			$wp_version  = $transient->checked[ $plugin_file ];
			$csv_version = $repo['version'];

			if ( version_compare( $csv_version, $wp_version, '>' ) ) {
				$obj              = new \stdClass();
				$obj->slug        = $plugin_file;
				$obj->new_version = $csv_version;
				$obj->url         = $repo['repo_url'];
				$obj->package     = $this->get_github_release_download_url( $repo['name'], $csv_version );

				$transient->response[ $plugin_file ] = $obj;
			}
		}

		return $transient;
	}

	/**
	 * Get GitHub release download URL.
	 *
	 * @since  1.0.0
	 * @param  string      $repo_name Repository name.
	 * @param  string      $version   Version number.
	 * @return string|bool            Download URL or false on failure.
	 */
	public function get_github_release_download_url( $repo_name, $version ) {
		$api_url = "https://api.github.com/repos/{$this->organization}/{$repo_name}/releases/tags/v{$version}";

		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/Plugin-Hub',
			),
		);

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching GitHub release: ' . $response->get_error_message() );
			return false;
		}

		$body    = wp_remote_retrieve_body( $response );
		$release = json_decode( $body, true );

		if ( isset( $release['zipball_url'] ) ) {
			return $release['zipball_url'];
		}

		$this->log( "Unable to find zipball_url in GitHub API response for {$repo_name} v{$version}" );
		$this->log( 'GitHub API response: ' . wp_json_encode( $release ) );
		return false;
	}

	/**
	 * AJAX handler for installing a GitHub plugin.
	 *
	 * @since 1.0.0
	 */
	public function ajax_install_github_plugin() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to install plugins.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$version   = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

		if ( empty( $repo_name ) || empty( $version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$download_url = $this->get_github_release_download_url( $repo_name, $version );

		if ( ! $download_url ) {
			/* translators: %1$s: Repository name, %2$s: Version number */
			$error_message = sprintf( esc_html__( 'Unable to fetch download URL for %1$s v%2$s. Please check the error log for more details.', 'plugin-hub' ), $repo_name, $version );
			wp_send_json_error( $error_message );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$skin      = new \WP_Ajax_Upgrader_Skin();
		$upgrader  = new \Plugin_Upgrader( $skin );
		$installed = $upgrader->install( $download_url );

		if ( is_wp_error( $installed ) ) {
			wp_send_json_error( $installed->get_error_message() );
		}

		$this->github_plugins[ $repo_name ] = array(
			'repo' => $repo_name,
			'file' => $upgrader->plugin_info(),
		);
		update_option( 'plugin_hub_github_plugins', $this->github_plugins );

		wp_send_json_success( esc_html__( 'Plugin installed successfully.', 'plugin-hub' ) );
	}

	/**
	 * AJAX handler for activating a GitHub plugin.
	 *
	 * @since 1.0.0
	 */
	public function ajax_activate_github_plugin() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to activate plugins.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		$result = activate_plugin( $plugin_file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		delete_option( "plugin_hub_disabled_{$repo_name}" );
		wp_send_json_success( esc_html__( 'Plugin activated successfully.', 'plugin-hub' ) );
	}

	/**
	 * AJAX handler for deactivating a GitHub plugin.
	 *
	 * @since 1.0.0
	 */
	public function ajax_deactivate_github_plugin() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'deactivate_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to deactivate plugins.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		deactivate_plugins( $plugin_file );

		wp_send_json_success( esc_html__( 'Plugin deactivated successfully.', 'plugin-hub' ) );
	}

	/**
	 * AJAX handler for updating a GitHub plugin.
	 *
	 * @since 1.0.0
	 */
	public function ajax_update_github_plugin() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to update plugins.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$version   = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

		if ( empty( $repo_name ) || empty( $version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$download_url = $this->get_github_release_download_url( $repo_name, $version );

		if ( ! $download_url ) {
			/* translators: %1$s: Repository name, %2$s: Version number */
			$error_message = sprintf( esc_html__( 'Unable to fetch download URL for %1$s v%2$s. Please check the error log for more details.', 'plugin-hub' ), $repo_name, $version );
			wp_send_json_error( $error_message );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );

		// Clear the plugin update transient.
		delete_site_transient( 'update_plugins' );

		$result = $upgrader->upgrade( $plugin_file, array( 'package' => $download_url ) );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Update failed for ' . $repo_name . '. Error: ' . $result->get_error_message() );
			wp_send_json_error( $result->get_error_message() );
		} elseif ( false === $result ) {
			$this->log( 'Update failed for ' . $repo_name . '. No error message provided.' );
			wp_send_json_error( esc_html__( 'Update failed. Please check the error log for more details.', 'plugin-hub' ) );
		}

		// Force refresh of plugin update information.
		wp_clean_plugins_cache();

		// Verify the update.
		$new_version = $this->get_installed_plugin_version( $repo_name );
		if ( version_compare( $new_version, $version, '>=' ) ) {
			/* translators: %s: Version number */
			wp_send_json_success( sprintf( esc_html__( 'Plugin updated successfully to version %s', 'plugin-hub' ), $new_version ) );
		} else {
			$this->log( 'Update reported success but version mismatch for ' . $repo_name . '. Expected: ' . $version . ', Actual: ' . $new_version );
			wp_send_json_error( esc_html__( 'Update reported success but version mismatch. Please check the error log for more details.', 'plugin-hub' ) );
		}
	}

	/**
	 * AJAX handler for deleting a GitHub plugin.
	 *
	 * @since 1.0.0
	 */
	public function ajax_delete_github_plugin() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to delete plugins.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		if ( is_plugin_active( $plugin_file ) ) {
			wp_send_json_error( esc_html__( 'Please deactivate the plugin before deleting.', 'plugin-hub' ) );
		}

		$deleted = delete_plugins( array( $plugin_file ) );

		if ( is_wp_error( $deleted ) ) {
			wp_send_json_error( $deleted->get_error_message() );
		}

		if ( $deleted ) {
			wp_send_json_success( esc_html__( 'Plugin deleted successfully.', 'plugin-hub' ) );
		}

		wp_send_json_error( esc_html__( 'Failed to delete the plugin.', 'plugin-hub' ) );
	}

	/**
	 * AJAX handler for disabling a GitHub plugin.
	 *
	 * @since 1.0.0
	 */
	public function ajax_disable_github_plugin() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'deactivate_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to disable plugins.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		deactivate_plugins( $plugin_file );
		update_option( "plugin_hub_disabled_{$repo_name}", true );

		wp_send_json_success( esc_html__( 'Plugin disabled successfully.', 'plugin-hub' ) );
	}

	/**
	 * Verify plugin update version.
	 *
	 * @since 1.0.0
	 */
	public function verify_plugin_update() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to verify plugin updates.', 'plugin-hub' ) );
		}

		$repo_name        = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$expected_version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';

		if ( empty( $repo_name ) || empty( $expected_version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$installed_version = $this->get_installed_plugin_version( $repo_name );

		if ( version_compare( $installed_version, $expected_version, '>=' ) ) {
			/* translators: %s: Plugin version */
			wp_send_json_success( sprintf( esc_html__( 'Plugin version verified: %s', 'plugin-hub' ), $installed_version ) );
		}

		/* translators: %1$s: Expected version, %2$s: Found version */
		wp_send_json_error( sprintf( esc_html__( 'Plugin version mismatch. Expected: %1$s, Found: %2$s', 'plugin-hub' ), $expected_version, $installed_version ) );
	}

	/**
	 * Get the latest version from GitHub releases.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string      $repo_name Repository name.
	 * @return string|bool            Version string or false on failure.
	 */
	private function get_latest_github_version( $repo_name ) {
		$api_url = "https://api.github.com/repos/{$this->organization}/{$repo_name}/releases/latest";

		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/Plugin-Hub',
			),
		);

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching latest GitHub release: ' . $response->get_error_message() );
			return false;
		}

		$body    = wp_remote_retrieve_body( $response );
		$release = json_decode( $body, true );

		if ( isset( $release['tag_name'] ) ) {
			return ltrim( $release['tag_name'], 'v' );
		}

		$this->log( "Unable to find tag_name in GitHub API response for {$repo_name}" );
		return false;
	}

	/**
	 * Force refresh all plugin information from GitHub.
	 *
	 * @since  1.0.0
	 * @return bool True if updated, false otherwise.
	 */
	public function force_refresh_plugins() {
		$repos   = $this->get_org_repos();
		$updated = false;

		foreach ( $repos as &$repo ) {
			$latest_version = $this->get_latest_github_version( $repo['name'] );
			if ( $latest_version && $latest_version !== $repo['version'] ) {
				$repo['version'] = $latest_version;
				$updated         = true;
			}
		}

		if ( $updated ) {
			set_transient( $this->cache_key, $repos, $this->cache_expiration );
		}

		// Clear WordPress plugin update cache.
		wp_clean_plugins_cache();
		delete_site_transient( 'update_plugins' );

		return $updated;
	}

	/**
	 * AJAX handler to force refresh plugins.
	 *
	 * @since 1.0.0
	 */
	public function ajax_force_refresh_plugins() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to refresh plugin information.', 'plugin-hub' ) );
		}

		$updated = $this->force_refresh_plugins();

		if ( $updated ) {
			wp_send_json_success( esc_html__( 'Plugin information refreshed successfully.', 'plugin-hub' ) );
		}

		wp_send_json_success( esc_html__( 'No updates found. Plugin information is already up to date.', 'plugin-hub' ) );
	}

	/**
	 * Get changelog from GitHub releases.
	 *
	 * @since  1.0.0
	 * @param  string      $repo_name       Repository name.
	 * @param  string      $current_version Current version.
	 * @param  string      $new_version     New version.
	 * @return string|bool                  Changelog HTML or false on failure.
	 */
	public function get_github_changelog( $repo_name, $current_version, $new_version ) {
		$api_url = "https://api.github.com/repos/{$this->organization}/{$repo_name}/releases";

		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/Plugin-Hub',
			),
		);

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching GitHub releases: ' . $response->get_error_message() );
			return false;
		}

		$body     = wp_remote_retrieve_body( $response );
		$releases = json_decode( $body, true );

		$changelog = '';
		foreach ( $releases as $release ) {
			$release_version = ltrim( $release['tag_name'], 'v' );
			if (
				version_compare( $release_version, $current_version, '>' ) &&
				version_compare( $release_version, $new_version, '<=' )
			) {
				$changelog .= '<h4>Version ' . esc_html( $release_version ) . '</h4>';
				$changelog .= wpautop( wp_kses_post( $release['body'] ) );
			}
		}

		return $changelog;
	}

	/**
	 * AJAX handler to get changelog.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_changelog() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		$repo_name       = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$current_version = isset( $_POST['current_version'] ) ? sanitize_text_field( wp_unslash( $_POST['current_version'] ) ) : '';
		$new_version     = isset( $_POST['new_version'] ) ? sanitize_text_field( wp_unslash( $_POST['new_version'] ) ) : '';

		if ( empty( $repo_name ) || empty( $current_version ) || empty( $new_version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$changelog = $this->get_github_changelog( $repo_name, $current_version, $new_version );

		if ( $changelog ) {
			wp_send_json_success( $changelog );
		}

		wp_send_json_error( esc_html__( 'Unable to fetch changelog.', 'plugin-hub' ) );
	}
}