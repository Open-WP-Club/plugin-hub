<?php
/**
 * Minimal WP-CLI declarations so the CLI command class can be analysed without WP-CLI installed.
 *
 * @package PluginHub
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Squiz.Commenting,Universal.NamingConventions.NoReservedKeywordParameterNames

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			public static function add_command( $name, $command, $args = array() ) {}
			public static function success( $message ) {}
			public static function error( $message, $halt = true ) {}
			public static function warning( $message ) {}
			public static function log( $message ) {}
			public static function line( $message = '' ) {}
			public static function confirm( $question, $assoc_args = array() ) {}
		}
	}
}

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {}
}
