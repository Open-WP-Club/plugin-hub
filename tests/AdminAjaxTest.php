<?php
/**
 * Tests the AJAX and admin-post handlers of PluginHub\Admin.
 *
 * The WordPress request helpers are stubbed: wp_send_json_*() and wp_die()
 * throw Hub_Test_Halt, so each test can read what the handler answered.
 *
 * @package PluginHub
 */

namespace {
	if ( ! defined( 'PLUGIN_HUB_PLUGIN_URL' ) ) {
		define( 'PLUGIN_HUB_PLUGIN_URL', 'https://example.test/wp-content/plugins/plugin-hub/' );
	}
	if ( ! defined( 'PLUGIN_HUB_PLUGIN_DIR' ) ) {
		define( 'PLUGIN_HUB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}
}

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once __DIR__ . '/fs-stubs.php';
	require_once __DIR__ . '/wp-list-table-stub.php';
	require_once __DIR__ . '/wp-ajax-stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';
	require_once dirname( __DIR__ ) . '/includes/class-config.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';
	require_once dirname( __DIR__ ) . '/includes/class-notifier.php';
	require_once dirname( __DIR__ ) . '/includes/admin.php';

	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-plugins-' . getmypid() );
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_add_dashboard_widget' ) ) {
		function delete_option( $key ) {
			unset( $GLOBALS['hub_test_options'][ $key ] );
			return true;
		}
		function wp_json_encode( $data, $flags = 0 ) {
			return json_encode( $data, $flags );
		}
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		}
		function size_format( $bytes, $decimals = 0 ) {
			return $bytes . ' B';
		}
		function is_email( $email ) {
			return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
		}
		function get_bloginfo( $show = '' ) {
			return 'Test Site';
		}
		function wp_mail( $to, $subject, $body ) {
			$GLOBALS['hub_test_mail'][] = array( $to, $subject, $body );
			return $GLOBALS['hub_test_mail_ok'] ?? true;
		}
		function wp_safe_remote_post( $url, $args = array() ) {
			$GLOBALS['hub_test_posts'][] = $url;
			return array( 'status' => $GLOBALS['hub_test_post_status'] ?? 200, 'body' => '' );
		}
		function wp_add_dashboard_widget( $id, $title, $callback ) {
			$GLOBALS['hub_test_widgets'][] = $id;
		}
		function wp_create_nonce( $action = -1 ) {
			return 'nonce-' . $action;
		}
		function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
			$GLOBALS['hub_test_enqueued'][] = array( 'style', $handle );
		}
		function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
			$GLOBALS['hub_test_enqueued'][] = array( 'script', $handle );
		}
		function wp_localize_script( $handle, $name, $data ) {
			$GLOBALS['hub_test_localized'][ $name ] = $data;
			return true;
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\Admin;
	use PluginHub\API;
	use PluginHub\Backups;
	use PluginHub\MU_Plugins;

	/**
	 * API double: in-memory pins, activity log and catalog.
	 */
	class Admin_Test_API extends API {
		public array $pins = array();
		public array $logged = array();
		public array $deleted_ids = array();
		public $pin_result = true;

		public function __construct() {}

		public function set_pin( $repo, $pin ) {
			if ( true !== $this->pin_result ) {
				return $this->pin_result;
			}
			$this->pins[ $repo ] = $pin;
			return true;
		}
		public function log_activity( $action, $plugin, $version = '' ) {
			$this->logged[] = array( $action, $plugin, $version );
		}
		public function delete_activity_entries( $ids ) {
			$this->deleted_ids = $ids;
			return count( $ids );
		}
		public function get_cached_repos() {
			return array( array( 'name' => 'gamma', 'version' => '3.0.0', 'available' => true ) );
		}
		public function is_plugin_installed( $name ) {
			return false;
		}
		public function refresh_csv_cache() {
			$GLOBALS['hub_test_refreshed'][] = 'plugins';
			return array();
		}
		public function get_activity_log( $filters = array() ) {
			return array();
		}
	}

	/**
	 * MU double with an empty catalog.
	 */
	class Admin_Test_MU extends MU_Plugins {
		public function get_catalog( $force = false ) {
			return array();
		}
		public function get_installed( $fetch = true ) {
			return array();
		}
		public function refresh_catalog() {
			$GLOBALS['hub_test_refreshed'][] = 'mu';
			return array();
		}
	}

	final class AdminAjaxTest extends TestCase {

		private Admin_Test_API $api;
		private Admin $admin;
		private string $uploads;

		protected function setUp(): void {
			$this->uploads               = sys_get_temp_dir() . '/plugin-hub-admin-' . getmypid();
			$GLOBALS['hub_test_uploads'] = $this->uploads;
			$GLOBALS['hub_test_options'] = array();
			$GLOBALS['hub_test_mail']    = array();
			$GLOBALS['hub_test_posts']   = array();
			$_POST                       = array();
			$_GET                        = array();
			\hub_test_rmtree( WP_PLUGIN_DIR );
			mkdir( WP_PLUGIN_DIR, 0755, true );
			$this->api   = new Admin_Test_API();
			$this->admin = new Admin( $this->api, new Admin_Test_MU( $this->api ) );
		}

		protected function tearDown(): void {
			\hub_test_rmtree( $this->uploads );
			\hub_test_rmtree( WP_PLUGIN_DIR );
			foreach ( array( 'hub_test_nonce_ok', 'hub_test_caps', 'hub_test_options', 'hub_test_uploads', 'hub_test_mail', 'hub_test_mail_ok', 'hub_test_posts', 'hub_test_post_status', 'hub_test_network_admin', 'hub_test_token_managed', 'hub_test_widgets', 'hub_test_enqueued', 'hub_test_localized', 'hub_test_refreshed', 'hub_test_transients' ) as $key ) {
				unset( $GLOBALS[ $key ] );
			}
			$_POST = array();
			$_GET  = array();
		}

		/**
		 * Run a handler and return the Hub_Test_Halt it ended with.
		 */
		private function call( callable $handler ): \Hub_Test_Halt {
			try {
				$handler();
			} catch ( \Hub_Test_Halt $halt ) {
				return $halt;
			}
			$this->fail( 'The handler neither answered nor died.' );
		}

		private function assert_success( \Hub_Test_Halt $halt, $data = null ): void {
			$this->assertSame( 'json_success', $halt->kind, 'Handler answered: ' . json_encode( $halt->data ) );
			if ( null !== $data ) {
				$this->assertSame( $data, $halt->data );
			}
		}

		private function assert_error( \Hub_Test_Halt $halt, $data = null ): void {
			$this->assertSame( 'json_error', $halt->kind, 'Handler answered: ' . json_encode( $halt->data ) );
			if ( null !== $data ) {
				$this->assertSame( $data, $halt->data );
			}
		}

		private function make_backup( string $repo = 'demo-plugin', string $version = '1.2.3' ): string {
			if ( ! class_exists( 'ZipArchive' ) ) {
				$this->markTestSkipped( 'The zip extension is not available.' );
			}
			mkdir( WP_PLUGIN_DIR . '/' . $repo, 0755, true );
			file_put_contents( WP_PLUGIN_DIR . '/' . $repo . '/' . $repo . '.php', "<?php\n/**\n * Plugin Name: Demo\n * Version: {$version}\n */\n" );
			$id = Backups::create( $repo, $repo . '/' . $repo . '.php' );
			$this->assertIsString( $id );
			return $id;
		}

		/**
		 * Every handler added in 1.8, with the capability it requires.
		 */
		public static function guarded_handlers(): array {
			return array(
				'save settings'    => array( 'ajax_save_hub_settings', 'manage_options' ),
				'save pin'         => array( 'ajax_save_plugin_pin', 'update_plugins' ),
				'delete backups'   => array( 'ajax_delete_backups', 'manage_options' ),
				'restore backup'   => array( 'ajax_restore_backup', 'update_plugins' ),
				'delete activity'  => array( 'ajax_delete_activity_entries', 'manage_options' ),
				'import config'    => array( 'ajax_import_hub_config', 'manage_options' ),
				'test notification' => array( 'ajax_send_test_notification', 'manage_options' ),
			);
		}

		#[DataProvider( 'guarded_handlers' )]
		public function test_handlers_require_a_valid_nonce( string $method ): void {
			$GLOBALS['hub_test_nonce_ok'] = false;
			$this->assertSame( 'nonce', $this->call( array( $this->admin, $method ) )->kind );
		}

		#[DataProvider( 'guarded_handlers' )]
		public function test_handlers_require_their_capability( string $method, string $capability ): void {
			$GLOBALS['hub_test_caps'] = array( 'read' );
			$this->assert_error( $this->call( array( $this->admin, $method ) ), 'You do not have permission to do this.' );

			$GLOBALS['hub_test_caps'] = array_diff( array( 'manage_options', 'update_plugins' ), array( $capability ) );
			$this->assert_error( $this->call( array( $this->admin, $method ) ), 'You do not have permission to do this.' );
		}

		// ---------------------------------------------------------------- settings

		public function test_save_settings_stores_sanitized_values(): void {
			$_POST['settings'] = array(
				'notify_emails'  => 'a@example.test',
				'backup_enabled' => '1',
			);
			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_hub_settings' ) ), 'Settings saved.' );
			$this->assertSame( 'a@example.test', \PluginHub\Settings::get( 'notify_emails' ) );
		}

		public function test_save_settings_without_day_checkboxes_falls_back_to_every_day(): void {
			\PluginHub\Settings::save( array( 'window_enabled' => true, 'window_days' => array( 1, 2 ) ) );
			$this->assertSame( array( 1, 2 ), \PluginHub\Settings::get( 'window_days' ) );
			$_POST['settings'] = array( 'window_enabled' => '1' );

			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_hub_settings' ) ) );
			// Browsers omit unchecked boxes; an empty window could never open, so every day applies.
			$this->assertSame( array( 0, 1, 2, 3, 4, 5, 6 ), \PluginHub\Settings::get( 'window_days' ) );
		}

		public function test_save_settings_without_a_settings_array_still_succeeds(): void {
			$_POST['settings'] = 'not-an-array';
			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_hub_settings' ) ) );
		}

		// --------------------------------------------------------------------- pin

		public function test_save_pin_sets_and_clears(): void {
			$_POST = array( 'repo' => 'demo', 'pin' => '1.x' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_plugin_pin' ) ), 'Pin saved.' );
			$this->assertSame( array( 'demo' => '1.x' ), $this->api->pins );

			$_POST = array( 'repo' => 'demo', 'pin' => '' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_plugin_pin' ) ) );
			$this->assertSame( array( 'demo' => '' ), $this->api->pins );
		}

		public static function bad_pin_input(): array {
			return array(
				'no repo'        => array( array( 'pin' => '1.x' ) ),
				'traversal repo' => array( array( 'repo' => '../x', 'pin' => '1.x' ) ),
				'bad pin'        => array( array( 'repo' => 'demo', 'pin' => 'latest' ) ),
			);
		}

		#[DataProvider( 'bad_pin_input' )]
		public function test_save_pin_rejects_bad_input( array $post ): void {
			$_POST = $post;
			$this->assert_error( $this->call( array( $this->admin, 'ajax_save_plugin_pin' ) ), 'Invalid plugin information.' );
			$this->assertSame( array(), $this->api->pins );
		}

		public function test_save_pin_forwards_api_errors(): void {
			$this->api->pin_result = new \WP_Error( 'x', 'Storage failed' );
			$_POST                 = array( 'repo' => 'demo', 'pin' => '1.x' );
			$this->assert_error( $this->call( array( $this->admin, 'ajax_save_plugin_pin' ) ), 'Storage failed' );
		}

		// ----------------------------------------------------------------- backups

		public function test_delete_backups_removes_the_posted_ids(): void {
			$id = $this->make_backup();
			$this->assertCount( 1, Backups::all() );

			$_POST['ids'] = array( $id );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_delete_backups' ) ), '1 backup(s) deleted.' );
			$this->assertCount( 0, Backups::all() );
		}

		public function test_delete_backups_ignores_malformed_ids(): void {
			$_POST['ids'] = array( '../../etc/passwd', '' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_delete_backups' ) ), '0 backup(s) deleted.' );
		}

		public function test_restore_backup_validates_the_id(): void {
			$this->assert_error( $this->call( array( $this->admin, 'ajax_restore_backup' ) ), 'Invalid backup.' );

			$_POST['id'] = 'demo-plugin__1.0_1.zip';
			$this->assert_error( $this->call( array( $this->admin, 'ajax_restore_backup' ) ), 'The backup no longer exists.' );
		}

		public function test_restore_backup_restores_and_logs(): void {
			$id = $this->make_backup( 'demo-plugin', '1.2.3' );
			file_put_contents( WP_PLUGIN_DIR . '/demo-plugin/new-file.txt', 'added later' );

			$_POST['id'] = $id;
			$this->assert_success( $this->call( array( $this->admin, 'ajax_restore_backup' ) ), 'demo-plugin restored to version 1.2.3.' );
			$this->assertFileDoesNotExist( WP_PLUGIN_DIR . '/demo-plugin/new-file.txt' );
			$this->assertSame( array( array( 'backup_restore', 'demo-plugin', '1.2.3' ) ), $this->api->logged );
		}

		public function test_restore_backup_reports_a_damaged_archive(): void {
			$id  = $this->make_backup();
			$row = Backups::all()[0];
			file_put_contents( $row['path'], 'not a zip' );

			$_POST['id'] = $id;
			$this->assert_error( $this->call( array( $this->admin, 'ajax_restore_backup' ) ), 'The backup archive is damaged.' );
			$this->assertSame( array(), $this->api->logged, 'A failed restore is not logged as a restore.' );
		}

		// ---------------------------------------------------------------- activity

		public function test_delete_activity_entries_passes_ids_through(): void {
			$_POST['ids'] = array( 'a1', 'b2', '' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_delete_activity_entries' ) ), '2 entries deleted.' );
			$this->assertSame( array( 'a1', 'b2' ), $this->api->deleted_ids );
		}

		public function test_clear_activity_log_empties_the_option(): void {
			$GLOBALS['hub_test_options']['plugin_hub_activity_log'] = array( array( 'action' => 'x' ) );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_clear_activity_log' ) ), 'Activity log cleared.' );
			$this->assertSame( array(), $GLOBALS['hub_test_options']['plugin_hub_activity_log'] );
		}

		// ------------------------------------------------------------------ import

		private function config_json( array $override = array() ): string {
			return (string) json_encode(
				array_merge(
					array(
						'format'   => 'plugin-hub-config',
						'version'  => 1,
						'plugins'  => array(
							array( 'repo' => 'gamma', 'version' => '3.0.0', 'active' => true, 'autoupdate' => true, 'pin' => '1.x' ),
						),
						'mu'       => array(),
						'settings' => array(),
					),
					$override
				)
			);
		}

		public function test_import_applies_the_file_and_reports_missing_plugins(): void {
			$_POST['config'] = $this->config_json();
			$halt            = $this->call( array( $this->admin, 'ajax_import_hub_config' ) );

			$this->assert_success( $halt );
			$this->assertSame( 'Configuration imported.', $halt->data['message'] );
			$this->assertSame( array( array( 'repo' => 'gamma', 'version' => '3.0.0', 'active' => true ) ), $halt->data['missing'] );
			$this->assertSame( array(), $halt->data['mu_missing'] );
			$this->assertSame( array( 'gamma' => '1.x' ), $this->api->pins );
			$this->assertSame( array( 'gamma' ), $GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] );
		}

		public static function bad_config(): array {
			return array(
				'empty'      => array( '' ),
				'not json'   => array( '{nope' ),
				'wrong file' => array( '{"hello":"world"}' ),
			);
		}

		#[DataProvider( 'bad_config' )]
		public function test_import_rejects_invalid_files( string $json ): void {
			$_POST['config'] = $json;
			$this->assertSame( 'json_error', $this->call( array( $this->admin, 'ajax_import_hub_config' ) )->kind );
			$this->assertSame( array(), $this->api->pins );
		}

		// ----------------------------------------------------------- notifications

		public function test_test_notification_reports_the_email_result(): void {
			$GLOBALS['hub_test_options']['admin_email'] = 'admin@example.test';

			$this->assert_success( $this->call( array( $this->admin, 'ajax_send_test_notification' ) ), 'Email sent.' );
			$this->assertSame( array( 'admin@example.test' ), $GLOBALS['hub_test_mail'][0][0] );

			$GLOBALS['hub_test_mail_ok'] = false;
			$this->assert_error( $this->call( array( $this->admin, 'ajax_send_test_notification' ) ), 'Email failed.' );
		}

		public function test_test_notification_counts_a_delivered_webhook_as_success(): void {
			$GLOBALS['hub_test_options']['admin_email'] = 'admin@example.test';
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'webhook_url' => 'https://hooks.example.test/x' );
			$GLOBALS['hub_test_mail_ok']                = false;

			$this->assert_success( $this->call( array( $this->admin, 'ajax_send_test_notification' ) ), 'Email failed. Webhook delivered.' );
			$this->assertSame( array( 'https://hooks.example.test/x' ), $GLOBALS['hub_test_posts'] );

			$GLOBALS['hub_test_post_status'] = 500;
			$this->assert_error( $this->call( array( $this->admin, 'ajax_send_test_notification' ) ), 'Email failed. Webhook failed.' );
		}

		// ------------------------------------------------------------ older handlers

		public function test_save_github_token_saves_clears_and_validates(): void {
			$_POST = array( 'token' => '  ghp_abc  ' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_github_token' ) ), 'Token saved successfully.' );
			$this->assertSame( 'ghp_abc', $GLOBALS['hub_test_options']['plugin_hub_github_token'] );

			$_POST = array( 'clear' => '1' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_save_github_token' ) ), 'Token removed.' );
			$this->assertArrayNotHasKey( 'plugin_hub_github_token', $GLOBALS['hub_test_options'] );

			$_POST = array( 'token' => '' );
			$this->assert_error( $this->call( array( $this->admin, 'ajax_save_github_token' ) ), 'Enter a token or use Remove Token.' );
		}

		public function test_save_github_token_respects_wp_config_and_capability(): void {
			$GLOBALS['hub_test_token_managed'] = true;
			$_POST                             = array( 'token' => 'x' );
			$this->assert_error( $this->call( array( $this->admin, 'ajax_save_github_token' ) ), 'The GitHub token is managed in wp-config.php and cannot be changed here.' );

			$GLOBALS['hub_test_token_managed'] = false;
			$GLOBALS['hub_test_caps']          = array();
			$this->assert_error( $this->call( array( $this->admin, 'ajax_save_github_token' ) ), 'You do not have permission to change this setting.' );
		}

		public function test_toggle_beta_plugins(): void {
			$_POST = array( 'show_beta' => 'true' );
			$this->assert_success( $this->call( array( $this->admin, 'ajax_toggle_beta_plugins' ) ) );
			$this->assertTrue( $GLOBALS['hub_test_options']['plugin_hub_show_beta'] );

			$_POST = array( 'show_beta' => 'false' );
			$this->call( array( $this->admin, 'ajax_toggle_beta_plugins' ) );
			$this->assertFalse( $GLOBALS['hub_test_options']['plugin_hub_show_beta'] );
		}

		public function test_autoupdate_setting_adds_removes_and_validates(): void {
			$_POST = array( 'repo' => 'alpha', 'enabled' => 'true' );
			$this->call( array( $this->admin, 'ajax_save_autoupdate_setting' ) );
			$_POST = array( 'repo' => 'beta', 'enabled' => '1' );
			$this->call( array( $this->admin, 'ajax_save_autoupdate_setting' ) );
			$this->assertSame( array( 'alpha', 'beta' ), array_values( $GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] ) );

			$_POST = array( 'repo' => 'alpha', 'enabled' => 'false' );
			$this->call( array( $this->admin, 'ajax_save_autoupdate_setting' ) );
			$this->assertSame( array( 'beta' ), $GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] );

			$_POST = array( 'enabled' => 'true' );
			$this->assert_error( $this->call( array( $this->admin, 'ajax_save_autoupdate_setting' ) ), 'Invalid plugin information.' );
		}

		// ------------------------------------------------------------ admin-post

		public function test_exports_require_nonce_and_capability(): void {
			$GLOBALS['hub_test_nonce_ok'] = false;
			$this->assertSame( 'nonce', $this->call( array( $this->admin, 'handle_export_config' ) )->kind );
			$this->assertSame( 'nonce', $this->call( array( $this->admin, 'handle_export_activity' ) )->kind );

			$GLOBALS['hub_test_nonce_ok'] = true;
			$GLOBALS['hub_test_caps']     = array();
			$halt                         = $this->call( array( $this->admin, 'handle_export_config' ) );
			$this->assertSame( array( 'die', 'You do not have permission to export the configuration.' ), array( $halt->kind, $halt->data ) );
			$halt = $this->call( array( $this->admin, 'handle_export_activity' ) );
			$this->assertSame( array( 'die', 'You do not have permission to export the activity log.' ), array( $halt->kind, $halt->data ) );
		}

		public function test_refresh_cache_ignores_other_actions_and_checks_rights(): void {
			$this->admin->handle_refresh_cache();
			$this->assertArrayNotHasKey( 'hub_test_refreshed', $GLOBALS );

			$_GET['action']           = 'refresh_cache';
			$GLOBALS['hub_test_caps'] = array();
			$halt                     = $this->call( array( $this->admin, 'handle_refresh_cache' ) );
			$this->assertSame( 'die', $halt->kind );
			$this->assertArrayNotHasKey( 'hub_test_refreshed', $GLOBALS );
		}

		public function test_refresh_cache_needs_the_network_capability_in_network_admin(): void {
			$_GET['action']                 = 'refresh_cache';
			$GLOBALS['hub_test_caps']       = array( 'manage_options' );
			$GLOBALS['hub_test_network_admin'] = true;
			$this->assertSame( 'die', $this->call( array( $this->admin, 'handle_refresh_cache' ) )->kind );
		}

		public function test_dashboard_widget_is_only_added_for_updaters(): void {
			$GLOBALS['hub_test_caps'] = array( 'read' );
			$this->admin->add_dashboard_widget();
			$this->assertArrayNotHasKey( 'hub_test_widgets', $GLOBALS );

			$GLOBALS['hub_test_caps'] = array( 'update_plugins' );
			$this->admin->add_dashboard_widget();
			$this->assertSame( array( 'plugin_hub_dashboard' ), $GLOBALS['hub_test_widgets'] );
		}

		// ----------------------------------------------------------------- scripts

		public function test_scripts_load_only_on_the_plugin_hub_screens(): void {
			$this->admin->enqueue_scripts( 'plugins.php' );
			$this->admin->enqueue_styles( 'index.php' );
			$this->assertArrayNotHasKey( 'hub_test_enqueued', $GLOBALS );

			$this->admin->enqueue_scripts( 'plugins_page_plugin-hub-network' );
			$this->admin->enqueue_styles( 'plugins_page_plugin-hub' );
			$this->assertSame( array( array( 'script', 'plugin-hub-script' ), array( 'style', 'plugin-hub-style' ) ), $GLOBALS['hub_test_enqueued'] );
			$this->assertSame( 'nonce-plugin-hub-nonce', $GLOBALS['hub_test_localized']['pluginHubAjax']['nonce'] );
		}

		/**
		 * Every `i18n.<key>` the script reads must be provided by PHP, or the UI shows "undefined".
		 */
		public function test_every_string_used_by_the_script_is_localized(): void {
			$this->admin->enqueue_scripts( 'plugins_page_plugin-hub' );
			$provided = array_keys( $GLOBALS['hub_test_localized']['pluginHubAjax']['i18n'] );

			$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/script.js' );
			preg_match_all( '/\bi18n\.([a-z_]+)/', $js, $matches );
			$used = array_unique( $matches[1] );

			$this->assertNotEmpty( $used );
			$this->assertSame( array(), array_values( array_diff( $used, $provided ) ), 'Strings used by script.js but missing from Admin::get_script_strings().' );
		}
	}
}
