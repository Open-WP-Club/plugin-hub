<?php
/**
 * Tests the MU_Plugins flows that talk to GitHub and the filesystem.
 *
 * HTTP is served from $GLOBALS['hub_test_routes'] and the MU directory is a
 * temporary folder, so the full install / update / rollback cycle runs without
 * WordPress.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';

	if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
		define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-mu-dir-' . getmypid() );
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;
	use PluginHub\MU_Plugins;

	final class MuPluginsNetworkTest extends TestCase {

		private const TREE_URL   = 'https://api.github.com/repos/Open-WP-Club/mu-plugin/git/trees/main?recursive=1';
		private const README_URL = 'https://raw.githubusercontent.com/Open-WP-Club/mu-plugin/main/README.MD';
		private const RAW        = 'https://raw.githubusercontent.com/Open-WP-Club/mu-plugin/main/';

		private string $uploads;

		protected function setUp(): void {
			$this->uploads                  = sys_get_temp_dir() . '/plugin-hub-uploads-' . getmypid();
			$GLOBALS['hub_test_uploads']    = $this->uploads;
			$GLOBALS['hub_test_options']    = array();
			$GLOBALS['hub_test_transients'] = array();
			$GLOBALS['hub_test_requests']   = array();
			$GLOBALS['hub_test_routes']     = array();
			$GLOBALS['hub_test_file_mods']  = true;
			$this->remove_dir( WPMU_PLUGIN_DIR );
			$this->remove_dir( $this->uploads );
			mkdir( WPMU_PLUGIN_DIR, 0755, true );
		}

		protected function tearDown(): void {
			$this->remove_dir( WPMU_PLUGIN_DIR );
			$this->remove_dir( $this->uploads );
			unset( $GLOBALS['hub_test_multisite'], $GLOBALS['hub_test_super_admin'], $GLOBALS['hub_test_options'], $GLOBALS['hub_test_transients'], $GLOBALS['hub_test_requests'], $GLOBALS['hub_test_routes'], $GLOBALS['hub_test_uploads'], $GLOBALS['hub_test_file_mods'] );
		}

		private function remove_dir( string $dir ): void {
			if ( ! is_dir( $dir ) ) {
				return;
			}
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST ) as $item ) {
				$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
			}
			rmdir( $dir );
		}

		private function plugin( string $name, string $version, string $extra = '' ): string {
			return "<?php\n/**\n * Plugin Name: {$name}\n * Description: Test.\n * Version: {$version}\n */\nif ( ! defined( 'ABSPATH' ) ) { exit; }\n{$extra}\n";
		}

		private function make_manager(): MU_Plugins {
			$api = ( new \ReflectionClass( API::class ) )->newInstanceWithoutConstructor();
			return new MU_Plugins( $api );
		}

		/**
		 * Serve a catalog made of the given files (path => contents) plus a README table.
		 */
		private function serve( array $files ): void {
			$tree = array();
			$rows = '';
			foreach ( $files as $path => $contents ) {
				$tree[]                                      = array(
					'type' => 'blob',
					'path' => $path,
					'sha'  => MU_Plugins::blob_sha( $contents ),
				);
				$GLOBALS['hub_test_routes'][ self::RAW . $path ] = array( 'status' => 200, 'body' => $contents );
				if ( false !== strpos( $path, '/' ) && 'mu-plugins/000-helpers.php' !== $path ) {
					$header = MU_Plugins::parse_header( $contents );
					$rows  .= '| [' . $header['name'] . '](' . $path . ') | Does ' . basename( $path ) . ". | {$header['version']} | 6.6+ | 7.4+ |\n";
				}
			}
			$GLOBALS['hub_test_routes'][ self::TREE_URL ]   = array( 'status' => 200, 'body' => json_encode( array( 'tree' => $tree ) ) );
			$GLOBALS['hub_test_routes'][ self::README_URL ] = array(
				'status' => 200,
				'body'   => "<!-- PLUGIN_LIST_START -->\n| Plugin Name | Description | Version | WP | PHP |\n|---|---|---:|---:|---:|\n{$rows}<!-- PLUGIN_LIST_END -->\n",
			);
		}

		private function count_requests( string $url ): int {
			return count( array_keys( $GLOBALS['hub_test_requests'], $url, true ) );
		}

		// ---------------------------------------------------------------- catalog

		public function test_catalog_is_built_cached_and_remembered(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$manager = $this->make_manager();

			$catalog = $manager->get_catalog();
			$this->assertSame( array( 'one.php' ), array_keys( $catalog ) );
			$this->assertSame( 'admin', $catalog['one.php']['category'] );

			$manager->get_catalog();
			$this->assertSame( 1, $this->count_requests( self::TREE_URL ), 'A second call uses the transient.' );
			$this->assertSame( $catalog, $GLOBALS['hub_test_options']['plugin_hub_mu_last_known'] );
		}

		public function test_catalog_failure_falls_back_to_last_known_and_backs_off(): void {
			$known                                                 = array( 'one.php' => array( 'file' => 'one.php', 'path' => 'mu-plugins/admin/one.php', 'category' => 'admin', 'sha' => str_repeat( 'a', 40 ) ) );
			$GLOBALS['hub_test_options']['plugin_hub_mu_last_known'] = $known;
			$GLOBALS['hub_test_routes'][ self::TREE_URL ]          = array( 'status' => 500, 'body' => '' );
			$manager = $this->make_manager();

			$this->assertSame( $known, $manager->get_catalog() );
			$this->assertSame( $known, $manager->get_catalog() );
			$this->assertSame( 1, $this->count_requests( self::TREE_URL ), 'The failure back-off prevents a retry storm.' );

			$this->serve( array( 'mu-plugins/admin/two.php' => $this->plugin( 'Two', '1.0.0' ) ) );
			$this->assertSame( array( 'two.php' ), array_keys( $manager->refresh_catalog() ), 'An explicit refresh ignores the back-off.' );
		}

		public function test_truncated_tree_is_not_trusted(): void {
			$GLOBALS['hub_test_routes'][ self::TREE_URL ] = array(
				'status' => 200,
				'body'   => json_encode( array( 'truncated' => true, 'tree' => array( array( 'type' => 'blob', 'path' => 'mu-plugins/admin/one.php', 'sha' => str_repeat( 'a', 40 ) ) ) ) ),
			);

			$this->assertSame( array(), $this->make_manager()->get_catalog() );
		}

		public function test_rate_limit_response_backs_off(): void {
			$GLOBALS['hub_test_routes'][ self::TREE_URL ] = array( 'status' => 403, 'body' => '', 'headers' => array( 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) ( time() + 600 ) ) );
			$manager                                      = $this->make_manager();

			$manager->get_catalog();
			$manager->get_catalog();

			$this->assertSame( 1, $this->count_requests( self::TREE_URL ) );
		}

		// ----------------------------------------------------------- descriptions

		public function test_descriptions_are_loaded_once_and_survive_an_outage(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$manager = $this->make_manager();

			$descriptions = $manager->get_descriptions();
			$this->assertSame( 'Does one.php.', $descriptions['one.php']['description'] );
			$manager->get_descriptions();
			$this->assertSame( 1, $this->count_requests( self::README_URL ) );

			// Cache expires and GitHub is down: the last known descriptions are used.
			$GLOBALS['hub_test_transients']                = array();
			$GLOBALS['hub_test_routes'][ self::README_URL ] = array( 'status' => 502, 'body' => '' );
			$this->assertSame( $descriptions, $manager->get_descriptions() );
			$manager->get_descriptions();
			$this->assertSame( 2, $this->count_requests( self::README_URL ), 'The failure is not retried immediately.' );
		}

		public function test_unparsable_readme_does_not_replace_known_descriptions(): void {
			$known                                                    = array( 'one.php' => array( 'name' => 'One', 'description' => 'Known.' ) );
			$GLOBALS['hub_test_options']['plugin_hub_mu_descriptions'] = $known;
			$GLOBALS['hub_test_routes'][ self::README_URL ]           = array( 'status' => 200, 'body' => '# no table here' );

			$this->assertSame( $known, $this->make_manager()->get_descriptions() );
			$this->assertSame( $known, $GLOBALS['hub_test_options']['plugin_hub_mu_descriptions'] );
		}

		// ------------------------------------------------------------ install / update

		public function test_install_writes_the_file_flat_and_logs_it(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$manager = $this->make_manager();

			$this->assertSame( '1.0.0', $manager->install( 'one.php' ) );
			$this->assertFileExists( WPMU_PLUGIN_DIR . '/one.php' );
			$this->assertSame( 'mu_install', end( $GLOBALS['hub_test_options']['plugin_hub_activity_log'] )['action'] );
			$this->assertInstanceOf( \WP_Error::class, $manager->install( 'one.php' ), 'Installing twice is refused.' );
		}

		public function test_install_rejects_unknown_files_and_blocked_site(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$manager = $this->make_manager();

			$this->assertInstanceOf( \WP_Error::class, $manager->install( '../evil.php' ) );
			$this->assertInstanceOf( \WP_Error::class, $manager->install( 'missing.php' ) );
			$this->assertInstanceOf( \WP_Error::class, $manager->install( '000-helpers.php' ) );

			$GLOBALS['hub_test_file_mods'] = false;
			$this->assertSame( 'plugin_hub_mu_mods', $manager->install( 'one.php' )->get_error_code() );
			$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/one.php' );
		}

		public function test_download_that_does_not_match_the_tree_is_rejected(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$GLOBALS['hub_test_routes'][ self::RAW . 'mu-plugins/admin/one.php' ] = array( 'status' => 200, 'body' => $this->plugin( 'One', '9.9.9' ) );

			$this->assertSame( 'plugin_hub_mu_changed', $this->make_manager()->install( 'one.php' )->get_error_code() );
			$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/one.php' );
		}

		public function test_invalid_php_is_never_installed(): void {
			$this->serve( array( 'mu-plugins/admin/broken.php' => $this->plugin( 'Broken', '1.0.0', 'function (' ) ) );

			$this->assertSame( 'plugin_hub_mu_invalid', $this->make_manager()->install( 'broken.php' )->get_error_code() );
			$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/broken.php' );
		}

		public function test_install_adds_shared_helpers_when_needed(): void {
			$this->serve(
				array(
					'mu-plugins/000-helpers.php'    => $this->plugin( 'Helpers', '1.0.0', 'function mu_log() {}' ),
					'mu-plugins/security/needy.php' => $this->plugin( 'Needy', '1.0.0', 'mu_log( "x" );' ),
					'mu-plugins/admin/alone.php'    => $this->plugin( 'Alone', '1.0.0' ),
				)
			);
			$manager = $this->make_manager();

			$manager->install( 'alone.php' );
			$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/000-helpers.php' );

			$this->assertSame( '1.0.0', $manager->install( 'needy.php' ) );
			$this->assertFileExists( WPMU_PLUGIN_DIR . '/000-helpers.php' );
			$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/000-autoloader.php' );
		}

		public function test_update_replaces_only_that_file_in_place_and_keeps_a_rollback_copy(): void {
			mkdir( WPMU_PLUGIN_DIR . '/sub' );
			$old = $this->plugin( 'One', '1.0.0' );
			file_put_contents( WPMU_PLUGIN_DIR . '/sub/one.php', $old );
			file_put_contents( WPMU_PLUGIN_DIR . '/other.php', 'untouched' );

			$new = $this->plugin( 'One', '1.1.0' );
			$this->serve( array( 'mu-plugins/admin/one.php' => $new ) );
			$manager = $this->make_manager();

			$this->assertSame( '1.1.0', $manager->update( 'one.php' ) );
			$this->assertSame( $new, file_get_contents( WPMU_PLUGIN_DIR . '/sub/one.php' ) );
			$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/one.php' );
			$this->assertSame( 'untouched', file_get_contents( WPMU_PLUGIN_DIR . '/other.php' ) );
			$this->assertSame( array( '.', '..', 'one.php' ), array_values( array_diff( scandir( WPMU_PLUGIN_DIR . '/sub' ), array() ) ), 'No .bak or temp file is left next to the plugin.' );
			$backup = file_get_contents( $manager->get_backup_path( 'one.php' ) );
			$this->assertSame( MU_Plugins::BACKUP_GUARD . $old, $backup );
			$this->assertStringEndsWith( '.prev.php', $manager->get_backup_path( 'one.php' ) );
			$this->assertFileExists( $this->uploads . '/plugin-hub-mu-backups/index.php' );
			$this->assertFileExists( $this->uploads . '/plugin-hub-mu-backups/.htaccess' );
		}

		public function test_update_is_refused_when_already_current_or_not_newer(): void {
			$current = $this->plugin( 'One', '1.0.0' );
			file_put_contents( WPMU_PLUGIN_DIR . '/one.php', $current );
			$this->serve( array( 'mu-plugins/admin/one.php' => $current ) );
			$this->assertSame( 'plugin_hub_mu_current', $this->make_manager()->update( 'one.php' )->get_error_code() );

			// The repository file differs but carries an older version: never downgrade.
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '0.9.0' ) ) );
			$GLOBALS['hub_test_transients'] = array();
			$this->assertInstanceOf( \WP_Error::class, $this->make_manager()->update( 'one.php' ) );
			$this->assertSame( $current, file_get_contents( WPMU_PLUGIN_DIR . '/one.php' ) );
		}

		public function test_update_all_reports_updated_and_failed_files(): void {
			file_put_contents( WPMU_PLUGIN_DIR . '/good.php', $this->plugin( 'Good', '1.0.0' ) );
			file_put_contents( WPMU_PLUGIN_DIR . '/bad.php', $this->plugin( 'Bad', '1.0.0' ) );
			$this->serve(
				array(
					'mu-plugins/admin/good.php' => $this->plugin( 'Good', '1.1.0' ),
					'mu-plugins/admin/bad.php'  => $this->plugin( 'Bad', '1.1.0', 'function (' ),
				)
			);

			$result = $this->make_manager()->update_all();

			$this->assertSame( array( 'good.php' => '1.1.0' ), $result['updated'] );
			$this->assertSame( array( 'bad.php' ), array_keys( $result['failed'] ) );
			$this->assertStringContainsString( '1.0.0', file_get_contents( WPMU_PLUGIN_DIR . '/bad.php' ), 'A failed update leaves the old file in place.' );
		}

		public function test_concurrent_operation_is_refused_by_the_lock(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$GLOBALS['hub_test_transients']['plugin_hub_mu_lock_v1'] = true;

			$this->assertSame( 'plugin_hub_mu_locked', $this->make_manager()->install( 'one.php' )->get_error_code() );
		}

		// ------------------------------------------------------------- rollback

		public function test_rollback_restores_the_previous_version_and_disables_auto_update(): void {
			$old = $this->plugin( 'One', '1.0.0' );
			file_put_contents( WPMU_PLUGIN_DIR . '/one.php', $old );
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.1.0' ) ) );
			$manager = $this->make_manager();

			$GLOBALS['hub_test_options']['plugin_hub_mu_autoupdate'] = array( 'one.php', 'keep.php' );
			$manager->update( 'one.php' );

			$this->assertSame( '1.0.0', $manager->rollback( 'one.php' ) );
			$this->assertSame( $old, file_get_contents( WPMU_PLUGIN_DIR . '/one.php' ) );
			$this->assertFileDoesNotExist( $manager->get_backup_path( 'one.php' ) );
			$this->assertSame( array( 'keep.php' ), $GLOBALS['hub_test_options']['plugin_hub_mu_autoupdate'] );
			$this->assertSame( 'mu_rollback', end( $GLOBALS['hub_test_options']['plugin_hub_activity_log'] )['action'] );
		}

		public function test_rollback_without_a_stored_copy_or_with_a_broken_one_fails_safely(): void {
			$current = $this->plugin( 'One', '1.1.0' );
			file_put_contents( WPMU_PLUGIN_DIR . '/one.php', $current );
			$this->serve( array( 'mu-plugins/admin/one.php' => $current ) );
			$manager = $this->make_manager();

			$this->assertSame( 'plugin_hub_mu_nobackup', $manager->rollback( 'one.php' )->get_error_code() );

			mkdir( dirname( $manager->get_backup_path( 'one.php' ) ), 0755, true );
			file_put_contents( $manager->get_backup_path( 'one.php' ), '<?php function (' );
			$this->assertSame( 'plugin_hub_mu_invalid', $manager->rollback( 'one.php' )->get_error_code() );
			$this->assertSame( $current, file_get_contents( WPMU_PLUGIN_DIR . '/one.php' ) );
		}

		public function test_rollback_rejects_invalid_names(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );

			$this->assertSame( 'plugin_hub_mu_unknown', $this->make_manager()->rollback( '../one.php' )->get_error_code() );
			$this->assertSame( '', $this->make_manager()->get_backup_path( '../one.php' ) );
		}

		public function test_backup_starts_with_an_exit_guard_so_it_can_never_run_or_leak(): void {
			file_put_contents( WPMU_PLUGIN_DIR . '/one.php', $this->plugin( 'One', '1.0.0', '// secret' ) );
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.1.0' ) ) );
			$manager = $this->make_manager();
			$manager->update( 'one.php' );

			$path = $manager->get_backup_path( 'one.php' );
			$this->assertStringStartsWith( '<?php exit;', file_get_contents( $path ) );
			$this->assertSame( '', (string) shell_exec( 'php ' . escapeshellarg( $path ) ), 'Executing the backup prints nothing.' );
		}

		public function test_backup_without_the_guard_is_not_restored(): void {
			$current = $this->plugin( 'One', '1.1.0' );
			file_put_contents( WPMU_PLUGIN_DIR . '/one.php', $current );
			$this->serve( array( 'mu-plugins/admin/one.php' => $current ) );
			$manager = $this->make_manager();

			mkdir( dirname( $manager->get_backup_path( 'one.php' ) ), 0755, true );
			file_put_contents( $manager->get_backup_path( 'one.php' ), $this->plugin( 'One', '0.1.0' ) );

			$this->assertSame( 'plugin_hub_mu_invalid', $manager->rollback( 'one.php' )->get_error_code() );
			$this->assertSame( $current, file_get_contents( WPMU_PLUGIN_DIR . '/one.php' ) );
		}

		public function test_shared_helpers_cannot_be_rolled_back(): void {
			$helpers = $this->plugin( 'Helpers', '2.0.0', 'function mu_log() {}' );
			file_put_contents( WPMU_PLUGIN_DIR . '/000-helpers.php', $helpers );
			$this->serve( array( 'mu-plugins/000-helpers.php' => $helpers ) );
			$manager = $this->make_manager();

			mkdir( dirname( $manager->get_backup_path( '000-helpers.php' ) ), 0755, true );
			file_put_contents( $manager->get_backup_path( '000-helpers.php' ), MU_Plugins::BACKUP_GUARD . $this->plugin( 'Helpers', '1.0.0' ) );

			$this->assertSame( 'plugin_hub_mu_helpers_rollback', $manager->rollback( '000-helpers.php' )->get_error_code() );
			$this->assertSame( $helpers, file_get_contents( WPMU_PLUGIN_DIR . '/000-helpers.php' ) );
			$this->assertFalse( $manager->get_view_data()['installed'][0]['has_backup'], 'The helpers row never offers a rollback.' );
		}

		public function test_failed_automatic_update_is_reported(): void {
			file_put_contents( WPMU_PLUGIN_DIR . '/bad.php', $this->plugin( 'Bad', '1.0.0' ) );
			$this->serve( array( 'mu-plugins/admin/bad.php' => $this->plugin( 'Bad', '2.0.0', 'function (' ) ) );
			$GLOBALS['hub_test_options']['plugin_hub_mu_autoupdate'] = array( 'bad.php' );

			$summary = $this->make_manager()->run_auto_updates();

			$this->assertSame( array(), $summary['updated'] );
			$this->assertCount( 1, $summary['failed'] );
			$this->assertStringContainsString( 'MU: bad.php', $summary['failed'][0] );
			$this->assertCount( 1, $summary['available'], 'The pending update is still listed.' );
		}

		public function test_multisite_view_flags_the_network_and_hides_actions_for_non_super_admins(): void {
			$this->serve( array( 'mu-plugins/admin/one.php' => $this->plugin( 'One', '1.0.0' ) ) );
			$GLOBALS['hub_test_multisite']   = true;
			$GLOBALS['hub_test_super_admin'] = false;

			$data = $this->make_manager()->get_view_data();
			$this->assertTrue( $data['multisite'] );
			$this->assertFalse( $data['file_mods'] );

			$GLOBALS['hub_test_super_admin'] = true;
			$this->assertTrue( $this->make_manager()->get_view_data()['file_mods'] );
		}

		// ------------------------------------------------------------- auto-updates

		public function test_cron_updates_only_opted_in_plugins_and_reports_the_rest(): void {
			file_put_contents( WPMU_PLUGIN_DIR . '/auto.php', $this->plugin( 'Auto', '1.0.0' ) );
			file_put_contents( WPMU_PLUGIN_DIR . '/manual.php', $this->plugin( 'Manual', '1.0.0' ) );
			$this->serve(
				array(
					'mu-plugins/admin/auto.php'   => $this->plugin( 'Auto', '2.0.0' ),
					'mu-plugins/admin/manual.php' => $this->plugin( 'Manual', '2.0.0' ),
				)
			);
			$GLOBALS['hub_test_options']['plugin_hub_mu_autoupdate'] = array( 'auto.php' );

			$summary = $this->make_manager()->run_auto_updates();

			$this->assertSame( array( 'MU: auto.php → v2.0.0' ), $summary['updated'] );
			$this->assertSame( array( 'MU: manual.php (v1.0.0 → v2.0.0)' ), $summary['available'] );
			$this->assertStringContainsString( '1.0.0', file_get_contents( WPMU_PLUGIN_DIR . '/manual.php' ) );
		}

		public function test_cron_does_not_write_when_file_modifications_are_disabled(): void {
			file_put_contents( WPMU_PLUGIN_DIR . '/auto.php', $this->plugin( 'Auto', '1.0.0' ) );
			$this->serve( array( 'mu-plugins/admin/auto.php' => $this->plugin( 'Auto', '2.0.0' ) ) );
			$GLOBALS['hub_test_options']['plugin_hub_mu_autoupdate'] = array( 'auto.php' );
			$GLOBALS['hub_test_file_mods']                           = false;

			$summary = $this->make_manager()->run_auto_updates();

			$this->assertSame( array(), $summary['updated'] );
			$this->assertCount( 1, $summary['available'] );
			$this->assertStringContainsString( '1.0.0', file_get_contents( WPMU_PLUGIN_DIR . '/auto.php' ) );
		}

		// ------------------------------------------------------------- view data

		public function test_view_data_lists_available_plugins_with_descriptions_and_requirements(): void {
			file_put_contents( WPMU_PLUGIN_DIR . '/have.php', $this->plugin( 'Have', '1.0.0' ) );
			$this->serve(
				array(
					'mu-plugins/admin/have.php'     => $this->plugin( 'Have', '1.0.0' ),
					'mu-plugins/admin/new.php'      => $this->plugin( 'New', '2.0.0' ),
					'mu-plugins/000-helpers.php'    => $this->plugin( 'Helpers', '1.0.0' ),
				)
			);

			$data = $this->make_manager()->get_view_data();

			$this->assertSame( 'ok', $data['dir_state'] );
			$this->assertSame( array( 'new.php' ), array_column( $data['available'], 'file' ) );
			$this->assertSame( 'Does new.php.', $data['available'][0]['description'] );
			$this->assertSame( '2.0.0', $data['available'][0]['version'] );
			$this->assertSame( '6.6+', $data['available'][0]['wp'] );
			$this->assertSame( '7.4+', $data['available'][0]['php'] );
			$this->assertSame( 'have.php', $data['installed'][0]['file'] );
			$this->assertFalse( $data['installed'][0]['has_backup'] );
		}
	}
}
