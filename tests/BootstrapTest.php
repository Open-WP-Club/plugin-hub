<?php
/**
 * Loads plugin-hub.php and uninstall.php in separate PHP processes.
 *
 * Both files define constants and functions globally, so they cannot share a
 * process with the other tests. tests/fixtures/runner.php records what they do.
 *
 * @package PluginHub
 */

namespace PluginHub\Tests;

use PHPUnit\Framework\TestCase;

final class BootstrapTest extends TestCase {

	private string $uploads;

	protected function setUp(): void {
		$this->uploads = sys_get_temp_dir() . '/plugin-hub-boot-' . getmypid();
		$this->remove_dir( $this->uploads );
		mkdir( $this->uploads, 0755, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->uploads );
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

	/**
	 * Run a scenario and decode the recorded JSON.
	 */
	private function run_scenario( string $scenario ): array {
		$command = array( PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', __DIR__ . '/fixtures/runner.php', $scenario, $this->uploads );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		proc_close( $process );

		$data = json_decode( $stdout, true );
		$this->assertIsArray( $data, "The runner did not print JSON.\nSTDOUT: $stdout\nSTDERR: $stderr" );
		$this->assertSame( '', trim( $stderr ), 'PHP reported errors or warnings while loading.' );
		return $data;
	}

	// ----------------------------------------------------------------- loading

	public function test_the_plugin_loads_and_registers_every_hook(): void {
		$rec = $this->run_scenario( 'load' );

		$this->assertSame( array(), $rec['uncallable'], 'Every registered hook points at an existing public method.' );
		$this->assertSame( array(), $rec['commands'], 'WP-CLI commands are only registered under WP-CLI.' );

		$expected = array(
			'init|PluginHub\\Main::load_textdomain',
			'admin_menu|PluginHub\\Admin::add_admin_menu',
			'network_admin_menu|PluginHub\\Admin::add_network_admin_menu',
			'admin_post_plugin_hub_export_config|PluginHub\\Admin::handle_export_config',
			'wp_ajax_save_hub_settings|PluginHub\\Admin::ajax_save_hub_settings',
			'wp_ajax_install_github_plugin|PluginHub\\API::ajax_install_github_plugin',
			'wp_ajax_save_github_token|PluginHub\\Admin::ajax_save_github_token',
			'wp_ajax_install_mu_plugin|PluginHub\\MU_Plugins::ajax_install',
			'plugin_hub_daily_update_check|PluginHub\\API::do_auto_updates_and_notify',
		);
		foreach ( $expected as $hook ) {
			$this->assertContains( $hook, $rec['actions'] );
		}
		$this->assertContains( 'pre_set_site_transient_update_plugins|PluginHub\\API::check_for_plugin_updates|10|1', $rec['filters'] );
		$this->assertContains( 'plugins_api|PluginHub\\API::get_plugin_information|20|3', $rec['filters'] );
	}

	public function test_every_ajax_action_the_script_calls_has_a_server_handler(): void {
		$rec = $this->run_scenario( 'load' );
		$js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/script.js' );

		preg_match_all( "/action:\s*'([a-z_]+)'/", $js, $matches );
		$called = array_unique( $matches[1] );
		$this->assertNotEmpty( $called );

		$registered = array();
		foreach ( $rec['actions'] as $action ) {
			if ( 0 === strpos( $action, 'wp_ajax_' ) ) {
				$registered[] = substr( explode( '|', $action )[0], strlen( 'wp_ajax_' ) );
			}
		}

		$this->assertSame( array(), array_values( array_diff( $called, $registered ) ), 'script.js calls AJAX actions that no PHP handler is registered for.' );
	}

	public function test_the_text_domain_is_loaded_from_the_languages_folder(): void {
		$rec = $this->run_scenario( 'load' );
		$this->assertSame( array( 'plugin-hub', 'plugin-hub/languages/' ), $rec['textdomain'] ?? null );
	}

	public function test_wp_cli_commands_are_registered_only_under_wp_cli(): void {
		$rec = $this->run_scenario( 'load-cli' );
		$this->assertSame( array( 'plugin-hub|PluginHub\\CLI' ), $rec['commands'] );
	}

	public function test_constants_and_token_helpers(): void {
		$rec = $this->run_scenario( 'load' );

		$this->assertSame( array( 'version' => '1.8.0', 'org' => 'Open-WP-Club', 'dir' => true ), $rec['constants'] );
		$this->assertSame( '', $rec['token_empty'] );
		$this->assertSame( 'ghp_stored', $rec['token_stored'], 'The stored token is trimmed.' );
		$this->assertFalse( $rec['managed_before'] );
		$this->assertSame( 'ghp_config', $rec['token_config'], 'A wp-config.php constant wins over the database.' );
		$this->assertTrue( $rec['managed_after'] );
	}

	public function test_activation_schedules_the_hourly_check_once(): void {
		$rec = $this->run_scenario( 'load' );

		$this->assertSame( 'PluginHub\\activate_plugin_hub', $rec['registered']['activation'] );
		$this->assertSame( 'PluginHub\\deactivate_plugin_hub', $rec['registered']['deactivation'] );
		$this->assertSame( 1, $rec['activate_first'] );
		$this->assertSame( 1, $rec['activate_second'], 'An already scheduled event is not scheduled again.' );
		$this->assertSame( array( 'plugin_hub_daily_update_check' ), $rec['deactivate_cleared'] );
	}

	public function test_upgrade_reschedules_once(): void {
		$rec = $this->run_scenario( 'load' );

		$this->assertSame( array( 'scheduled' => 1, 'cleared' => 1, 'version' => '1.8.0' ), $rec['upgrade_first'] );
		$this->assertSame( array( 'scheduled' => 1, 'cleared' => 1 ), $rec['upgrade_second'], 'The second call is a no-op: nothing more was scheduled or cleared.' );
	}

	// -------------------------------------------------------------- versions

	public function test_the_version_is_the_same_everywhere(): void {
		$root   = dirname( __DIR__ );
		$plugin = (string) file_get_contents( $root . '/plugin-hub.php' );
		$readme = (string) file_get_contents( $root . '/readme.txt' );
		$npm    = json_decode( (string) file_get_contents( $root . '/package.json' ), true );

		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $plugin, $header );
		preg_match( "/define\( 'PLUGIN_HUB_VERSION', '([^']+)'/", $plugin, $constant );
		preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable );
		preg_match( "/'([0-9.]+)' === get_option\( 'plugin_hub_db_version'/", $plugin, $db );

		$version = $header[1];
		$this->assertSame( $version, $constant[1], 'PLUGIN_HUB_VERSION' );
		$this->assertSame( $version, $stable[1], 'readme.txt Stable tag' );
		$this->assertSame( $version, $npm['version'], 'package.json' );
		$this->assertSame( $version, $db[1], 'maybe_upgrade() target version' );
		$this->assertStringContainsString( "= {$version} =", $readme, 'readme.txt changelog entry' );
	}

