<?php
/**
 * Tests the WP-CLI command class against recording doubles.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once __DIR__ . '/wp-cli-fake.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';
	require_once dirname( __DIR__ ) . '/includes/class-config.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';
	require_once dirname( __DIR__ ) . '/includes/class-cli.php';

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
	if ( ! function_exists( __NAMESPACE__ . '\\size_format' ) ) {
		function size_format( $bytes, $decimals = 0 ) {
			return $bytes . ' B';
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;
	use PluginHub\CLI;
	use PluginHub\MU_Plugins;

	/**
	 * API double: three catalog plugins, two installed, scripted results.
	 */
	class Cli_Test_API extends API {
		public array $installed = array( 'alpha' => '1.0.0', 'beta' => '2.0.0' );
		public array $active    = array( 'alpha' );
		public array $pins      = array();
		public array $calls     = array();
		/** Results by "method:repo", WP_Error or true. */
		public array $results = array();

		public function __construct() {}

		public function get_org_repos() {
			return array(
				array( 'name' => 'alpha', 'version' => '1.5.0', 'available' => true ),
				array( 'name' => 'beta', 'version' => '2.0.0', 'available' => true ),
				array( 'name' => 'gamma', 'version' => '3.0.0', 'available' => true ),
			);
		}
		public function get_cached_repos() {
			return $this->get_org_repos();
		}
		public function is_plugin_installed( $name ) {
			return isset( $this->installed[ $name ] );
		}
		public function is_plugin_active( $name ) {
			return in_array( $name, $this->active, true );
		}
		public function get_installed_plugin_version( $name ) {
			return $this->installed[ $name ] ?? '';
		}
		public function is_update_available( $repo, $installed ) {
			return version_compare( $repo['version'], $installed, '>' );
		}
		public function is_update_allowed( $repo, $installed ) {
			return true;
		}
		public function get_pin( $repo ) {
			return $this->pins[ $repo ] ?? '';
		}
		public function set_pin( $repo, $pin ) {
			$this->calls[] = "set_pin:$repo:$pin";
			return $this->results[ "set_pin:$repo" ] ?? true;
		}
		public function install_plugin( $name, $version, $background = false ) {
			$this->calls[] = "install:$name:$version";
			return $this->results[ "install:$name" ] ?? true;
		}
		public function update_plugin( $name, $version, $rollback = false, $background = false, $log = '' ) {
			$this->calls[] = ( $rollback ? 'rollback' : 'update' ) . ":$name:$version";
			return $this->results[ "update:$name" ] ?? true;
		}
		public function activate_plugin_by_repo( $name, $network = false ) {
			$this->calls[] = 'activate:' . $name . ( $network ? ':network' : '' );
			return $this->results[ "activate:$name" ] ?? true;
		}
		public function deactivate_plugin_by_repo( $name, $network = false, $force = false ) {
			$this->calls[] = 'deactivate:' . $name . ( $network ? ':network' : '' ) . ( $force ? ':force' : '' );
			return $this->results[ "deactivate:$name" ] ?? true;
		}
		public function delete_plugin_by_repo( $name, $force = false ) {
			$this->calls[] = 'delete:' . $name . ( $force ? ':force' : '' );
			return $this->results[ "delete:$name" ] ?? true;
		}
		public function refresh_csv_cache() {
			$this->calls[] = 'refresh';
			return $this->get_org_repos();
		}
		public function do_auto_updates_and_notify( $force = false ) {
			$this->calls[] = 'schedule';
		}
		public function log_activity( $action, $plugin, $version = '' ) {}
	}

	/**
	 * MU manager double with scripted results.
	 */
	class Cli_Test_MU extends MU_Plugins {
		public array $calls  = array();
		public $update_all   = array( 'updated' => array(), 'failed' => array() );
		public array $failed = array();

		public function refresh_catalog() {
			$this->calls[] = 'refresh';
			return array();
		}
		public function get_catalog( $force = false ) {
			return array();
		}
		public function get_installed( $fetch = true ) {
			return array(
				array(
					'file'           => 'one.php',
					'category'       => 'admin',
					'local_version'  => '1.0.0',
					'remote_version' => '1.1.0',
					'update'         => true,
					'autoupdate'     => false,
				),
			);
		}
		public function update_all( $log_action = 'mu_update' ) {
			return $this->update_all;
		}
		public function install( $file ) {
			return $this->act( 'install', $file );
		}
		public function update( $file, $log_action = 'mu_update' ) {
			return $this->act( 'update', $file );
		}
		public function rollback( $file ) {
			return $this->act( 'rollback', $file );
		}
		private function act( $action, $file ) {
			$this->calls[] = "$action:$file";
			return in_array( $file, $this->failed, true ) ? new \WP_Error( 'x', 'boom' ) : '1.2.3';
		}
	}

	final class CliTest extends TestCase {

		private Cli_Test_API $api;
		private Cli_Test_MU $mu;
		private CLI $cli;
		private string $uploads;

		protected function setUp(): void {
			$this->uploads               = sys_get_temp_dir() . '/plugin-hub-cli-' . getmypid();
			$GLOBALS['hub_test_uploads'] = $this->uploads;
			$GLOBALS['hub_test_options'] = array();
			\WP_CLI::reset();
			$this->api = new Cli_Test_API();
			$this->mu  = new Cli_Test_MU( $this->api );
			$this->cli = new CLI( $this->api, $this->mu );
		}

		protected function tearDown(): void {
			if ( is_dir( $this->uploads ) ) {
				foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->uploads, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
					$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
				}
				rmdir( $this->uploads );
			}
			unset( $GLOBALS['hub_test_uploads'], $GLOBALS['hub_test_options'] );
		}

		/**
		 * Run a command and return the message that halted it, or null.
		 */
		private function halts( callable $run ): ?string {
			try {
				$run();
			} catch ( \RuntimeException $e ) {
				return $e->getMessage();
			}
			return null;
		}

		// ------------------------------------------------------------------ list

		public function test_list_shows_status_versions_and_autoupdate(): void {
			$GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] = array( 'beta' );
			$this->api->pins['alpha']                                      = '1.x';

			$this->cli->list_plugins( array(), array() );

			$table = \WP_CLI::$tables[0];
			$this->assertSame( 'table', $table['format'] );
			$this->assertSame( array( 'name', 'status', 'version', 'latest', 'update', 'autoupdate', 'pin' ), $table['fields'] );
			$this->assertSame(
				array(
					array( 'name' => 'alpha', 'status' => 'active', 'version' => '1.0.0', 'latest' => '1.5.0', 'update' => 'available', 'autoupdate' => 'off', 'pin' => '1.x' ),
					array( 'name' => 'beta', 'status' => 'inactive', 'version' => '2.0.0', 'latest' => '2.0.0', 'update' => 'none', 'autoupdate' => 'on', 'pin' => '' ),
					array( 'name' => 'gamma', 'status' => 'not installed', 'version' => '', 'latest' => '3.0.0', 'update' => 'none', 'autoupdate' => 'off', 'pin' => '' ),
				),
				$table['items']
			);
		}

		#[DataProvider( 'status_filters' )]
		public function test_list_status_filter( string $status, array $expected ): void {
			$this->cli->list_plugins( array(), array( 'status' => $status ) );
			$this->assertSame( $expected, array_column( \WP_CLI::$tables[0]['items'], 'name' ) );
		}

		public static function status_filters(): array {
			return array(
				'all'       => array( 'all', array( 'alpha', 'beta', 'gamma' ) ),
				'active'    => array( 'active', array( 'alpha' ) ),
				'inactive'  => array( 'inactive', array( 'beta' ) ),
				'installed' => array( 'installed', array( 'alpha', 'beta' ) ),
				'available' => array( 'available', array( 'gamma' ) ),
				'update'    => array( 'update', array( 'alpha' ) ),
			);
		}

		public function test_list_honours_fields_and_format(): void {
			$this->cli->list_plugins( array(), array( 'fields' => 'name,pin', 'format' => 'json' ) );
			$this->assertSame( array( 'name', 'pin' ), \WP_CLI::$tables[0]['fields'] );
			$this->assertSame( 'json', \WP_CLI::$tables[0]['format'] );
		}

		// --------------------------------------------------------------- install

		public function test_install_uses_the_catalog_version_and_can_activate(): void {
			$this->cli->install( array( 'gamma' ), array( 'activate' => true ) );
			$this->assertSame( array( 'install:gamma:3.0.0', 'activate:gamma' ), $this->api->calls );
			$this->assertSame( array( 'Installed gamma 3.0.0.', 'Activated gamma.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_install_unknown_plugin_fails_the_command(): void {
			$message = $this->halts( fn () => $this->cli->install( array( 'nope' ), array() ) );
			$this->assertSame( 'WP_CLI::error: 1 item(s) failed.', $message );
			$this->assertSame( array( 'nope: not in the catalog or has no release.' ), \WP_CLI::texts( 'warning' ) );
			$this->assertSame( array(), $this->api->calls );
		}

		public function test_install_error_is_a_warning_and_counts_as_failure(): void {
			$this->api->results['install:gamma'] = new \WP_Error( 'x', 'Download failed' );
			$message                             = $this->halts( fn () => $this->cli->install( array( 'gamma' ), array() ) );
			$this->assertNotNull( $message );
			$this->assertSame( array( 'gamma: Download failed' ), \WP_CLI::texts( 'warning' ) );
		}

		// ---------------------------------------------------------------- update

		public function test_update_all_updates_only_outdated_plugins(): void {
			$this->cli->update( array(), array( 'all' => true ) );
			$this->assertSame( array( 'update:alpha:1.5.0' ), $this->api->calls );
			$this->assertSame( array( 'Updated alpha to 1.5.0.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_update_all_dry_run_lists_without_updating(): void {
			$this->cli->update( array(), array( 'all' => true, 'dry-run' => true ) );
			$this->assertSame( array(), $this->api->calls );
			$this->assertSame( array( 'Would update alpha 1.0.0 -> 1.5.0.' ), \WP_CLI::texts( 'log' ) );
			$this->assertSame( array(), \WP_CLI::texts( 'success' ) );
		}

		public function test_update_all_dry_run_reports_when_nothing_is_pending(): void {
			$this->api->installed['alpha'] = '1.5.0';
			$this->cli->update( array(), array( 'all' => true, 'dry-run' => true ) );
			$this->assertSame( array( 'Everything is up to date.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_update_all_reports_when_everything_is_current(): void {
			$this->api->installed['alpha'] = '1.5.0';
			$this->cli->update( array(), array( 'all' => true ) );
			$this->assertSame( array( 'Everything is up to date.' ), \WP_CLI::texts( 'success' ) );
			$this->assertSame( array(), $this->api->calls );
		}

		public function test_update_named_plugin_says_when_it_is_current(): void {
			$this->cli->update( array( 'beta' ), array() );
			$this->assertSame( array( 'beta is up to date (2.0.0).' ), \WP_CLI::texts( 'log' ) );
		}

		public function test_update_requires_a_target(): void {
			$message = $this->halts( fn () => $this->cli->update( array(), array() ) );
			$this->assertSame( 'WP_CLI::error: Specify one or more plugins or use --all.', $message );
		}

		public function test_update_rejects_plugins_that_are_not_installed(): void {
			$message = $this->halts( fn () => $this->cli->update( array( 'gamma' ), array() ) );
			$this->assertNotNull( $message );
			$this->assertSame( array( 'gamma: not an installed catalog plugin.' ), \WP_CLI::texts( 'warning' ) );
		}

		public function test_update_failure_exits_with_an_error(): void {
			$this->api->results['update:alpha'] = new \WP_Error( 'x', 'Health check failed' );
			$message                            = $this->halts( fn () => $this->cli->update( array( 'alpha' ), array() ) );
			$this->assertSame( 'WP_CLI::error: 1 item(s) failed.', $message );
			$this->assertSame( array( 'alpha: Health check failed' ), \WP_CLI::texts( 'warning' ) );
		}

		public function test_rollback_installs_the_requested_release(): void {
			$this->cli->rollback( array( 'alpha', '0.9.0' ) );
			$this->assertSame( array( 'rollback:alpha:0.9.0' ), $this->api->calls );
			$this->assertSame( array( 'Rolled alpha back to 0.9.0.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_rollback_error_halts(): void {
			$this->api->results['update:alpha'] = new \WP_Error( 'x', 'No such release' );
			$message                            = $this->halts( fn () => $this->cli->rollback( array( 'alpha', '9.9.9' ) ) );
			$this->assertSame( 'WP_CLI::error: No such release', $message );
		}

		// -------------------------------------------------- activate / delete / pin

		public function test_activate_and_deactivate_pass_flags_through(): void {
			$this->cli->activate( array( 'alpha', 'beta' ), array( 'network' => true ) );
			$this->cli->deactivate( array( 'alpha' ), array( 'network' => true, 'force' => true ) );
			$this->assertSame( array( 'activate:alpha:network', 'activate:beta:network', 'deactivate:alpha:network:force' ), $this->api->calls );
		}

		public function test_deactivate_failure_is_reported_at_the_end(): void {
			$this->api->results['deactivate:alpha'] = new \WP_Error( 'x', 'Required by other plugins' );
			$message                                = $this->halts( fn () => $this->cli->deactivate( array( 'alpha', 'beta' ), array() ) );
			$this->assertSame( 'WP_CLI::error: 1 item(s) failed.', $message );
			$this->assertContains( 'deactivate:beta', $this->api->calls, 'The next plugin is still processed.' );
		}

		public function test_delete_passes_force(): void {
			$this->cli->delete( array( 'beta' ), array( 'force' => true ) );
			$this->assertSame( array( 'delete:beta:force' ), $this->api->calls );
			$this->assertSame( array( 'Deleted beta.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_pin_shows_sets_and_clears(): void {
			$this->cli->pin( array( 'alpha' ), array() );
			$this->api->pins['alpha'] = '1.x';
			$this->cli->pin( array( 'alpha' ), array() );
			$this->cli->pin( array( 'alpha', '1.2.x' ), array() );
			$this->cli->pin( array( 'alpha' ), array( 'clear' => true ) );

			$this->assertSame( array( 'alpha is not pinned.', 'alpha is pinned to 1.x.' ), \WP_CLI::texts( 'log' ) );
			$this->assertSame( array( 'set_pin:alpha:1.2.x', 'set_pin:alpha:' ), $this->api->calls );
			$this->assertSame( array( 'Pinned alpha to 1.2.x.', 'Pin removed from alpha.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_invalid_pin_halts(): void {
			$this->api->results['set_pin:alpha'] = new \WP_Error( 'x', 'Invalid version pin.' );
			$message                             = $this->halts( fn () => $this->cli->pin( array( 'alpha', 'latest' ), array() ) );
			$this->assertSame( 'WP_CLI::error: Invalid version pin.', $message );
		}

		// --------------------------------------------------- refresh / schedule

		public function test_refresh_reloads_both_catalogs(): void {
			$this->cli->refresh( array() );
			$this->assertSame( array( 'refresh' ), $this->api->calls );
			$this->assertSame( array( 'refresh' ), $this->mu->calls );
			$this->assertSame( array( 'Catalog refreshed (3 plugins).' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_run_schedule_triggers_the_scheduled_check(): void {
			$this->cli->run_schedule( array() );
			$this->assertSame( array( 'schedule' ), $this->api->calls );
		}

		// -------------------------------------------------------------------- mu

		public function test_mu_list_maps_rows(): void {
			$this->cli->mu( array( 'list' ), array( 'format' => 'csv' ) );
			$this->assertSame( 'csv', \WP_CLI::$tables[0]['format'] );
			$this->assertSame(
				array( array( 'file' => 'one.php', 'category' => 'admin', 'version' => '1.0.0', 'latest' => '1.1.0', 'update' => 'available', 'autoupdate' => 'off' ) ),
				\WP_CLI::$tables[0]['items']
			);
		}

		public function test_mu_actions_run_per_file(): void {
			$this->cli->mu( array( 'install', 'one.php', 'two.php' ), array() );
			$this->assertSame( array( 'install:one.php', 'install:two.php' ), $this->mu->calls );
			$this->assertSame( array( 'one.php: install done (version 1.2.3).', 'two.php: install done (version 1.2.3).' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_mu_failure_exits_with_an_error(): void {
			$this->mu->failed = array( 'two.php' );
			$message          = $this->halts( fn () => $this->cli->mu( array( 'rollback', 'one.php', 'two.php' ), array() ) );
			$this->assertSame( 'WP_CLI::error: 1 item(s) failed.', $message );
			$this->assertSame( array( 'two.php: boom' ), \WP_CLI::texts( 'warning' ) );
		}

		public function test_mu_update_without_files_updates_all(): void {
			$this->mu->update_all = array( 'updated' => array( 'one.php' => '1.1.0' ), 'failed' => array( 'two.php' => 'nope' ) );
			$message              = $this->halts( fn () => $this->cli->mu( array( 'update' ), array() ) );
			$this->assertNotNull( $message );
			$this->assertSame( array( 'Updated one.php to 1.1.0.' ), \WP_CLI::texts( 'success' ) );
			$this->assertSame( array( 'two.php: nope' ), \WP_CLI::texts( 'warning' ) );
		}

		public function test_mu_update_all_with_nothing_to_do(): void {
			$this->cli->mu( array( 'update' ), array() );
			$this->assertSame( array( 'All MU plugins are up to date.' ), \WP_CLI::texts( 'success' ) );
		}

		public function test_mu_update_all_error_halts(): void {
			$this->mu->update_all = new \WP_Error( 'x', 'Not allowed' );
			$message              = $this->halts( fn () => $this->cli->mu( array( 'update' ), array() ) );
			$this->assertSame( 'WP_CLI::error: Not allowed', $message );
		}

		#[DataProvider( 'bad_mu_usage' )]
		public function test_mu_usage_errors( array $args ): void {
			$message = $this->halts( fn () => $this->cli->mu( $args, array() ) );
			$this->assertStringContainsString( 'Usage: wp plugin-hub mu', (string) $message );
		}

		public static function bad_mu_usage(): array {
			return array(
				'unknown action' => array( array( 'explode', 'one.php' ) ),
				'install no file' => array( array( 'install' ) ),
				'rollback no file' => array( array( 'rollback' ) ),
			);
		}

		// ------------------------------------------------------------- settings

		public function test_settings_get_lists_and_reads_one_key(): void {
			$this->cli->settings( array( 'get' ) );
			$rows = array_column( \WP_CLI::$tables[0]['items'], 'value', 'key' );
			$this->assertArrayHasKey( 'backup_enabled', $rows );
			$this->assertContains( $rows['backup_enabled'], array( 'true', 'false' ) );

			$this->cli->settings( array( 'get', 'backup_enabled' ) );
			$this->assertContains( \WP_CLI::texts( 'line' )[0], array( 'true', 'false' ) );
		}

		public function test_settings_get_unknown_key_halts(): void {
			$message = $this->halts( fn () => $this->cli->settings( array( 'get', 'nonsense' ) ) );
			$this->assertSame( 'WP_CLI::error: Unknown setting: nonsense', $message );
		}

		public function test_settings_set_parses_booleans_and_lists(): void {
			$this->cli->settings( array( 'set', 'backup_enabled', 'false' ) );
			$this->cli->settings( array( 'set', 'window_days', 'mon, tue ,,wed' ) );

			$saved = \PluginHub\Settings::all();
			$this->assertFalse( $saved['backup_enabled'] );
			$this->assertSame( 'backup_enabled = false', \WP_CLI::texts( 'success' )[0] );
			$this->assertStringStartsWith( 'window_days = ', \WP_CLI::texts( 'success' )[1] );
		}

		public function test_settings_set_requires_key_and_value(): void {
			foreach ( array( array( 'set' ), array( 'set', 'nonsense', '1' ), array( 'set', 'backup_enabled' ) ) as $args ) {
				\WP_CLI::reset();
				$message = $this->halts( fn () => $this->cli->settings( $args ) );
				$this->assertSame( 'WP_CLI::error: Usage: wp plugin-hub settings set <key> <value>', $message );
			}
		}

		// --------------------------------------------------------- export / import

		public function test_export_prints_json_or_writes_a_file(): void {
			$this->cli->export( array(), array() );
			$printed = json_decode( \WP_CLI::texts( 'line' )[0], true );
			$this->assertSame( 'plugin-hub-config', $printed['format'] );

			$file = sys_get_temp_dir() . '/plugin-hub-cli-export-' . getmypid() . '.json';
			$this->cli->export( array(), array( 'file' => $file ) );
			$this->assertSame( 'plugin-hub-config', json_decode( (string) file_get_contents( $file ), true )['format'] );
			$this->assertSame( array( 'Exported to ' . $file ), \WP_CLI::texts( 'success' ) );
			unlink( $file );
		}

		public function test_export_to_an_unwritable_path_halts(): void {
			$message = $this->halts( fn () => @$this->cli->export( array(), array( 'file' => '/nonexistent-dir/x.json' ) ) );
			$this->assertSame( 'WP_CLI::error: Could not write /nonexistent-dir/x.json', $message );
		}

		public function test_import_rejects_unreadable_and_invalid_files(): void {
			$message = $this->halts( fn () => $this->cli->import( array( '/nonexistent/x.json' ), array() ) );
			$this->assertSame( 'WP_CLI::error: Cannot read /nonexistent/x.json', $message );

			$file = sys_get_temp_dir() . '/plugin-hub-cli-bad-' . getmypid() . '.json';
			file_put_contents( $file, '{"hello":"world"}' );
			$message = $this->halts( fn () => $this->cli->import( array( $file ), array() ) );
			$this->assertSame( 'WP_CLI::error: This is not a Plugin Hub configuration file.', $message );
			unlink( $file );
		}

		public function test_import_dry_run_applies_nothing(): void {
			$file = $this->config_file( array( array( 'repo' => 'gamma', 'version' => '3.0.0', 'active' => true, 'autoupdate' => true, 'pin' => '' ) ) );
			$this->cli->import( array( $file ), array( 'dry-run' => true ) );
			$this->assertSame( array( 'Valid configuration: 1 plugins, 0 MU plugins.' ), \WP_CLI::texts( 'success' ) );
			$this->assertSame( array(), $this->api->calls );
			unlink( $file );
		}

		public function test_import_lists_missing_plugins_unless_told_to_install_them(): void {
			$file = $this->config_file( array( array( 'repo' => 'gamma', 'version' => '3.0.0', 'active' => true, 'autoupdate' => false, 'pin' => '' ) ) );

			$this->cli->import( array( $file ), array() );
			$this->assertSame( array( 'Not installed: gamma 3.0.0' ), \WP_CLI::texts( 'log' ) );
			$this->assertSame( array(), array_filter( $this->api->calls, fn ( $c ) => str_starts_with( $c, 'install' ) ) );

			\WP_CLI::reset();
			$this->cli->import( array( $file ), array( 'install-missing' => true ) );
			$this->assertContains( 'install:gamma:3.0.0', $this->api->calls );
			$this->assertContains( 'activate:gamma', $this->api->calls );
			unlink( $file );
		}

		public function test_import_install_failure_exits_with_an_error(): void {
			$this->api->results['install:gamma'] = new \WP_Error( 'x', 'Checksum mismatch' );
			$file                                = $this->config_file( array( array( 'repo' => 'gamma', 'version' => '3.0.0', 'active' => false, 'autoupdate' => false, 'pin' => '' ) ) );
			$message                             = $this->halts( fn () => $this->cli->import( array( $file ), array( 'install-missing' => true ) ) );
			$this->assertSame( 'WP_CLI::error: 1 item(s) failed.', $message );
			$this->assertSame( array( 'gamma: Checksum mismatch' ), \WP_CLI::texts( 'warning' ) );
			unlink( $file );
		}

		private function config_file( array $plugins ): string {
			$file = sys_get_temp_dir() . '/plugin-hub-cli-config-' . getmypid() . '-' . uniqid() . '.json';
			file_put_contents(
				$file,
				json_encode(
					array(
						'format'   => 'plugin-hub-config',
						'version'  => 1,
						'plugins'  => $plugins,
						'mu'       => array(),
						'settings' => array(),
					)
				)
			);
			return $file;
		}

		// -------------------------------------------------------------- backups

		public function test_backups_command_lists_and_restore_rejects_unknown_ids(): void {
			$dir = \PluginHub\Backups::get_dir() . '/alpha';
			mkdir( $dir, 0755, true );
			file_put_contents( $dir . '/1.0.0_1700000000.zip', 'zip' );

			$this->cli->backups( array(), array( 'format' => 'json' ) );
			$this->assertSame(
				array( array( 'id' => 'alpha__1.0.0_1700000000.zip', 'repo' => 'alpha', 'version' => '1.0.0', 'created' => '2023-11-14 22:13:20', 'size' => '3 B' ) ),
				\WP_CLI::$tables[0]['items']
			);

			$message = $this->halts( fn () => $this->cli->restore_backup( array( 'nope__x.zip' ) ) );
			$this->assertNotNull( $message );
		}
	}
}
