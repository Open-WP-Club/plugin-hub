<?php
/**
 * Pure update-policy helpers.
 *
 * Nothing in this class calls WordPress, so every rule can be unit tested.
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
 * Policy class.
 *
 * Version pins, maintenance windows, health checks, plugin dependencies and
 * activity-log helpers.
 *
 * @since 1.8.0
 */
class Policy {

	/**
	 * Check that a pin string is empty or a supported constraint.
	 *
	 * Supported: '' (no pin), an exact version such as 1.2.3, "1.x" or "1.2.x".
	 *
	 * @since  1.8.0
	 * @param  string $pin Pin string.
	 * @return bool
	 */
	public static function is_valid_pin( $pin ) {
		if ( '' === $pin ) {
			return true;
		}

		return (bool) preg_match( '/^(?:\d+\.x|\d+\.\d+\.x|\d+(?:\.\d+){1,3}(?:[-_.][0-9A-Za-z][0-9A-Za-z._-]*)?)$/D', $pin );
	}

	/**
	 * Check whether a pin allows installing a version.
	 *
	 * @since  1.8.0
	 * @param  string $pin     Pin string.
	 * @param  string $version Candidate version.
	 * @return bool
	 */
	public static function pin_allows( $pin, $version ) {
		if ( '' === $pin ) {
			return true;
		}

		// A corrupted pin is treated as a block rather than silently ignored.
		if ( ! self::is_valid_pin( $pin ) ) {
			return false;
		}

		if ( '.x' === substr( $pin, -2 ) ) {
			$prefix = explode( '.', substr( $pin, 0, -2 ) );
			$parts  = explode( '.', $version );

			foreach ( $prefix as $index => $part ) {
				if ( ! isset( $parts[ $index ] ) || (int) $part !== (int) $parts[ $index ] ) {
					return false;
				}
			}

			return true;
		}

		return 0 === version_compare( $pin, $version );
	}

	/**
	 * Check whether the maintenance window is open.
	 *
	 * The window is [start, end) in hours. When start is greater than end it
	 * wraps midnight; when both are equal the whole day is open. The weekday is
	 * the day the hour falls on.
	 *
	 * @since  1.8.0
	 * @param  int[] $days       Allowed weekdays, 0 (Sunday) to 6.
	 * @param  int   $start_hour Window start hour.
	 * @param  int   $end_hour   Window end hour.
	 * @param  int   $weekday    Current weekday.
	 * @param  int   $hour       Current hour.
	 * @return bool
	 */
	public static function window_open( array $days, $start_hour, $end_hour, $weekday, $hour ) {
		if ( ! in_array( $weekday, array_map( 'intval', $days ), true ) ) {
			return false;
		}

		if ( $start_hour === $end_hour ) {
			return true;
		}

		if ( $start_hour < $end_hour ) {
			return $hour >= $start_hour && $hour < $end_hour;
		}

		return $hour >= $start_hour || $hour < $end_hour;
	}

	/**
	 * Decide whether a loopback response looks healthy.
	 *
	 * @since  1.8.0
	 * @param  int    $code HTTP status code, 0 when the request failed.
	 * @param  string $body Response body.
	 * @return bool
	 */
	public static function is_healthy_response( $code, $body ) {
		if ( $code < 200 || $code >= 500 ) {
			return false;
		}

		return false === stripos( $body, 'There has been a critical error' ) && false === stripos( $body, 'Fatal error' );
	}

	/**
	 * Check whether a release is old enough for automatic installation.
	 *
	 * @since  1.8.0
	 * @param  int $published_ts Release timestamp, 0 when unknown.
	 * @param  int $delay_days   Required age in days.
	 * @param  int $now          Current timestamp.
	 * @return bool
	 */
	public static function release_old_enough( $published_ts, $delay_days, $now ) {
		if ( $delay_days <= 0 ) {
			return true;
		}

		if ( $published_ts <= 0 ) {
			return false;
		}

		return ( $now - $published_ts ) >= $delay_days * 86400;
	}

