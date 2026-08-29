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
	 * The CSV URL for plugin data.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $csv_url = '';

	/**
	 * GitHub plugins array.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    array
	 */
	private $github_plugins = array();

	/**
	 * In-request cache of get_plugins() result.
	 *
	 * @since  1.3.0
	 * @access private
	 * @var    array|null
	 */
	private $installed_plugins_cache = null;

	/**
	 * Expected extraction directory while installing a GitHub package.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private $expected_package_slug = '';

	/**
	 * Cache key for transients.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $cache_key = 'plugin_hub_csv_cache_v2';

	/**
	 * Cache expiration time in seconds.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    int
	 */
	private $cache_expiration = DAY_IN_SECONDS;

	/**
	 * Maximum accepted catalog size (one MiB).
	 *
	 * @since 1.4.0
	 * @var int
	 */
	private $maximum_catalog_size = 1048576;

	/**
	 * Log a debug message if WP_DEBUG is enabled.
	 *
	 * @since  1.2.0
	 * @access private
	 * @param  string $message The message to log.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Plugin Hub: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentionally gated by WP_DEBUG.
		}
	}

	/**
	 * Get all installed plugins, caching the result for the current request.
	 *
	 * Calling get_plugins() is expensive (reads disk). In a single admin page
	 * load the installed plugin list doesn't change, so we cache it to avoid
	 * calling it dozens of times in the plugin list loop.
	 *
	 * @since  1.3.0
	 * @access private
	 * @return array All installed plugins from get_plugins().
	 */
	private function get_all_installed_plugins() {
		if ( null === $this->installed_plugins_cache ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$this->installed_plugins_cache = get_plugins();
		}
		return $this->installed_plugins_cache;
	}

	/**
	 * Validate a repository or plugin name.
	 *
	 * GitHub repo names only allow alphanumeric, hyphens, and underscores.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  string $name Name to validate.
	 * @return bool         True if the name contains only allowed characters.
	 */
	private function is_valid_repo_name( $name ) {
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+$/', $name );
	}

	/**
	 * Validate a plugin version string.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  string $version Version string to validate.
	 * @return bool            True if the version string is in a valid format.
	 */
	private function is_valid_version( $version ) {
		return (bool) preg_match( '/^\d+(?:\.\d+){0,3}(?:[-_.][0-9A-Za-z][0-9A-Za-z._-]*)?$/D', $version );
	}

	/**
	 * Check whether a URL points to an allowed GitHub host.
	 *
	 * Prevents SSRF by ensuring plugin packages are only downloaded from
	 * known GitHub infrastructure.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  string $url URL to check.
	 * @return bool        True if the URL host is an allowed GitHub host.
	 */
	private function is_github_url( $url ) {
		$allowed_hosts = array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' );
		$scheme        = wp_parse_url( $url, PHP_URL_SCHEME );
		$host          = wp_parse_url( $url, PHP_URL_HOST );

		return 'https' === $scheme && in_array( strtolower( (string) $host ), $allowed_hosts, true );
	}

	/**
	 * Get headers for GitHub API requests.
	 *
	 * Includes Authorization header when a token is configured.
	 *
	 * @since  1.3.0
	 * @access private
	 * @return array Headers array for wp_remote_get().
	 */
	private function get_github_headers() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'Plugin-Hub/' . PLUGIN_HUB_VERSION . '; ' . home_url( '/' ),
			'X-GitHub-Api-Version' => '2026-03-10',
		);

		$token = get_github_token();
		if ( ! empty( $token ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Track GitHub API rate limit from response headers.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  array|\WP_Error $response The wp_remote_get() response.
	 */
	private function track_rate_limit( $response ) {
		if ( is_wp_error( $response ) ) {
			return;
		}

		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$limit     = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
		$reset     = wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );

		if ( '' !== $remaining && '' !== $limit ) {
			set_transient(
				'plugin_hub_rate_limit',
				array(
					'remaining' => (int) $remaining,
					'limit'     => (int) $limit,
					'reset'     => (int) $reset,
				),
				5 * MINUTE_IN_SECONDS
			);
		}
	}

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->csv_url = 'https://raw.githubusercontent.com/' . PLUGIN_HUB_ORGANIZATION . '/.github/main/plugins.csv';
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
		if ( false !== $cached_data && is_array( $cached_data ) ) {
			return $cached_data;
		}

		$response = wp_safe_remote_get(
			$this->csv_url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => $this->maximum_catalog_size,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching CSV file: ' . $response->get_error_message() );
			return $this->get_last_known_repos();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->log( 'Unexpected HTTP status ' . $status_code . ' fetching plugin CSV.' );
			return $this->get_last_known_repos();
		}

		$csv_content = wp_remote_retrieve_body( $response );
		$repos       = $this->parse_csv_content( $csv_content );

		if ( empty( $repos ) ) {
			$this->log( 'The plugin catalog was empty or invalid; using the last known catalog.' );
			return $this->get_last_known_repos();
		}

		set_transient( $this->cache_key, $repos, $this->cache_expiration );
		update_option( 'plugin_hub_last_known_repos', $repos, false );

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
		// Normalize line endings so Windows-formatted (\r\n) and old Mac (\r) CSVs parse correctly.
		$csv_content = str_replace( array( "\r\n", "\r" ), "\n", $csv_content );
		$lines       = explode( "\n", trim( $csv_content ) );
		$repos       = array();

		// Remove the header row.
		array_shift( $lines );

		foreach ( $lines as $line ) {
			$data = str_getcsv( $line );
			if ( count( $data ) < 5 ) {
				continue;
			}

			$name     = trim( $data[0] );
			$version  = trim( $data[3] );
			$repo_url = esc_url_raw( trim( $data[4] ), array( 'https' ) );

			if ( ! $this->is_valid_repo_name( $name ) || ! $this->is_catalog_repo_url( $repo_url, $name ) ) {
				$this->log( 'Skipped invalid catalog entry for repository: ' . sanitize_text_field( $name ) );
				continue;
			}

			$repos[ strtolower( $name ) ] = array(
				'name'         => $name,
				'display_name' => sanitize_text_field( trim( $data[1] ) ),
				'description'  => sanitize_textarea_field( trim( $data[2] ) ),
				'version'      => sanitize_text_field( $version ),
				'repo_url'     => $repo_url,
				'available'    => $this->is_valid_version( $version ),
			);
		}

		return array_values( $repos );
	}

	/**
	 * Return the last successfully validated catalog.
	 *
	 * @since 1.4.0
	 * @return array
	 */
	private function get_last_known_repos() {
		$repos = get_option( 'plugin_hub_last_known_repos', array() );
		return is_array( $repos ) ? $repos : array();
	}

	/**
	 * Validate that a catalog URL is the expected GitHub repository URL.
	 *
	 * @since 1.4.0
	 * @param string $url       Repository URL.
	 * @param string $repo_name Repository name.
	 * @return bool
	 */
	private function is_catalog_repo_url( $url, $repo_name ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

		return 'github.com' === $host && 0 === strcasecmp( $path, PLUGIN_HUB_ORGANIZATION . '/' . $repo_name );
	}

	/**
	 * Find a repository in the validated catalog.
	 *
	 * @since 1.4.0
	 * @param string $repo_name Repository name.
	 * @return array|false
	 */
	private function get_catalog_repository( $repo_name ) {
		foreach ( $this->get_org_repos() as $repo ) {
			if ( isset( $repo['name'] ) && 0 === strcasecmp( $repo['name'], $repo_name ) ) {
				return $repo;
			}
		}

		return false;
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
		foreach ( $this->get_all_installed_plugins() as $plugin_file => $plugin_data ) {
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
		$is_active   = $plugin_file && is_plugin_active( $plugin_file ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals

		if ( ! $is_active && is_multisite() && $plugin_file ) {
			$is_active = is_plugin_active_for_network( $plugin_file );
		}

		return $is_active;
	}

	/**
	 * Get the plugin file path.
	 *
	 * @since  1.0.0
	 * @param  string $plugin_name The plugin name/slug.
	 * @return string|bool              Plugin file path or false if not found.
	 */
	public function get_plugin_file( $plugin_name ) {
		foreach ( $this->get_all_installed_plugins() as $plugin_file => $plugin_data ) {
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
		return ! empty( $repo['available'] ) && 'Not Installed' !== $installed_version && version_compare( $repo['version'], $installed_version, '>' );
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
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$repos = $this->get_org_repos();

		foreach ( $repos as $repo ) {
			if ( empty( $repo['available'] ) ) {
				continue;
			}

			$plugin_file = $this->get_plugin_file( $repo['name'] );
			if ( ! $plugin_file ) {
				continue;
			}

			if ( ! isset( $transient->checked[ $plugin_file ] ) ) {
				continue;
			}

			$wp_version  = $transient->checked[ $plugin_file ];
			$csv_version = $repo['version'];

			if ( version_compare( $csv_version, $wp_version, '>' ) ) {
				$package = $this->get_github_release_download_url( $repo['name'], $csv_version );

				if ( $package ) {
					$transient->response[ $plugin_file ] = $this->build_update_data( $repo, $plugin_file, $package );
				}
			}
		}

		return $transient;
	}

	/**
	 * Build update metadata in the format expected by WordPress core.
	 *
	 * @since 1.4.0
	 * @param array  $repo        Catalog repository data.
	 * @param string $plugin_file Plugin basename.
	 * @param string $package     Package URL.
	 * @return object
	 */
	private function build_update_data( $repo, $plugin_file, $package ) {
		return (object) array(
			'id'           => $repo['repo_url'],
			'slug'         => $repo['name'],
			'plugin'       => $plugin_file,
			'new_version'  => $repo['version'],
			'url'          => $repo['repo_url'],
			'package'      => $package,
			'requires'     => '6.0',
			'tested'       => '7.1',
			'requires_php' => '8.0',
		);
	}

	/**
	 * Supply the plugin details modal for this custom update source.
	 *
	 * @since 1.4.0
	 * @param false|object|array $result Current API result.
	 * @param string             $action API action.
	 * @param object             $args   API arguments.
	 * @return false|object|array
	 */
	public function get_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$repo = $this->get_catalog_repository( sanitize_text_field( $args->slug ) );
		if ( ! $repo ) {
			return $result;
		}

		$package   = $this->get_github_release_download_url( $repo['name'], $repo['version'] );
		$changelog = $this->get_github_changelog( $repo['name'], '0.0.0', $repo['version'] );

		return (object) array(
			'name'          => $repo['display_name'],
			'slug'          => $repo['name'],
			'version'       => $repo['version'],
			'author'        => '<a href="https://openwpclub.com">Open WP Club</a>',
			'homepage'      => $repo['repo_url'],
			'requires'      => '6.0',
			'tested'        => '7.1',
			'requires_php'  => '8.0',
			'download_link' => $package ? $package : '',
			'sections'      => array(
				'description' => wpautop( esc_html( $repo['description'] ) ),
				'changelog'   => $changelog ? $changelog : esc_html__( 'No changelog is available.', 'plugin-hub' ),
			),
		);
	}

	/**
	 * Get GitHub release download URL.
	 *
	 * @since  1.0.0
	 * @param  string $repo_name Repository name.
	 * @param  string $version   Version number.
	 * @return string|bool            Download URL or false on failure.
	 */
	public function get_github_release_download_url( $repo_name, $version ) {
		if ( ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $version ) || ! $this->get_catalog_repository( $repo_name ) ) {
			return false;
		}

		$cache_key  = 'plugin_hub_release_' . md5( strtolower( $repo_name ) . ':' . $version );
		$cached_url = get_transient( $cache_key );
		if ( is_string( $cached_url ) && $this->is_github_url( $cached_url ) ) {
			return $cached_url;
		}

		// Release assets follow a predictable public URL convention. Check it
		// before using the rate-limited GitHub REST API. Keep the version in the
		// URL so the downloaded package always matches the trusted CSV catalog.
		$direct_url = $this->get_direct_release_asset_url( $repo_name, $version );
		if ( $direct_url ) {
			set_transient( $cache_key, $direct_url, HOUR_IN_SECONDS );
			return $direct_url;
		}

		$args           = array(
			'headers' => $this->get_github_headers(),
			'timeout' => 15,
		);
		$release        = null;
		$tag_candidates = array( 'v' . $version, $version );

		foreach ( $tag_candidates as $tag ) {
			$api_url  = 'https://api.github.com/repos/' . PLUGIN_HUB_ORGANIZATION . '/' . $repo_name . '/releases/tags/' . rawurlencode( $tag );
			$response = wp_remote_get( $api_url, $args );
			$this->track_rate_limit( $response );

			if ( is_wp_error( $response ) ) {
				$this->log( 'Error fetching GitHub release: ' . $response->get_error_message() );
				return false;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( 404 === $status_code ) {
				continue;
			}
			if ( 200 !== $status_code ) {
				$this->log( "Unexpected HTTP status {$status_code} fetching GitHub release for {$repo_name} {$tag}." );
				return false;
			}

			$release = json_decode( wp_remote_retrieve_body( $response ), true );
			break;
		}

		if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
			$this->log( "No valid published GitHub release found for {$repo_name} {$version}." );
			return false;
		}

		// Priority: asset named {repo-name}.zip > any .zip asset > zipball_url.
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			$any_zip_url = false;

			foreach ( $release['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && ! empty( $asset['name'] ) && '.zip' === substr( $asset['name'], -4 ) && $this->is_github_url( $asset['browser_download_url'] ) ) {
					if ( $asset['name'] === $repo_name . '.zip' ) {
						$download_url = $asset['browser_download_url'];
						set_transient( $cache_key, $download_url, HOUR_IN_SECONDS );
						return $download_url;
					}
					if ( ! $any_zip_url ) {
						$any_zip_url = $asset['browser_download_url'];
					}
				}
			}

			if ( $any_zip_url ) {
				set_transient( $cache_key, $any_zip_url, HOUR_IN_SECONDS );
				return $any_zip_url;
			}
		}

		if ( isset( $release['zipball_url'] ) ) {
			if ( $this->is_github_url( $release['zipball_url'] ) ) {
				set_transient( $cache_key, $release['zipball_url'], HOUR_IN_SECONDS );
				return $release['zipball_url'];
			}
		}

		$this->log( "Unable to find download URL in GitHub API response for {$repo_name} v{$version}" );
		$this->log( 'GitHub API response: ' . wp_json_encode( $release ) );
		return false;
	}

	/**
	 * Find a conventionally named release asset without using the GitHub API.
	 *
	 * Open-WP-Club releases normally publish `{repository}.zip`. GitHub returns
	 * a redirect for an existing asset, so a HEAD request can verify the URL
	 * without downloading the archive or consuming the REST API rate limit.
	 *
	 * @since  1.4.0
	 * @param  string $repo_name Repository name.
	 * @param  string $version   Version number.
	 * @return string|bool       Direct asset URL or false when not found.
	 */
	private function get_direct_release_asset_url( $repo_name, $version ) {
		$tag_candidates = array( $version, 'v' . $version );

		foreach ( $tag_candidates as $tag ) {
			$download_url = 'https://github.com/' . PLUGIN_HUB_ORGANIZATION . '/' . $repo_name . '/releases/download/' . rawurlencode( $tag ) . '/' . rawurlencode( $repo_name . '.zip' );
			$response     = wp_safe_remote_head(
				$download_url,
				array(
					'timeout'     => 8,
					'redirection' => 0,
					'headers'     => array(
						'User-Agent' => 'Plugin-Hub/' . PLUGIN_HUB_VERSION,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code >= 200 && $status_code < 400 ) {
				return $download_url;
			}
		}

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

		if ( empty( $repo_name ) || empty( $version ) || ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$repo = $this->get_catalog_repository( $repo_name );
		if ( ! $repo || 0 !== version_compare( $repo['version'], $version ) ) {
			wp_send_json_error( esc_html__( 'The requested plugin version is not in the trusted catalog.', 'plugin-hub' ) );
		}

		if ( $this->is_plugin_installed( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'The plugin is already installed.', 'plugin-hub' ) );
		}

		$download_url = $this->get_github_release_download_url( $repo_name, $version );

		if ( ! $download_url ) {
			/* translators: %1$s: Repository name, %2$s: Version number */
			$error_message = sprintf( esc_html__( 'Unable to fetch download URL for %1$s v%2$s. Please check the error log for more details.', 'plugin-hub' ), $repo_name, $version );
			wp_send_json_error( $error_message );
		}

		if ( ! $this->is_github_url( $download_url ) ) {
			wp_send_json_error( esc_html__( 'Invalid download URL.', 'plugin-hub' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$skin                        = new \WP_Ajax_Upgrader_Skin();
		$upgrader                    = new \Plugin_Upgrader( $skin );
		$this->expected_package_slug = $repo_name;
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 10, 4 );
		$installed = $upgrader->install( $download_url );
		remove_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 10 );
		$this->expected_package_slug = '';

		if ( is_wp_error( $installed ) ) {
			wp_send_json_error( $installed->get_error_message() );
		} elseif ( false === $installed ) {
			wp_send_json_error( esc_html__( 'Installation failed. Please check the error log for more details.', 'plugin-hub' ) );
		}

		$plugin_info = $upgrader->plugin_info();
		if ( ! $plugin_info ) {
			wp_send_json_error( esc_html__( 'Plugin installed but file path could not be determined.', 'plugin-hub' ) );
		}

		wp_clean_plugins_cache();
		$this->installed_plugins_cache = null;
		$installed_version             = $this->get_installed_plugin_version( $repo_name );
		if ( 0 !== version_compare( $installed_version, $version ) ) {
			wp_send_json_error( esc_html__( 'Plugin installation completed but the installed version could not be verified.', 'plugin-hub' ) );
		}

		$this->github_plugins[ $repo_name ] = array(
			'repo' => $repo_name,
			'file' => $plugin_info,
		);
		update_option( 'plugin_hub_github_plugins', $this->github_plugins );
		$this->log_activity( 'install', $repo_name, $version );

		wp_send_json_success( esc_html__( 'Plugin installed successfully.', 'plugin-hub' ) );
	}

	/**
	 * Normalize GitHub zipball directories to the repository slug.
	 *
	 * GitHub-generated archives use a commit-specific root directory. WordPress
	 * requires a stable plugin directory so future updates can find the plugin.
	 *
	 * @since 1.4.0
	 * @param string       $source        Extracted source path.
	 * @param string       $remote_source Temporary extraction parent path.
	 * @param \WP_Upgrader $upgrader      Upgrader instance.
	 * @param array        $hook_extra    Upgrader context.
	 * @return string|\WP_Error
	 */
	public function normalize_package_source( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $upgrader, $hook_extra );

		if ( empty( $this->expected_package_slug ) ) {
			return $source;
		}

		$desired_source = trailingslashit( $remote_source ) . $this->expected_package_slug . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $desired_source ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $desired_source, true ) ) {
			return new \WP_Error( 'plugin_hub_source_rename_failed', __( 'The downloaded plugin package could not be prepared.', 'plugin-hub' ) );
		}

		return $desired_source;
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

		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
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
		$this->log_activity( 'activate', $repo_name, $this->get_installed_plugin_version( $repo_name ) );

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

		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		$deactivated_version = $this->get_installed_plugin_version( $repo_name );
		if ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) {
			wp_send_json_error( esc_html__( 'Network-active plugins must be managed from Network Admin.', 'plugin-hub' ) );
		}
		deactivate_plugins( $plugin_file );
		$this->log_activity( 'deactivate', $repo_name, $deactivated_version );

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

		if ( empty( $repo_name ) || empty( $version ) || ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$repo        = $this->get_catalog_repository( $repo_name );
		$is_rollback = isset( $_POST['is_rollback'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['is_rollback'] ) );

		if ( ! $repo ) {
			wp_send_json_error( esc_html__( 'The requested plugin is not in the trusted catalog.', 'plugin-hub' ) );
		}

		if ( ! $is_rollback && 0 !== version_compare( $repo['version'], $version ) ) {
			wp_send_json_error( esc_html__( 'The requested update is no longer current. Refresh the plugin list and try again.', 'plugin-hub' ) );
		}

		$current_version = $this->get_installed_plugin_version( $repo_name );
		if ( 'Not Installed' === $current_version ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}
		if ( ! $is_rollback && version_compare( $version, $current_version, '<=' ) ) {
			wp_send_json_error( esc_html__( 'The installed plugin is already at this version or newer.', 'plugin-hub' ) );
		}
		if ( $is_rollback && 0 === version_compare( $version, $current_version ) ) {
			wp_send_json_error( esc_html__( 'The selected version is already installed.', 'plugin-hub' ) );
		}

		$result = $this->perform_plugin_upgrade( $repo, $version, false );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Update failed for ' . $repo_name . '. Error: ' . $result->get_error_message() );
			wp_send_json_error( $result->get_error_message() );
		} elseif ( false === $result ) {
			$this->log( 'Update failed for ' . $repo_name . '. No error message provided.' );
			wp_send_json_error( esc_html__( 'Update failed. Please check the error log for more details.', 'plugin-hub' ) );
		}

		// Verify the update.
		$new_version = $this->get_installed_plugin_version( $repo_name );
		if ( 0 === version_compare( $new_version, $version ) ) {
			$this->log_activity( $is_rollback ? 'rollback' : 'update', $repo_name, $new_version );
			/* translators: %s: Version number */
			wp_send_json_success( sprintf( esc_html__( 'Plugin updated successfully to version %s', 'plugin-hub' ), $new_version ) );
		} else {
			$this->log( 'Update reported success but version mismatch for ' . $repo_name . '. Expected: ' . $version . ', Actual: ' . $new_version );
			wp_send_json_error( esc_html__( 'Update reported success but version mismatch. Please check the error log for more details.', 'plugin-hub' ) );
		}
	}

	/**
	 * Upgrade a plugin through WordPress core's update pipeline.
	 *
	 * Plugin_Upgrader::upgrade() reads its package from the update_plugins site
	 * transient. Supplying a `package` method argument is not supported by core.
	 *
	 * @since 1.4.0
	 * @param array  $repo       Validated catalog repository.
	 * @param string $version    Release version to install.
	 * @param bool   $background Whether this runs from cron.
	 * @return bool|\WP_Error
	 */
	private function perform_plugin_upgrade( $repo, $version, $background = false ) {
		$plugin_file = $this->get_plugin_file( $repo['name'] );
		if ( ! $plugin_file ) {
			return new \WP_Error( 'plugin_hub_plugin_not_found', __( 'Plugin not found.', 'plugin-hub' ) );
		}

		$download_url = $this->get_github_release_download_url( $repo['name'], $version );
		if ( ! $download_url || ! $this->is_github_url( $download_url ) ) {
			return new \WP_Error( 'plugin_hub_package_unavailable', __( 'Unable to fetch a trusted download URL for this release.', 'plugin-hub' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$update_data            = $repo;
		$update_data['version'] = $version;
		$updates                = get_site_transient( 'update_plugins' );

		if ( ! is_object( $updates ) ) {
			$updates = new \stdClass();
		}
		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			$updates->response = array();
		}

		$updates->response[ $plugin_file ] = $this->build_update_data( $update_data, $plugin_file, $download_url );
		$filter_removed                    = remove_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_updates' ) );
		set_site_transient( 'update_plugins', $updates );
		if ( $filter_removed ) {
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_plugin_updates' ) );
		}

		$skin                        = $background ? new \Automatic_Upgrader_Skin() : new \WP_Ajax_Upgrader_Skin();
		$upgrader                    = new \Plugin_Upgrader( $skin );
		$this->expected_package_slug = $repo['name'];
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 10, 4 );
		$result = $upgrader->upgrade( $plugin_file );
		remove_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 10 );
		$this->expected_package_slug = '';

		wp_clean_plugins_cache();
		delete_site_transient( 'update_plugins' );
		$this->installed_plugins_cache = null;

		return $result;
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

		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );

		if ( ! $plugin_file ) {
			wp_send_json_error( esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		if ( $this->is_plugin_active( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Please deactivate the plugin before deleting.', 'plugin-hub' ) );
		}

		$deleted_version = $this->get_installed_plugin_version( $repo_name );
		$deleted         = delete_plugins( array( $plugin_file ) );

		if ( is_wp_error( $deleted ) ) {
			wp_send_json_error( $deleted->get_error_message() );
		}

		if ( $deleted ) {
			$this->installed_plugins_cache = null;
			$this->log_activity( 'delete', $repo_name, $deleted_version );
			unset( $this->github_plugins[ $repo_name ] );
			update_option( 'plugin_hub_github_plugins', $this->github_plugins );
			delete_option( "plugin_hub_disabled_{$repo_name}" );

			wp_send_json_success( esc_html__( 'Plugin deleted successfully.', 'plugin-hub' ) );
		}

		wp_send_json_error( esc_html__( 'Failed to delete the plugin.', 'plugin-hub' ) );
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

		if ( empty( $repo_name ) || empty( $expected_version ) || ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $expected_version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$installed_version = $this->get_installed_plugin_version( $repo_name );

		if ( 0 === version_compare( $installed_version, $expected_version ) ) {
			/* translators: %s: Plugin version */
			wp_send_json_success( sprintf( esc_html__( 'Plugin version verified: %s', 'plugin-hub' ), $installed_version ) );
		}

		/* translators: %1$s: Expected version, %2$s: Found version */
		wp_send_json_error( sprintf( esc_html__( 'Plugin version mismatch. Expected: %1$s, Found: %2$s', 'plugin-hub' ), $expected_version, $installed_version ) );
	}

	/**
	 * Get changelog from GitHub releases.
	 *
	 * @since  1.0.0
	 * @param  string $repo_name       Repository name.
	 * @param  string $current_version Current version.
	 * @param  string $new_version     New version.
	 * @return string|bool                  Changelog HTML or false on failure.
	 */
	public function get_github_changelog( $repo_name, $current_version, $new_version ) {
		if ( ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $current_version ) || ! $this->is_valid_version( $new_version ) || ! $this->get_catalog_repository( $repo_name ) ) {
			return false;
		}

		$api_url = 'https://api.github.com/repos/' . PLUGIN_HUB_ORGANIZATION . "/{$repo_name}/releases?per_page=30";

		$args = array(
			'headers' => $this->get_github_headers(),
			'timeout' => 15,
		);

		$response = wp_remote_get( $api_url, $args );
		$this->track_rate_limit( $response );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching GitHub releases: ' . $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->log( "Unexpected HTTP status {$status_code} fetching releases for {$repo_name}." );
			return false;
		}

		$body     = wp_remote_retrieve_body( $response );
		$releases = json_decode( $body, true );

		if ( ! is_array( $releases ) ) {
			$this->log( "Invalid JSON response fetching changelog for {$repo_name}." );
			return false;
		}

		$changelog = '';
		foreach ( $releases as $release ) {
			if ( empty( $release['tag_name'] ) || ! isset( $release['body'] ) || ! empty( $release['draft'] ) ) {
				continue;
			}

			$release_version = ltrim( $release['tag_name'], 'v' );
			if ( ! $this->is_valid_version( $release_version ) ) {
				continue;
			}
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

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to view changelogs.', 'plugin-hub' ) );
		}

		$repo_name       = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$current_version = isset( $_POST['current_version'] ) ? sanitize_text_field( wp_unslash( $_POST['current_version'] ) ) : '';
		$new_version     = isset( $_POST['new_version'] ) ? sanitize_text_field( wp_unslash( $_POST['new_version'] ) ) : '';

		if ( empty( $repo_name ) || empty( $current_version ) || empty( $new_version ) || ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $current_version ) || ! $this->is_valid_version( $new_version ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$changelog = $this->get_github_changelog( $repo_name, $current_version, $new_version );

		if ( $changelog ) {
			wp_send_json_success( $changelog );
		}

		wp_send_json_error( esc_html__( 'Unable to fetch changelog.', 'plugin-hub' ) );
	}

	// -------------------------------------------------------------------------
	// Activity log
	// -------------------------------------------------------------------------

	/**
	 * Append an entry to the activity log.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  string $action  install|update|rollback|activate|deactivate|delete|auto_update.
	 * @param  string $plugin  Plugin slug.
	 * @param  string $version Version involved.
	 */
	private function log_activity( $action, $plugin, $version = '' ) {
		$user = wp_get_current_user();
		$log  = get_option( 'plugin_hub_activity_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'    => time(),
			'user'    => $user->exists() ? ( $user->display_name ? $user->display_name : $user->user_login ) : __( 'WordPress Cron', 'plugin-hub' ),
			'action'  => $action,
			'plugin'  => $plugin,
			'version' => $version,
		);

		if ( count( $log ) > 200 ) {
			$log = array_slice( $log, -200 );
		}

		update_option( 'plugin_hub_activity_log', $log, false );
	}

	/**
	 * Return the activity log, most-recent first.
	 *
	 * @since  1.3.0
	 * @return array
	 */
	public function get_activity_log() {
		$log = get_option( 'plugin_hub_activity_log', array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	// -------------------------------------------------------------------------
	// Rollback / release list
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: return the last 10 GitHub releases for a plugin.
	 *
	 * @since 1.3.0
	 */
	public function ajax_get_plugin_releases() {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission.', 'plugin-hub' ) );
		}

		$repo_name = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';

		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		if ( ! $this->get_catalog_repository( $repo_name ) || ! $this->is_plugin_installed( $repo_name ) ) {
			wp_send_json_error( esc_html__( 'The requested plugin is not available for rollback.', 'plugin-hub' ) );
		}

		$api_url = 'https://api.github.com/repos/' . PLUGIN_HUB_ORGANIZATION . "/{$repo_name}/releases?per_page=10";
		$args    = array(
			'headers' => $this->get_github_headers(),
			'timeout' => 15,
		);

		$response = wp_remote_get( $api_url, $args );
		$this->track_rate_limit( $response );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_send_json_error( esc_html__( 'Unable to fetch releases.', 'plugin-hub' ) );
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			wp_send_json_error( esc_html__( 'Unable to fetch releases.', 'plugin-hub' ) );
		}

		$current_version = $this->get_installed_plugin_version( $repo_name );
		$result          = array();

		foreach ( $releases as $release ) {
			if ( empty( $release['tag_name'] ) || ! empty( $release['draft'] ) ) {
				continue;
			}
			$version = ltrim( $release['tag_name'], 'v' );
			if ( ! $this->is_valid_version( $version ) ) {
				continue;
			}
			$result[] = array(
				'version' => sanitize_text_field( $version ),
				'name'    => sanitize_text_field( ! empty( $release['name'] ) ? $release['name'] : $release['tag_name'] ),
				'date'    => ! empty( $release['published_at'] ) ? substr( $release['published_at'], 0, 10 ) : '',
				'current' => ( 0 === version_compare( $version, $current_version ) ),
			);
		}

		wp_send_json_success( $result );
	}

	// -------------------------------------------------------------------------
	// Auto-update (cron)
	// -------------------------------------------------------------------------

	/**
	 * Check for updates, auto-update enabled plugins, and e-mail the admin.
	 *
	 * Called by the daily WP Cron event `plugin_hub_daily_update_check`.
	 *
	 * @since 1.3.0
	 */
	public function do_auto_updates_and_notify() {
		$repos           = $this->refresh_csv_cache();
		$autoupdate_list = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$updated         = array();
		$available       = array();

		foreach ( $repos as $repo ) {
			if ( ! $this->is_plugin_installed( $repo['name'] ) ) {
				continue;
			}

			$installed_version = $this->get_installed_plugin_version( $repo['name'] );
			if ( ! $this->is_update_available( $repo, $installed_version ) ) {
				continue;
			}

			if ( in_array( $repo['name'], $autoupdate_list, true ) ) {
					$result = $this->perform_plugin_upgrade( $repo, $repo['version'], true );

				if ( ! is_wp_error( $result ) && false !== $result && 0 === version_compare( $this->get_installed_plugin_version( $repo['name'] ), $repo['version'] ) ) {
					$this->log_activity( 'auto_update', $repo['name'], $repo['version'] );
					$updated[] = $repo['name'] . ' → v' . $repo['version'];
				} else {
					$this->log( 'Automatic update failed for ' . $repo['name'] . ' v' . $repo['version'] . '.' );
				}
			} else {
				$available[] = $repo['name'] . ' (v' . $installed_version . ' → v' . $repo['version'] . ')';
			}
		}

		if ( empty( $updated ) && empty( $available ) ) {
			delete_option( 'plugin_hub_last_notification_hash' );
			return;
		}

		$notification_hash = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'updated'   => $updated,
					'available' => $available,
				)
			)
		);

		if ( get_option( 'plugin_hub_last_notification_hash', '' ) === $notification_hash ) {
			return;
		}

		if ( $this->send_update_notification( $updated, $available ) ) {
			update_option( 'plugin_hub_last_notification_hash', $notification_hash, false );
		}
	}

	/**
	 * Send a plain-text e-mail to the admin summarising update activity.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  array $updated   Plugins that were auto-updated.
	 * @param  array $available Plugins with updates pending manual action.
	 * @return bool Whether WordPress accepted the message for delivery.
	 */
	private function send_update_notification( $updated, $available ) {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$hub_url     = admin_url( 'plugins.php?page=plugin-hub' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Plugin Hub Update Report', 'plugin-hub' ), $site_name );

		$body = '';

		if ( ! empty( $updated ) ) {
			$body .= __( 'The following plugins were automatically updated:', 'plugin-hub' ) . "\n";
			foreach ( $updated as $item ) {
				$body .= '  - ' . $item . "\n";
			}
			$body .= "\n";
		}

		if ( ! empty( $available ) ) {
			$body .= __( 'The following plugins have updates available:', 'plugin-hub' ) . "\n";
			foreach ( $available as $item ) {
				$body .= '  - ' . $item . "\n";
			}
			$body .= "\n";
		}

		/* translators: %s: Plugin Hub admin URL */
		$body .= sprintf( __( 'Manage your plugins: %s', 'plugin-hub' ), $hub_url );

		return wp_mail( $admin_email, $subject, $body );
	}
}
