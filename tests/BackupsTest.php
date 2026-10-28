<?php
/**
 * Tests plugin zip backups: create, list, prune, restore and delete.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';

	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-plugins-' . getmypid() );
	}
	if ( ! function_exists( __NAMESPACE__ . '\\size_format' ) ) {
		function size_format( $bytes, $decimals = 0 ) {
			return $bytes . ' B';
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\untrailingslashit' ) ) {
		function untrailingslashit( $value ) {
			return rtrim( $value, '/\\' );
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
}

namespace {
	require_once __DIR__ . '/fs-stubs.php';
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\TestCase;
	use PluginHub\Backups;

	final class BackupsTest extends TestCase {

		private string $uploads;

		protected function setUp(): void {
			if ( ! class_exists( 'ZipArchive' ) ) {
				$this->markTestSkipped( 'The zip extension is not available.' );
			}
			$this->uploads               = sys_get_temp_dir() . '/plugin-hub-bk-uploads-' . getmypid();
			$GLOBALS['hub_test_uploads'] = $this->uploads;
			$GLOBALS['hub_test_options'] = array();
			\hub_test_rmtree( $this->uploads );
			\hub_test_rmtree( WP_PLUGIN_DIR );
			mkdir( WP_PLUGIN_DIR, 0755, true );
		}

		protected function tearDown(): void {
			\hub_test_rmtree( $this->uploads );
			\hub_test_rmtree( WP_PLUGIN_DIR );
		}

		private function make_plugin( string $repo = 'demo-plugin', string $version = '1.2.3' ): void {
			mkdir( WP_PLUGIN_DIR . '/' . $repo . '/inc', 0755, true );
			file_put_contents( WP_PLUGIN_DIR . '/' . $repo . '/' . $repo . '.php', "<?php\n/**\n * Plugin Name: Demo\n * Version: {$version}\n */\n" );
			file_put_contents( WP_PLUGIN_DIR . '/' . $repo . '/inc/a.txt', 'original' );
		}

		public function test_create_lists_and_reads_version(): void {
			$this->make_plugin();
			$id = Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );

			$this->assertIsString( $id );
			$this->assertMatchesRegularExpression( '/^demo-plugin__1\.2\.3_\d+\.zip$/', $id );

			$rows = Backups::all();
			$this->assertCount( 1, $rows );
			$this->assertSame( 'demo-plugin', $rows[0]['repo'] );
			$this->assertSame( '1.2.3', $rows[0]['version'] );
			$this->assertGreaterThan( 0, $rows[0]['size'] );
			$this->assertSame( $id, Backups::latest_for( 'demo-plugin' )['id'] );
			$this->assertNull( Backups::latest_for( 'other' ) );
		}

		public function test_directory_is_guarded(): void {
			$this->make_plugin();
			Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );

			$this->assertFileExists( Backups::get_dir() . '/index.php' );
			$this->assertStringContainsString( 'Deny from all', (string) file_get_contents( Backups::get_dir() . '/.htaccess' ) );
		}

		public function test_create_returns_false_when_disabled(): void {
			$this->make_plugin();
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'backup_enabled' => false );

			$this->assertFalse( Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' ) );
			$this->assertSame( array(), Backups::all() );
		}

		public function test_create_rejects_mismatched_plugin(): void {
			$this->make_plugin();

			$this->assertInstanceOf( \WP_Error::class, Backups::create( '../evil', '../evil/x.php' ) );
			$this->assertInstanceOf( \WP_Error::class, Backups::create( 'demo-plugin', 'other/other.php' ) );
		}

		public function test_restore_replaces_directory_and_removes_new_files(): void {
			$this->make_plugin();
			$id = Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );

			file_put_contents( WP_PLUGIN_DIR . '/demo-plugin/inc/a.txt', 'changed' );
			file_put_contents( WP_PLUGIN_DIR . '/demo-plugin/new.txt', 'new' );

			$this->assertTrue( Backups::restore( $id ) );
			$this->assertSame( 'original', file_get_contents( WP_PLUGIN_DIR . '/demo-plugin/inc/a.txt' ) );
			$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/demo-plugin/new.txt' );
			$this->assertSame( array( 'demo-plugin' ), array_values( array_diff( scandir( WP_PLUGIN_DIR ), array( '.', '..' ) ) ) );
		}

		public function test_restore_rejects_unknown_and_malformed_ids(): void {
			$this->assertInstanceOf( \WP_Error::class, Backups::restore( 'demo-plugin__1.0_1.zip' ) );
			$this->assertInstanceOf( \WP_Error::class, Backups::restore( '../../etc__passwd.zip' ) );
			$this->assertInstanceOf( \WP_Error::class, Backups::restore( '' ) );
		}

		public function test_restore_keeps_current_plugin_when_extraction_fails(): void {
			$this->make_plugin();
			$id = Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );
			file_put_contents( WP_PLUGIN_DIR . '/demo-plugin/inc/a.txt', 'changed' );

			// Replace the archive with one that has an unexpected top-level folder.
			$path = Backups::latest_for( 'demo-plugin' )['path'];
			unlink( $path );
			$zip = new \ZipArchive();
			$zip->open( $path, \ZipArchive::CREATE );
			$zip->addFromString( 'other/file.php', 'x' );
			$zip->close();

			$this->assertInstanceOf( \WP_Error::class, Backups::restore( $id ) );
			$this->assertSame( 'changed', file_get_contents( WP_PLUGIN_DIR . '/demo-plugin/inc/a.txt' ) );
		}

		public function test_delete_removes_backup_and_empty_folder(): void {
			$this->make_plugin();
			$id = Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );

			$this->assertSame( 0, Backups::delete( array( 'nope__1.0_1.zip' ) ) );
			$this->assertSame( 1, Backups::delete( array( $id ) ) );
			$this->assertSame( array(), Backups::all() );
			$this->assertDirectoryDoesNotExist( Backups::get_dir() . '/demo-plugin' );
		}

		public function test_prune_keeps_newest_per_plugin_and_always_one(): void {
			$this->make_plugin();
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array(
				'backup_keep' => 2,
				'backup_days' => 0,
			);
			for ( $i = 0; $i < 4; $i++ ) {
				Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );
			}
			$this->assertCount( 2, Backups::all() );

			// Age every archive beyond the retention window: the newest one survives.
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array(
				'backup_keep' => 5,
				'backup_days' => 1,
			);
			$dir = Backups::get_dir() . '/demo-plugin';
			foreach ( glob( $dir . '/*.zip' ) as $index => $file ) {
				rename( $file, $dir . '/1.2.3_' . ( time() - ( 10 * DAY_IN_SECONDS ) - $index ) . '.zip' );
			}
			$this->assertSame( 1, Backups::prune() );
			$this->assertCount( 1, Backups::all() );
		}

		public function test_usage_counts_plugin_and_mu_backups(): void {
			$this->make_plugin();
			Backups::create( 'demo-plugin', 'demo-plugin/demo-plugin.php' );
			$mu = dirname( Backups::get_dir() ) . '/plugin-hub-mu-backups';
			mkdir( $mu, 0755, true );
			file_put_contents( $mu . '/x.prev.php', str_repeat( 'a', 100 ) );

			$usage = Backups::usage();
			$this->assertSame( 1, $usage['count'] );
			$this->assertSame( 100, $usage['mu'] );
			$this->assertSame( $usage['plugins'] + 100, $usage['total'] );
			$this->assertSame( '100 B', Backups::format_size( 100 ) );
		}
	}
}
