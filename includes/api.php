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

require_once __DIR__ . '/sponsorship.php';
require_once __DIR__ . '/class-policy.php';
require_once __DIR__ . '/class-settings.php';
require_once __DIR__ . '/class-notifier.php';
require_once __DIR__ . '/class-backups.php';

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
	 * The catalog URL for plugin data.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $catalog_url = '';

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
	 * MU plugin manager, set by Main so cron can include MU plugins.
	 *
	 * @since  1.6.0
	 * @access private
	 * @var    MU_Plugins|null
	 */
	private $mu_plugins = null;

	/**
	 * Expected extraction directory while installing a GitHub package.
	 *
	 * @since 1.4.0
	 * @var string
	 */
	private $expected_package_slug = '';

	/**
	 * SHA-256 the next downloaded package must match (empty when not verified).
	 *
	 * @since 1.8.0
	 * @var string
	 */
	private $expected_checksum = '';

	/**
	 * Package URL the expected checksum applies to.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	private $expected_package_url = '';

	/**
	 * Cache key for transients.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    string
	 */
	private $cache_key = 'plugin_hub_catalog_cache_v1';

	/**
	 * Short backoff after an unavailable or invalid catalog.
	 *
	 * @var string
	 */
	private $catalog_retry_key = 'plugin_hub_catalog_retry_v1';

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
	 * @param  string $message The message to log.
	 */
	public function log( $message ) {
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
	 * @return array Headers array for wp_remote_get().
	 */
	public function get_github_headers() {
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
	 * @param  array|\WP_Error $response The wp_remote_get() response.
	 */
	public function track_rate_limit( $response ) {
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
	 * Attach the MU plugin manager used by the daily update check.
	 *
	 * @since 1.6.0
	 * @param MU_Plugins $mu_plugins MU plugin manager.
	 */
	public function set_mu_plugins( MU_Plugins $mu_plugins ) {
		$this->mu_plugins = $mu_plugins;
	}

	/**
	 * Initialize the class.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->catalog_url = 'https://raw.githubusercontent.com/' . PLUGIN_HUB_ORGANIZATION . '/.github/main/catalog.json';
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
	 * Get organization repositories from the product catalog.
	 *
	 * @since  1.0.0
	 * @return array Array of repository data.
	 */
	public function get_org_repos() {
		$cached_data = get_transient( $this->cache_key );
		if ( false !== $cached_data && is_array( $cached_data ) ) {
			return $cached_data;
		}

		if ( false !== get_transient( $this->catalog_retry_key ) ) {
			return $this->get_last_known_repos();
		}

		$response = wp_safe_remote_get(
			$this->catalog_url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => $this->maximum_catalog_size,
			)
		);
		if ( is_wp_error( $response ) ) {
			$this->log( 'Error fetching catalog file: ' . $response->get_error_message() );
			set_transient( $this->catalog_retry_key, true, 5 * MINUTE_IN_SECONDS );
			return $this->get_last_known_repos();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$this->log( 'Unexpected HTTP status ' . $status_code . ' fetching plugin catalog.' );
			set_transient( $this->catalog_retry_key, true, 5 * MINUTE_IN_SECONDS );
			return $this->get_last_known_repos();
		}

		$catalog_content = wp_remote_retrieve_body( $response );
		$repos           = $this->parse_catalog_content( $catalog_content );

		if ( empty( $repos ) ) {
			$this->log( 'The plugin catalog was empty or invalid; using the last known catalog.' );
			set_transient( $this->catalog_retry_key, true, 5 * MINUTE_IN_SECONDS );
			return $this->get_last_known_repos();
		}

		$catalog    = json_decode( $catalog_content, true );
		$supporters = Sponsorship::validate( $catalog['sponsorship'] ?? null );
		if ( null !== $supporters ) {
			update_option( 'plugin_hub_supporters', $supporters, false );
		}

		delete_transient( $this->catalog_retry_key );
		set_transient( $this->cache_key, $repos, $this->cache_expiration );
		update_option( 'plugin_hub_last_known_repos', $repos, false );

		return $repos;
	}

	/**
	 * Parse catalog.json content into repository array.
	 *
	 * Only entries of type "plugin" are installable through Plugin Hub;
	 * apps, websites, and internal tooling in the catalog are ignored.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string $catalog_content The catalog.json body to parse.
	 * @return array                   Array of repository data.
	 */
	private function parse_catalog_content( $catalog_content ) {
		$catalog = json_decode( $catalog_content, true );
		if ( ! is_array( $catalog ) || empty( $catalog['products'] ) || ! is_array( $catalog['products'] ) ) {
			return array();
		}

		$repos = array();

		foreach ( $catalog['products'] as $product ) {
			if ( ! is_array( $product ) || 'plugin' !== ( $product['type'] ?? '' ) ) {
				continue;
			}

			$name     = trim( (string) ( $product['repo_name'] ?? '' ) );
			$version  = trim( (string) ( $product['version'] ?? '' ) );
			$repo_url = esc_url_raw( trim( (string) ( $product['repo_url'] ?? '' ) ), array( 'https' ) );

			if ( ! $this->is_valid_repo_name( $name ) || ! $this->is_catalog_repo_url( $repo_url, $name ) ) {
				$this->log( 'Skipped invalid catalog entry for repository: ' . sanitize_text_field( $name ) );
				continue;
			}

			$repos[ strtolower( $name ) ] = array(
				'name'         => $name,
				'display_name' => sanitize_text_field( trim( (string) ( $product['display_name'] ?? '' ) ) ),
				'description'  => sanitize_textarea_field( trim( (string) ( $product['description'] ?? '' ) ) ),
				'version'      => sanitize_text_field( $version ),
				'repo_url'     => $repo_url,
				'available'    => $this->is_valid_version( $version ),
				'sha256'       => preg_match( '/^[a-f0-9]{64}$/D', strtolower( trim( (string) ( $product['sha256'] ?? '' ) ) ) ) ? strtolower( trim( (string) $product['sha256'] ) ) : '',
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
		delete_transient( $this->catalog_retry_key );
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

			if ( version_compare( $csv_version, $wp_version, '>' ) && $this->is_update_allowed( $repo, $wp_version ) ) {
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

	// -------------------------------------------------------------------------
	// Pins, failed versions, dependencies
	// -------------------------------------------------------------------------

	/**
	 * Return the cached catalog without any network request.
	 *
	 * @since 1.8.0
	 * @return array
	 */
	public function get_cached_repos() {
		$cached = get_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		return $this->get_last_known_repos();
	}

	/**
	 * Return all installed plugins (cached for the request).
	 *
	 * @since 1.8.0
	 * @return array
	 */
	public function get_installed_plugins() {
		return $this->get_all_installed_plugins();
	}

	/**
	 * Return every stored version pin.
	 *
	 * @since 1.8.0
	 * @return array Map of repository name to pin.
	 */
	public function get_pins() {
		$pins = get_option( 'plugin_hub_pins', array() );
		return is_array( $pins ) ? $pins : array();
	}

	/**
	 * Return the pin for one repository.
	 *
	 * @since 1.8.0
	 * @param string $repo Repository name.
	 * @return string Pin or an empty string.
	 */
	public function get_pin( $repo ) {
		$pins = $this->get_pins();
		return isset( $pins[ $repo ] ) && is_string( $pins[ $repo ] ) ? $pins[ $repo ] : '';
	}

	/**
	 * Store or clear the pin for one repository.
	 *
	 * @since 1.8.0
	 * @param string $repo Repository name.
	 * @param string $pin  Pin (exact version, `1.x`, `1.2.x`) or an empty string to unpin.
	 * @return bool|\WP_Error
	 */
	public function set_pin( $repo, $pin ) {
		$pin = trim( (string) $pin );

		if ( ! $this->is_valid_repo_name( $repo ) || ! Policy::is_valid_pin( $pin ) ) {
			return new \WP_Error( 'plugin_hub_invalid_pin', __( 'Invalid version pin.', 'plugin-hub' ) );
		}

		$pins = $this->get_pins();
		if ( '' === $pin ) {
			unset( $pins[ $repo ] );
		} else {
			$pins[ $repo ] = $pin;
		}

		update_option( 'plugin_hub_pins', $pins, false );
		$this->log_activity( 'pin', $repo, $pin );

		return true;
	}

	/**
	 * Versions that were automatically rolled back and must not be retried.
	 *
	 * @since 1.8.0
	 * @return array Map of repository name to list of versions.
	 */
	public function get_failed_versions() {
		$failed = get_option( 'plugin_hub_failed_versions', array() );
		return is_array( $failed ) ? $failed : array();
	}

	/**
	 * Remember a release that failed its health check.
	 *
	 * @since 1.8.0
	 * @param string $repo    Repository name.
	 * @param string $version Failed version.
	 */
	private function record_failed_version( $repo, $version ) {
		$failed          = $this->get_failed_versions();
		$failed[ $repo ] = array_values( array_unique( array_merge( (array) ( $failed[ $repo ] ?? array() ), array( $version ) ) ) );
		update_option( 'plugin_hub_failed_versions', $failed, false );
	}

	/**
	 * Whether a visible and installable update exists for a catalog entry.
	 *
	 * Takes version pins and releases that previously failed their health
	 * check into account, unlike is_update_available().
	 *
	 * @since 1.8.0
	 * @param array  $repo              Repository data.
	 * @param string $installed_version Installed version.
	 * @return bool
	 */
	public function is_update_allowed( $repo, $installed_version ) {
		if ( ! $this->is_update_available( $repo, $installed_version ) ) {
			return false;
		}

		if ( ! Policy::pin_allows( $this->get_pin( $repo['name'] ), $repo['version'] ) ) {
			return false;
		}

		$failed = $this->get_failed_versions();
		return ! in_array( $repo['version'], (array) ( $failed[ $repo['name'] ] ?? array() ), true );
	}

	/**
	 * Slugs of every installed plugin.
	 *
	 * @since  1.8.0
	 * @access private
	 * @return array
	 */
	private function get_installed_slugs() {
		$slugs = array();
		foreach ( array_keys( $this->get_all_installed_plugins() ) as $file ) {
			$slugs[] = false !== strpos( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
		}
		return $slugs;
	}

	/**
	 * Installed plugins that declare a dependency on a catalog plugin.
	 *
	 * @since 1.8.0
	 * @param string $repo Repository name (plugin slug).
	 * @return array List of arrays with file, name and active.
	 */
	public function get_dependents( $repo ) {
		$plugins = $this->get_all_installed_plugins();
		$result  = array();

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( Policy::dependents_of( $repo, $plugins ) as $file ) {
			$result[] = array(
				'file'   => $file,
				'name'   => (string) ( $plugins[ $file ]['Name'] ?? $file ),
				'active' => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			);
		}

		return $result;
	}

	/**
	 * Required plugins ("Requires Plugins" header) that are not installed.
	 *
	 * @since 1.8.0
	 * @param string $repo Repository name.
	 * @return array Missing slugs.
	 */
	public function get_missing_requirements( $repo ) {
		$file = $this->get_plugin_file( $repo );
		if ( ! $file ) {
			return array();
		}

		$plugins = $this->get_all_installed_plugins();
		return Policy::missing_requirements( (array) ( $plugins[ $file ] ?? array() ), $this->get_installed_slugs() );
	}

	/**
	 * Number of pending updates, computed without any network request.
	 *
	 * @since 1.8.0
	 * @return int
	 */
	public function count_pending_updates() {
		$count     = 0;
		$transient = get_site_transient( 'update_plugins' );
		$prefix    = 'https://github.com/' . PLUGIN_HUB_ORGANIZATION . '/';

		if ( is_object( $transient ) && ! empty( $transient->response ) && is_array( $transient->response ) ) {
			foreach ( $transient->response as $item ) {
				if ( is_object( $item ) && isset( $item->id ) && is_string( $item->id ) && 0 === strpos( $item->id, $prefix ) ) {
					++$count;
				}
			}
		}

		return $count + (int) get_option( 'plugin_hub_mu_update_count', 0 );
	}

	/**
	 * Publication time of a release, cached for twelve hours.
	 *
	 * @since 1.8.0
	 * @param string $repo    Repository name.
	 * @param string $version Release version.
	 * @return int Unix timestamp or 0 when unknown.
	 */
	public function get_release_published_at( $repo, $version ) {
		if ( ! $this->is_valid_repo_name( $repo ) || ! $this->is_valid_version( $version ) ) {
			return 0;
		}

		$cache_key = 'plugin_hub_relts_' . md5( strtolower( $repo ) . ':' . $version );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$published = 0;
		foreach ( array( 'v' . $version, $version ) as $tag ) {
			$response = wp_remote_get(
				'https://api.github.com/repos/' . PLUGIN_HUB_ORGANIZATION . '/' . $repo . '/releases/tags/' . rawurlencode( $tag ),
				array(
					'headers' => $this->get_github_headers(),
					'timeout' => 15,
				)
			);
			$this->track_rate_limit( $response );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}

			$release = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( is_array( $release ) && ! empty( $release['published_at'] ) ) {
				$published = (int) strtotime( (string) $release['published_at'] );
				break;
			}
		}

		set_transient( $cache_key, $published, $published > 0 ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

		return $published;
	}

	// -------------------------------------------------------------------------
	// Package integrity
	// -------------------------------------------------------------------------

	/**
	 * Arm checksum verification for the package that is about to be downloaded.
	 *
	 * Only the catalog version carries a published checksum, so rollbacks to
	 * older releases are exempt.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  array  $repo    Catalog repository.
	 * @param  string $version Version being installed.
	 * @param  string $url     Package URL.
	 * @return true|\WP_Error
	 */
	private function begin_package_verification( $repo, $version, $url ) {
		$this->expected_checksum    = '';
		$this->expected_package_url = '';

		if ( 0 !== version_compare( $repo['version'], $version ) ) {
			return true;
		}

		if ( ! empty( $repo['sha256'] ) ) {
			$this->expected_checksum    = $repo['sha256'];
			$this->expected_package_url = $url;
			add_filter( 'upgrader_pre_download', array( $this, 'verify_package_checksum' ), 10, 3 );
			return true;
		}

		if ( Settings::get( 'require_checksum' ) ) {
			return new \WP_Error( 'plugin_hub_no_checksum', __( 'This release has no published SHA-256 checksum, and checksum verification is required in the Plugin Hub settings.', 'plugin-hub' ) );
		}

		return true;
	}

	/**
	 * Disarm checksum verification.
	 *
	 * @since  1.8.0
	 * @access private
	 */
	private function end_package_verification() {
		remove_filter( 'upgrader_pre_download', array( $this, 'verify_package_checksum' ), 10 );
		$this->expected_checksum    = '';
		$this->expected_package_url = '';
	}

	/**
	 * Download the package ourselves and compare its SHA-256 with the catalog.
	 *
	 * @since 1.8.0
	 * @param bool|\WP_Error|string $reply   Existing reply.
	 * @param string                $package Package URL.
	 * @param \WP_Upgrader|null     $upgrader Upgrader instance (unused).
	 * @return bool|\WP_Error|string
	 */
	public function verify_package_checksum( $reply, $package, $upgrader = null ) {
		unset( $upgrader );

		if ( '' === $this->expected_checksum || $package !== $this->expected_package_url ) {
			return $reply;
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$file = download_url( $package );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$actual = hash_file( 'sha256', $file );
		if ( false === $actual || ! hash_equals( $this->expected_checksum, strtolower( $actual ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'plugin_hub_checksum_mismatch', __( 'The downloaded package does not match the SHA-256 checksum published in the catalog. The installation was cancelled.', 'plugin-hub' ) );
		}

		return $file;
	}

	// -------------------------------------------------------------------------
	// Core operations (no AJAX, usable from CLI and cron)
	// -------------------------------------------------------------------------

	/**
	 * Load the admin includes the upgrader needs.
	 *
	 * @since  1.8.0
	 * @access private
	 */
	private function load_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	}

	/**
	 * Install a catalog plugin.
	 *
	 * @since 1.8.0
	 * @param string $repo_name  Repository name.
	 * @param string $version    Catalog version to install.
	 * @param bool   $background Use a silent upgrader skin (cron, CLI).
	 * @return true|\WP_Error
	 */
	public function install_plugin( $repo_name, $version, $background = false ) {
		if ( empty( $repo_name ) || empty( $version ) || ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $version ) ) {
			return new \WP_Error( 'plugin_hub_invalid', esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$repo = $this->get_catalog_repository( $repo_name );
		if ( ! $repo || 0 !== version_compare( $repo['version'], $version ) ) {
			return new \WP_Error( 'plugin_hub_untrusted', esc_html__( 'The requested plugin version is not in the trusted catalog.', 'plugin-hub' ) );
		}

		if ( $this->is_plugin_installed( $repo_name ) ) {
			return new \WP_Error( 'plugin_hub_installed', esc_html__( 'The plugin is already installed.', 'plugin-hub' ) );
		}

		$download_url = $this->get_github_release_download_url( $repo_name, $version );

		if ( ! $download_url ) {
			/* translators: %1$s: Repository name, %2$s: Version number */
			return new \WP_Error( 'plugin_hub_no_package', sprintf( esc_html__( 'Unable to fetch download URL for %1$s v%2$s. Please check the error log for more details.', 'plugin-hub' ), $repo_name, $version ) );
		}

		if ( ! $this->is_github_url( $download_url ) ) {
			return new \WP_Error( 'plugin_hub_bad_url', esc_html__( 'Invalid download URL.', 'plugin-hub' ) );
		}

		$verification = $this->begin_package_verification( $repo, $version, $download_url );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		$this->load_upgrader();

		$skin                        = $background ? new \Automatic_Upgrader_Skin() : new \WP_Ajax_Upgrader_Skin();
		$upgrader                    = new \Plugin_Upgrader( $skin );
		$this->expected_package_slug = $repo_name;
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 10, 4 );
		$installed = $upgrader->install( $download_url );
		remove_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 10 );
		$this->expected_package_slug = '';
		$this->end_package_verification();

		if ( is_wp_error( $installed ) ) {
			return $installed;
		} elseif ( false === $installed ) {
			return new \WP_Error( 'plugin_hub_install_failed', esc_html__( 'Installation failed. Please check the error log for more details.', 'plugin-hub' ) );
		}

		$plugin_info = $upgrader->plugin_info();
		if ( ! $plugin_info ) {
			return new \WP_Error( 'plugin_hub_no_file', esc_html__( 'Plugin installed but file path could not be determined.', 'plugin-hub' ) );
		}

		wp_clean_plugins_cache();
		$this->installed_plugins_cache = null;
		$installed_version             = $this->get_installed_plugin_version( $repo_name );
		if ( 0 !== version_compare( $installed_version, $version ) ) {
			return new \WP_Error( 'plugin_hub_unverified', esc_html__( 'Plugin installation completed but the installed version could not be verified.', 'plugin-hub' ) );
		}

		$this->github_plugins[ $repo_name ] = array(
			'repo' => $repo_name,
			'file' => $plugin_info,
		);
		update_option( 'plugin_hub_github_plugins', $this->github_plugins );
		$this->log_activity( 'install', $repo_name, $version );

		return true;
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

		$result = $this->install_plugin( $repo_name, $version );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

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
	 * Whether a request needs the network-wide variant of an action.
	 *
	 * @since  1.8.0
	 * @access private
	 * @return bool
	 */
	private function requested_network() {
		$network = isset( $_POST['network'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['network'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX handler.

		if ( $network && ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to manage network plugins.', 'plugin-hub' ) );
		}

		return $network;
	}

	/**
	 * Whether the request carries the "force" confirmation flag.
	 *
	 * @since  1.8.0
	 * @access private
	 * @return bool
	 */
	private function requested_force() {
		return isset( $_POST['force'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling AJAX handler.
	}

	/**
	 * Activate a catalog plugin.
	 *
	 * @since 1.8.0
	 * @param string $repo_name Repository name.
	 * @param bool   $network   Activate for the whole network.
	 * @return true|\WP_Error
	 */
	public function activate_plugin_by_repo( $repo_name, $network = false ) {
		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
			return new \WP_Error( 'plugin_hub_invalid', esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );
		if ( ! $plugin_file ) {
			return new \WP_Error( 'plugin_hub_not_found', esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		$this->load_upgrader();
		$result = activate_plugin( $plugin_file, '', (bool) $network );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		delete_option( "plugin_hub_disabled_{$repo_name}" );
		$this->log_activity( 'activate', $repo_name, $this->get_installed_plugin_version( $repo_name ) );

		return true;
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
		$result    = $this->activate_plugin_by_repo( $repo_name, $this->requested_network() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( esc_html__( 'Plugin activated successfully.', 'plugin-hub' ) );
	}

	/**
	 * Names of active plugins that require the given plugin.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  string $repo_name Repository name.
	 * @return string[]
	 */
	private function get_active_dependent_names( $repo_name ) {
		$names = array();
		foreach ( $this->get_dependents( $repo_name ) as $dependent ) {
			if ( $dependent['active'] ) {
				$names[] = $dependent['name'];
			}
		}
		return $names;
	}

	/**
	 * Deactivate a catalog plugin.
	 *
	 * @since 1.8.0
	 * @param string $repo_name Repository name.
	 * @param bool   $network   Deactivate for the whole network.
	 * @param bool   $force     Skip the dependent plugin check.
	 * @return true|\WP_Error
	 */
	public function deactivate_plugin_by_repo( $repo_name, $network = false, $force = false ) {
		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
			return new \WP_Error( 'plugin_hub_invalid', esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );
		if ( ! $plugin_file ) {
			return new \WP_Error( 'plugin_hub_not_found', esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		$this->load_upgrader();

		if ( is_multisite() && ! $network && is_plugin_active_for_network( $plugin_file ) ) {
			return new \WP_Error( 'plugin_hub_network_active', esc_html__( 'Network-active plugins must be managed from Network Admin.', 'plugin-hub' ) );
		}

		if ( ! $force ) {
			$dependents = $this->get_active_dependent_names( $repo_name );
			if ( ! empty( $dependents ) ) {
				/* translators: %s: Comma-separated plugin names. */
				return new \WP_Error( 'plugin_hub_has_dependents', sprintf( esc_html__( 'These active plugins require this plugin: %s', 'plugin-hub' ), implode( ', ', $dependents ) ) );
			}
		}

		$deactivated_version = $this->get_installed_plugin_version( $repo_name );
		deactivate_plugins( $plugin_file, false, (bool) $network );
		$this->log_activity( 'deactivate', $repo_name, $deactivated_version );

		return true;
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
		$result    = $this->deactivate_plugin_by_repo( $repo_name, $this->requested_network(), $this->requested_force() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( esc_html__( 'Plugin deactivated successfully.', 'plugin-hub' ) );
	}

	/**
	 * Update (or roll back) a catalog plugin.
	 *
	 * Creates a local backup first, verifies the result and, for updates of an
	 * active plugin, runs a loopback health check and automatically restores
	 * the previous version when the site breaks.
	 *
	 * @since 1.8.0
	 * @param string $repo_name   Repository name.
	 * @param string $version     Target version.
	 * @param bool   $is_rollback Installing an older release on purpose.
	 * @param bool   $background  Use a silent upgrader skin (cron, CLI).
	 * @param string $log_action  Activity log action; defaults to update/rollback.
	 * @return true|\WP_Error
	 */
	public function update_plugin( $repo_name, $version, $is_rollback = false, $background = false, $log_action = '' ) {
		if ( empty( $repo_name ) || empty( $version ) || ! $this->is_valid_repo_name( $repo_name ) || ! $this->is_valid_version( $version ) ) {
			return new \WP_Error( 'plugin_hub_invalid', esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$repo = $this->get_catalog_repository( $repo_name );
		if ( ! $repo ) {
			return new \WP_Error( 'plugin_hub_untrusted', esc_html__( 'The requested plugin is not in the trusted catalog.', 'plugin-hub' ) );
		}

		if ( ! $is_rollback && 0 !== version_compare( $repo['version'], $version ) ) {
			return new \WP_Error( 'plugin_hub_stale', esc_html__( 'The requested update is no longer current. Refresh the plugin list and try again.', 'plugin-hub' ) );
		}

		$current_version = $this->get_installed_plugin_version( $repo_name );
		if ( 'Not Installed' === $current_version ) {
			return new \WP_Error( 'plugin_hub_not_found', esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}
		if ( ! $is_rollback && version_compare( $version, $current_version, '<=' ) ) {
			return new \WP_Error( 'plugin_hub_current', esc_html__( 'The installed plugin is already at this version or newer.', 'plugin-hub' ) );
		}
		if ( $is_rollback && 0 === version_compare( $version, $current_version ) ) {
			return new \WP_Error( 'plugin_hub_current', esc_html__( 'The selected version is already installed.', 'plugin-hub' ) );
		}
		if ( $is_rollback && version_compare( $version, $current_version, '>' ) ) {
			return new \WP_Error( 'plugin_hub_not_older', esc_html__( 'Rollback only supports installing an older version. Use Update instead.', 'plugin-hub' ) );
		}

		if ( ! $is_rollback && ! Policy::pin_allows( $this->get_pin( $repo_name ), $version ) ) {
			/* translators: %s: Version pin. */
			return new \WP_Error( 'plugin_hub_pinned', sprintf( esc_html__( 'This plugin is pinned to %s. Remove the pin to update.', 'plugin-hub' ), $this->get_pin( $repo_name ) ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );
		$was_active  = $this->is_plugin_active( $repo_name );

		$backup_id = false;
		if ( $plugin_file ) {
			$backup_id = Backups::create( $repo_name, $plugin_file );
			if ( is_wp_error( $backup_id ) ) {
				$this->log( 'Backup before update failed for ' . $repo_name . ': ' . $backup_id->get_error_message() );
				$backup_id = false;
			}
		}

		$result = $this->perform_plugin_upgrade( $repo, $version, $background );

		if ( is_wp_error( $result ) ) {
			$this->log( 'Update failed for ' . $repo_name . '. Error: ' . $result->get_error_message() );
			return $result;
		} elseif ( false === $result ) {
			$this->log( 'Update failed for ' . $repo_name . '. No error message provided.' );
			return new \WP_Error( 'plugin_hub_update_failed', esc_html__( 'Update failed. Please check the error log for more details.', 'plugin-hub' ) );
		}

		$new_version = $this->get_installed_plugin_version( $repo_name );
		if ( 0 !== version_compare( $new_version, $version ) ) {
			$this->log( 'Update reported success but version mismatch for ' . $repo_name . '. Expected: ' . $version . ', Actual: ' . $new_version );
			return new \WP_Error( 'plugin_hub_mismatch', esc_html__( 'Update reported success but version mismatch. Please check the error log for more details.', 'plugin-hub' ) );
		}

		if ( ! $is_rollback && $was_active && Settings::get( 'auto_rollback' ) && ! $this->check_site_health() ) {
			$restored = $this->auto_rollback( $repo, $current_version, is_string( $backup_id ) ? $backup_id : '' );
			$this->record_failed_version( $repo_name, $version );
			$this->log_activity( 'auto_rollback', $repo_name, $current_version );

			return new \WP_Error(
				'plugin_hub_rolled_back',
				sprintf(
					/* translators: 1: Plugin name, 2: Failed version, 3: Restored version. */
					esc_html__( 'The site failed its health check after updating %1$s to %2$s, so version %3$s was restored automatically.', 'plugin-hub' ),
					$repo_name,
					$version,
					$restored ? $current_version : __( 'the previous version (restore failed, check the site)', 'plugin-hub' )
				)
			);
		}

		if ( '' === $log_action ) {
			$log_action = $is_rollback ? 'rollback' : 'update';
		}
		$this->log_activity( $log_action, $repo_name, $new_version );

		return true;
	}

	/**
	 * Request key front-end pages and decide whether the site still works.
	 *
	 * A request that cannot be made at all (loopback blocked) is inconclusive
	 * and counts as healthy, so a hosting restriction never causes rollbacks.
	 *
	 * @since 1.8.0
	 * @return bool False when a page answers with a fatal error.
	 */
	public function check_site_health() {
		$urls = apply_filters( 'plugin_hub_health_check_urls', array( home_url( '/' ), site_url( 'wp-login.php' ) ) );

		foreach ( (array) $urls as $url ) {
			$response = wp_remote_get(
				add_query_arg( 'plugin_hub_health', (string) time(), $url ),
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
					'cookies'     => array(),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log( 'Health check could not reach ' . $url . ': ' . $response->get_error_message() );
				continue;
			}

			if ( ! Policy::is_healthy_response( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) ) ) {
				$this->log( 'Health check failed for ' . $url . ' with HTTP ' . wp_remote_retrieve_response_code( $response ) );
				return false;
			}
		}

		return true;
	}

	/**
	 * Restore the previous version after a failed health check.
	 *
	 * Uses the local backup first and falls back to the previous GitHub release.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  array  $repo             Catalog repository.
	 * @param  string $previous_version Version to restore.
	 * @param  string $backup_id        Backup created before the update.
	 * @return bool
	 */
	private function auto_rollback( $repo, $previous_version, $backup_id ) {
		if ( '' !== $backup_id ) {
			$restored = Backups::restore( $backup_id );
			if ( true === $restored ) {
				$this->installed_plugins_cache = null;
				return true;
			}
			$this->log( 'Restoring the local backup failed: ' . ( is_wp_error( $restored ) ? $restored->get_error_message() : 'unknown error' ) );
		}

		$result                        = $this->perform_plugin_upgrade( $repo, $previous_version, true );
		$this->installed_plugins_cache = null;

		return true === $result && 0 === version_compare( $this->get_installed_plugin_version( $repo['name'] ), $previous_version );
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

		$repo_name   = isset( $_POST['repo'] ) ? sanitize_text_field( wp_unslash( $_POST['repo'] ) ) : '';
		$version     = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$is_rollback = isset( $_POST['is_rollback'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['is_rollback'] ) );

		$result = $this->update_plugin( $repo_name, $version, $is_rollback );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		/* translators: %s: Version number */
		wp_send_json_success( sprintf( esc_html__( 'Plugin updated successfully to version %s', 'plugin-hub' ), $this->get_installed_plugin_version( $repo_name ) ) );
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
	 * @param bool   $background Whether this runs from cron or CLI.
	 * @return bool|\WP_Error
	 */
	protected function perform_plugin_upgrade( $repo, $version, $background = false ) {
		$plugin_file = $this->get_plugin_file( $repo['name'] );
		if ( ! $plugin_file ) {
			return new \WP_Error( 'plugin_hub_plugin_not_found', __( 'Plugin not found.', 'plugin-hub' ) );
		}

		$download_url = $this->get_github_release_download_url( $repo['name'], $version );
		if ( ! $download_url || ! $this->is_github_url( $download_url ) ) {
			return new \WP_Error( 'plugin_hub_package_unavailable', __( 'Unable to fetch a trusted download URL for this release.', 'plugin-hub' ) );
		}

		$verification = $this->begin_package_verification( $repo, $version, $download_url );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		$this->load_upgrader();

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
		$this->end_package_verification();

		wp_clean_plugins_cache();
		delete_site_transient( 'update_plugins' );
		$this->installed_plugins_cache = null;

		return $result;
	}

	/**
	 * Delete a catalog plugin.
	 *
	 * @since 1.8.0
	 * @param string $repo_name Repository name.
	 * @param bool   $force     Skip the dependent plugin check.
	 * @return true|\WP_Error
	 */
	public function delete_plugin_by_repo( $repo_name, $force = false ) {
		if ( empty( $repo_name ) || ! $this->is_valid_repo_name( $repo_name ) ) {
			return new \WP_Error( 'plugin_hub_invalid', esc_html__( 'Invalid plugin information.', 'plugin-hub' ) );
		}

		$plugin_file = $this->get_plugin_file( $repo_name );
		if ( ! $plugin_file ) {
			return new \WP_Error( 'plugin_hub_not_found', esc_html__( 'Plugin not found.', 'plugin-hub' ) );
		}

		if ( $this->is_plugin_active( $repo_name ) ) {
			return new \WP_Error( 'plugin_hub_active', esc_html__( 'Please deactivate the plugin before deleting.', 'plugin-hub' ) );
		}

		if ( ! $force ) {
			$dependents = $this->get_active_dependent_names( $repo_name );
			if ( ! empty( $dependents ) ) {
				/* translators: %s: Comma-separated plugin names. */
				return new \WP_Error( 'plugin_hub_has_dependents', sprintf( esc_html__( 'These active plugins require this plugin: %s', 'plugin-hub' ), implode( ', ', $dependents ) ) );
			}
		}

		$this->load_upgrader();

		$deleted_version = $this->get_installed_plugin_version( $repo_name );
		$deleted         = delete_plugins( array( $plugin_file ) );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		if ( $deleted ) {
			$this->installed_plugins_cache = null;
			$this->log_activity( 'delete', $repo_name, $deleted_version );
			unset( $this->github_plugins[ $repo_name ] );
			update_option( 'plugin_hub_github_plugins', $this->github_plugins );
			delete_option( "plugin_hub_disabled_{$repo_name}" );

			return true;
		}

		return new \WP_Error( 'plugin_hub_delete_failed', esc_html__( 'Failed to delete the plugin.', 'plugin-hub' ) );
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
		$result    = $this->delete_plugin_by_repo( $repo_name, $this->requested_force() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( esc_html__( 'Plugin deleted successfully.', 'plugin-hub' ) );
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
	 * @param  string $action  install|update|rollback|activate|deactivate|delete|auto_update|auto_rollback|pin|backup_restore|import|mu_install|mu_update|mu_rollback|mu_auto_update.
	 * @param  string $plugin  Plugin slug (or MU plugin file name).
	 * @param  string $version Version involved.
	 */
	public function log_activity( $action, $plugin, $version = '' ) {
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

		update_option( 'plugin_hub_activity_log', $this->trim_activity_log( $log ), false );
	}

	/**
	 * Apply the retention settings to a chronological log.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  array $log Chronological log entries.
	 * @return array
	 */
	private function trim_activity_log( $log ) {
		$days = (int) Settings::get( 'log_days' );
		$max  = max( 1, (int) Settings::get( 'log_max' ) );

		if ( $days > 0 ) {
			$cutoff = time() - $days * DAY_IN_SECONDS;
			$log    = array_values(
				array_filter(
					$log,
					static function ( $entry ) use ( $cutoff ) {
						return (int) ( $entry['time'] ?? 0 ) >= $cutoff;
					}
				)
			);
		}

		if ( count( $log ) > $max ) {
			$log = array_slice( $log, -$max );
		}

		return $log;
	}

	/**
	 * Apply the retention settings to the stored log.
	 *
	 * @since 1.8.0
	 */
	public function prune_activity_log() {
		$log = get_option( 'plugin_hub_activity_log', array() );
		if ( ! is_array( $log ) ) {
			return;
		}

		$trimmed = $this->trim_activity_log( $log );
		if ( count( $trimmed ) !== count( $log ) ) {
			update_option( 'plugin_hub_activity_log', $trimmed, false );
		}
	}

	/**
	 * Return the activity log, most-recent first, with stable entry ids.
	 *
	 * @since  1.3.0
	 * @param  array $filters Optional filters: action, user, search, from, to.
	 * @return array
	 */
	public function get_activity_log( $filters = array() ) {
		$log = get_option( 'plugin_hub_activity_log', array() );
		if ( ! is_array( $log ) ) {
			return array();
		}

		$entries = Policy::activity_ids( array_values( $log ) );
		if ( ! empty( $filters ) ) {
			$entries = Policy::filter_activity( $entries, $filters );
		}

		return array_reverse( $entries );
	}

	/**
	 * Delete activity log entries by id.
	 *
	 * @since 1.8.0
	 * @param string[] $ids Entry ids from get_activity_log().
	 * @return int Number of removed entries.
	 */
	public function delete_activity_entries( $ids ) {
		$log = get_option( 'plugin_hub_activity_log', array() );
		if ( ! is_array( $log ) || empty( $ids ) ) {
			return 0;
		}

		$entries = Policy::activity_ids( array_values( $log ) );
		$ids     = array_map( 'strval', (array) $ids );
		$kept    = array();

		foreach ( $entries as $entry ) {
			if ( in_array( (string) $entry['id'], $ids, true ) ) {
				continue;
			}
			unset( $entry['id'] );
			$kept[] = $entry;
		}

		$removed = count( $entries ) - count( $kept );
		if ( $removed > 0 ) {
			update_option( 'plugin_hub_activity_log', $kept, false );
		}

		return $removed;
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
			// Rollback only offers the current version (for context) and older releases.
			if ( version_compare( $version, $current_version, '>' ) ) {
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
	 * Check for updates, auto-update enabled plugins, and notify the admin.
	 *
	 * Called by the hourly WP Cron event `plugin_hub_daily_update_check`. The
	 * work itself runs at most once a day, only inside the maintenance window
	 * when one is configured, and never touches pinned plugins.
	 *
	 * @since 1.3.0
	 * @param bool $force Ignore the maintenance window and the once-a-day limit.
	 */
	public function do_auto_updates_and_notify( $force = false ) {
		$settings = Settings::all();

		if ( ! $force ) {
			if ( ! empty( $settings['window_enabled'] ) && ! Policy::window_open( (array) $settings['window_days'], (int) $settings['window_start'], (int) $settings['window_end'], (int) wp_date( 'w' ), (int) wp_date( 'G' ) ) ) {
				return;
			}

			$last_run = (int) get_option( 'plugin_hub_last_run', 0 );
			if ( $last_run > 0 && time() - $last_run < 20 * HOUR_IN_SECONDS ) {
				return;
			}
		}

		update_option( 'plugin_hub_last_run', time(), false );
		$this->prune_activity_log();
		Backups::prune();

		$repos           = $this->refresh_csv_cache();
		$autoupdate_list = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$delay_days      = (int) $settings['update_delay_days'];
		$updated         = array();
		$available       = array();
		$failed          = array();

		foreach ( $repos as $repo ) {
			if ( ! $this->is_plugin_installed( $repo['name'] ) ) {
				continue;
			}

			$installed_version = $this->get_installed_plugin_version( $repo['name'] );
			if ( ! $this->is_update_allowed( $repo, $installed_version ) ) {
				continue;
			}

			$label = $repo['name'] . ' (v' . $installed_version . ' → v' . $repo['version'] . ')';

			if ( ! in_array( $repo['name'], (array) $autoupdate_list, true ) ) {
				$available[] = $label;
				continue;
			}

			if ( $delay_days > 0 && ! Policy::release_old_enough( $this->get_release_published_at( $repo['name'], $repo['version'] ), $delay_days, time() ) ) {
				/* translators: %d: Number of days. */
				$available[] = $label . ' – ' . sprintf( _n( 'waiting %d day after release', 'waiting %d days after release', $delay_days, 'plugin-hub' ), $delay_days );
				continue;
			}

			$result = $this->update_plugin( $repo['name'], $repo['version'], false, true, 'auto_update' );

			if ( true === $result ) {
				$updated[] = $repo['name'] . ' → v' . $repo['version'];
			} elseif ( is_wp_error( $result ) && 'plugin_hub_rolled_back' === $result->get_error_code() ) {
				$this->log( 'Automatic update rolled back for ' . $repo['name'] . ' v' . $repo['version'] . '.' );
				$failed[] = $label . ' – ' . __( 'rolled back after a failed health check', 'plugin-hub' );
			} else {
				$this->log( 'Automatic update failed for ' . $repo['name'] . ' v' . $repo['version'] . '.' );
				$failed[] = $label;
			}
		}

		if ( $this->mu_plugins ) {
			$mu_summary = $this->mu_plugins->run_auto_updates();
			$updated    = array_merge( $updated, $mu_summary['updated'] );
			$available  = array_merge( $available, $mu_summary['available'] );
			$failed     = array_merge( $failed, $mu_summary['failed'] ?? array() );
			update_option( 'plugin_hub_mu_update_count', count( $mu_summary['available'] ), false );
		}

		if ( empty( $updated ) && empty( $available ) && empty( $failed ) ) {
			delete_option( 'plugin_hub_last_notification_hash' );
			return;
		}

		if ( ! Notifier::should_notify( (string) $settings['notify_mode'], $updated, $available, $failed ) ) {
			return;
		}

		$notification_hash = hash(
			'sha256',
			(string) wp_json_encode(
				array(
					'updated'   => $updated,
					'available' => $available,
					'failed'    => $failed,
				)
			)
		);

		if ( get_option( 'plugin_hub_last_notification_hash', '' ) === $notification_hash ) {
			return;
		}

		if ( $this->send_update_notification( $updated, $available, $failed ) ) {
			update_option( 'plugin_hub_last_notification_hash', $notification_hash, false );
		}
	}

	/**
	 * Send the update report by e-mail and webhook.
	 *
	 * @since  1.3.0
	 * @access private
	 * @param  array $updated   Plugins that were auto-updated.
	 * @param  array $available Plugins with updates pending manual action.
	 * @param  array $failed    Plugins whose automatic update failed.
	 * @return bool Whether a channel accepted the message.
	 */
	private function send_update_notification( $updated, $available, $failed = array() ) {
		$site_name = get_bloginfo( 'name' );
		$hub_url   = admin_url( 'plugins.php?page=plugin-hub' );

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

		if ( ! empty( $failed ) ) {
			$body .= __( 'The automatic update of the following plugins failed. Update them manually from Plugin Hub:', 'plugin-hub' ) . "\n";
			foreach ( $failed as $item ) {
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

		return Notifier::send( $subject, $body );
	}
}
