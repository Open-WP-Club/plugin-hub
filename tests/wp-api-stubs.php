<?php
/**
 * Shared stubs for the HTTP, hook and formatting functions used by the API tests.
 *
 * Every function is guarded so any test can require this file first.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';

	if ( ! function_exists( __NAMESPACE__ . '\\get_site_transient' ) ) {
		function get_site_transient( $key ) {
			return $GLOBALS['hub_test_site_transients'][ $key ] ?? false;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_filter' ) ) {
		function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
			$GLOBALS['hub_test_filters'][ $hook ] = $callback;
			return true;
		}
		function remove_filter( $hook, $callback, $priority = 10 ) {
			$had = isset( $GLOBALS['hub_test_filters'][ $hook ] );
			unset( $GLOBALS['hub_test_filters'][ $hook ] );
			return $had;
		}
		function apply_filters( $hook, $value, ...$args ) {
			return $GLOBALS['hub_test_filter_values'][ $hook ] ?? $value;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\site_url' ) ) {
		function site_url( $path = '' ) {
			return 'https://example.test/' . ltrim( $path, '/' );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_query_arg' ) ) {
		function add_query_arg( $args, $value = '', $url = '' ) {
			if ( is_array( $args ) ) {
				$url = $value;
			} else {
				$args = array( $args => $value );
			}
			return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_date' ) ) {
		function wp_date( $format, $timestamp = null ) {
			return date( $format, $timestamp ?? ( $GLOBALS['hub_test_now'] ?? time() ) );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\_n' ) ) {
		function _n( $single, $plural, $number, $domain = 'default' ) {
			return 1 === $number ? $single : $plural;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\delete_option' ) ) {
		function delete_option( $key ) {
			unset( $GLOBALS['hub_test_options'][ $key ] );
			return true;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0 ) {
			return json_encode( $data, $flags );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_args' ) ) {
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\size_format' ) ) {
		function size_format( $bytes, $decimals = 0 ) {
			return $bytes . ' B';
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_email' ) ) {
		function is_email( $email ) {
			return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\get_bloginfo' ) ) {
		function get_bloginfo( $show = '' ) {
			return 'Test Site';
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\admin_url' ) ) {
		function admin_url( $path = '' ) {
			return 'https://example.test/wp-admin/' . $path;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_mail' ) ) {
		function wp_mail( $to, $subject, $body ) {
			$GLOBALS['hub_test_mail'][] = array( $to, $subject, $body );
			return true;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_safe_remote_post' ) ) {
		function wp_safe_remote_post( $url, $args = array() ) {
			return array( 'status' => 200, 'body' => '' );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\esc_html__' ) ) {
		function esc_html__( $text, $domain = 'default' ) {
			return $text;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_remote_get' ) ) {
		/**
		 * Routes: exact URL, then the URL without its query string, then
		 * $GLOBALS['hub_test_default_http'] (a healthy 200 page unless set).
		 */
		function wp_remote_get( $url, $args = array() ) {
			$GLOBALS['hub_test_requests'][] = $url;
			$base                           = preg_replace( '/\?.*$/', '', $url );
			foreach ( array( $url, $base ) as $key ) {
				if ( array_key_exists( $key, $GLOBALS['hub_test_routes'] ?? array() ) ) {
					return $GLOBALS['hub_test_routes'][ $key ];
				}
			}
			return $GLOBALS['hub_test_default_http'] ?? array( 'status' => 200, 'body' => '<html>ok</html>' );
		}
		function wp_safe_remote_head( $url, $args = array() ) {
			$GLOBALS['hub_test_heads'][] = $url;
			return $GLOBALS['hub_test_routes'][ $url ] ?? $GLOBALS['hub_test_default_http'] ?? array( 'status' => 404, 'body' => '' );
		}
		function wpautop( $text ) {
			return '<p>' . trim( $text ) . "</p>\n";
		}
		function wp_kses_post( $text ) {
			return preg_replace( '#<script.*?</script>#is', '', (string) $text );
		}
		function trailingslashit( $value ) {
			return rtrim( $value, '/\\' ) . '/';
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_github_token_managed_by_config' ) ) {
		function is_github_token_managed_by_config() {
			return $GLOBALS['hub_test_token_managed'] ?? false;
		}
	}
}
