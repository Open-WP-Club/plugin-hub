<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads a minimal set of WordPress constants and function stubs so plugin
 * classes can be unit tested without a full WordPress install. Only the
 * functions actually exercised by the test suite are stubbed here.
 *
 * @package PluginHub
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! defined( 'DAY_IN_SECONDS' ) ) {
		define( 'DAY_IN_SECONDS', 86400 );
	}
	if ( ! defined( 'PLUGIN_HUB_VERSION' ) ) {
		define( 'PLUGIN_HUB_VERSION', 'test' );
	}
	if ( ! defined( 'PLUGIN_HUB_ORGANIZATION' ) ) {
		define( 'PLUGIN_HUB_ORGANIZATION', 'Open-WP-Club' );
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $name, $default = false ) {
			return $default;
		}
	}

	if ( ! function_exists( 'wp_parse_url' ) ) {
		function wp_parse_url( $url, $component = -1 ) {
			return parse_url( $url, $component );
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) {
			return $thing instanceof \WP_Error;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
		}
	}
}

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\get_github_token' ) ) {
		function get_github_token() {
			return '';
		}
	}

	require_once dirname( __DIR__ ) . '/includes/api.php';
}
