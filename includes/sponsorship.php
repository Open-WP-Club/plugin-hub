<?php
/**
 * Validated supporter recognition from the existing first-party catalog.
 *
 * @package PluginHub
 */

namespace PluginHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates and selects public supporter credits without network requests. */
class Sponsorship {
	/**
	 * Accept only plain text, HTTPS links and embedded PNG logos.
	 *
	 * @param mixed $feed Catalog sponsorship data.
	 * @return array|null Null means invalid; an empty array means no supporters.
	 */
	public static function validate( $feed ) {
		if ( ! is_array( $feed ) || 1 !== ( $feed['schemaVersion'] ?? null ) || 3 !== ( $feed['maxPositions'] ?? null ) || ! isset( $feed['supporters'] ) || ! is_array( $feed['supporters'] ) || count( $feed['supporters'] ) > 100 ) {
			return null;
		}
		$entries = array();
		$ids     = array();
		foreach ( $feed['supporters'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				return null;
			}
			foreach ( array( 'id', 'name', 'url', 'description', 'logoData', 'startsAt' ) as $key ) {
				if ( ! isset( $entry[ $key ] ) || ! is_string( $entry[ $key ] ) ) {
					return null;
				}
			}
			if ( ! preg_match( '/^[a-z0-9-]+$/D', $entry['id'] ) || isset( $ids[ $entry['id'] ] ) || '' === trim( $entry['name'] ) ) {
				return null;
			}
			$ids[ $entry['id'] ] = true;
			foreach ( array( 'name', 'description' ) as $key ) {
				if ( strlen( $entry[ $key ] ) > 960 || preg_match( '/[<>\r\n]/', $entry[ $key ] ) ) {
					return null;
				}
			}
			$url = wp_parse_url( $entry['url'] );
			if ( ! filter_var( $entry['url'], FILTER_VALIDATE_URL ) || ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? '' ) || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) ) {
				return null;
			}
			if ( ! isset( $entry['active'], $entry['priority'] ) || ! is_bool( $entry['active'] ) || ! is_numeric( $entry['priority'] ) || ! is_finite( (float) $entry['priority'] ) || ! array_key_exists( 'endsAt', $entry ) ) {
				return null;
			}
			foreach ( array( $entry['startsAt'], $entry['endsAt'] ) as $date ) {
				if ( null !== $date && ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $date ) || false === strtotime( $date ) || gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $date ) ) !== $date ) ) {
					return null;
				}
			}
			if ( '' === $entry['startsAt'] || ( null !== $entry['endsAt'] && strtotime( $entry['endsAt'] ) <= strtotime( $entry['startsAt'] ) ) ) {
				return null;
			}
			if ( strlen( $entry['logoData'] ) > 65536 || ! preg_match( '#^data:image/png;base64,[A-Za-z0-9+/=]+$#D', $entry['logoData'] ) ) {
				return null;
			}
			$png = base64_decode( substr( $entry['logoData'], 22 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode an embedded PNG, never executable content.
			if ( false === $png || substr( $png, 0, 8 ) !== "\x89PNG\r\n\x1a\n" ) {
				return null;
			}
			$entries[] = array_intersect_key( $entry, array_flip( array( 'id', 'name', 'url', 'description', 'logoData', 'active', 'priority', 'startsAt', 'endsAt' ) ) );
		}
		return $entries;
	}

	/**
	 * Expiration is checked locally, even when the catalog is offline.
	 *
	 * @param array $entries Validated supporters.
	 * @param int   $now UTC timestamp.
	 * @return array
	 */
	public static function active( $entries, $now ) {
		$entries = array_filter(
			$entries,
			static function ( $entry ) use ( $now ) {
				return $entry['active'] && strtotime( $entry['startsAt'] ) <= $now && ( null === $entry['endsAt'] || strtotime( $entry['endsAt'] ) > $now );
			}
		);
		usort(
			$entries,
			static function ( $a, $b ) {
				$order = $b['priority'] <=> $a['priority'];
				return 0 !== $order ? $order : strcmp( $a['id'], $b['id'] );
			}
		);
		return array_slice( $entries, 0, 3 );
	}
}
