<?php
/**
 * Export and import of the Plugin Hub configuration.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.8.0
 */

namespace PluginHub;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Config class.
 *
 * Moves the managed plugin list, auto-update choices, pins and Plugin Hub
 * settings between sites. Import never installs anything; it reports the
 * plugins that are still missing so the admin can install them.
 *
 * @since 1.8.0
 */
class Config {

	/**
	 * Format marker stored in every export.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const FORMAT = 'plugin-hub-config';

	/**
	 * Maximum accepted size of an import document (one MiB).
	 *
	 * @since 1.8.0
	 * @var int
	 */
	const MAX_SIZE = 1048576;

	/**
	 * Build the export document for this site.
	 *
	 * @since  1.8.0
	 * @param  API        $api API instance.
	 * @param  MU_Plugins $mu  MU plugin manager.
	 * @return array
	 */
	public static function export( API $api, MU_Plugins $mu ) {
		$autoupdate = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$autoupdate = is_array( $autoupdate ) ? $autoupdate : array();
		$plugins    = array();

		foreach ( $api->get_cached_repos() as $repo ) {
			if ( empty( $repo['name'] ) || ! $api->is_plugin_installed( $repo['name'] ) ) {
				continue;
			}
			$plugins[] = array(
				'repo'       => $repo['name'],
				'version'    => $api->get_installed_plugin_version( $repo['name'] ),
				'active'     => (bool) $api->is_plugin_active( $repo['name'] ),
				'autoupdate' => in_array( $repo['name'], $autoupdate, true ),
				'pin'        => $api->get_pin( $repo['name'] ),
			);
		}

		$mu_files = array();
		foreach ( $mu->get_installed( false ) as $row ) {
			$mu_files[] = array(
				'file'       => $row['file'],
				'autoupdate' => ! empty( $row['autoupdate'] ),
			);
		}

		return array(
			'format'     => self::FORMAT,
			'version'    => 1,
			'plugin_hub' => PLUGIN_HUB_VERSION,
			'site'       => home_url(),
			'exported'   => gmdate( 'c' ),
			'plugins'    => $plugins,
			'mu'         => $mu_files,
			'settings'   => Settings::all(),
		);
	}

