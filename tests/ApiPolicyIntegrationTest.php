<?php
/**
 * Tests for the pin, failed-version, catalog checksum and activity log behaviour of PluginHub\API.
 *
 * @package PluginHub
 */

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\get_site_transient' ) ) {
		function get_site_transient( $key ) {
			return $GLOBALS['hub_test_site_transients'][ $key ] ?? false;
		}
	}
}

namespace PluginHub\Tests {

	require_once __DIR__ . '/stubs.php';

	use PHPUnit\Framework\TestCase;
	use PluginHub\API;
	use ReflectionMethod;

	final class ApiPolicyIntegrationTest extends TestCase {

		private API $api;

		protected function setUp(): void {
			$GLOBALS['hub_test_options']         = array();
			$GLOBALS['hub_test_transients']      = array();
			$GLOBALS['hub_test_site_transients'] = array();
			$this->api                           = new API();
		}

		private function repo( string $version = '1.3.0' ): array {
			return array(
				'name'      => 'demo',
				'version'   => $version,
				'available' => true,
			);
		}

		public function test_unpinned_update_is_allowed(): void {
			$this->assertTrue( $this->api->is_update_allowed( $this->repo(), '1.2.0' ) );
		}

		public function test_exact_pin_blocks_update(): void {
			$this->assertTrue( $this->api->set_pin( 'demo', '1.2.0' ) );
			$this->assertFalse( $this->api->is_update_allowed( $this->repo(), '1.2.0' ) );
		}

		public function test_major_pin_allows_same_major_only(): void {
			$this->api->set_pin( 'demo', '1.x' );
			$this->assertTrue( $this->api->is_update_allowed( $this->repo( '1.9.0' ), '1.2.0' ) );
			$this->assertFalse( $this->api->is_update_allowed( $this->repo( '2.0.0' ), '1.2.0' ) );
		}

		public function test_unpin_removes_the_pin(): void {
			$this->api->set_pin( 'demo', '1.x' );
			$this->api->set_pin( 'demo', '' );
			$this->assertSame( '', $this->api->get_pin( 'demo' ) );
			$this->assertSame( array(), $this->api->get_pins() );
		}

		public function test_invalid_pin_is_rejected(): void {
			$this->assertInstanceOf( \WP_Error::class, $this->api->set_pin( 'demo', 'latest' ) );
			$this->assertInstanceOf( \WP_Error::class, $this->api->set_pin( '../x', '1.x' ) );
			$this->assertSame( '', $this->api->get_pin( 'demo' ) );
		}

		public function test_failed_version_is_not_offered_again(): void {
			$GLOBALS['hub_test_options']['plugin_hub_failed_versions'] = array( 'demo' => array( '1.3.0' ) );
			$this->assertFalse( $this->api->is_update_allowed( $this->repo( '1.3.0' ), '1.2.0' ) );
			$this->assertTrue( $this->api->is_update_allowed( $this->repo( '1.4.0' ), '1.2.0' ) );
		}

		public function test_cached_repos_never_use_the_network(): void {
			$GLOBALS['hub_test_requests'] = array();
			$this->assertSame( array(), $this->api->get_cached_repos() );

			$GLOBALS['hub_test_options']['plugin_hub_last_known_repos'] = array( array( 'name' => 'demo' ) );
			$this->assertSame( array( array( 'name' => 'demo' ) ), $this->api->get_cached_repos() );
			$this->assertSame( array(), $GLOBALS['hub_test_requests'] );
		}

		public function test_catalog_checksum_is_kept_only_when_valid(): void {
			$hash    = str_repeat( 'ab', 32 );
			$catalog = (string) json_encode(
				array(
					'products' => array(
						array(
							'type'      => 'plugin',
							'repo_name' => 'good',
							'version'   => '1.0.0',
							'repo_url'  => 'https://github.com/Open-WP-Club/good',
							'sha256'    => strtoupper( $hash ),
						),
						array(
							'type'      => 'plugin',
							'repo_name' => 'bad',
							'version'   => '1.0.0',
							'repo_url'  => 'https://github.com/Open-WP-Club/bad',
							'sha256'    => 'not-a-hash',
						),
					),
				)
			);

			$method = new ReflectionMethod( API::class, 'parse_catalog_content' );
			$repos  = $method->invoke( $this->api, $catalog );

			$this->assertSame( $hash, $repos[0]['sha256'] );
			$this->assertSame( '', $repos[1]['sha256'] );
		}

		public function test_pending_updates_count_only_our_plugins_and_mu(): void {
			$GLOBALS['hub_test_site_transients']['update_plugins'] = (object) array(
				'response' => array(
					'a/a.php' => (object) array( 'id' => 'https://github.com/Open-WP-Club/a' ),
					'b/b.php' => (object) array( 'id' => 'w.org/plugins/b' ),
				),
			);
			$GLOBALS['hub_test_options']['plugin_hub_mu_update_count']  = 2;

			$this->assertSame( 3, $this->api->count_pending_updates() );
		}

		public function test_activity_entries_get_ids_and_can_be_deleted(): void {
			$this->api->log_activity( 'install', 'demo', '1.0.0' );
			$this->api->log_activity( 'install', 'demo', '1.0.0' );
			$this->api->log_activity( 'update', 'demo', '1.1.0' );

			$log = $this->api->get_activity_log();
			$this->assertCount( 3, $log );
			$this->assertCount( 3, array_unique( array_column( $log, 'id' ) ) );
			$this->assertSame( 'update', $log[0]['action'] );

			$this->assertSame( 1, $this->api->delete_activity_entries( array( $log[0]['id'] ) ) );
			$this->assertCount( 2, $this->api->get_activity_log() );
			$this->assertCount( 0, $this->api->get_activity_log( array( 'action' => 'update' ) ) );
			$this->assertCount( 2, $this->api->get_activity_log( array( 'action' => 'install' ) ) );
		}

		public function test_activity_log_respects_the_maximum(): void {
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'log_max' => 50 );
			for ( $i = 0; $i < 55; $i++ ) {
				$this->api->log_activity( 'update', 'demo', '1.0.' . $i );
			}

			$this->assertCount( 50, $this->api->get_activity_log() );
		}
	}
}