	/**
	 * Split a "Requires Plugins" header value into slugs.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  mixed $value Header value.
	 * @return string[]
	 */
	private static function parse_requirements( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn ( $item ) => '' !== $item ) );
	}

	/**
	 * Find installed plugins that require a plugin.
	 *
	 * @since  1.8.0
	 * @param  string $slug    Plugin slug (directory name).
	 * @param  array  $plugins get_plugins() result.
	 * @return string[]        Plugin files that list the slug in RequiresPlugins.
	 */
	public static function dependents_of( $slug, array $plugins ) {
		$files = array();

		foreach ( $plugins as $file => $data ) {
			if ( dirname( (string) $file ) === $slug || ! is_array( $data ) ) {
				continue;
			}
			if ( in_array( $slug, self::parse_requirements( $data['RequiresPlugins'] ?? '' ), true ) ) {
				$files[] = (string) $file;
			}
		}

		return $files;
	}

	/**
	 * List required plugins that are not installed.
	 *
	 * @since  1.8.0
	 * @param  array    $plugin_data     Plugin header data.
	 * @param  string[] $installed_slugs Installed plugin slugs.
	 * @return string[]
	 */
	public static function missing_requirements( array $plugin_data, array $installed_slugs ) {
		return array_values( array_diff( self::parse_requirements( $plugin_data['RequiresPlugins'] ?? '' ), $installed_slugs ) );
	}

	/**
	 * Add a deterministic id to every activity entry.
	 *
	 * Identical entries get an occurrence suffix so each id stays unique.
	 *
	 * @since  1.8.0
	 * @param  array $log Activity entries.
	 * @return array      Entries with an "id" key, same order.
	 */
	public static function activity_ids( array $log ) {
		$seen = array();
		$out  = array();

		foreach ( $log as $key => $entry ) {
			unset( $entry['id'] );
			$hash = md5( (string) wp_json_encode( $entry ) );
			$n    = $seen[ $hash ] ?? 0;

			$seen[ $hash ] = $n + 1;
			$entry['id']   = $hash . '-' . $n;
			$out[ $key ]   = $entry;
		}

		return $out;
	}

	/**
	 * Filter activity entries.
	 *
	 * Dates are Y-m-d and compared in UTC.
	 *
	 * @since  1.8.0
	 * @param  array $entries Activity entries.
	 * @param  array $f       Filters: action, user, search, from, to.
	 * @return array
	 */
	public static function filter_activity( array $entries, array $f ) {
		$action = (string) ( $f['action'] ?? '' );
		$user   = (string) ( $f['user'] ?? '' );
		$search = strtolower( trim( (string) ( $f['search'] ?? '' ) ) );
		$from   = ! empty( $f['from'] ) ? strtotime( $f['from'] . ' 00:00:00 UTC' ) : false;
		$to     = ! empty( $f['to'] ) ? strtotime( $f['to'] . ' 23:59:59 UTC' ) : false;

		$result = array();
		foreach ( $entries as $key => $entry ) {
			$time = (int) ( $entry['time'] ?? 0 );

			if ( '' !== $action && ( $entry['action'] ?? '' ) !== $action ) {
				continue;
			}
			if ( '' !== $user && ( $entry['user'] ?? '' ) !== $user ) {
				continue;
			}
			if ( false !== $from && $time < $from ) {
				continue;
			}
			if ( false !== $to && $time > $to ) {
				continue;
			}
			if ( '' !== $search ) {
				$haystack = strtolower( implode( ' ', array( $entry['plugin'] ?? '', $entry['version'] ?? '', $entry['user'] ?? '', $entry['action'] ?? '' ) ) );
				if ( false === strpos( $haystack, $search ) ) {
					continue;
				}
			}

			$result[ $key ] = $entry;
		}

		return $result;
	}
}