	/**
	 * Encode an export document as pretty JSON.
	 *
	 * @since  1.8.0
	 * @param  array $data Export document.
	 * @return string
	 */
	public static function to_json( array $data ) {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Parse and normalise an import document.
	 *
	 * @since  1.8.0
	 * @param  string $json Raw JSON.
	 * @return array|\WP_Error Normalised document or an error.
	 */
	public static function validate( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) || strlen( $json ) > self::MAX_SIZE ) {
			return new \WP_Error( 'plugin_hub_config_invalid', __( 'The configuration file is empty or too large.', 'plugin-hub' ) );
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) || 1 !== (int) ( $data['version'] ?? 0 ) ) {
			return new \WP_Error( 'plugin_hub_config_invalid', __( 'This is not a Plugin Hub configuration file.', 'plugin-hub' ) );
		}

		$plugins = array();
		foreach ( (array) ( $data['plugins'] ?? array() ) as $entry ) {
			$repo = is_array( $entry ) ? trim( (string) ( $entry['repo'] ?? '' ) ) : '';
			if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $repo ) ) {
				continue;
			}
			$pin = trim( (string) ( $entry['pin'] ?? '' ) );

			$plugins[ strtolower( $repo ) ] = array(
				'repo'       => $repo,
				'version'    => sanitize_text_field( (string) ( $entry['version'] ?? '' ) ),
				'active'     => ! empty( $entry['active'] ),
				'autoupdate' => ! empty( $entry['autoupdate'] ),
				'pin'        => Policy::is_valid_pin( $pin ) ? $pin : '',
			);
		}

		$mu_files = array();
		foreach ( (array) ( $data['mu'] ?? array() ) as $entry ) {
			$file = is_array( $entry ) ? trim( (string) ( $entry['file'] ?? '' ) ) : '';
			if ( ! MU_Plugins::is_valid_file_name( $file ) ) {
				continue;
			}
			$mu_files[ $file ] = array(
				'file'       => $file,
				'autoupdate' => ! empty( $entry['autoupdate'] ),
			);
		}

		return array(
			'format'     => self::FORMAT,
			'version'    => 1,
			'plugin_hub' => sanitize_text_field( (string) ( $data['plugin_hub'] ?? '' ) ),
			'site'       => sanitize_text_field( (string) ( $data['site'] ?? '' ) ),
			'exported'   => sanitize_text_field( (string) ( $data['exported'] ?? '' ) ),
			'plugins'    => array_values( $plugins ),
			'mu'         => array_values( $mu_files ),
			'settings'   => Settings::sanitize( is_array( $data['settings'] ?? null ) ? $data['settings'] : array() ),
		);
	}

	/**
	 * Apply a validated document: settings, auto-update choices and pins.
	 *
	 * @since  1.8.0
	 * @param  array      $data    Document from validate().
	 * @param  API        $api     API instance.
	 * @param  MU_Plugins $mu      MU plugin manager.
	 * @param  array      $options Optional. `skip_settings` leaves Plugin Hub settings untouched.
	 * @return array Keys: missing, mu_missing, applied, skipped.
	 */
	public static function apply( array $data, API $api, MU_Plugins $mu, array $options = array() ) {
		$result = array(
			'missing'    => array(),
			'mu_missing' => array(),
			'applied'    => array(
				'settings'   => 0,
				'autoupdate' => 0,
				'pins'       => 0,
				'mu'         => 0,
			),
			'skipped'    => array(),
		);

		if ( empty( $options['skip_settings'] ) && ! empty( $data['settings'] ) ) {
			Settings::save( $data['settings'] );
			$result['applied']['settings'] = 1;
		}

		$catalog = array();
		foreach ( $api->get_cached_repos() as $repo ) {
			if ( ! empty( $repo['name'] ) ) {
				$catalog[ strtolower( $repo['name'] ) ] = $repo;
			}
		}

		$autoupdate = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$autoupdate = is_array( $autoupdate ) ? $autoupdate : array();

		foreach ( (array) ( $data['plugins'] ?? array() ) as $entry ) {
			$key = strtolower( $entry['repo'] );
			if ( ! isset( $catalog[ $key ] ) ) {
				$result['skipped'][] = $entry['repo'];
				continue;
			}

			$name = $catalog[ $key ]['name'];

			$autoupdate = array_values( array_diff( $autoupdate, array( $name ) ) );
			if ( ! empty( $entry['autoupdate'] ) ) {
				$autoupdate[] = $name;
				++$result['applied']['autoupdate'];
			}

			if ( true === $api->set_pin( $name, (string) $entry['pin'] ) && '' !== $entry['pin'] ) {
				++$result['applied']['pins'];
			}

			if ( ! $api->is_plugin_installed( $name ) && ! empty( $catalog[ $key ]['available'] ) ) {
				$result['missing'][] = array(
					'repo'    => $name,
					'version' => $catalog[ $key ]['version'],
					'active'  => ! empty( $entry['active'] ),
				);
			}
		}
		update_option( 'plugin_hub_autoupdate_plugins', array_values( array_unique( $autoupdate ) ) );

		$mu_catalog   = $mu->get_catalog();
		$mu_installed = array_column( $mu->get_installed( false ), 'file' );
		$mu_list      = get_option( MU_Plugins::AUTOUPDATE_OPTION, array() );
		$mu_list      = is_array( $mu_list ) ? $mu_list : array();

		foreach ( (array) ( $data['mu'] ?? array() ) as $entry ) {
			if ( ! isset( $mu_catalog[ $entry['file'] ] ) ) {
				$result['skipped'][] = $entry['file'];
				continue;
			}

			$mu_list = array_values( array_diff( $mu_list, array( $entry['file'] ) ) );
			if ( ! empty( $entry['autoupdate'] ) ) {
				$mu_list[] = $entry['file'];
				++$result['applied']['mu'];
			}

			if ( ! in_array( $entry['file'], $mu_installed, true ) ) {
				$result['mu_missing'][] = $entry['file'];
			}
		}
		update_option( MU_Plugins::AUTOUPDATE_OPTION, array_values( array_unique( $mu_list ) ), false );

		$api->log_activity( 'import', 'config', '' );

		return $result;
	}
}
