<?php
/**
 * Tests configuration export, validation and import.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';
	require_once dirname( __DIR__ ) . '/includes/class-config.php';

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

namespace PluginHub\Tests {
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;
	use PluginHub\Config;
	use PluginHub\MU_Plugins;

	/**
	 * API double with an in-memory catalog and installed set.
	 */
	class Config_Test_API extends API {
		public array $pins      = array();
		public array $installed = array();
		public array $logged    = array();

		public function get_cached_repos() {
			return array(
				array(
					'name'      => 'alpha',
					'version'   => '2.0.0',
					'available' => true,
				),
				array(
					'name'      => 'beta',
					'version'   => '1.0.0',
					'available' => true,
				),
			);
		}
		public function is_plugin_installed( $name ) {
			return in_array( $name, $this->installed, true );
		}
		public function is_plugin_active( $name ) {
			return 'alpha' === $name;
		}
		public function get_installed_plugin_version( $name ) {
			return '1.5.0';
		}
		public function get_pin( $repo ) {
			return $this->pins[ $repo ] ?? '';
		}
		public function set_pin( $repo, $pin ) {
			$this->pins[ $repo ] = $pin;
			return true;
		}
		public function log_activity( $action, $plugin, $version = '' ) {
			$this->logged[] = $action;
		}
	}

	/**
	 * MU manager double with a fixed catalog.
	 */
	class Config_Test_MU extends MU_Plugins {
		public function get_catalog( $force = false ) {
			return array(
				'one.php' => array( 'category' => 'a' ),
				'two.php' => array( 'category' => 'a' ),
			);
		}
		public function get_installed( $fetch = true ) {
			return array(
				array(
					'file'       => 'one.php',
					'autoupdate' => true,
				),
			);
		}
	}

	final class ConfigTest extends TestCase {

		private Config_Test_API $api;
		private Config_Test_MU $mu;

		protected function setUp(): void {
			$GLOBALS['hub_test_options'] = array();
			$this->api                   = new Config_Test_API();
			$this->mu                    = new Config_Test_MU( $this->api );
		}

		private function document( array $override = array() ): string {
			return json_encode(
				array_merge(
					array(
						'format'   => 'plugin-hub-config',
						'version'  => 1,
						'plugins'  => array(
							array(
								'repo'       => 'alpha',
								'version'    => '1.0.0',
								'active'     => true,
								'autoupdate' => true,
								'pin'        => '1.x',
							),
							array(
								'repo'       => 'beta',
								'version'    => '1.0.0',
								'active'     => true,
								'autoupdate' => false,
								'pin'        => 'not-a-pin',
							),
							array(
								'repo'       => 'gone',
								'autoupdate' => true,
							),
							array( 'repo' => '../bad' ),
						),
						'mu'       => array(
							array(
								'file'       => 'two.php',
								'autoupdate' => true,
							),
							array( 'file' => '../evil.php' ),
						),
						'settings' => array( 'backup_keep' => 99999 ),
					),
					$override
				)
			);
		}

		public function test_export_lists_installed_plugins_and_mu_files(): void {
			$this->api->installed                                 = array( 'alpha' );
			$this->api->pins['alpha']                             = '2.x';
			$GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] = array( 'alpha' );

			$data = Config::export( $this->api, $this->mu );

			$this->assertSame( 'plugin-hub-config', $data['format'] );
			$this->assertCount( 1, $data['plugins'] );
			$this->assertSame(
				array(
					'repo'       => 'alpha',
					'version'    => '1.5.0',
					'active'     => true,
					'autoupdate' => true,
					'pin'        => '2.x',
				),
				$data['plugins'][0]
			);
			$this->assertSame( array( array( 'file' => 'one.php', 'autoupdate' => true ) ), $data['mu'] );
			$this->assertIsArray( $data['settings'] );
			$this->assertArrayNotHasKey( 'github_token', $data['settings'] );
			$this->assertSame( $data, json_decode( Config::to_json( $data ), true ) );
		}

		public function test_validate_rejects_garbage(): void {
			$this->assertInstanceOf( \WP_Error::class, Config::validate( '' ) );
			$this->assertInstanceOf( \WP_Error::class, Config::validate( 'not json' ) );
			$this->assertInstanceOf( \WP_Error::class, Config::validate( '{"format":"other","version":1}' ) );
			$this->assertInstanceOf( \WP_Error::class, Config::validate( '{"format":"plugin-hub-config","version":2}' ) );
			$this->assertInstanceOf( \WP_Error::class, Config::validate( str_repeat( 'a', Config::MAX_SIZE + 1 ) ) );
		}

		public function test_validate_normalises_entries(): void {
			$data = Config::validate( $this->document() );

			$this->assertIsArray( $data );
			$this->assertSame( array( 'alpha', 'beta', 'gone' ), array_column( $data['plugins'], 'repo' ) );
			$this->assertSame( '1.x', $data['plugins'][0]['pin'] );
			$this->assertSame( '', $data['plugins'][1]['pin'] );
			$this->assertSame( array( 'two.php' ), array_column( $data['mu'], 'file' ) );
			$this->assertLessThanOrEqual( 20, $data['settings']['backup_keep'] );
		}

		public function test_apply_sets_autoupdate_pins_and_reports_missing(): void {
			$this->api->installed                                 = array( 'beta' );
			$GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] = array( 'beta' );
			$data = Config::validate( $this->document() );

			$result = Config::apply( $data, $this->api, $this->mu );

			$this->assertSame( array( 'alpha' ), $GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] );
			$this->assertSame( '1.x', $this->api->pins['alpha'] );
			$this->assertSame(
				array( array( 'repo' => 'alpha', 'version' => '2.0.0', 'active' => true ) ),
				$result['missing']
			);
			$this->assertSame( array( 'two.php' ), $result['mu_missing'] );
			$this->assertSame( array( 'two.php' ), $GLOBALS['hub_test_options'][ MU_Plugins::AUTOUPDATE_OPTION ] );
			$this->assertSame( array( 'gone' ), $result['skipped'] );
			$this->assertSame( 1, $result['applied']['autoupdate'] );
			$this->assertSame( 1, $result['applied']['pins'] );
			$this->assertSame( array( 'import' ), $this->api->logged );
			$this->assertArrayHasKey( 'plugin_hub_settings', $GLOBALS['hub_test_options'] );
		}

		public function test_apply_can_skip_settings(): void {
			$data = Config::validate( $this->document() );
			Config::apply( $data, $this->api, $this->mu, array( 'skip_settings' => true ) );

			$this->assertArrayNotHasKey( 'plugin_hub_settings', $GLOBALS['hub_test_options'] );
		}
	}
}
