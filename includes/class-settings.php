<?php
/**
 * Plugin Hub settings.
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
 * Settings class.
 *
 * Every setting lives in one option, so it can be exported and imported.
 *
 * @since 1.8.0
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @since 1.8.0
	 * @var   string
	 */
	const OPTION = 'plugin_hub_settings';

	/**
	 * Default values.
	 *
	 * @since  1.8.0
	 * @return array
	 */
	public static function defaults() {
		return array(
			'notify_emails'     => '',
			'notify_mode'       => 'all',
			'webhook_url'       => '',
			'auto_rollback'     => true,
			'require_checksum'  => false,
			'backup_enabled'    => true,
			'backup_keep'       => 3,
			'backup_days'       => 30,
			'window_enabled'    => false,
			'window_days'       => array( 0, 1, 2, 3, 4, 5, 6 ),
			'window_start'      => 1,
			'window_end'        => 5,
			'update_delay_days' => 0,
			'log_days'          => 0,
			'log_max'           => 200,
		);
	}

	/**
	 * All settings, defaults merged with stored values.
	 *
	 * @since  1.8.0
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
	}

	/**
	 * Get one setting.
	 *
	 * @since  1.8.0
	 * @param  string $key Setting key.
	 * @return mixed       Null for an unknown key.
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Clamp an integer into a range.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  mixed $value Raw value.
	 * @param  int   $min   Minimum.
	 * @param  int   $max   Maximum.
	 * @return int
	 */
	private static function clamp( $value, $min, $max ) {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * Validate input. Only known keys that are present are returned.
	 *
	 * @since  1.8.0
	 * @param  array $input Raw input.
	 * @return array        Sanitized subset.
	 */
	public static function sanitize( array $input ) {
		$out = array();

		if ( array_key_exists( 'notify_emails', $input ) ) {
			$emails = preg_split( '/[\s,;]+/', (string) $input['notify_emails'], -1, PREG_SPLIT_NO_EMPTY );
			$emails = array_filter(
				(array) $emails,
				static function ( $email ) {
					return (bool) is_email( $email );
				}
			);

			$out['notify_emails'] = implode( ', ', array_unique( $emails ) );
		}

		if ( array_key_exists( 'notify_mode', $input ) ) {
			$out['notify_mode'] = 'failures' === $input['notify_mode'] ? 'failures' : 'all';
		}

		if ( array_key_exists( 'webhook_url', $input ) ) {
			$url = esc_url_raw( trim( (string) $input['webhook_url'] ), array( 'https' ) );

			$out['webhook_url'] = 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? $url : '';
		}

		foreach ( array( 'auto_rollback', 'require_checksum', 'backup_enabled', 'window_enabled' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		$ranges = array(
			'backup_keep'       => array( 1, 20 ),
			'backup_days'       => array( 0, 365 ),
			'window_start'      => array( 0, 23 ),
			'window_end'        => array( 0, 23 ),
			'update_delay_days' => array( 0, 60 ),
			'log_days'          => array( 0, 3650 ),
			'log_max'           => array( 50, 2000 ),
		);
		foreach ( $ranges as $key => $range ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = self::clamp( $input[ $key ], $range[0], $range[1] );
			}
		}

		if ( array_key_exists( 'window_days', $input ) ) {
			$days = array();
			foreach ( (array) $input['window_days'] as $day ) {
				if ( is_numeric( $day ) && (int) $day >= 0 && (int) $day <= 6 ) {
					$days[] = (int) $day;
				}
			}
			$days = array_values( array_unique( $days ) );
			sort( $days );

			// An empty window could never open, so fall back to every day.
			$out['window_days'] = $days ? $days : self::defaults()['window_days'];
		}

		return $out;
	}

	/**
	 * Sanitize input and merge it into the stored settings.
	 *
	 * @since  1.8.0
	 * @param  array $input Raw input.
	 * @return array        The complete saved settings.
	 */
	public static function save( array $input ) {
		$settings = array_merge( self::all(), self::sanitize( $input ) );
		update_option( self::OPTION, $settings, false );

		return $settings;
	}
}
