<?php
/**
 * Tests the update pipeline of PluginHub\API end to end: checksum
 * verification, health check, automatic rollback and the scheduled run.
 *
 * Only the WordPress upgrader is replaced (see Flow_Test_API::perform_plugin_upgrade());
 * backups, restore, settings, policy and notifications run for real.
 *
 * @package PluginHub
 */

namespace {
	if ( ! function_exists( 'download_url' ) ) {
		function download_url( $url ) {
			$GLOBALS['hub_test_downloads'][] = $url;
			return $GLOBALS['hub_test_download_result'] ?? new WP_Error( 'download_failed', 'No download configured.' );
		}
	}
	require_once __DIR__ . '/fs-stubs.php';
}

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';
	require_once dirname( __DIR__ ) . '/includes/class-notifier.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';

	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-plugins-' . getmypid() );
	}

	require_once __DIR__ . '/wp-api-stubs.php';
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;
	use PluginHub\Backups;
	use PluginHub\Settings;

	/**
	 * API double: the catalog and the plugins directory are real, the WordPress
	 * upgrader is replaced by a function that rewrites the plugin header.
	 */
	class Flow_Test_API extends API {
		public array $catalog   = array();
		public array $active    = array();
		public array $published = array();
		/** @var bool|null null runs the real check_site_health(). */
		public $healthy = true;
		/** @var array<int, array{string, string, bool}> */
		public array $upgrades = array();
		/** Upgrade results in order; an empty queue means success. */
		public array $upgrade_results = array();
		public array $logs = array();

		public function __construct() {}

		public function get_org_repos() {
			return $this->catalog;
		}
		public function refresh_csv_cache() {
			return $this->catalog;
		}
		public function get_cached_repos() {
			return $this->catalog;
		}
		public function get_plugin_file( $name ) {
			return is_dir( WP_PLUGIN_DIR . '/' . $name ) ? "$name/$name.php" : false;
		}
		public function is_plugin_installed( $name ) {
			return is_dir( WP_PLUGIN_DIR . '/' . $name );
		}
		public function is_plugin_active( $name ) {
			return in_array( $name, $this->active, true );
		}
		public function get_installed_plugin_version( $name ) {
			$file = WP_PLUGIN_DIR . "/$name/$name.php";
			if ( ! is_file( $file ) ) {
				return 'Not Installed';
			}
			preg_match( '/Version:\s*(\S+)/', (string) file_get_contents( $file ), $m );
			return $m[1] ?? 'Not Installed';
		}
		public function get_release_published_at( $repo, $version ) {
			return $this->published[ $repo ] ?? 0;
		}
		public function check_site_health() {
			return null === $this->healthy ? parent::check_site_health() : $this->healthy;
		}
		public function log( $message ) {
			$this->logs[] = $message;
		}
		protected function perform_plugin_upgrade( $repo, $version, $background = false ) {
			$this->upgrades[] = array( $repo['name'], $version, $background );
			$result           = $this->upgrade_results ? array_shift( $this->upgrade_results ) : true;
			if ( true === $result ) {
				Flow_Test_API::write_plugin( $repo['name'], $version, "new in $version" );
			}
			return $result;
		}

		public static function write_plugin( string $name, string $version, string $marker = 'original' ): void {
			if ( ! is_dir( WP_PLUGIN_DIR . '/' . $name ) ) {
				mkdir( WP_PLUGIN_DIR . '/' . $name, 0755, true );
			}
			file_put_contents( WP_PLUGIN_DIR . "/$name/$name.php", "<?php\n/**\n * Plugin Name: $name\n * Version: $version\n */\n" );
			file_put_contents( WP_PLUGIN_DIR . "/$name/marker.txt", $marker );
		}
	}

	final class ApiUpdateFlowTest extends TestCase {

		private Flow_Test_API $api;
		private string $uploads;

		protected function setUp(): void {
			if ( ! class_exists( 'ZipArchive' ) ) {
				$this->markTestSkipped( 'The zip extension is not available.' );
			}
			$this->uploads                      = sys_get_temp_dir() . '/plugin-hub-flow-' . getmypid();
			$GLOBALS['hub_test_uploads']        = $this->uploads;
			$GLOBALS['hub_test_options']        = array( 'admin_email' => 'admin@example.test' );
			$GLOBALS['hub_test_transients']     = array();
			$GLOBALS['hub_test_mail']           = array();
			$GLOBALS['hub_test_requests']       = array();
			$GLOBALS['hub_test_routes']         = array();
			$GLOBALS['hub_test_filters']        = array();
			$GLOBALS['hub_test_filter_values']  = array();
			$GLOBALS['hub_test_downloads']      = array();
			$GLOBALS['hub_test_now']            = gmmktime( 10, 0, 0, 10, 28, 2026 ); // A Wednesday.
			\hub_test_rmtree( WP_PLUGIN_DIR );
			\hub_test_rmtree( $this->uploads );
			mkdir( WP_PLUGIN_DIR, 0755, true );

			$this->api = new Flow_Test_API();
			$this->api->catalog = array( $this->repo( 'demo', '1.1.0' ) );
			Flow_Test_API::write_plugin( 'demo', '1.0.0' );
			$this->api->active = array( 'demo' );
		}

		protected function tearDown(): void {
			\hub_test_rmtree( WP_PLUGIN_DIR );
			\hub_test_rmtree( $this->uploads );
			foreach ( array( 'hub_test_uploads', 'hub_test_options', 'hub_test_transients', 'hub_test_mail', 'hub_test_requests', 'hub_test_routes', 'hub_test_filters', 'hub_test_filter_values', 'hub_test_downloads', 'hub_test_download_result', 'hub_test_now' ) as $key ) {
				unset( $GLOBALS[ $key ] );
			}
		}

		private function repo( string $name, string $version, string $sha = '' ): array {
			return array(
				'name'         => $name,
				'display_name' => ucfirst( $name ),
				'version'      => $version,
				'available'    => true,
				'sha256'       => $sha,
			);
		}

		private function set_settings( array $settings ): void {
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = $settings;
		}

		private function activity_actions(): array {
			return array_column( array_reverse( $this->api->get_activity_log() ), 'action' );
		}

		/**
		 * Requested URLs without the cache-busting query string.
		 */
		private function requested_pages(): array {
			return array_map( static fn ( $url ) => preg_replace( '/\?.*$/', '', $url ), $GLOBALS['hub_test_requests'] );
		}

		private function invoke_private( string $method, ...$args ) {
			$m = new \ReflectionMethod( API::class, $method );
			return $m->invoke( $this->api, ...$args );
		}

		// ---------------------------------------------------------- checksum

		public function test_checksum_is_armed_only_for_the_catalog_version(): void {
			$repo = $this->repo( 'demo', '1.1.0', str_repeat( 'a', 64 ) );

			$this->assertTrue( $this->invoke_private( 'begin_package_verification', $repo, '1.1.0', 'https://github.com/x.zip' ) );
			$this->assertArrayHasKey( 'upgrader_pre_download', $GLOBALS['hub_test_filters'] );

			$GLOBALS['hub_test_filters'] = array();
			$this->assertTrue( $this->invoke_private( 'begin_package_verification', $repo, '1.0.5', 'https://github.com/old.zip' ), 'Older releases carry no published checksum.' );
			$this->assertArrayNotHasKey( 'upgrader_pre_download', $GLOBALS['hub_test_filters'] );
		}

		public function test_missing_checksum_is_refused_only_when_required(): void {
			$repo = $this->repo( 'demo', '1.1.0' );

			$this->assertTrue( $this->invoke_private( 'begin_package_verification', $repo, '1.1.0', 'https://github.com/x.zip' ) );

			$this->set_settings( array( 'require_checksum' => true ) );
			$result = $this->invoke_private( 'begin_package_verification', $repo, '1.1.0', 'https://github.com/x.zip' );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'plugin_hub_no_checksum', $result->get_error_code() );

			$this->assertTrue( $this->invoke_private( 'begin_package_verification', $repo, '1.0.5', 'https://github.com/old.zip' ), 'Rollbacks are exempt even when checksums are required.' );
		}

		public function test_package_with_matching_checksum_is_accepted(): void {
			$file = tempnam( sys_get_temp_dir(), 'hub' );
			file_put_contents( $file, 'package-bytes' );
			$GLOBALS['hub_test_download_result'] = $file;

			$url = 'https://github.com/x.zip';
			// The catalog parser stores hashes lowercased.
			$this->invoke_private( 'begin_package_verification', $this->repo( 'demo', '1.1.0', hash( 'sha256', 'package-bytes' ) ), '1.1.0', $url );

			$this->assertSame( $file, $this->api->verify_package_checksum( false, $url ) );
			$this->assertFileExists( $file );
			unlink( $file );
		}

		public function test_package_with_a_wrong_checksum_is_deleted_and_refused(): void {
			$file = tempnam( sys_get_temp_dir(), 'hub' );
			file_put_contents( $file, 'tampered' );
			$GLOBALS['hub_test_download_result'] = $file;

			$url = 'https://github.com/x.zip';
			$this->invoke_private( 'begin_package_verification', $this->repo( 'demo', '1.1.0', hash( 'sha256', 'package-bytes' ) ), '1.1.0', $url );

			$result = $this->api->verify_package_checksum( false, $url );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'plugin_hub_checksum_mismatch', $result->get_error_code() );
			$this->assertFileDoesNotExist( $file, 'The rejected download is removed.' );
		}

		public function test_other_packages_and_download_errors_pass_through(): void {
			$url = 'https://github.com/x.zip';
			$this->assertSame( 'untouched', $this->api->verify_package_checksum( 'untouched', $url ), 'Not armed: nothing happens.' );

			$this->invoke_private( 'begin_package_verification', $this->repo( 'demo', '1.1.0', hash( 'sha256', 'x' ) ), '1.1.0', $url );
			$this->assertSame( 'untouched', $this->api->verify_package_checksum( 'untouched', 'https://github.com/other.zip' ), 'Only the armed URL is verified.' );

			$error = $this->api->verify_package_checksum( false, $url );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'download_failed', $error->get_error_code() );
		}

		// ------------------------------------------------------- health check

		public function test_health_check_passes_on_healthy_pages(): void {
			$this->api->healthy = null;
			$this->assertTrue( $this->api->check_site_health() );
			$this->assertSame( array( 'https://example.test/', 'https://example.test/wp-login.php' ), $this->requested_pages() );
		}

		public static function failing_responses(): array {
			return array(
				'server error'   => array( array( 'status' => 500, 'body' => 'x' ) ),
				'critical error' => array( array( 'status' => 200, 'body' => 'There has been a critical error on this website.' ) ),
				'fatal error'    => array( array( 'status' => 200, 'body' => 'PHP Fatal error: boom' ) ),
			);
		}

		#[DataProvider( 'failing_responses' )]
		public function test_health_check_fails_on_a_broken_page( array $response ): void {
			$this->api->healthy                                         = null;
			$GLOBALS['hub_test_routes']['https://example.test/wp-login.php'] = $response;
			$this->assertFalse( $this->api->check_site_health() );
		}

		public function test_unreachable_pages_are_inconclusive_not_failures(): void {
			$this->api->healthy                              = null;
			$GLOBALS['hub_test_routes']['https://example.test/'] = new \WP_Error( 'http_request_failed', 'cURL error 7' );
			$this->assertTrue( $this->api->check_site_health() );
			$this->assertNotEmpty( $this->api->logs );
		}

		public function test_health_check_urls_can_be_filtered(): void {
			$this->api->healthy                                       = null;
			$GLOBALS['hub_test_filter_values']['plugin_hub_health_check_urls'] = array( 'https://example.test/shop/' );
			$this->api->check_site_health();
			$this->assertSame( array( 'https://example.test/shop/' ), $this->requested_pages() );
		}

		// ------------------------------------------------------ update_plugin

		public static function rejected_updates(): array {
			return array(
				'invalid name'   => array( '../x', '1.1.0', false, 'plugin_hub_invalid' ),
				'empty version'  => array( 'demo', '', false, 'plugin_hub_invalid' ),
				'not in catalog' => array( 'unknown', '1.1.0', false, 'plugin_hub_untrusted' ),
				'stale version'  => array( 'demo', '1.0.5', false, 'plugin_hub_stale' ),
				'rollback: same' => array( 'demo', '1.0.0', true, 'plugin_hub_current' ),
				'rollback: newer' => array( 'demo', '1.1.0', true, 'plugin_hub_not_older' ),
			);
		}

		#[DataProvider( 'rejected_updates' )]
		public function test_update_validation( string $name, string $version, bool $rollback, string $code ): void {
			$result = $this->api->update_plugin( $name, $version, $rollback, true );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertSame( array(), $this->api->upgrades );
		}

		public function test_update_refuses_a_plugin_that_is_not_installed(): void {
			$this->api->catalog[] = $this->repo( 'other', '2.0.0' );
			$result               = $this->api->update_plugin( 'other', '2.0.0', false, true );
			$this->assertSame( 'plugin_hub_not_found', $result->get_error_code() );
		}

		public function test_update_refuses_when_already_current(): void {
			Flow_Test_API::write_plugin( 'demo', '1.1.0' );
			$this->assertSame( 'plugin_hub_current', $this->api->update_plugin( 'demo', '1.1.0', false, true )->get_error_code() );
		}

		public function test_update_respects_the_pin(): void {
			$this->api->set_pin( 'demo', '1.0.0' );
			$result = $this->api->update_plugin( 'demo', '1.1.0', false, true );
			$this->assertSame( 'plugin_hub_pinned', $result->get_error_code() );
			$this->assertSame( array(), $this->api->upgrades );

			$this->assertTrue( $this->api->update_plugin( 'demo', '1.0.0', true, true ) instanceof \WP_Error, 'Rollback to the installed version is refused, but not because of the pin.' );
		}

		public function test_successful_update_backs_up_first_and_logs(): void {
			$this->assertTrue( $this->api->update_plugin( 'demo', '1.1.0', false, true ) );

			$this->assertSame( '1.1.0', $this->api->get_installed_plugin_version( 'demo' ) );
			$backups = Backups::all();
			$this->assertCount( 1, $backups );
			$this->assertSame( '1.0.0', $backups[0]['version'], 'The backup holds the version before the update.' );
			$this->assertSame( array( 'update' ), $this->activity_actions() );
		}

		public function test_explicit_rollback_is_logged_and_skips_the_health_check(): void {
			Flow_Test_API::write_plugin( 'demo', '1.1.0' );
			$this->api->healthy = false;

			$this->assertTrue( $this->api->update_plugin( 'demo', '1.0.0', true, true ) );
			$this->assertSame( array( 'rollback' ), $this->activity_actions() );
			$this->assertSame( array(), $this->api->get_failed_versions() );
		}

		public function test_upgrader_failure_is_returned_and_not_logged(): void {
			$this->api->upgrade_results = array( new \WP_Error( 'upgrade_failed', 'Disk full' ) );
			$result                     = $this->api->update_plugin( 'demo', '1.1.0', false, true );
			$this->assertSame( 'upgrade_failed', $result->get_error_code() );
			$this->assertSame( array(), $this->activity_actions() );

			$this->api->upgrade_results = array( false );
			$this->assertSame( 'plugin_hub_update_failed', $this->api->update_plugin( 'demo', '1.1.0', false, true )->get_error_code() );
		}

		// ------------------------------------------------------ auto-rollback

		public function test_failed_health_check_restores_the_backup_and_blocks_the_release(): void {
			$this->api->healthy = false;

			$result = $this->api->update_plugin( 'demo', '1.1.0', false, true, 'auto_update' );

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'plugin_hub_rolled_back', $result->get_error_code() );
			$this->assertStringContainsString( 'version 1.0.0 was restored automatically', $result->get_error_message() );
			$this->assertSame( '1.0.0', $this->api->get_installed_plugin_version( 'demo' ) );
			$this->assertSame( 'original', file_get_contents( WP_PLUGIN_DIR . '/demo/marker.txt' ), 'The files come back from the local backup.' );
			$this->assertCount( 1, $this->api->upgrades, 'No GitHub download is needed while the backup works.' );
			$this->assertSame( array( 'demo' => array( '1.1.0' ) ), $this->api->get_failed_versions() );
			$this->assertSame( array( 'auto_rollback' ), $this->activity_actions() );

			$repo = $this->api->catalog[0];
			$this->assertFalse( $this->api->is_update_allowed( $repo, '1.0.0' ), 'The broken release is not offered again.' );
		}

		public function test_rollback_falls_back_to_the_previous_release_without_a_backup(): void {
			$this->set_settings( array( 'backup_enabled' => false ) );
			$this->api->healthy = false;

			$result = $this->api->update_plugin( 'demo', '1.1.0', false, true );

			$this->assertSame( 'plugin_hub_rolled_back', $result->get_error_code() );
			$this->assertSame( '1.0.0', $this->api->get_installed_plugin_version( 'demo' ) );
			$this->assertSame(
				array( array( 'demo', '1.1.0', true ), array( 'demo', '1.0.0', true ) ),
				$this->api->upgrades,
				'The previous GitHub release is installed in the background.'
			);
		}

		public function test_message_admits_when_the_restore_itself_fails(): void {
			$this->set_settings( array( 'backup_enabled' => false ) );
			$this->api->healthy         = false;
			$this->api->upgrade_results = array( true, new \WP_Error( 'x', 'GitHub down' ) );

			$result = $this->api->update_plugin( 'demo', '1.1.0', false, true );

			$this->assertSame( 'plugin_hub_rolled_back', $result->get_error_code() );
			$this->assertStringContainsString( 'the previous version (restore failed, check the site)', $result->get_error_message() );
			$this->assertSame( '1.1.0', $this->api->get_installed_plugin_version( 'demo' ) );
		}

		public function test_no_health_check_when_disabled_or_the_plugin_was_inactive(): void {
			$this->api->healthy = false;

			$this->set_settings( array( 'auto_rollback' => false ) );
			$this->assertTrue( $this->api->update_plugin( 'demo', '1.1.0', false, true ) );

			Flow_Test_API::write_plugin( 'demo', '1.0.0' );
			$this->set_settings( array( 'auto_rollback' => true ) );
			$this->api->active = array();
			$this->api->catalog = array( $this->repo( 'demo', '1.2.0' ) );
			$this->assertTrue( $this->api->update_plugin( 'demo', '1.2.0', false, true ), 'An inactive plugin cannot break the site.' );
			$this->assertSame( array(), $this->api->get_failed_versions() );
		}

		// ------------------------------------------------------ scheduled run

		private function run_schedule( bool $force = false ): void {
			$this->api->do_auto_updates_and_notify( $force );
		}

		private function enable_autoupdate( string ...$names ): void {
			$GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] = $names;
		}

		public function test_scheduled_run_updates_opted_in_plugins_and_reports_them(): void {
			$this->enable_autoupdate( 'demo' );
			$this->run_schedule();

			$this->assertSame( '1.1.0', $this->api->get_installed_plugin_version( 'demo' ) );
			$this->assertSame( array( 'auto_update' ), $this->activity_actions() );
			$this->assertCount( 1, $GLOBALS['hub_test_mail'] );
			$this->assertSame( array( 'admin@example.test' ), $GLOBALS['hub_test_mail'][0][0] );
			$this->assertStringContainsString( "automatically updated:\n  - demo → v1.1.0", $GLOBALS['hub_test_mail'][0][2] );
		}

		public function test_scheduled_run_only_reports_plugins_without_auto_update(): void {
			$this->run_schedule();

			$this->assertSame( '1.0.0', $this->api->get_installed_plugin_version( 'demo' ) );
			$this->assertStringContainsString( "updates available:\n  - demo (v1.0.0 → v1.1.0)", $GLOBALS['hub_test_mail'][0][2] );
		}

		public function test_pinned_plugins_are_left_alone(): void {
			$this->enable_autoupdate( 'demo' );
			$this->api->set_pin( 'demo', '1.0.0' );
			$this->run_schedule();

			$this->assertSame( array(), $this->api->upgrades );
			$this->assertSame( array(), $GLOBALS['hub_test_mail'], 'Nothing to report.' );
		}

		public function test_a_recent_release_waits_for_the_delay(): void {
			$this->enable_autoupdate( 'demo' );
			$this->set_settings( array( 'update_delay_days' => 3 ) );
			$this->api->published['demo'] = $GLOBALS['hub_test_now'] - DAY_IN_SECONDS;

			$this->run_schedule();

			$this->assertSame( array(), $this->api->upgrades );
			$this->assertStringContainsString( 'demo (v1.0.0 → v1.1.0) – waiting 3 days after release', $GLOBALS['hub_test_mail'][0][2] );
		}

		public function test_an_old_enough_release_is_installed_despite_the_delay(): void {
			$this->enable_autoupdate( 'demo' );
			$this->set_settings( array( 'update_delay_days' => 3 ) );
			$this->api->published['demo'] = $GLOBALS['hub_test_now'] - 5 * DAY_IN_SECONDS;

			$this->run_schedule();
			$this->assertSame( '1.1.0', $this->api->get_installed_plugin_version( 'demo' ) );
		}

		public function test_a_release_with_unknown_age_waits_when_a_delay_is_set(): void {
			$this->enable_autoupdate( 'demo' );
			$this->set_settings( array( 'update_delay_days' => 1 ) );
			$this->run_schedule();
			$this->assertSame( array(), $this->api->upgrades );
		}

		public function test_maintenance_window_blocks_runs_outside_it(): void {
			$this->enable_autoupdate( 'demo' );
			// Wednesday (3) at 10:00; the window allows Monday to Friday, 01:00-05:00.
			$this->set_settings( array( 'window_enabled' => true, 'window_days' => array( 1, 2, 3, 4, 5 ), 'window_start' => 1, 'window_end' => 5 ) );
			$this->run_schedule();
			$this->assertSame( array(), $this->api->upgrades );
			$this->assertArrayNotHasKey( 'plugin_hub_last_run', $GLOBALS['hub_test_options'], 'A skipped run does not use up the day.' );

			$this->set_settings( array( 'window_enabled' => true, 'window_days' => array( 1, 2, 3, 4, 5 ), 'window_start' => 9, 'window_end' => 11 ) );
			$this->run_schedule();
			$this->assertCount( 1, $this->api->upgrades );
		}

		public function test_window_on_other_days_blocks_the_run_and_force_overrides_it(): void {
			$this->enable_autoupdate( 'demo' );
			$this->set_settings( array( 'window_enabled' => true, 'window_days' => array( 6 ), 'window_start' => 0, 'window_end' => 0 ) );
			$this->run_schedule();
			$this->assertSame( array(), $this->api->upgrades );

			$this->run_schedule( true );
			$this->assertCount( 1, $this->api->upgrades );
		}

		public function test_the_scheduled_check_runs_once_a_day(): void {
			$this->run_schedule();
			$this->assertGreaterThan( 0, $GLOBALS['hub_test_options']['plugin_hub_last_run'] );
			$GLOBALS['hub_test_options']['plugin_hub_last_run'] = time();
			$GLOBALS['hub_test_mail']                           = array();
			$GLOBALS['hub_test_options']['plugin_hub_last_notification_hash'] = '';

			$this->run_schedule();
			$this->assertSame( array(), $GLOBALS['hub_test_mail'], 'The second run within a day does nothing.' );

			$this->run_schedule( true );
			$this->assertCount( 1, $GLOBALS['hub_test_mail'], 'Force ignores the daily limit.' );
		}

		public function test_identical_reports_are_not_sent_twice(): void {
			$this->run_schedule( true );
			$this->run_schedule( true );
			$this->assertCount( 1, $GLOBALS['hub_test_mail'] );
		}

		public function test_notify_mode_failures_only_is_quiet_for_plain_updates(): void {
			$this->set_settings( array( 'notify_mode' => 'failures' ) );
			$this->run_schedule();
			$this->assertSame( array(), $GLOBALS['hub_test_mail'] );
		}

		public function test_a_rolled_back_auto_update_is_reported_as_failed(): void {
			$this->enable_autoupdate( 'demo' );
			$this->api->healthy = false;
			$this->set_settings( array( 'notify_mode' => 'failures' ) );

			$this->run_schedule();

			$this->assertSame( '1.0.0', $this->api->get_installed_plugin_version( 'demo' ) );
			$this->assertCount( 1, $GLOBALS['hub_test_mail'] );
			$this->assertStringContainsString( 'automatic update of the following plugins failed', $GLOBALS['hub_test_mail'][0][2] );
			$this->assertStringContainsString( 'demo (v1.0.0 → v1.1.0) – rolled back after a failed health check', $GLOBALS['hub_test_mail'][0][2] );

			// The same broken release is not retried on the next run.
			$this->api->upgrades = array();
			$this->run_schedule( true );
			$this->assertSame( array(), $this->api->upgrades );
		}

		public function test_a_plain_update_failure_is_reported(): void {
			$this->enable_autoupdate( 'demo' );
			$this->api->upgrade_results = array( new \WP_Error( 'upgrade_failed', 'Disk full' ) );
			$this->run_schedule();

			$this->assertStringContainsString( "failed. Update them manually from Plugin Hub:\n  - demo (v1.0.0 → v1.1.0)", $GLOBALS['hub_test_mail'][0][2] );
		}

		public function test_an_empty_run_clears_the_notification_hash(): void {
			$GLOBALS['hub_test_options']['plugin_hub_last_notification_hash'] = 'old';
			Flow_Test_API::write_plugin( 'demo', '1.1.0' );

			$this->run_schedule();

			$this->assertArrayNotHasKey( 'plugin_hub_last_notification_hash', $GLOBALS['hub_test_options'] );
			$this->assertSame( array(), $GLOBALS['hub_test_mail'] );
		}
	}
}
