<?php
/**
 * Recording WP-CLI fake for unit tests.
 *
 * Messages are collected in WP_CLI::$messages. WP_CLI::error() throws, like the
 * real one halts the command, so tests can assert on the abort.
 *
 * @package PluginHub
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Squiz.Commenting,Universal.NamingConventions.NoReservedKeywordParameterNames

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/** @var array<int, array{0: string, 1: string}> */
			public static $messages = array();

			/** @var array<int, array{format: string, items: array, fields: array}> */
			public static $tables = array();

			public static function reset() {
				self::$messages = array();
				self::$tables   = array();
			}

			public static function texts( $type = null ) {
				$out = array();
				foreach ( self::$messages as $message ) {
					if ( null === $type || $type === $message[0] ) {
						$out[] = $message[1];
					}
				}
				return $out;
			}

			public static function success( $message ) {
				self::$messages[] = array( 'success', $message );
			}

			public static function warning( $message ) {
				self::$messages[] = array( 'warning', $message );
			}

			public static function log( $message ) {
				self::$messages[] = array( 'log', $message );
			}

			public static function line( $message = '' ) {
				self::$messages[] = array( 'line', $message );
			}

			public static function error( $message, $halt = true ) {
				self::$messages[] = array( 'error', $message );
				throw new \RuntimeException( 'WP_CLI::error: ' . $message );
			}

			public static function add_command( $name, $command, $args = array() ) {}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$tables[] = array(
				'format' => $format,
				'items'  => $items,
				'fields' => $fields,
			);
		}
	}
}
