<?php
/** Tests the catalog-to-supporter boundary without adding an HTTP client. @package PluginHub */
namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;

	final class CatalogSponsorshipTest extends TestCase {
		protected function setUp(): void {
			$GLOBALS['hub_test_options'] = array();
			$GLOBALS['hub_test_transients'] = array();
			$GLOBALS['hub_test_requests'] = array();
		}
		protected function tearDown(): void {
			unset( $GLOBALS['hub_test_options'], $GLOBALS['hub_test_transients'], $GLOBALS['hub_test_requests'], $GLOBALS['hub_test_response'] );
		}
		private function response( $feed ) {
			$GLOBALS['hub_test_response'] = array( 'status' => 200, 'body' => json_encode( array(
				'products' => array( array( 'type' => 'plugin', 'repo_name' => 'plugin-hub', 'repo_url' => 'https://github.com/Open-WP-Club/plugin-hub', 'version' => '1.4.3' ) ),
				'sponsorship' => $feed,
			) ) );
		}
		private function empty_feed() {
			return array( 'schemaVersion' => 1, 'maxPositions' => 3, 'supporters' => array(), 'campaigns' => array() );
		}
		public function test_empty_feed_removes_old_supporters_with_the_existing_catalog_request() {
			$GLOBALS['hub_test_options']['plugin_hub_supporters'] = array( 'old' );
			$this->response( $this->empty_feed() );
			$api = new API();
			$this->assertCount( 1, $api->get_org_repos() );
			$this->assertSame( array(), $GLOBALS['hub_test_options']['plugin_hub_supporters'] );
			$this->assertSame( array( 'https://raw.githubusercontent.com/Open-WP-Club/.github/main/catalog.json' ), $GLOBALS['hub_test_requests'] );
			$api->get_org_repos();
			$this->assertCount( 1, $GLOBALS['hub_test_requests'], 'Cached display must not make another HTTP request' );
		}
		public function test_invalid_sponsor_feed_never_blocks_catalog_updates_or_replaces_good_data() {
			$GLOBALS['hub_test_options']['plugin_hub_supporters'] = array( 'last validated supporter' );
			$this->response( array( 'schemaVersion' => 2 ) );
			$this->assertCount( 1, ( new API() )->get_org_repos() );
			$this->assertSame( array( 'last validated supporter' ), $GLOBALS['hub_test_options']['plugin_hub_supporters'] );
		}
		public function test_catalog_failure_preserves_last_known_repositories_and_supporters() {
			$GLOBALS['hub_test_options']['plugin_hub_supporters'] = array( 'last validated supporter' );
			$GLOBALS['hub_test_options']['plugin_hub_last_known_repos'] = array( array( 'name' => 'plugin-hub' ) );
			$GLOBALS['hub_test_response'] = array( 'status' => 503, 'body' => '' );
			$this->assertSame( array( array( 'name' => 'plugin-hub' ) ), ( new API() )->get_org_repos() );
			$this->assertSame( array( 'last validated supporter' ), $GLOBALS['hub_test_options']['plugin_hub_supporters'] );
		}
		public function test_failed_catalog_is_not_requested_on_every_page_or_operation() {
			$GLOBALS['hub_test_options']['plugin_hub_last_known_repos'] = array( array( 'name' => 'plugin-hub' ) );
			$GLOBALS['hub_test_response'] = array( 'status' => 503, 'body' => '' );
			$api = new API();
			$api->get_org_repos();
			( new API() )->get_org_repos();
			$this->assertCount( 1, $GLOBALS['hub_test_requests'] );
			$this->assertTrue( $GLOBALS['hub_test_transients']['plugin_hub_catalog_retry_v1'] );
			// Simulate expiry without sleeping. The next visit retries normally.
			unset( $GLOBALS['hub_test_transients']['plugin_hub_catalog_retry_v1'] );
			$api->get_org_repos();
			$this->assertCount( 2, $GLOBALS['hub_test_requests'] );
		}
		public function test_manual_refresh_bypasses_failure_backoff_and_recovers() {
			$GLOBALS['hub_test_response'] = array( 'status' => 503, 'body' => '' );
			$api = new API(); $api->get_org_repos();
			$this->response( $this->empty_feed() );
			$this->assertCount( 1, $api->refresh_csv_cache() );
			$this->assertArrayNotHasKey( 'plugin_hub_catalog_retry_v1', $GLOBALS['hub_test_transients'] );
			$this->assertCount( 2, $GLOBALS['hub_test_requests'] );
		}

	}
}
