<?php
/**
 * Tests the AJAX layer of PluginHub\MU_Plugins: guards, validation and messages.
 *
 * The install/update/rollback operations themselves are covered by
 * MuPluginsNetworkTest; here they are replaced by a scripted double.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/wp-api-stubs.php';
	require_once __DIR__ . '/wp-ajax-stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';

	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-mu-dir-' . getmypid() );
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
		function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
			$GLOBALS['hub_test_actions'][ $hook ] = $callback;
			return true;
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;
	use PluginHub\MU_Plugins;

	/**
	 * MU manager double: scripted operation results and a fixed catalog.
	 */
	class Mu_Ajax_Test_Manager extends MU_Plugins {
		public array $calls = array();
		/** @var array<string, mixed> Results by operation name. */
		public array $results = array();
		public array $catalog = array( 'one.php' => array( 'file' => 'one.php', 'category' => 'admin' ) );

		public function get_catalog( $force = false ) {
			return $this->catalog;
		}
		public function install( $file ) {
			$this->calls[] = "install:$file";
			return $this->results['install'] ?? '1.0.0';
		}
		public function update( $file, $log_action = 'mu_update' ) {
			$this->calls[] = "update:$file";
			return $this->results['update'] ?? '1.1.0';
		}
		public function rollback( $file ) {
			$this->calls[] = "rollback:$file";
			return $this->results['rollback'] ?? '0.9.0';
		}
		public function update_all( $log_action = 'mu_update' ) {
			$this->calls[] = 'update_all';
			return $this->results['update_all'] ?? array( 'updated' => array(), 'failed' => array() );
		}
	}

	final class MuPluginsAjaxTest extends TestCase {

		private Mu_Ajax_Test_Manager $mu;

		protected function setUp(): void {
			$GLOBALS['hub_test_options']    = array();
			$GLOBALS['hub_test_transients'] = array();
			$GLOBALS['hub_test_multisite']  = false;
			$GLOBALS['hub_test_file_mods']  = true;
			$GLOBALS['hub_test_actions']    = array();
			$_POST                          = array();
			$api                            = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			$this->mu                       = new Mu_Ajax_Test_Manager( $api );
		}

		protected function tearDown(): void {
			foreach ( array( 'hub_test_options', 'hub_test_transients', 'hub_test_multisite', 'hub_test_file_mods', 'hub_test_actions', 'hub_test_nonce_ok', 'hub_test_caps', 'hub_test_super_admin' ) as $key ) {
				unset( $GLOBALS[ $key ] );
			}
			$_POST = array();
		}

		private function ajax( string $method ): \Hub_Test_Halt {
			try {
				$this->mu->$method();
			} catch ( \Hub_Test_Halt $halt ) {
				return $halt;
			}
			$this->fail( 'The handler did not answer.' );
		}

		public static function handlers(): array {
			return array(
				'install'  => array( 'ajax_install', 'install_plugins' ),
				'update'   => array( 'ajax_update', 'update_plugins' ),
				'rollback' => array( 'ajax_rollback', 'update_plugins' ),
				'all'      => array( 'ajax_update_all', 'update_plugins' ),
				'autoupdate' => array( 'ajax_save_autoupdate', 'update_plugins' ),
			);
		}

		#[DataProvider( 'handlers' )]
		public function test_every_handler_checks_nonce_and_capability( string $method, string $capability ): void {
			$_POST['file']                = 'one.php';
			$GLOBALS['hub_test_nonce_ok'] = false;
			$this->assertSame( 'nonce', $this->ajax( $method )->kind );

			$GLOBALS['hub_test_nonce_ok'] = true;
			$GLOBALS['hub_test_caps']     = array( 'read' );
			$halt                         = $this->ajax( $method );
			$this->assertSame( array( 'json_error', 'You do not have permission to manage MU plugins.' ), array( $halt->kind, $halt->data ) );
			$this->assertSame( array(), $this->mu->calls );
		}

		public static function writing_handlers(): array {
			return array(
				'install'  => array( 'ajax_install' ),
				'update'   => array( 'ajax_update' ),
				'rollback' => array( 'ajax_rollback' ),
				'all'      => array( 'ajax_update_all' ),
			);
		}

		#[DataProvider( 'writing_handlers' )]
		public function test_writes_are_refused_when_file_changes_are_disabled( string $method ): void {
			$_POST['file']                 = 'one.php';
			$GLOBALS['hub_test_file_mods'] = false;
			$halt                          = $this->ajax( $method );
			$this->assertSame( array( 'json_error', 'File modifications are disabled on this site.' ), array( $halt->kind, $halt->data ) );
			$this->assertSame( array(), $this->mu->calls );
		}

		#[DataProvider( 'writing_handlers' )]
		public function test_writes_on_multisite_need_a_super_admin( string $method ): void {
			$_POST['file']                  = 'one.php';
			$GLOBALS['hub_test_multisite']  = true;
			$GLOBALS['hub_test_super_admin'] = false;
			$halt                           = $this->ajax( $method );
			$this->assertStringContainsString( 'Only network administrators', $halt->data );
			$this->assertSame( array(), $this->mu->calls );

			$GLOBALS['hub_test_super_admin'] = true;
			$this->assertSame( 'json_success', $this->ajax( $method )->kind );
		}

		public static function bad_files(): array {
			return array(
				'missing'   => array( null ),
				'traversal' => array( '../wp-config.php' ),
				'not php'   => array( 'one.txt' ),
				'slash'     => array( 'admin/one.php' ),
			);
		}

		#[DataProvider( 'bad_files' )]
		public function test_invalid_file_names_are_rejected( ?string $file ): void {
			if ( null !== $file ) {
				$_POST['file'] = $file;
			}
			foreach ( array( 'ajax_install', 'ajax_update', 'ajax_rollback', 'ajax_save_autoupdate' ) as $method ) {
				$halt = $this->ajax( $method );
				$this->assertSame( array( 'json_error', 'Invalid MU plugin information.' ), array( $halt->kind, $halt->data ), $method );
			}
			$this->assertSame( array(), $this->mu->calls );
		}

		public function test_install_update_and_rollback_report_the_version(): void {
			$_POST['file'] = 'one.php';

			$this->assertSame( 'one.php installed (version 1.0.0).', $this->ajax( 'ajax_install' )->data );
			$this->assertSame( 'one.php updated to version 1.1.0.', $this->ajax( 'ajax_update' )->data );
			$this->assertSame( 'one.php restored to version 0.9.0. Automatic updates were switched off for it.', $this->ajax( 'ajax_rollback' )->data );
			$this->assertSame( array( 'install:one.php', 'update:one.php', 'rollback:one.php' ), $this->mu->calls );
		}

		public function test_operation_errors_become_json_errors(): void {
			$_POST['file']               = 'one.php';
			$this->mu->results['install'] = new \WP_Error( 'x', 'Already installed' );
			$this->mu->results['update']  = new \WP_Error( 'x', 'Checksum mismatch' );
			$this->mu->results['rollback'] = new \WP_Error( 'x', 'No backup' );

			$this->assertSame( array( 'json_error', 'Already installed' ), array( $this->ajax( 'ajax_install' )->kind, $this->ajax( 'ajax_install' )->data ) );
			$this->assertSame( 'Checksum mismatch', $this->ajax( 'ajax_update' )->data );
			$this->assertSame( 'No backup', $this->ajax( 'ajax_rollback' )->data );
		}

		public function test_update_all_messages(): void {
			$halt = $this->ajax( 'ajax_update_all' );
			$this->assertSame( array( 'json_success', 'All MU plugins are up to date.' ), array( $halt->kind, $halt->data ) );

			$this->mu->results['update_all'] = array( 'updated' => array( 'a.php' => '1.0.0', 'b.php' => '2.0.0' ), 'failed' => array( 'c.php' => 'boom' ) );
			$halt                            = $this->ajax( 'ajax_update_all' );
			$this->assertSame( array( 'json_success', '2 MU plugin(s) updated. c.php: boom' ), array( $halt->kind, $halt->data ) );

			$this->mu->results['update_all'] = array( 'updated' => array(), 'failed' => array( 'c.php' => 'boom' ) );
			$halt                            = $this->ajax( 'ajax_update_all' );
			$this->assertSame( array( 'json_error', '0 MU plugin(s) updated. c.php: boom' ), array( $halt->kind, $halt->data ), 'Nothing succeeded: an error.' );

			$this->mu->results['update_all'] = new \WP_Error( 'x', 'Catalog unavailable' );
			$this->assertSame( array( 'json_error', 'Catalog unavailable' ), array( $this->ajax( 'ajax_update_all' )->kind, $this->ajax( 'ajax_update_all' )->data ) );
		}

		public function test_autoupdate_toggle_stores_the_list(): void {
			$_POST = array( 'file' => 'one.php', 'enabled' => 'true' );
			$this->assertSame( 'json_success', $this->ajax( 'ajax_save_autoupdate' )->kind );
			$this->assertSame( array( 'one.php' ), $GLOBALS['hub_test_options'][ MU_Plugins::AUTOUPDATE_OPTION ] );

			$_POST = array( 'file' => 'one.php', 'enabled' => '1' );
			$this->ajax( 'ajax_save_autoupdate' );
			$this->assertSame( array( 'one.php' ), $GLOBALS['hub_test_options'][ MU_Plugins::AUTOUPDATE_OPTION ], 'No duplicates.' );

			$_POST = array( 'file' => 'one.php', 'enabled' => 'false' );
			$this->ajax( 'ajax_save_autoupdate' );
			$this->assertSame( array(), $GLOBALS['hub_test_options'][ MU_Plugins::AUTOUPDATE_OPTION ] );
		}

		public function test_autoupdate_can_only_be_enabled_for_catalog_files(): void {
			$_POST = array( 'file' => 'ghost.php', 'enabled' => 'true' );
			$halt  = $this->ajax( 'ajax_save_autoupdate' );
			$this->assertSame( array( 'json_error', 'The requested MU plugin is not in the trusted catalog.' ), array( $halt->kind, $halt->data ) );

			$_POST = array( 'file' => 'ghost.php', 'enabled' => 'false' );
			$this->assertSame( 'json_success', $this->ajax( 'ajax_save_autoupdate' )->kind, 'A stale entry can always be switched off.' );
		}

		public function test_autoupdate_toggle_is_allowed_without_file_modifications(): void {
			$GLOBALS['hub_test_file_mods'] = false;
			$_POST                         = array( 'file' => 'one.php', 'enabled' => 'true' );
			$this->assertSame( 'json_success', $this->ajax( 'ajax_save_autoupdate' )->kind );
		}

		public function test_hooks_are_registered(): void {
			$this->mu->register_hooks();
			$this->assertSame(
				array( 'wp_ajax_install_mu_plugin', 'wp_ajax_update_mu_plugin', 'wp_ajax_rollback_mu_plugin', 'wp_ajax_update_all_mu_plugins', 'wp_ajax_save_mu_autoupdate' ),
				array_keys( $GLOBALS['hub_test_actions'] )
			);
		}

		public function test_clear_caches_removes_every_mu_transient(): void {
			foreach ( array( 'CACHE_KEY', 'RETRY_KEY', 'FETCH_RETRY_KEY', 'LOCK_KEY', 'DESCRIPTIONS_KEY', 'DESCRIPTIONS_RETRY_KEY' ) as $constant ) {
				$GLOBALS['hub_test_transients'][ constant( MU_Plugins::class . '::' . $constant ) ] = 'cached';
			}
			$GLOBALS['hub_test_transients']['unrelated'] = 'keep';

			$this->mu->clear_caches();
			$this->assertSame( array( 'unrelated' => 'keep' ), $GLOBALS['hub_test_transients'] );
		}

		public function test_installed_count_uses_the_local_directory(): void {
			$dir = WPMU_PLUGIN_DIR;
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) );
			} else {
				mkdir( $dir, 0755, true );
			}
			file_put_contents( $dir . '/one.php', "<?php\n/**\n * Plugin Name: One\n * Version: 1.0.0\n */\n" );
			file_put_contents( $dir . '/unrelated.php', "<?php\n" );

			$this->assertSame( 1, $this->mu->count_installed() );

			array_map( 'unlink', glob( $dir . '/*' ) );
			rmdir( $dir );
		}
	}
}
