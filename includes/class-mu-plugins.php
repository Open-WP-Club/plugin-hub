<?php
/**
 * Must-use (MU) plugin management.
 *
 * Installs and updates the single-file MU plugins published in the
 * Open-WP-Club/mu-plugin repository (branch main). The repository has no
 * releases, so the catalog is built from the git trees API and file bodies are
 * fetched from raw.githubusercontent.com.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.6.0
 */

namespace PluginHub;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MU plugin manager.
 *
 * "Ours" means a file inside the MU plugin directory (subfolders included)
 * whose basename matches a catalog file. Only such files are ever updated, and
 * only the matched file is replaced.
 *
 * @since 1.6.0
 */
class MU_Plugins {

	/**
	 * Repository that publishes the MU plugins.
	 */
	const REPOSITORY = 'mu-plugin';

	/**
	 * Branch that is tracked.
	 */
	const BRANCH = 'main';

	/**
	 * Shared helper file that other MU plugins may depend on.
	 */
	const HELPERS_FILE = '000-helpers.php';

	/**
	 * Autoloader file. It is never installed or updated by Plugin Hub.
	 */
	const AUTOLOADER_FILE = '000-autoloader.php';

	/**
	 * Option holding the auto-update opt-in list (file basenames).
	 */
	const AUTOUPDATE_OPTION = 'plugin_hub_mu_autoupdate';

	/**
	 * Option holding the last successfully validated catalog.
	 */
	const LAST_KNOWN_OPTION = 'plugin_hub_mu_last_known';

	/**
	 * Option mapping blob SHAs to the header data of that exact content.
	 */
	const VERSIONS_OPTION = 'plugin_hub_mu_versions';

	/**
	 * Catalog transient.
	 */
	const CACHE_KEY = 'plugin_hub_mu_catalog_v1';

	/**
	 * Catalog failure back-off transient.
	 */
	const RETRY_KEY = 'plugin_hub_mu_retry_v1';

	/**
	 * Back-off transient set after a failed file download.
	 */
	const FETCH_RETRY_KEY = 'plugin_hub_mu_fetch_retry_v1';

	/**
	 * Descriptions transient (parsed from the repository README table).
	 */
	const DESCRIPTIONS_KEY = 'plugin_hub_mu_descriptions_v1';

	/**
	 * Option holding the last known descriptions, used during outages.
	 */
	const DESCRIPTIONS_OPTION = 'plugin_hub_mu_descriptions';

	/**
	 * Descriptions failure back-off transient.
	 */
	const DESCRIPTIONS_RETRY_KEY = 'plugin_hub_mu_descriptions_retry_v1';

	/**
	 * Operation lock transient.
	 */
	const LOCK_KEY = 'plugin_hub_mu_lock_v1';

	/**
	 * The API instance (shared GitHub headers, rate limit and activity log).
	 *
	 * @var API
	 */
	private $api;

	/**
	 * Per-request cache of the local scan.
	 *
	 * @var array|null
	 */
	private $local_cache = null;

	/**
	 * Initialize the class.
	 *
	 * @param API $api API instance.
	 */
	public function __construct( API $api ) {
		$this->api = $api;
	}

	/**
	 * Register AJAX hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_install_mu_plugin', array( $this, 'ajax_install' ) );
		add_action( 'wp_ajax_update_mu_plugin', array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_rollback_mu_plugin', array( $this, 'ajax_rollback' ) );
		add_action( 'wp_ajax_update_all_mu_plugins', array( $this, 'ajax_update_all' ) );
		add_action( 'wp_ajax_save_mu_autoupdate', array( $this, 'ajax_save_autoupdate' ) );
	}

	// -------------------------------------------------------------------------
	// Pure helpers (no WordPress dependencies; unit tested).
	// -------------------------------------------------------------------------

	/**
	 * Validate a repository path and split it into category and file.
	 *
	 * Only mu-plugins/<category>/<file>.php and the top-level shared helper
	 * file are accepted. Anything else (traversal, nested folders, index.php,
	 * the autoloader) is rejected.
	 *
	 * @param string $path Path from the git tree.
	 * @return array|false Array with 'category' and 'file', or false.
	 */
	public static function parse_catalog_path( $path ) {
		if ( ! is_string( $path ) || false !== strpos( $path, '..' ) ) {
			return false;
		}

		if ( ! preg_match( '#^mu-plugins/(?:([a-z0-9][a-z0-9_-]*)/)?([a-z0-9][a-z0-9._-]*\.php)$#', $path, $matches ) ) {
			return false;
		}

		$category = $matches[1];
		$file     = $matches[2];

		if ( self::AUTOLOADER_FILE === $file || 'index.php' === $file ) {
			return false;
		}

		if ( '' === $category && self::HELPERS_FILE !== $file ) {
			return false;
		}

		if ( '' !== $category && self::HELPERS_FILE === $file ) {
			return false;
		}

		return array(
			'category' => $category,
			'file'     => $file,
		);
	}

