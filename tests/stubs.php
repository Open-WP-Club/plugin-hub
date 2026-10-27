<?php
/**
 * Shared WordPress function stubs for tests that exercise MU_Plugins and API.
 *
 * Every stub is guarded so test files can require this file in any order.
 * State lives in $GLOBALS['hub_test_*'] so each test can reset it.
 *
 * @package PluginHub
 */

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
		function get_option( $key, $default = false ) {
			return $GLOBALS['hub_test_options'][ $key ] ?? $default;
		}
		function update_option( $key, $value, $autoload = null ) {
			$GLOBALS['hub_test_options'][ $key ] = $value;
			return true;
		}
		function get_transient( $key ) {
			return $GLOBALS['hub_test_transients'][ $key ] ?? false;
		}
		function delete_transient( $key ) {
			unset( $GLOBALS['hub_test_transients'][ $key ] );
			return true;
		}
		function set_transient( $key, $value, $expiration = 0 ) {
			$GLOBALS['hub_test_transients'][ $key ] = $value;
			return true;
		}
		/**
		 * Returns $GLOBALS['hub_test_routes'][ $url ] when present (a response
		 * array or a WP_Error), otherwise the single $GLOBALS['hub_test_response'].
		 */
		function wp_safe_remote_get( $url, $args = array() ) {
			$GLOBALS['hub_test_requests'][] = $url;
			if ( isset( $GLOBALS['hub_test_routes'] ) && array_key_exists( $url, $GLOBALS['hub_test_routes'] ) ) {
				return $GLOBALS['hub_test_routes'][ $url ];
			}
			return $GLOBALS['hub_test_response'] ?? array( 'status' => 404, 'body' => '' );
		}
		function wp_remote_retrieve_response_code( $response ) {
			return $response['status'];
		}
		function wp_remote_retrieve_body( $response ) {
			return $response['body'];
		}
		function wp_remote_retrieve_header( $response, $header ) {
			return $response['headers'][ $header ] ?? '';
		}
		function sanitize_text_field( $value ) {
			return trim( strip_tags( $value ) );
		}
		function sanitize_textarea_field( $value ) {
			return trim( strip_tags( $value ) );
		}
		function esc_url_raw( $value, $protocols = null ) {
			return $value;
		}
		function home_url( $path = '' ) {
			return 'https://example.test' . $path;
		}
		function untrailingslashit( $value ) {
			return rtrim( $value, '/\\' );
		}
		function wp_normalize_path( $path ) {
			return str_replace( '\\', '/', $path );
		}
		function wp_upload_dir( $time = null, $create = true ) {
			return array( 'basedir' => $GLOBALS['hub_test_uploads'] ?? sys_get_temp_dir() . '/plugin-hub-uploads' );
		}
		function wp_mkdir_p( $dir ) {
			return is_dir( $dir ) || mkdir( $dir, 0755, true );
		}
		function wp_is_writable( $path ) {
			return is_writable( $path );
		}
		function wp_delete_file( $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		function wp_generate_password( $length = 12, $special = true ) {
			return substr( md5( uniqid( '', true ) ), 0, $length );
		}
		function wp_strip_all_tags( $text ) {
			return trim( strip_tags( $text ) );
		}
		function wp_get_current_user() {
			return new class() {
				public $display_name = 'Tester';
				public $user_login   = 'tester';
				public function exists() {
					return true;
				}
			};
		}
		function is_multisite() {
			return $GLOBALS['hub_test_multisite'] ?? false;
		}
		function is_super_admin() {
			return $GLOBALS['hub_test_super_admin'] ?? true;
		}
		function __( $text, $domain = 'default' ) {
			return $text;
		}
	}
}

namespace {
	// Global on purpose: MU_Plugins checks function_exists( 'wp_is_file_mod_allowed' ).
	if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
		function wp_is_file_mod_allowed( $context ) {
			return $GLOBALS['hub_test_file_mods'] ?? true;
		}
	}
}
