<?php
/**
 * Minimal WP_Filesystem layer shared by tests that restore backups.
 *
 * @package PluginHub
 */

namespace {
	// Minimal filesystem layer for Backups::restore().
	if ( ! class_exists( 'Hub_Test_Filesystem' ) ) {
		class Hub_Test_Filesystem {
			public function wp_plugins_dir() {
				return WP_PLUGIN_DIR . '/';
			}
			public function exists( $path ) {
				return file_exists( $path );
			}
			public function is_dir( $path ) {
				return is_dir( $path );
			}
			public function move( $from, $to, $overwrite = false ) {
				return rename( $from, $to );
			}
			public function delete( $path, $recursive = false ) {
				return hub_test_rmtree( $path );
			}
		}
	}
	if ( ! function_exists( 'hub_test_rmtree' ) ) {
		function hub_test_rmtree( $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				return unlink( $path );
			}
			if ( is_dir( $path ) ) {
				foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $item ) {
					hub_test_rmtree( $path . '/' . $item );
				}
				return rmdir( $path );
			}
			return true;
		}
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		function WP_Filesystem() {
			$GLOBALS['wp_filesystem'] = new Hub_Test_Filesystem();
			return true;
		}
	}
	if ( ! function_exists( 'unzip_file' ) ) {
		function unzip_file( $file, $to ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $file ) ) {
				return new WP_Error( 'unzip_failed', 'Could not open the archive.' );
			}
			$zip->extractTo( $to );
			$zip->close();
			return true;
		}
	}
}