	/**
	 * Whether a file basename is plausible (used before the catalog lookup).
	 *
	 * @param string $file Basename.
	 * @return bool
	 */
	public static function is_valid_file_name( $file ) {
		return is_string( $file ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.php$/', $file ) && false === strpos( $file, '..' );
	}

	/**
	 * Whether a version string is a plain dotted version.
	 *
	 * @param string $version Version.
	 * @return bool
	 */
	public static function is_valid_version( $version ) {
		return is_string( $version ) && 1 === preg_match( '/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.]+)?$/', $version );
	}

	/**
	 * Read a header field from the first 8 KB of a file.
	 *
	 * Header names are case-insensitive ("Plugin name:" is accepted).
	 *
	 * @param string $contents File contents.
	 * @param string $field    Field name, e.g. "Version".
	 * @return string Value or empty string.
	 */
	public static function get_header_field( $contents, $field ) {
		$head = substr( (string) $contents, 0, 8192 );
		$re   = '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . '[ \t]*:[ \t]*(.*)$/mi';

		if ( ! preg_match( $re, $head, $matches ) ) {
			return '';
		}

		return trim( preg_replace( '/\s*\*\/\s*$/', '', $matches[1] ) );
	}

	/**
	 * Parse the headers this plugin cares about.
	 *
	 * @param string $contents File contents.
	 * @return array Array with 'name' and 'version' (empty strings when absent).
	 */
	public static function parse_header( $contents ) {
		return array(
			'name'    => self::get_header_field( $contents, 'Plugin Name' ),
			'version' => self::get_header_field( $contents, 'Version' ),
		);
	}

	/**
	 * Whether the remote version is a valid, strictly newer version.
	 *
	 * @param string $local_version  Installed version.
	 * @param string $remote_version Repository version.
	 * @return bool
	 */
	public static function is_update_available( $local_version, $remote_version ) {
		if ( ! self::is_valid_version( $remote_version ) ) {
			return false;
		}
		if ( ! self::is_valid_version( $local_version ) ) {
			return false;
		}

		return version_compare( $remote_version, $local_version, '>' );
	}

	/**
	 * Compute the git blob SHA-1 of file contents.
	 *
	 * @param string $contents File contents.
	 * @return string
	 */
	public static function blob_sha( $contents ) {
		return sha1( 'blob ' . strlen( $contents ) . "\0" . $contents );
	}

	/**
	 * Build the catalog from a git tree listing.
	 *
	 * @param array $tree The "tree" array of the git trees API response.
	 * @return array Map of file basename to entry (file, path, category, sha).
	 */
	public static function parse_tree( $tree ) {
		$catalog = array();

		if ( ! is_array( $tree ) ) {
			return $catalog;
		}

		foreach ( $tree as $item ) {
			if ( ! is_array( $item ) || 'blob' !== ( $item['type'] ?? '' ) ) {
				continue;
			}

			$sha    = (string) ( $item['sha'] ?? '' );
			$parsed = self::parse_catalog_path( (string) ( $item['path'] ?? '' ) );

			if ( false === $parsed || 1 !== preg_match( '/^[0-9a-f]{40}$/', $sha ) ) {
				continue;
			}

			// The first entry wins when two categories ship the same basename.
			if ( isset( $catalog[ $parsed['file'] ] ) ) {
				continue;
			}

			$catalog[ $parsed['file'] ] = array(
				'file'     => $parsed['file'],
				'path'     => $item['path'],
				'category' => $parsed['category'],
				'sha'      => $sha,
			);
		}

		ksort( $catalog );

		return $catalog;
	}

	/**
	 * Detect whether source code calls shared mu_* helpers.
	 *
	 * Calls to mu_*() functions that are not declared in the same file mean
	 * the file needs 000-helpers.php. Comments and strings are ignored because
	 * the check works on tokens.
	 *
	 * @param string $code PHP source.
	 * @return bool
	 */
	public static function needs_helpers( $code ) {
		$tokens = token_get_all( (string) $code );
		$count  = count( $tokens );
		$calls  = array();
		$known  = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( T_FUNCTION === $token[0] ) {
				$j = $i + 1;
				while ( $j < $count && ( '&' === $tokens[ $j ] || ( is_array( $tokens[ $j ] ) && ( in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) || '&' === $tokens[ $j ][1] ) ) ) ) {
					++$j;
				}
				if ( $j < $count && is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$known[ strtolower( $tokens[ $j ][1] ) ] = true;
				}
				continue;
			}

			$name = '';
			if ( T_STRING === $token[0] ) {
				$name = $token[1];
			} elseif ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) {
				$name = ltrim( $token[1], '\\' );
			}

			if ( '' === $name || 0 !== stripos( $name, 'mu_' ) ) {
				continue;
			}

