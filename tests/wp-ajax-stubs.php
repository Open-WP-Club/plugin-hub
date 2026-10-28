<?php
/**
 * Stubs for the AJAX request helpers.
 *
 * wp_send_json_*() and wp_die() throw Hub_Test_Halt, so a test can read what a
 * handler answered; check_ajax_referer() fails when
 * $GLOBALS['hub_test_nonce_ok'] is false; current_user_can() consults
 * $GLOBALS['hub_test_caps'] (unset means every capability).
 *
 * @package PluginHub
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Squiz.Commenting

namespace {
	// map_deep() resolves its callback by name in the global namespace.
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
	}
	if ( ! class_exists( 'Hub_Test_Halt' ) ) {
		class Hub_Test_Halt extends \RuntimeException {
			/** @var string json_success|json_error|die|nonce */
			public $kind;
			public $data;

			public function __construct( $kind, $data = null ) {
				parent::__construct( $kind );
				$this->kind = $kind;
				$this->data = $data;
			}
		}
	}
}

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\check_ajax_referer' ) ) {
		function check_ajax_referer( $action = -1, $arg = false, $die = true ) {
			if ( ! ( $GLOBALS['hub_test_nonce_ok'] ?? true ) ) {
				throw new \Hub_Test_Halt( 'nonce' );
			}
			return 1;
		}
		function check_admin_referer( $action = -1, $arg = '_wpnonce' ) {
			return check_ajax_referer( $action );
		}
		function wp_send_json_success( $data = null ) {
			throw new \Hub_Test_Halt( 'json_success', $data );
		}
		function wp_send_json_error( $data = null ) {
			throw new \Hub_Test_Halt( 'json_error', $data );
		}
		function wp_die( $message = '' ) {
			throw new \Hub_Test_Halt( 'die', $message );
		}
		function map_deep( $value, $callback ) {
			if ( is_array( $value ) ) {
				return array_map( static fn ( $item ) => map_deep( $item, $callback ), $value );
			}
			return is_scalar( $value ) ? $callback( $value ) : $value;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
		function current_user_can( $capability ) {
			return ! isset( $GLOBALS['hub_test_caps'] ) || in_array( $capability, $GLOBALS['hub_test_caps'], true );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}
}