	public function test_translation_template_matches_the_version(): void {
		$root = dirname( __DIR__ );
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( $root . '/plugin-hub.php' ), $header );
		$pot = (string) file_get_contents( $root . '/languages/plugin-hub.pot' );
		$po  = (string) file_get_contents( $root . '/languages/plugin-hub-bg_BG.po' );

		$this->assertStringContainsString( 'Project-Id-Version: Plugin Hub ' . $header[1], $pot );
		$this->assertStringContainsString( 'Project-Id-Version: Plugin Hub ' . $header[1], $po );
	}

	public function test_the_bulgarian_translation_is_complete_and_compiled(): void {
		$root = dirname( __DIR__ ) . '/languages/';
		$po   = (string) file_get_contents( $root . 'plugin-hub-bg_BG.po' );
		$pot  = (string) file_get_contents( $root . 'plugin-hub.pot' );

		// Every template string is in the translation, and none is left untranslated.
		$this->assertSame( substr_count( $pot, "\nmsgid " ) - 1, substr_count( explode( "\n#~ ", $po )[0], "\nmsgid " ) - 1, 'The .po has the same strings as the .pot.' );
		$active = explode( "\n#~ ", $po )[0];
		$this->assertDoesNotMatchRegularExpression( '/msgid "[^"]+"\nmsgstr ""\n(?!")/', $active, 'No single-line string is untranslated.' );
		$this->assertDoesNotMatchRegularExpression( '/msgstr\[0\] ""\n/', $active, 'No plural form is untranslated.' );

		$mo = (string) file_get_contents( $root . 'plugin-hub-bg_BG.mo' );
		$this->assertSame( "\xde\x12\x04\x95", substr( $mo, 0, 4 ), 'A little-endian .mo file.' );
		$this->assertGreaterThanOrEqual( filemtime( $root . 'plugin-hub-bg_BG.po' ) - 5, filemtime( $root . 'plugin-hub-bg_BG.mo' ), 'The .mo is not older than the .po.' );
	}

	// ------------------------------------------------------------- uninstall

	public function test_uninstall_removes_every_option_transient_and_backup(): void {
		mkdir( $this->uploads . '/plugin-hub-mu-backups', 0755, true );
		file_put_contents( $this->uploads . '/plugin-hub-mu-backups/one.prev.php', '<?php' );
		file_put_contents( $this->uploads . '/plugin-hub-mu-backups/index.php', '<?php' );
		mkdir( $this->uploads . '/plugin-hub-backups/demo', 0755, true );
		file_put_contents( $this->uploads . '/plugin-hub-backups/demo/1.0.0_1.zip', 'zip' );
		file_put_contents( $this->uploads . '/keep-me.txt', 'unrelated' );

		$rec = $this->run_scenario( 'uninstall' );

		$this->assertDirectoryDoesNotExist( $this->uploads . '/plugin-hub-mu-backups' );
		$this->assertDirectoryDoesNotExist( $this->uploads . '/plugin-hub-backups' );
		$this->assertFileExists( $this->uploads . '/keep-me.txt', 'Only Plugin Hub folders are removed.' );
		$this->assertSame( array( 'plugin_hub_daily_update_check' ), $rec['cleared'] );
		$this->assertSame( array( array( 'user', 0, 'plugin_hub_hide_supporters', true ) ), $rec['user_meta'] );

		$this->assertCount( 1, $rec['queries'] );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $rec['queries'][0][0] );
		$this->assertSame( 'plugin\_hub\_disabled\_%', $rec['queries'][0][1][0], 'LIKE wildcards in the prefix are escaped.' );
	}

	public function test_uninstall_covers_every_option_and_transient_the_plugin_creates(): void {
		$rec     = $this->run_scenario( 'uninstall' );
		$deleted = $rec['deleted'];
		$source  = '';
		foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
			$source .= (string) file_get_contents( $file );
		}
		$source .= (string) file_get_contents( dirname( __DIR__ ) . '/plugin-hub.php' );

		preg_match_all( "/(?:update_option|add_option)\(\s*'(plugin_hub_[a-z_]+)'/", $source, $options );
		preg_match_all( "/set_transient\(\s*'(plugin_hub_[a-z_0-9]+)'/", $source, $transients );

		$missing = array();
		foreach ( array_unique( $options[1] ) as $option ) {
			if ( ! in_array( 'option:' . $option, $deleted, true ) ) {
				$missing[] = $option;
			}
		}
		// Dynamic names are removed by the LIKE query; the activity log and failed versions are options too.
		$this->assertSame( array(), $missing, 'Options written by the plugin that uninstall.php never deletes.' );

		$missing = array();
		foreach ( array_unique( $transients[1] ) as $transient ) {
			if ( ! in_array( 'transient:' . $transient, $deleted, true ) ) {
				$missing[] = $transient;
			}
		}
		$this->assertSame( array(), $missing, 'Transients set by the plugin that uninstall.php never deletes.' );
	}
}