			// Previous significant token: reject methods, static calls, new, and declarations.
			$k = $i - 1;
			while ( $k >= 0 && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				--$k;
			}
			if ( $k >= 0 && is_array( $tokens[ $k ] ) ) {
				$blocked = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_FUNCTION, T_CONST );
				if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ) {
					$blocked[] = T_NULLSAFE_OBJECT_OPERATOR;
				}
				if ( in_array( $tokens[ $k ][0], $blocked, true ) ) {
					continue;
				}
			}

			// Next significant token must be an opening parenthesis.
			$n = $i + 1;
			while ( $n < $count && is_array( $tokens[ $n ] ) && in_array( $tokens[ $n ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				++$n;
			}
			if ( $n < $count && '(' === $tokens[ $n ] ) {
				$calls[ strtolower( $name ) ] = true;
			}
		}

		return count( array_diff_key( $calls, $known ) ) > 0;
	}

	/**
	 * Sanity-check downloaded source before it touches the MU directory.
	 *
	 * @param string $code Source code.
	 * @return string Empty string when valid, otherwise a reason key:
	 *                not_php, no_name, bad_version or syntax.
	 */
	public static function check_source( $code ) {
		$code = (string) $code;

		if ( 0 !== strpos( $code, '<?php' ) ) {
			return 'not_php';
		}

		$header = self::parse_header( $code );
		if ( '' === $header['name'] ) {
			return 'no_name';
		}
		if ( ! self::is_valid_version( $header['version'] ) ) {
			return 'bad_version';
		}

		try {
			$tokens = token_get_all( $code, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return 'syntax';
		}

		return empty( $tokens ) ? 'syntax' : '';
	}

	// -------------------------------------------------------------------------
	// Environment
	// -------------------------------------------------------------------------

	/**
	 * Absolute path of the MU plugin directory, without trailing slash.
	 *
	 * @return string
	 */
	public function get_mu_dir() {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		return untrailingslashit( wp_normalize_path( $dir ) );
	}

	/**
	 * Whether file modifications are allowed on this site.
	 *
	 * @return bool
	 */
	public function file_mods_allowed() {
		return function_exists( 'wp_is_file_mod_allowed' ) ? wp_is_file_mod_allowed( 'plugin_hub_mu_plugins' ) : true;
	}

	// -------------------------------------------------------------------------
	// Catalog (git trees API, cached, with back-off and last-known fallback)
	// -------------------------------------------------------------------------

	/**
	 * Get the MU plugin catalog.
	 *
	 * @param bool $force Bypass the cache and the failure back-off.
	 * @return array Map of file basename to catalog entry.
	 */
	public function get_catalog( $force = false ) {
		if ( $force ) {
			delete_transient( self::CACHE_KEY );
			delete_transient( self::RETRY_KEY );
		} else {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}

			if ( false !== get_transient( self::RETRY_KEY ) ) {
				return $this->get_last_known();
			}
		}

		$url      = 'https://api.github.com/repos/' . PLUGIN_HUB_ORGANIZATION . '/' . self::REPOSITORY . '/git/trees/' . self::BRANCH . '?recursive=1';
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'headers'             => $this->api->get_github_headers(),
				'limit_response_size' => 2097152,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->api->log( 'MU catalog request failed: ' . $response->get_error_message() );
			set_transient( self::RETRY_KEY, true, 5 * MINUTE_IN_SECONDS );
			return $this->get_last_known();
		}

		$this->api->track_rate_limit( $response );

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			$this->api->log( 'Unexpected HTTP status ' . $status . ' fetching the MU plugin tree.' );
			set_transient( self::RETRY_KEY, true, $this->get_backoff( $response, $status ) );
			return $this->get_last_known();
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$catalog = is_array( $data ) && empty( $data['truncated'] ) ? self::parse_tree( $data['tree'] ?? array() ) : array();

		if ( empty( $catalog ) ) {
			$this->api->log( 'The MU plugin tree was empty, truncated or invalid; using the last known catalog.' );
			set_transient( self::RETRY_KEY, true, 5 * MINUTE_IN_SECONDS );
			return $this->get_last_known();
		}

		delete_transient( self::RETRY_KEY );
		set_transient( self::CACHE_KEY, $catalog, DAY_IN_SECONDS );
		update_option( self::LAST_KNOWN_OPTION, $catalog, false );
		$this->prune_versions( $catalog );

		return $catalog;
	}

	/**
	 * Force a catalog refresh.
	 *
	 * @return array
	 */
	public function refresh_catalog() {
		delete_transient( self::FETCH_RETRY_KEY );
		delete_transient( self::DESCRIPTIONS_KEY );
		delete_transient( self::DESCRIPTIONS_RETRY_KEY );
		return $this->get_catalog( true );
	}

	/**
	 * Delay before the next catalog attempt after a failed response.
	 *
	 * Rate-limited responses wait until the limit resets (capped at one hour).
	 *
	 * @param array $response HTTP response.
	 * @param int   $status   Status code.
	 * @return int Seconds.
	 */
	private function get_backoff( $response, $status ) {
		$delay = 5 * MINUTE_IN_SECONDS;

		if ( in_array( $status, array( 403, 429 ), true ) && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
			$reset = (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' );
			if ( $reset > time() ) {
				$delay = min( HOUR_IN_SECONDS, max( $delay, $reset - time() ) );
			}
		}

		return $delay;
	}

	/**
	 * Last successfully validated catalog.
	 *
	 * @return array
	 */
	private function get_last_known() {
		$catalog = get_option( self::LAST_KNOWN_OPTION, array() );
		return is_array( $catalog ) ? $catalog : array();
	}

	/**
	 * Drop cached version data for blobs no longer in the catalog.
	 *
	 * @param array $catalog Catalog.
	 */
	private function prune_versions( $catalog ) {
		$versions = get_option( self::VERSIONS_OPTION, array() );
		if ( ! is_array( $versions ) || empty( $versions ) ) {
			return;
		}

		$shas   = array_column( $catalog, 'sha' );
		$pruned = array_intersect_key( $versions, array_flip( $shas ) );
		if ( count( $pruned ) !== count( $versions ) ) {
			update_option( self::VERSIONS_OPTION, $pruned, false );
		}
	}

	// -------------------------------------------------------------------------
	// Remote files
	// -------------------------------------------------------------------------

	/**
	 * Download a catalog file and verify it against the tree's blob SHA.
	 *
	 * @param array $entry Catalog entry.
	 * @return string|\WP_Error File contents.
	 */
	private function fetch_blob( $entry ) {
		if ( false === self::parse_catalog_path( $entry['path'] ?? '' ) ) {
			return new \WP_Error( 'plugin_hub_mu_path', __( 'Invalid MU plugin path.', 'plugin-hub' ) );
		}

		$response = wp_safe_remote_get(
			'https://raw.githubusercontent.com/' . PLUGIN_HUB_ORGANIZATION . '/' . self::REPOSITORY . '/' . self::BRANCH . '/' . $entry['path'],
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 524288,
				'headers'             => array( 'User-Agent' => 'Plugin-Hub/' . PLUGIN_HUB_VERSION . '; ' . home_url( '/' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::FETCH_RETRY_KEY, true, 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'plugin_hub_mu_download', __( 'Could not download the MU plugin from GitHub.', 'plugin-hub' ) );
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::FETCH_RETRY_KEY, true, 5 * MINUTE_IN_SECONDS );
			return new \WP_Error( 'plugin_hub_mu_download', __( 'GitHub did not return the MU plugin file.', 'plugin-hub' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( self::blob_sha( $body ) !== $entry['sha'] ) {
			return new \WP_Error( 'plugin_hub_mu_changed', __( 'The MU plugin changed on GitHub while downloading. Refresh the catalog and try again.', 'plugin-hub' ) );
		}

		return $body;
	}

	/**
	 * Parse the generated plugin table of the repository README.
	 *
	 * The table sits between PLUGIN_LIST_START/END markers and has the columns
	 * Plugin Name, Description, Version, WP, PHP. Only rows linking to a valid
	 * catalog path are kept.
	 *
	 * @param string $markdown README contents.
	 * @return array Map of file basename to array with 'name' and 'description'.
	 */
	public static function parse_readme_table( $markdown ) {
		$markdown = (string) $markdown;
		$start    = strpos( $markdown, '<!-- PLUGIN_LIST_START -->' );
		$end      = strpos( $markdown, '<!-- PLUGIN_LIST_END -->' );
		if ( false === $start || false === $end || $end <= $start ) {
			return array();
		}

		$result = array();
		foreach ( preg_split( '/\R/', substr( $markdown, $start, $end - $start ) ) as $line ) {
			if ( ! preg_match( '/^\|\s*\[([^\]]+)\]\(([^)\s]+)\)\s*\|(.*)\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*([^|]*?)\s*\|\s*$/', $line, $match ) ) {
				continue;
			}

			$parsed = self::parse_catalog_path( $match[2] );
			if ( false === $parsed || '' === $parsed['category'] ) {
				continue;
			}

			$description = trim( str_replace( '\\|', '|', $match[3] ) );
			$description = trim( wp_strip_all_tags( $description ) );
			if ( strlen( $description ) > 600 ) {
				$description = rtrim( substr( $description, 0, 597 ) ) . '...';
			}

			$result[ $parsed['file'] ] = array(
				'name'        => trim( $match[1] ),
				'description' => $description,
				'version'     => self::is_valid_version( $match[4] ) ? $match[4] : '',
				'wp'          => self::clean_requirement( $match[5] ),
				'php'         => self::clean_requirement( $match[6] ),
			);
		}

		return $result;
	}

	/**
	 * Normalise a "6.6+" style requirement cell.
	 *
	 * @param string $value Raw table cell.
	 * @return string Requirement, or '' when it does not look like a version.
	 */
	private static function clean_requirement( $value ) {
		$value = trim( (string) $value );
		return 1 === preg_match( '/^\d+(?:\.\d+){0,2}\+?$/', $value ) ? $value : '';
	}

	/**
	 * Descriptions for the catalog, from the README table (one request, cached).
	 *
	 * Failures never block the UI: the last known descriptions are used.
	 *
	 * @return array Map of file basename to array with 'name' and 'description'.
	 */
	public function get_descriptions() {
		$cached = get_transient( self::DESCRIPTIONS_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$known = get_option( self::DESCRIPTIONS_OPTION, array() );
		$known = is_array( $known ) ? $known : array();
		if ( false !== get_transient( self::DESCRIPTIONS_RETRY_KEY ) ) {
			return $known;
		}

		$response = wp_safe_remote_get(
			'https://raw.githubusercontent.com/' . PLUGIN_HUB_ORGANIZATION . '/' . self::REPOSITORY . '/' . self::BRANCH . '/README.MD',
			array(
				'timeout'             => 15,
				'redirection'         => 3,
				'limit_response_size' => 1048576,
				'headers'             => array( 'User-Agent' => 'Plugin-Hub/' . PLUGIN_HUB_VERSION . '; ' . home_url( '/' ) ),
			)
		);

		$parsed = array();
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$parsed = self::parse_readme_table( wp_remote_retrieve_body( $response ) );
		}

		if ( empty( $parsed ) ) {
			$this->api->log( 'Could not load the MU plugin descriptions; using the last known ones.' );
			set_transient( self::DESCRIPTIONS_RETRY_KEY, true, 5 * MINUTE_IN_SECONDS );
			return $known;
		}

		delete_transient( self::DESCRIPTIONS_RETRY_KEY );
		set_transient( self::DESCRIPTIONS_KEY, $parsed, DAY_IN_SECONDS );
		update_option( self::DESCRIPTIONS_OPTION, $parsed, false );

		return $parsed;
	}

	/**
	 * Header data for a catalog entry, fetched at most once per blob SHA.
	 *
	 * @param array $entry   Catalog entry.
	 * @param bool  $passive Skip the download while a fetch back-off is active.
	 * @return array|null Array with 'name' and 'version', or null when unknown.
	 */
	private function get_remote_info( $entry, $passive = false ) {
		$versions = get_option( self::VERSIONS_OPTION, array() );
		$versions = is_array( $versions ) ? $versions : array();

		if ( isset( $versions[ $entry['sha'] ]['version'] ) ) {
			return $versions[ $entry['sha'] ];
		}

		if ( $passive && false !== get_transient( self::FETCH_RETRY_KEY ) ) {
			return null;
		}

		$body = $this->fetch_blob( $entry );
		if ( is_wp_error( $body ) ) {
			return null;
		}

		$info = self::parse_header( $body );
		if ( ! self::is_valid_version( $info['version'] ) ) {
			return null;
		}

		$versions[ $entry['sha'] ] = $info;
		update_option( self::VERSIONS_OPTION, $versions, false );

		return $info;
	}

	// -------------------------------------------------------------------------
	// Local files
	// -------------------------------------------------------------------------

	/**
	 * Scan the MU directory for files that match catalog basenames.
	 *
	 * @param array $catalog Catalog.
	 * @return array Map of basename to array(path, version, name, sha). The
	 *               shallowest copy wins when a basename appears twice.
	 */
	private function scan_local( $catalog ) {
		if ( null !== $this->local_cache ) {
			return $this->local_cache;
		}

		$found = array();
		$dir   = $this->get_mu_dir();

		if ( is_dir( $dir ) && ! empty( $catalog ) ) {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::LEAVES_ONLY
				);
				$iterator->setMaxDepth( 3 );
			} catch ( \Exception $e ) {
				$this->api->log( 'Could not scan the MU plugin directory: ' . $e->getMessage() );
				return array();
			}

			foreach ( $iterator as $item ) {
				$name = $item->getFilename();
				if ( ! $item->isFile() || ! isset( $catalog[ $name ] ) ) {
					continue;
				}

				$path  = wp_normalize_path( $item->getPathname() );
				$depth = substr_count( substr( $path, strlen( $dir ) ), '/' );
				if ( isset( $found[ $name ] ) && $found[ $name ]['depth'] <= $depth ) {
					continue;
				}

				$contents = $item->getSize() <= 1048576 ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
				if ( false === $contents ) {
					continue;
				}

				$header         = self::parse_header( $contents );
				$found[ $name ] = array(
					'file'    => $name,
					'path'    => $path,
					'depth'   => $depth,
					'version' => $header['version'],
					'name'    => $header['name'],
					'sha'     => self::blob_sha( $contents ),
				);
			}
		}

		$this->local_cache = $found;

		return $found;
	}

	/**
	 * Installed "ours" with update state.
	 *
	 * @param bool $fetch Whether remote headers may be downloaded for changed files.
	 * @return array List of rows, shared helper first.
	 */
	public function get_installed( $fetch = true ) {
		$catalog = $this->get_catalog();
		$rows    = array();
		$stop    = false;

		foreach ( $this->scan_local( $catalog ) as $file => $local ) {
			$entry          = $catalog[ $file ];
			$remote_version = '';

			if ( $local['sha'] === $entry['sha'] ) {
				$remote_version = $local['version'];
			} else {
				$cached = get_option( self::VERSIONS_OPTION, array() );
				if ( is_array( $cached ) && isset( $cached[ $entry['sha'] ]['version'] ) ) {
					$remote_version = $cached[ $entry['sha'] ]['version'];
				} elseif ( $fetch && ! $stop ) {
					$info = $this->get_remote_info( $entry, true );
					if ( null === $info ) {
						$stop = true; // Do not hammer GitHub after the first failure.
					} else {
						$remote_version = $info['version'];
					}
				}
			}

			$rows[] = array(
				'file'           => $file,
				'path'           => $local['path'],
				'category'       => $entry['category'],
				'name'           => '' !== $local['name'] ? $local['name'] : $file,
				'local_version'  => $local['version'],
				'remote_version' => $remote_version,
				'update'         => $local['sha'] !== $entry['sha'] && self::is_update_available( $local['version'], $remote_version ),
				'autoupdate'     => $this->is_autoupdate_enabled( $file ),
				'is_helper'      => self::HELPERS_FILE === $file,
				'has_backup'     => self::HELPERS_FILE !== $file && is_file( $this->get_backup_path( $file ) ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['is_helper'] !== $b['is_helper'] ) {
					return $a['is_helper'] ? -1 : 1;
				}
				return strcmp( $a['category'] . '/' . $a['file'], $b['category'] . '/' . $b['file'] );
			}
		);

		return $rows;
	}

	/**
	 * Data for the "MU" admin tab.
	 *
	 * @return array
	 */
	public function get_view_data() {
		$catalog   = $this->get_catalog();
		$installed = $this->get_installed( true );
		$local     = $this->scan_local( $catalog );
		$versions  = get_option( self::VERSIONS_OPTION, array() );
		$versions  = is_array( $versions ) ? $versions : array();
		$available = array();

		$descriptions = empty( $catalog ) ? array() : $this->get_descriptions();

		foreach ( $catalog as $file => $entry ) {
			if ( isset( $local[ $file ] ) || self::HELPERS_FILE === $file ) {
				continue;
			}
			$available[] = array(
				'file'        => $file,
				'category'    => $entry['category'],
				'name'        => $versions[ $entry['sha'] ]['name'] ?? ( $descriptions[ $file ]['name'] ?? $file ),
				'version'     => $versions[ $entry['sha'] ]['version'] ?? ( $descriptions[ $file ]['version'] ?? '' ),
				'description' => $descriptions[ $file ]['description'] ?? '',
				'wp'          => $descriptions[ $file ]['wp'] ?? '',
				'php'         => $descriptions[ $file ]['php'] ?? '',
			);
		}

		$dir = $this->get_mu_dir();
		if ( ! is_dir( $dir ) ) {
			$dir_state = 'missing';
		} elseif ( ! wp_is_writable( $dir ) ) {
			$dir_state = 'unwritable';
		} else {
			$dir_state = 'ok';
		}

		return array(
			'dir'          => $dir,
			'dir_state'    => $dir_state,
			'catalog_ok'   => ! empty( $catalog ),
			'file_mods'    => $this->file_mods_allowed() && ( ! is_multisite() || is_super_admin() ),
			'multisite'    => is_multisite(),
			'installed'    => $installed,
			'available'    => $available,
			'update_count' => count( array_filter( array_column( $installed, 'update' ) ) ),
		);
	}

	/**
	 * Count of installed catalog MU plugins (cache only, no network use beyond the catalog).
	 *
	 * @return int
	 */
	public function count_installed() {
		return count( $this->scan_local( $this->get_catalog() ) );
	}

	// -------------------------------------------------------------------------
	// Writing
	// -------------------------------------------------------------------------

	/**
	 * Directory that keeps the previous version of replaced MU plugins.
	 *
	 * It lives in the uploads folder (never in the MU directory), so a stored
	 * copy can never be loaded as code.
	 *
	 * @return string
	 */
	public function get_backup_dir() {
		$uploads = wp_upload_dir( null, false );
		$base    = is_array( $uploads ) && ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
		return untrailingslashit( wp_normalize_path( $base ) ) . '/plugin-hub-mu-backups';
	}

	/**
	 * First line of every stored backup.
	 *
	 * The backup is a .php file that exits immediately, so even a web server
	 * that ignores .htaccess (Nginx) neither shows the source nor runs it.
	 */
	const BACKUP_GUARD = "<?php exit; // Plugin Hub backup. Not loadable. ?>\n";

	/**
	 * Path of the stored previous version of a catalog file.
	 *
	 * @param string $file Catalog basename.
	 * @return string Empty string for an invalid name.
	 */
	public function get_backup_path( $file ) {
		return self::is_valid_file_name( $file ) ? $this->get_backup_dir() . '/' . $file . '.prev.php' : '';
	}

	/**
	 * Move a verified backup into the backup directory (or delete it).
	 *
	 * @param string $backup Temporary backup next to the replaced file.
	 * @param string $dest   Replaced file.
	 */
	private function store_previous( $backup, $dest ) {
		$target = $this->get_backup_path( basename( $dest ) );
		$dir    = $this->get_backup_dir();

		if ( '' === $target || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! wp_is_writable( $dir ) ) {
			wp_delete_file( $backup );
			return;
		}

		// Keep the folder private and non-browsable.
		if ( ! is_file( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local backup folder.
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local backup folder.
		}

		$previous = file_get_contents( $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		wp_delete_file( $backup );
		if ( false === $previous ) {
			return;
		}

		wp_delete_file( $target );
		file_put_contents( $target, self::BACKUP_GUARD . $previous, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local backup folder.
	}

	/**
	 * Read a stored backup, removing the guard line.
	 *
	 * @param string $path Backup path.
	 * @return string|false Original contents, or false when missing or malformed.
	 */
	private function read_backup( $path ) {
		$raw = is_file( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		if ( false === $raw || 0 !== strpos( $raw, self::BACKUP_GUARD ) ) {
			return false;
		}

		return substr( $raw, strlen( self::BACKUP_GUARD ) );
	}

	/**
	 * Atomically write a file (temp file + rename) with a .bak backup.
	 *
	 * Restores the backup if the replacement cannot be verified. Once the
	 * replacement is verified the backup moves to the backup directory so the
	 * update can be rolled back (or is deleted when $keep_previous is false).
	 *
	 * @param string $dest          Destination path.
	 * @param string $contents      New contents.
	 * @param bool   $keep_previous Keep the replaced version for a rollback.
	 * @return true|\WP_Error
	 */
	private function write_atomic( $dest, $contents, $keep_previous = true ) {
		$dir = dirname( $dest );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'plugin_hub_mu_dir', __( 'The MU plugin directory does not exist and could not be created.', 'plugin-hub' ) );
		}
		if ( ! wp_is_writable( $dir ) ) {
			/* translators: %s: directory path. */
			return new \WP_Error( 'plugin_hub_mu_dir', sprintf( __( 'The directory %s is not writable.', 'plugin-hub' ), $dir ) );
		}
		if ( is_file( $dest ) && ! wp_is_writable( $dest ) ) {
			/* translators: %s: file path. */
			return new \WP_Error( 'plugin_hub_mu_file', sprintf( __( 'The file %s is not writable.', 'plugin-hub' ), basename( $dest ) ) );
		}

		$temp   = $dest . '.tmp-' . wp_generate_password( 8, false );
		$backup = $dest . '.bak';
		$exists = is_file( $dest );
		$mode   = $exists ? ( fileperms( $dest ) & 0777 ) : 0644;

		if ( false === file_put_contents( $temp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic write into the MU directory.
			wp_delete_file( $temp );
			return new \WP_Error( 'plugin_hub_mu_write', __( 'Could not write the MU plugin file.', 'plugin-hub' ) );
		}
		chmod( $temp, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.chmod_chmod -- Match the replaced file's permissions.

		if ( $exists && ! copy( $dest, $backup ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Local backup before replacing.
			wp_delete_file( $temp );
			return new \WP_Error( 'plugin_hub_mu_backup', __( 'Could not back up the existing MU plugin file.', 'plugin-hub' ) );
		}

		if ( ! rename( $temp, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace.
			wp_delete_file( $temp );
			return new \WP_Error( 'plugin_hub_mu_write', __( 'Could not replace the MU plugin file.', 'plugin-hub' ) );
		}

		$written = file_get_contents( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		if ( $written !== $contents ) {
			if ( $exists ) {
				copy( $backup, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Restore the backup.
			} else {
				wp_delete_file( $dest );
			}
			return new \WP_Error( 'plugin_hub_mu_verify', __( 'The written MU plugin file did not match the download and was rolled back.', 'plugin-hub' ) );
		}

		// The replacement is verified: keep the old version for a rollback, or drop it.
		if ( $exists ) {
			if ( $keep_previous ) {
				$this->store_previous( $backup, $dest );
			} else {
				wp_delete_file( $backup );
			}
		}

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- OPcache may be restricted.
		}
		$this->local_cache = null;

		return true;
	}

	/**
	 * Download, validate and write one catalog entry.
	 *
	 * @param array  $entry Catalog entry.
	 * @param string $dest  Destination path.
	 * @param string $local_version Installed version for updates ('' for installs).
	 * @return array|\WP_Error Array with 'version' and 'code' on success.
	 */
	private function apply_entry( $entry, $dest, $local_version = '' ) {
		$body = $this->fetch_blob( $entry );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$problem = self::check_source( $body );
		if ( '' !== $problem ) {
			$messages = array(
				'not_php'     => __( 'The downloaded file is not a PHP file.', 'plugin-hub' ),
				'no_name'     => __( 'The downloaded file has no Plugin Name header.', 'plugin-hub' ),
				'bad_version' => __( 'The downloaded file has no valid Version header.', 'plugin-hub' ),
				'syntax'      => __( 'The downloaded file has a PHP syntax error and was not installed.', 'plugin-hub' ),
			);
			return new \WP_Error( 'plugin_hub_mu_invalid', $messages[ $problem ] );
		}

		$version = self::get_header_field( $body, 'Version' );
		if ( '' !== $local_version && ! self::is_update_available( $local_version, $version ) ) {
			return new \WP_Error( 'plugin_hub_mu_current', __( 'The installed MU plugin is already at this version or newer.', 'plugin-hub' ) );
		}

		// Shared helpers must be in place before a file that calls them.
		if ( self::HELPERS_FILE !== $entry['file'] && self::needs_helpers( $body ) ) {
			$ready = $this->ensure_helpers();
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		$written = $this->write_atomic( $dest, $body );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'version' => $version,
			'code'    => $body,
		);
	}

	/**
	 * Install or update 000-helpers.php.
	 *
	 * @return true|\WP_Error
	 */
	private function ensure_helpers() {
		$catalog = $this->get_catalog();
		if ( ! isset( $catalog[ self::HELPERS_FILE ] ) ) {
			return new \WP_Error( 'plugin_hub_mu_helpers', __( 'This MU plugin needs 000-helpers.php, which is not in the catalog.', 'plugin-hub' ) );
		}

		$entry = $catalog[ self::HELPERS_FILE ];
		$local = $this->scan_local( $catalog );

		if ( ! isset( $local[ self::HELPERS_FILE ] ) ) {
			$result = $this->apply_entry( $entry, $this->get_mu_dir() . '/' . self::HELPERS_FILE );
			$action = 'mu_install';
		} elseif ( $local[ self::HELPERS_FILE ]['sha'] !== $entry['sha'] ) {
			$info   = $this->get_remote_info( $entry );
			$result = null !== $info && self::is_update_available( $local[ self::HELPERS_FILE ]['version'], $info['version'] )
				? $this->apply_entry( $entry, $local[ self::HELPERS_FILE ]['path'], $local[ self::HELPERS_FILE ]['version'] )
				: true;
			$action = 'mu_update';
		} else {
			return true;
		}

		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message. */
			return new \WP_Error( 'plugin_hub_mu_helpers', sprintf( __( 'Could not install the shared helpers: %s', 'plugin-hub' ), $result->get_error_message() ) );
		}
		if ( is_array( $result ) ) {
			$this->api->log_activity( $action, self::HELPERS_FILE, $result['version'] );
		}

		return true;
	}

	/**
	 * Run a callback under a short lock so installs never overlap.
	 *
	 * @param callable $callback Work to do.
	 * @return mixed
	 */
	private function with_lock( $callback ) {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return new \WP_Error( 'plugin_hub_mu_locked', __( 'Another MU plugin operation is in progress. Try again in a moment.', 'plugin-hub' ) );
		}

		set_transient( self::LOCK_KEY, true, MINUTE_IN_SECONDS );
		try {
			return $callback();
		} finally {
			delete_transient( self::LOCK_KEY );
		}
	}

	/**
	 * Install a catalog MU plugin flat into the MU directory.
	 *
	 * @param string $file Catalog basename.
	 * @return string|\WP_Error Installed version.
	 */
	public function install( $file ) {
		if ( ! $this->file_mods_allowed() ) {
			return new \WP_Error( 'plugin_hub_mu_mods', __( 'File modifications are disabled on this site.', 'plugin-hub' ) );
		}

		return $this->with_lock(
			function () use ( $file ) {
				$catalog = $this->get_catalog();
				if ( ! self::is_valid_file_name( $file ) || ! isset( $catalog[ $file ] ) || self::HELPERS_FILE === $file ) {
					return new \WP_Error( 'plugin_hub_mu_unknown', __( 'The requested MU plugin is not in the trusted catalog.', 'plugin-hub' ) );
				}
				if ( isset( $this->scan_local( $catalog )[ $file ] ) ) {
					return new \WP_Error( 'plugin_hub_mu_exists', __( 'This MU plugin is already installed.', 'plugin-hub' ) );
				}

				$result = $this->apply_entry( $catalog[ $file ], $this->get_mu_dir() . '/' . $file );
				if ( is_wp_error( $result ) ) {
					$this->api->log( 'MU install failed for ' . $file . ': ' . $result->get_error_message() );
					return $result;
				}

				$this->api->log_activity( 'mu_install', $file, $result['version'] );
				return $result['version'];
			}
		);
	}

	/**
	 * Update an installed catalog MU plugin in place.
	 *
	 * @param string $file       Catalog basename.
	 * @param string $log_action Activity log action.
	 * @return string|\WP_Error New version.
	 */
	public function update( $file, $log_action = 'mu_update' ) {
		if ( ! $this->file_mods_allowed() ) {
			return new \WP_Error( 'plugin_hub_mu_mods', __( 'File modifications are disabled on this site.', 'plugin-hub' ) );
		}

		return $this->with_lock(
			function () use ( $file, $log_action ) {
				return $this->update_unlocked( $file, $log_action );
			}
		);
	}

	/**
	 * Update implementation shared by single and bulk updates.
	 *
	 * @param string $file       Catalog basename.
	 * @param string $log_action Activity log action.
	 * @return string|\WP_Error
	 */
	private function update_unlocked( $file, $log_action ) {
		$catalog = $this->get_catalog();
		if ( ! self::is_valid_file_name( $file ) || ! isset( $catalog[ $file ] ) ) {
			return new \WP_Error( 'plugin_hub_mu_unknown', __( 'The requested MU plugin is not in the trusted catalog.', 'plugin-hub' ) );
		}

		$local = $this->scan_local( $catalog );
		if ( ! isset( $local[ $file ] ) ) {
			return new \WP_Error( 'plugin_hub_mu_missing', __( 'This MU plugin is not installed.', 'plugin-hub' ) );
		}
		if ( $local[ $file ]['sha'] === $catalog[ $file ]['sha'] ) {
			return new \WP_Error( 'plugin_hub_mu_current', __( 'The installed MU plugin is already up to date.', 'plugin-hub' ) );
		}

		$result = $this->apply_entry( $catalog[ $file ], $local[ $file ]['path'], $local[ $file ]['version'] );
		if ( is_wp_error( $result ) ) {
			$this->api->log( 'MU update failed for ' . $file . ': ' . $result->get_error_message() );
			return $result;
		}

		$this->api->log_activity( $log_action, $file, $result['version'] );
		return $result['version'];
	}

	/**
	 * Restore the version that was replaced by the last update.
	 *
	 * Auto-update is switched off for the file so the cron does not undo the
	 * rollback.
	 *
	 * @param string $file Catalog basename.
	 * @return string|\WP_Error Restored version.
	 */
	public function rollback( $file ) {
		if ( ! $this->file_mods_allowed() ) {
			return new \WP_Error( 'plugin_hub_mu_mods', __( 'File modifications are disabled on this site.', 'plugin-hub' ) );
		}

		return $this->with_lock(
			function () use ( $file ) {
				$catalog = $this->get_catalog();
				if ( ! self::is_valid_file_name( $file ) || ! isset( $catalog[ $file ] ) ) {
					return new \WP_Error( 'plugin_hub_mu_unknown', __( 'The requested MU plugin is not in the trusted catalog.', 'plugin-hub' ) );
				}

				// Other MU plugins may already rely on newer helper functions.
				if ( self::HELPERS_FILE === $file ) {
					return new \WP_Error( 'plugin_hub_mu_helpers_rollback', __( 'The shared helpers file cannot be rolled back because other MU plugins may depend on it.', 'plugin-hub' ) );
				}

				$local = $this->scan_local( $catalog );
				if ( ! isset( $local[ $file ] ) ) {
					return new \WP_Error( 'plugin_hub_mu_missing', __( 'This MU plugin is not installed.', 'plugin-hub' ) );
				}

				$backup = $this->get_backup_path( $file );
				if ( ! is_file( $backup ) ) {
					return new \WP_Error( 'plugin_hub_mu_nobackup', __( 'There is no previous version to restore.', 'plugin-hub' ) );
				}

				$code = $this->read_backup( $backup );
				if ( false === $code || '' !== self::check_source( $code ) ) {
					return new \WP_Error( 'plugin_hub_mu_invalid', __( 'The stored previous version is not a valid MU plugin.', 'plugin-hub' ) );
				}

				$written = $this->write_atomic( $local[ $file ]['path'], $code, false );
				if ( is_wp_error( $written ) ) {
					return $written;
				}

				wp_delete_file( $backup );

				$list = get_option( self::AUTOUPDATE_OPTION, array() );
				$list = is_array( $list ) ? $list : array();
				update_option( self::AUTOUPDATE_OPTION, array_values( array_diff( $list, array( $file ) ) ), false );

				$version = self::get_header_field( $code, 'Version' );
				$this->api->log_activity( 'mu_rollback', $file, $version );

				return $version;
			}
		);
	}

	/**
	 * Update every installed MU plugin that has a newer version.
	 *
	 * @param string $log_action Activity log action.
	 * @return array|\WP_Error Array with 'updated' (file => version) and 'failed' (file => message).
	 */
	public function update_all( $log_action = 'mu_update' ) {
		if ( ! $this->file_mods_allowed() ) {
			return new \WP_Error( 'plugin_hub_mu_mods', __( 'File modifications are disabled on this site.', 'plugin-hub' ) );
		}

		return $this->with_lock(
			function () use ( $log_action ) {
				$result = array(
					'updated' => array(),
					'failed'  => array(),
				);

				// get_installed() lists the shared helpers first.
				foreach ( $this->get_installed( true ) as $row ) {
					if ( ! $row['update'] ) {
						continue;
					}
					$version = $this->update_unlocked( $row['file'], $log_action );
					if ( is_wp_error( $version ) ) {
						$result['failed'][ $row['file'] ] = $version->get_error_message();
					} else {
						$result['updated'][ $row['file'] ] = $version;
					}
				}

				return $result;
			}
		);
	}

	// -------------------------------------------------------------------------
	// Auto-updates
	// -------------------------------------------------------------------------

	/**
	 * Whether auto-update is enabled for a file.
	 *
	 * @param string $file Basename.
	 * @return bool
	 */
	public function is_autoupdate_enabled( $file ) {
		$list = get_option( self::AUTOUPDATE_OPTION, array() );
		return is_array( $list ) && in_array( $file, $list, true );
	}

	/**
	 * Cron entry point: update opted-in MU plugins and report the rest.
	 *
	 * @return array Array with 'updated', 'available' and 'failed' notification lines.
	 */
	public function run_auto_updates() {
		$summary = array(
			'updated'   => array(),
			'available' => array(),
			'failed'    => array(),
		);

		$catalog = $this->refresh_catalog();
		if ( empty( $catalog ) ) {
			return $summary;
		}

		$can_write = $this->file_mods_allowed();

		foreach ( $this->get_installed( true ) as $row ) {
			if ( ! $row['update'] ) {
				continue;
			}

			if ( $can_write && $row['autoupdate'] ) {
				$version = $this->update( $row['file'], 'mu_auto_update' );
				if ( ! is_wp_error( $version ) ) {
					$summary['updated'][] = 'MU: ' . $row['file'] . ' → v' . $version;
					continue;
				}
				$this->api->log( 'Automatic MU update failed for ' . $row['file'] . ': ' . $version->get_error_message() );
				$summary['failed'][] = 'MU: ' . $row['file'] . ' (' . $version->get_error_message() . ')';
			}

			$summary['available'][] = 'MU: ' . $row['file'] . ' (v' . $row['local_version'] . ' → v' . $row['remote_version'] . ')';
		}

		return $summary;
	}

	// -------------------------------------------------------------------------
	// Cache maintenance
	// -------------------------------------------------------------------------

	/**
	 * Delete MU caches (used on uninstall-equivalent cleanups).
	 */
	public function clear_caches() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::RETRY_KEY );
		delete_transient( self::FETCH_RETRY_KEY );
		delete_transient( self::LOCK_KEY );
		delete_transient( self::DESCRIPTIONS_KEY );
		delete_transient( self::DESCRIPTIONS_RETRY_KEY );
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Common AJAX guard: nonce, capability and file-modification check.
	 *
	 * @param string $capability Required capability.
	 * @param bool   $writes     Whether the request modifies files.
	 */
	private function guard( $capability, $writes = true ) {
		check_ajax_referer( 'plugin-hub-nonce', 'nonce' );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( esc_html__( 'You do not have permission to manage MU plugins.', 'plugin-hub' ) );
		}

		// The MU directory is shared by the whole network.
		if ( $writes && is_multisite() && ! is_super_admin() ) {
			wp_send_json_error( esc_html__( 'Only network administrators can change MU plugins on a multisite network.', 'plugin-hub' ) );
		}

		if ( $writes && ! $this->file_mods_allowed() ) {
			wp_send_json_error( esc_html__( 'File modifications are disabled on this site.', 'plugin-hub' ) );
		}
	}

	/**
	 * Read the requested file from POST data.
	 *
	 * @return string
	 */
	private function requested_file() {
		$file = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		if ( ! self::is_valid_file_name( $file ) ) {
			wp_send_json_error( esc_html__( 'Invalid MU plugin information.', 'plugin-hub' ) );
		}
		return $file;
	}

	/**
	 * AJAX: install an MU plugin.
	 */
	public function ajax_install() {
		$this->guard( 'install_plugins' );
		$file    = $this->requested_file();
		$version = $this->install( $file );

		if ( is_wp_error( $version ) ) {
			wp_send_json_error( $version->get_error_message() );
		}

		/* translators: 1: file name, 2: version number. */
		wp_send_json_success( sprintf( esc_html__( '%1$s installed (version %2$s).', 'plugin-hub' ), $file, $version ) );
	}

	/**
	 * AJAX: update one MU plugin.
	 */
	public function ajax_update() {
		$this->guard( 'update_plugins' );
		$file    = $this->requested_file();
		$version = $this->update( $file );

		if ( is_wp_error( $version ) ) {
			wp_send_json_error( $version->get_error_message() );
		}

		/* translators: 1: file name, 2: version number. */
		wp_send_json_success( sprintf( esc_html__( '%1$s updated to version %2$s.', 'plugin-hub' ), $file, $version ) );
	}

	/**
	 * AJAX: restore the previous version of one MU plugin.
	 */
	public function ajax_rollback() {
		$this->guard( 'update_plugins' );
		$file    = $this->requested_file();
		$version = $this->rollback( $file );

		if ( is_wp_error( $version ) ) {
			wp_send_json_error( $version->get_error_message() );
		}

		/* translators: 1: file name, 2: version number. */
		wp_send_json_success( sprintf( esc_html__( '%1$s restored to version %2$s. Automatic updates were switched off for it.', 'plugin-hub' ), $file, $version ) );
	}

	/**
	 * AJAX: update all MU plugins.
	 */
	public function ajax_update_all() {
		$this->guard( 'update_plugins' );
		$result = $this->update_all();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$updated = count( $result['updated'] );
		$failed  = count( $result['failed'] );

		if ( 0 === $updated && 0 === $failed ) {
			wp_send_json_success( esc_html__( 'All MU plugins are up to date.', 'plugin-hub' ) );
		}

		/* translators: %d: number of MU plugins. */
		$message = sprintf( esc_html__( '%d MU plugin(s) updated.', 'plugin-hub' ), $updated );
		foreach ( $result['failed'] as $file => $error ) {
			$message .= ' ' . $file . ': ' . $error;
		}

		if ( 0 === $updated ) {
			wp_send_json_error( $message );
		}

		wp_send_json_success( $message );
	}

	/**
	 * AJAX: toggle auto-update for one MU plugin.
	 */
	public function ajax_save_autoupdate() {
		$this->guard( 'update_plugins', false );
		$file    = $this->requested_file();
		$enabled = isset( $_POST['enabled'] ) ? filter_var( wp_unslash( $_POST['enabled'] ), FILTER_VALIDATE_BOOLEAN ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().

		if ( $enabled && ! isset( $this->get_catalog()[ $file ] ) ) {
			wp_send_json_error( esc_html__( 'The requested MU plugin is not in the trusted catalog.', 'plugin-hub' ) );
		}

		$list = get_option( self::AUTOUPDATE_OPTION, array() );
		$list = is_array( $list ) ? $list : array();

		if ( $enabled ) {
			$list[] = $file;
			$list   = array_values( array_unique( $list ) );
		} else {
			$list = array_values( array_diff( $list, array( $file ) ) );
		}

		update_option( self::AUTOUPDATE_OPTION, $list, false );
		wp_send_json_success( esc_html__( 'Setting updated.', 'plugin-hub' ) );
	}
}
