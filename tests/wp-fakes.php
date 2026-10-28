<?php
/**
 * Fakes of the WordPress admin functions and classes the plugin calls for
 * plugin management and upgrades. State lives in $GLOBALS['hub_test_*'].
 *
 * @package PluginHub
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Squiz.Commenting,Universal.NamingConventions.NoReservedKeywordParameterNames

namespace {
	if ( ! function_exists( 'get_plugins' ) ) {
		/** $GLOBALS['hub_test_plugins']: plugin file => header data. */
		function get_plugins() {
			return $GLOBALS['hub_test_plugins'] ?? array();
		}
		function get_plugin_data( $path ) {
			$file = ltrim( str_replace( rtrim( WP_PLUGIN_DIR, '/' ), '', $path ), '/' );
			return array( 'Version' => $GLOBALS['hub_test_plugins'][ $file ]['Version'] ?? '' );
		}
		function is_plugin_active( $file ) {
			return in_array( $file, $GLOBALS['hub_test_active_plugins'] ?? array(), true );
		}
		function activate_plugin( $file, $redirect = '', $network = false, $silent = false ) {
			$GLOBALS['hub_test_calls'][] = 'activate_plugin:' . $file . ( $network ? ':network' : '' );
			if ( isset( $GLOBALS['hub_test_activate_result'] ) ) {
				return $GLOBALS['hub_test_activate_result'];
			}
			$GLOBALS['hub_test_active_plugins'][] = $file;
			return null;
		}
		function deactivate_plugins( $files, $silent = false, $network = false ) {
			$GLOBALS['hub_test_calls'][]          = 'deactivate_plugins:' . implode( ',', (array) $files ) . ( $network ? ':network' : '' );
			$GLOBALS['hub_test_active_plugins'] = array_values( array_diff( $GLOBALS['hub_test_active_plugins'] ?? array(), (array) $files ) );
		}
		function delete_plugins( $files ) {
			$GLOBALS['hub_test_calls'][] = 'delete_plugins:' . implode( ',', $files );
			$result                      = $GLOBALS['hub_test_delete_result'] ?? true;
			if ( true === $result ) {
				foreach ( $files as $file ) {
					unset( $GLOBALS['hub_test_plugins'][ $file ] );
				}
			}
			return $result;
		}
		function wp_clean_plugins_cache( $clear_update_cache = true ) {
			$GLOBALS['hub_test_calls'][] = 'wp_clean_plugins_cache';
		}
		function set_site_transient( $key, $value, $expiration = 0 ) {
			$GLOBALS['hub_test_site_transients'][ $key ] = $value;
			return true;
		}
		function delete_site_transient( $key ) {
			unset( $GLOBALS['hub_test_site_transients'][ $key ] );
			return true;
		}
	}

	if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
		class Automatic_Upgrader_Skin {}
		class WP_Ajax_Upgrader_Skin {}

		/**
		 * Scripted upgrader. $GLOBALS['hub_test_upgrader'] holds the outcome:
		 * 'install' / 'upgrade' results and the 'plugin_info' to report.
		 */
		class Plugin_Upgrader {
			public $skin;

			public function __construct( $skin = null ) {
				$this->skin = $skin;
			}

			public function install( $package ) {
				$GLOBALS['hub_test_calls'][] = 'install:' . $package;
				$script                      = $GLOBALS['hub_test_upgrader'] ?? array();
				if ( isset( $script['on_install'] ) ) {
					( $script['on_install'] )( $package );
				}
				return $script['install'] ?? true;
			}

			public function upgrade( $plugin ) {
				$GLOBALS['hub_test_calls'][] = 'upgrade:' . $plugin;
				$script                      = $GLOBALS['hub_test_upgrader'] ?? array();
				if ( isset( $script['on_upgrade'] ) ) {
					( $script['on_upgrade'] )( $plugin );
				}
				return $script['upgrade'] ?? true;
			}

			public function plugin_info() {
				return ( $GLOBALS['hub_test_upgrader'] ?? array() )['plugin_info'] ?? false;
			}
		}
	}
}
