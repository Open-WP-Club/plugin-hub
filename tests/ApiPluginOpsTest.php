<?php
/**
 * Tests the plugin management side of PluginHub\API with the real class:
 * installed-plugin lookups, dependencies, download URLs, install, activate,
 * deactivate, delete, the update source hooks and their AJAX handlers.
 *
 * WordPress itself is replaced by tests/wp-fakes.php and the stub files.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/wp-api-stubs.php';
	require_once __DIR__ . '/wp-ajax-stubs.php';
	require_once __DIR__ . '/fs-stubs.php';
	require_once __DIR__ . '/wp-fakes.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';
	require_once dirname( __DIR__ ) . '/includes/class-notifier.php';

	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-plugins-' . getmypid() );
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_plugin_active_for_network' ) ) {
		function is_plugin_active_for_network( $file ) {
			return in_array( $file, $GLOBALS['hub_test_network_active'] ?? array(), true );
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\API;

	final class ApiPluginOpsTest extends TestCase {

		private const HEAD = 'https://github.com/Open-WP-Club/%1$s/releases/download/%2$s/%1$s.zip';
		private const TAG  = 'https://api.github.com/repos/Open-WP-Club/%s/releases/tags/%s';

		private API $api;

		protected function setUp(): void {
			$GLOBALS['hub_test_options']       = array();
			$GLOBALS['hub_test_transients']    = array();
			$GLOBALS['hub_test_routes']        = array();
			$GLOBALS['hub_test_requests']      = array();
			$GLOBALS['hub_test_heads']         = array();
			$GLOBALS['hub_test_calls']         = array();
			$GLOBALS['hub_test_filters']       = array();
			$GLOBALS['hub_test_default_http']  = array( 'status' => 404, 'body' => '' );
			$GLOBALS['hub_test_multisite']     = false;
			$GLOBALS['hub_test_site_transients'] = array();
			$GLOBALS['hub_test_uploads']       = sys_get_temp_dir() . '/plugin-hub-ops-' . getmypid();
			$GLOBALS['hub_test_plugins']       = array(
				'alpha/alpha.php' => array( 'Name' => 'Alpha', 'Version' => '1.0.0' ),
				'dep/dep.php'     => array( 'Name' => 'Dependent', 'Version' => '2.0.0', 'RequiresPlugins' => 'alpha' ),
				'solo/solo.php'   => array( 'Name' => 'Solo', 'Version' => '1.0.0', 'RequiresPlugins' => 'ghost, alpha' ),
				'hello.php'       => array( 'Name' => 'Hello', 'Version' => '1.7.2' ),
			);
			$GLOBALS['hub_test_active_plugins'] = array( 'alpha/alpha.php' );
			$_POST                              = array();

			$GLOBALS['hub_test_transients']['plugin_hub_catalog_cache_v1'] = array(
				$this->repo( 'alpha', '1.5.0' ),
				$this->repo( 'gamma', '3.0.0' ),
				$this->repo( 'dep', '2.0.0' ),
			);
			$this->api = new API();
		}

		protected function tearDown(): void {
			foreach ( array( 'hub_test_options', 'hub_test_transients', 'hub_test_routes', 'hub_test_requests', 'hub_test_heads', 'hub_test_calls', 'hub_test_filters', 'hub_test_default_http', 'hub_test_multisite', 'hub_test_site_transients', 'hub_test_uploads', 'hub_test_plugins', 'hub_test_active_plugins', 'hub_test_network_active', 'hub_test_activate_result', 'hub_test_delete_result', 'hub_test_upgrader', 'hub_test_nonce_ok', 'hub_test_caps', 'wp_filesystem' ) as $key ) {
				unset( $GLOBALS[ $key ] );
			}
			$_POST = array();
		}

		private function repo( string $name, string $version, string $sha = '' ): array {
			return array(
				'name'         => $name,
				'display_name' => ucfirst( $name ),
				'version'      => $version,
				'description'  => ucfirst( $name ) . ' <b>plugin</b>',
				'repo_url'     => 'https://github.com/Open-WP-Club/' . $name,
				'available'    => true,
				'sha256'       => $sha,
			);
		}

		private function head( string $name, string $version, int $status = 302 ): string {
			$url                                = sprintf( self::HEAD, $name, rawurlencode( $version ) );
			$GLOBALS['hub_test_routes'][ $url ] = array( 'status' => $status, 'body' => '' );
			return $url;
		}

		private function release( string $name, string $tag, array $release, int $status = 200 ): void {
			$GLOBALS['hub_test_routes'][ sprintf( self::TAG, $name, rawurlencode( $tag ) ) ] = array( 'status' => $status, 'body' => json_encode( $release ) );
		}

		private function calls(): array {
			return $GLOBALS['hub_test_calls'];
		}

		private function activity(): array {
			return array_column( array_reverse( $this->api->get_activity_log() ), 'action' );
		}

		private function ajax( string $method ): \Hub_Test_Halt {
			try {
				$this->api->$method();
			} catch ( \Hub_Test_Halt $halt ) {
				return $halt;
			}
			$this->fail( 'The handler did not answer.' );
		}

		private function set( string $private_property, $value ): void {
			$property = new \ReflectionProperty( API::class, $private_property );
			$property->setValue( $this->api, $value );
		}

		// ------------------------------------------------------ installed state

		public function test_installed_plugin_lookups(): void {
			$this->assertTrue( $this->api->is_plugin_installed( 'alpha' ) );
			$this->assertFalse( $this->api->is_plugin_installed( 'gamma' ) );
			$this->assertFalse( $this->api->is_plugin_installed( 'alph' ), 'Only whole directory names match.' );
			$this->assertSame( 'alpha/alpha.php', $this->api->get_plugin_file( 'alpha' ) );
			$this->assertFalse( $this->api->get_plugin_file( 'gamma' ) );
			$this->assertSame( '1.0.0', $this->api->get_installed_plugin_version( 'alpha' ) );
			$this->assertSame( 'Not Installed', $this->api->get_installed_plugin_version( 'gamma' ) );
		}

		public function test_active_state_includes_network_activation_on_multisite(): void {
			$this->assertTrue( $this->api->is_plugin_active( 'alpha' ) );
			$this->assertFalse( $this->api->is_plugin_active( 'dep' ) );
			$this->assertFalse( $this->api->is_plugin_active( 'gamma' ) );

			$GLOBALS['hub_test_network_active'] = array( 'dep/dep.php' );
			$this->assertFalse( $this->api->is_plugin_active( 'dep' ), 'Network activation only counts on multisite.' );
			$GLOBALS['hub_test_multisite'] = true;
			$this->assertTrue( $this->api->is_plugin_active( 'dep' ) );
		}

		public function test_installed_plugins_are_read_once_per_request(): void {
			$this->assertArrayHasKey( 'hello.php', $this->api->get_installed_plugins() );
			unset( $GLOBALS['hub_test_plugins']['alpha/alpha.php'] );
			$this->assertTrue( $this->api->is_plugin_installed( 'alpha' ), 'The list is cached.' );
			$this->assertFalse( ( new API() )->is_plugin_installed( 'alpha' ) );
		}

		public function test_dependents_and_missing_requirements(): void {
			$GLOBALS['hub_test_active_plugins'][] = 'dep/dep.php';
			$dependents                           = $this->api->get_dependents( 'alpha' );

			$this->assertSame(
				array(
					array( 'file' => 'dep/dep.php', 'name' => 'Dependent', 'active' => true ),
					array( 'file' => 'solo/solo.php', 'name' => 'Solo', 'active' => false ),
				),
				$dependents
			);
			$this->assertSame( array( 'ghost' ), $this->api->get_missing_requirements( 'solo' ) );
			$this->assertSame( array(), $this->api->get_missing_requirements( 'dep' ) );
			$this->assertSame( array(), $this->api->get_missing_requirements( 'gamma' ), 'Not installed means nothing to check.' );
		}

		// -------------------------------------------------------- update source

		public function test_outdated_plugins_are_offered_through_the_core_update_transient(): void {
			$this->head( 'alpha', '1.5.0' );
			$transient = (object) array( 'checked' => array( 'alpha/alpha.php' => '1.0.0', 'dep/dep.php' => '2.0.0' ), 'response' => array() );

			$result = $this->api->check_for_plugin_updates( $transient );

			$this->assertSame( array( 'alpha/alpha.php' ), array_keys( $result->response ) );
			$update = $result->response['alpha/alpha.php'];
			$this->assertSame( '1.5.0', $update->new_version );
			$this->assertSame( 'alpha', $update->slug );
			$this->assertSame( 'https://github.com/Open-WP-Club/alpha', $update->id );
			$this->assertSame( sprintf( self::HEAD, 'alpha', '1.5.0' ), $update->package );
		}

		public function test_pinned_plugins_and_unchecked_ones_are_not_offered(): void {
			$this->head( 'alpha', '1.5.0' );
			$this->api->set_pin( 'alpha', '1.0.0' );

			$pinned = $this->api->check_for_plugin_updates( (object) array( 'checked' => array( 'alpha/alpha.php' => '1.0.0' ), 'response' => array() ) );
			$this->assertSame( array(), $pinned->response );

			$this->api->set_pin( 'alpha', '' );
			$unchecked = $this->api->check_for_plugin_updates( (object) array( 'checked' => array( 'dep/dep.php' => '1.0.0' ), 'response' => array() ) );
			$this->assertSame( array(), $unchecked->response );
		}

		public function test_update_check_leaves_foreign_values_alone(): void {
			$this->assertFalse( $this->api->check_for_plugin_updates( false ) );
			$empty = (object) array( 'checked' => array() );
			$this->assertSame( $empty, $this->api->check_for_plugin_updates( $empty ) );
		}

		public function test_plugin_information_modal(): void {
			$this->head( 'alpha', '1.5.0' );
			$this->release( 'alpha', '1.5.0', array() );
			$GLOBALS['hub_test_routes']['https://api.github.com/repos/Open-WP-Club/alpha/releases?per_page=30'] = array(
				'status' => 200,
				'body'   => json_encode( array( array( 'tag_name' => 'v1.5.0', 'body' => 'Fixes bugs' ) ) ),
			);

			$info = $this->api->get_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'alpha' ) );

			$this->assertSame( 'Alpha', $info->name );
			$this->assertSame( '1.5.0', $info->version );
			$this->assertSame( sprintf( self::HEAD, 'alpha', '1.5.0' ), $info->download_link );
			$this->assertStringContainsString( '&lt;b&gt;plugin&lt;/b&gt;', $info->sections['description'], 'Catalog text is escaped.' );
			$this->assertStringContainsString( 'Fixes bugs', $info->sections['changelog'] );
		}

		public function test_plugin_information_ignores_other_requests(): void {
			$this->assertFalse( $this->api->get_plugin_information( false, 'query_plugins', (object) array( 'slug' => 'alpha' ) ) );
			$this->assertFalse( $this->api->get_plugin_information( false, 'plugin_information', (object) array() ) );
			$this->assertSame( 'kept', $this->api->get_plugin_information( 'kept', 'plugin_information', (object) array( 'slug' => 'not-ours' ) ) );
		}

		// ------------------------------------------------------ download URLs

		public function test_direct_release_asset_is_preferred_and_cached(): void {
			$url = $this->head( 'gamma', '3.0.0' );

			$this->assertSame( $url, $this->api->get_github_release_download_url( 'gamma', '3.0.0' ) );
			$this->assertSame( $url, $this->api->get_github_release_download_url( 'gamma', '3.0.0' ) );
			$this->assertCount( 1, $GLOBALS['hub_test_heads'], 'The second lookup is served from the transient.' );
			$this->assertSame( array(), $GLOBALS['hub_test_requests'], 'The REST API (and its rate limit) is not used.' );
		}

		public function test_untrusted_download_lookups_are_refused(): void {
			$this->assertFalse( $this->api->get_github_release_download_url( '../evil', '3.0.0' ) );
			$this->assertFalse( $this->api->get_github_release_download_url( 'gamma', 'latest' ) );
			$this->assertFalse( $this->api->get_github_release_download_url( 'not-in-catalog', '1.0.0' ) );
			$this->assertSame( array(), $GLOBALS['hub_test_heads'] );
		}

		public static function release_assets(): array {
			$zip = static fn ( $name, $url = null ) => array( 'name' => $name, 'browser_download_url' => $url ?? 'https://github.com/Open-WP-Club/gamma/releases/download/v3.0.0/' . $name );
			return array(
				'repo-named zip wins' => array( array( 'assets' => array( $zip( 'other.zip' ), $zip( 'gamma.zip' ) ), 'zipball_url' => 'https://api.github.com/zip' ), 'https://github.com/Open-WP-Club/gamma/releases/download/v3.0.0/gamma.zip' ),
				'first zip otherwise' => array( array( 'assets' => array( $zip( 'notes.txt' ), $zip( 'build.zip' ) ), 'zipball_url' => 'https://api.github.com/zip' ), 'https://github.com/Open-WP-Club/gamma/releases/download/v3.0.0/build.zip' ),
				'zipball fallback'    => array( array( 'assets' => array(), 'zipball_url' => 'https://api.github.com/repos/Open-WP-Club/gamma/zipball/v3.0.0' ), 'https://api.github.com/repos/Open-WP-Club/gamma/zipball/v3.0.0' ),
				'foreign asset host'  => array( array( 'assets' => array( $zip( 'gamma.zip', 'https://evil.example/gamma.zip' ) ), 'zipball_url' => 'https://api.github.com/zip' ), 'https://api.github.com/zip' ),
				'foreign zipball'     => array( array( 'assets' => array(), 'zipball_url' => 'https://evil.example/zip' ), false ),
				'draft release'       => array( array( 'draft' => true, 'zipball_url' => 'https://api.github.com/zip' ), false ),
			);
		}

		#[DataProvider( 'release_assets' )]
		public function test_rest_api_fallback_picks_the_best_trusted_package( array $release, $expected ): void {
			$this->release( 'gamma', 'v3.0.0', $release );
			$this->assertSame( $expected, $this->api->get_github_release_download_url( 'gamma', '3.0.0' ) );
		}

		public function test_rest_api_fallback_tries_both_tag_spellings(): void {
			$this->release( 'gamma', 'v3.0.0', array(), 404 );
			$this->release( 'gamma', '3.0.0', array( 'zipball_url' => 'https://api.github.com/zip' ) );
			$this->assertSame( 'https://api.github.com/zip', $this->api->get_github_release_download_url( 'gamma', '3.0.0' ) );
		}

		public function test_rest_api_errors_give_no_package(): void {
			$this->release( 'gamma', 'v3.0.0', array(), 500 );
			$this->assertFalse( $this->api->get_github_release_download_url( 'gamma', '3.0.0' ) );

			$GLOBALS['hub_test_routes'] = array( sprintf( self::TAG, 'gamma', 'v3.0.0' ) => new \WP_Error( 'http', 'offline' ) );
			$this->assertFalse( $this->api->get_github_release_download_url( 'gamma', '3.0.0' ) );
		}

		public function test_release_publication_time_is_fetched_and_cached(): void {
			$this->release( 'gamma', 'v3.0.0', array( 'published_at' => '2026-10-20T08:00:00Z' ) );
			$expected = strtotime( '2026-10-20T08:00:00Z' );

			$this->assertSame( $expected, $this->api->get_release_published_at( 'gamma', '3.0.0' ) );
			$this->assertSame( $expected, $this->api->get_release_published_at( 'gamma', '3.0.0' ) );
			$this->assertCount( 1, $GLOBALS['hub_test_requests'] );
			$this->assertSame( 0, $this->api->get_release_published_at( 'bad name', '3.0.0' ) );
		}

		public function test_unknown_publication_time_is_cached_briefly(): void {
			$this->assertSame( 0, $this->api->get_release_published_at( 'gamma', '3.0.0' ) );
			$this->api->get_release_published_at( 'gamma', '3.0.0' );
			$this->assertCount( 2, $GLOBALS['hub_test_requests'], 'One request per tag spelling, then the cached 0.' );
		}

		// -------------------------------------------------------------- install

		/**
		 * Make the fake upgrader "install" gamma when asked to.
		 */
		private function installer( $result = true, $info = 'gamma/gamma.php', string $version = '3.0.0' ): void {
			$GLOBALS['hub_test_upgrader'] = array(
				'install'     => $result,
				'plugin_info' => $info,
				'on_install'  => static function () use ( $version ) {
					$GLOBALS['hub_test_plugins']['gamma/gamma.php'] = array( 'Name' => 'Gamma', 'Version' => $version );
				},
			);
		}

		public static function bad_install_requests(): array {
			return array(
				'bad name'       => array( '../x', '3.0.0', 'plugin_hub_invalid' ),
				'empty version'  => array( 'gamma', '', 'plugin_hub_invalid' ),
				'bad version'    => array( 'gamma', 'latest', 'plugin_hub_invalid' ),
				'not in catalog' => array( 'ghost', '1.0.0', 'plugin_hub_untrusted' ),
				'old version'    => array( 'gamma', '2.0.0', 'plugin_hub_untrusted' ),
				'installed'      => array( 'alpha', '1.5.0', 'plugin_hub_installed' ),
				'no package'     => array( 'gamma', '3.0.0', 'plugin_hub_no_package' ),
			);
		}

		#[DataProvider( 'bad_install_requests' )]
		public function test_install_validation( string $name, string $version, string $code ): void {
			$result = $this->api->install_plugin( $name, $version, true );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( $code, $result->get_error_code() );
			$this->assertSame( array(), $this->calls() );
		}

		public function test_install_downloads_verifies_and_logs(): void {
			$url = $this->head( 'gamma', '3.0.0' );
			$this->installer();

			$this->assertTrue( $this->api->install_plugin( 'gamma', '3.0.0', true ) );

			$this->assertContains( 'install:' . $url, $this->calls() );
			$this->assertSame( array( 'install' ), $this->activity() );
			$this->assertSame( array( 'gamma' => array( 'repo' => 'gamma', 'file' => 'gamma/gamma.php' ) ), $GLOBALS['hub_test_options']['plugin_hub_github_plugins'] );
			$this->assertTrue( $this->api->is_plugin_installed( 'gamma' ) );
			$this->assertArrayNotHasKey( 'upgrader_pre_download', $GLOBALS['hub_test_filters'], 'Checksum verification is disarmed afterwards.' );
		}

		public function test_install_arms_checksum_verification_during_the_download(): void {
			$GLOBALS['hub_test_transients']['plugin_hub_catalog_cache_v1'][1] = $this->repo( 'gamma', '3.0.0', str_repeat( 'a', 64 ) );
			$this->head( 'gamma', '3.0.0' );
			$seen = null;
			$this->installer();
			$GLOBALS['hub_test_upgrader']['on_install'] = static function () use ( &$seen ) {
				$seen = $GLOBALS['hub_test_filters']['upgrader_pre_download'] ?? null;
				$GLOBALS['hub_test_plugins']['gamma/gamma.php'] = array( 'Name' => 'Gamma', 'Version' => '3.0.0' );
			};

			$this->api->install_plugin( 'gamma', '3.0.0', true );
			$this->assertNotNull( $seen, 'The filter was active while the upgrader ran.' );
			$this->assertArrayNotHasKey( 'upgrader_pre_download', $GLOBALS['hub_test_filters'] );
		}

		public function test_install_requires_a_checksum_when_configured(): void {
			$this->head( 'gamma', '3.0.0' );
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'require_checksum' => true );

			$result = $this->api->install_plugin( 'gamma', '3.0.0', true );
			$this->assertSame( 'plugin_hub_no_checksum', $result->get_error_code() );
			$this->assertSame( array(), $this->calls(), 'Nothing is downloaded.' );
		}

		public function test_install_upgrader_failures(): void {
			$this->head( 'gamma', '3.0.0' );

			$this->installer( new \WP_Error( 'upgrader', 'Could not create directory' ) );
			$this->assertSame( 'upgrader', $this->api->install_plugin( 'gamma', '3.0.0', true )->get_error_code() );

			$this->installer( false );
			$this->assertSame( 'plugin_hub_install_failed', $this->api->install_plugin( 'gamma', '3.0.0', true )->get_error_code() );

			unset( $GLOBALS['hub_test_plugins']['gamma/gamma.php'] );
			$this->installer( true, false );
			$this->assertSame( 'plugin_hub_no_file', $this->api->install_plugin( 'gamma', '3.0.0', true )->get_error_code() );

			unset( $GLOBALS['hub_test_plugins']['gamma/gamma.php'] );
			$this->installer( true, 'gamma/gamma.php', '2.9.0' );
			$this->assertSame( 'plugin_hub_unverified', $this->api->install_plugin( 'gamma', '3.0.0', true )->get_error_code() );
			$this->assertSame( array(), $this->activity(), 'Failed installs are not logged.' );
		}

		public function test_package_source_is_renamed_to_the_plugin_slug(): void {
			$this->assertSame( '/tmp/x/gamma-abc123/', $this->api->normalize_package_source( '/tmp/x/gamma-abc123/', '/tmp/x', null, array() ), 'Idle without an install.' );

			$this->set( 'expected_package_slug', 'gamma' );
			$this->assertSame( '/tmp/x/gamma/', $this->api->normalize_package_source( '/tmp/x/gamma/', '/tmp/x', null, array() ) );

			$root = sys_get_temp_dir() . '/plugin-hub-src-' . getmypid();
			mkdir( $root . '/Open-WP-Club-gamma-abc123', 0755, true );
			$GLOBALS['wp_filesystem'] = new \Hub_Test_Filesystem();
			$this->assertSame( $root . '/gamma/', $this->api->normalize_package_source( $root . '/Open-WP-Club-gamma-abc123/', $root, null, array() ) );
			$this->assertDirectoryExists( $root . '/gamma' );
			\hub_test_rmtree( $root );

			$GLOBALS['wp_filesystem'] = null;
			$error                    = $this->api->normalize_package_source( '/tmp/x/other/', '/tmp/x', null, array() );
			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertSame( 'plugin_hub_source_rename_failed', $error->get_error_code() );
		}

		// ------------------------------------------------- activate / deactivate

		public function test_activation(): void {
			$this->assertSame( 'plugin_hub_invalid', $this->api->activate_plugin_by_repo( '../x' )->get_error_code() );
			$this->assertSame( 'plugin_hub_not_found', $this->api->activate_plugin_by_repo( 'gamma' )->get_error_code() );

			$GLOBALS['hub_test_options']['plugin_hub_disabled_dep'] = true;
			$this->assertTrue( $this->api->activate_plugin_by_repo( 'dep', true ) );
			$this->assertContains( 'activate_plugin:dep/dep.php:network', $this->calls() );
			$this->assertSame( array( 'activate' ), $this->activity() );
			$this->assertArrayNotHasKey( 'plugin_hub_disabled_dep', $GLOBALS['hub_test_options'] );
		}

		public function test_activation_errors_from_wordpress_are_returned(): void {
			$GLOBALS['hub_test_activate_result'] = new \WP_Error( 'plugin_fatal', 'Plugin could not be activated' );
			$result                              = $this->api->activate_plugin_by_repo( 'dep' );
			$this->assertSame( 'plugin_fatal', $result->get_error_code() );
			$this->assertSame( array(), $this->activity() );
		}

		public function test_deactivation_blocks_on_active_dependents_unless_forced(): void {
			$GLOBALS['hub_test_active_plugins'][] = 'dep/dep.php';

			$blocked = $this->api->deactivate_plugin_by_repo( 'alpha' );
			$this->assertSame( 'plugin_hub_has_dependents', $blocked->get_error_code() );
			$this->assertStringContainsString( 'Dependent', $blocked->get_error_message() );
			$this->assertTrue( $this->api->is_plugin_active( 'alpha' ) );

			$this->assertTrue( $this->api->deactivate_plugin_by_repo( 'alpha', false, true ) );
			$this->assertFalse( $this->api->is_plugin_active( 'alpha' ) );
			$this->assertSame( array( 'deactivate' ), $this->activity() );
		}

		public function test_deactivation_validation_and_network_rules(): void {
			$this->assertSame( 'plugin_hub_invalid', $this->api->deactivate_plugin_by_repo( '' )->get_error_code() );
			$this->assertSame( 'plugin_hub_not_found', $this->api->deactivate_plugin_by_repo( 'gamma' )->get_error_code() );

			$GLOBALS['hub_test_multisite']      = true;
			$GLOBALS['hub_test_network_active'] = array( 'alpha/alpha.php' );
			$this->assertSame( 'plugin_hub_network_active', $this->api->deactivate_plugin_by_repo( 'alpha', false, true )->get_error_code() );
			$this->assertTrue( $this->api->deactivate_plugin_by_repo( 'alpha', true, true ) );
			$this->assertContains( 'deactivate_plugins:alpha/alpha.php:network', $this->calls() );
		}

		// ---------------------------------------------------------------- delete

		public function test_delete_rules(): void {
			$this->assertSame( 'plugin_hub_invalid', $this->api->delete_plugin_by_repo( '../x' )->get_error_code() );
			$this->assertSame( 'plugin_hub_not_found', $this->api->delete_plugin_by_repo( 'gamma' )->get_error_code() );
			$this->assertSame( 'plugin_hub_active', $this->api->delete_plugin_by_repo( 'alpha' )->get_error_code() );

			$GLOBALS['hub_test_active_plugins'] = array( 'dep/dep.php' );
			$GLOBALS['hub_test_plugins']['dep/dep.php']['RequiresPlugins'] = '';
			$this->assertSame( 'plugin_hub_has_dependents', $this->api->delete_plugin_by_repo( 'alpha' )->get_error_code() );
		}

		public function test_delete_removes_the_plugin_and_its_records(): void {
			$GLOBALS['hub_test_active_plugins']                      = array();
			$GLOBALS['hub_test_options']['plugin_hub_github_plugins'] = array( 'alpha' => array( 'repo' => 'alpha' ) );
			$GLOBALS['hub_test_options']['plugin_hub_disabled_alpha'] = true;
			$api                                                       = new API();

			$this->assertTrue( $api->delete_plugin_by_repo( 'alpha', true ) );

			$this->assertContains( 'delete_plugins:alpha/alpha.php', $this->calls() );
			$this->assertFalse( $api->is_plugin_installed( 'alpha' ) );
			$this->assertSame( array(), $GLOBALS['hub_test_options']['plugin_hub_github_plugins'] );
			$this->assertArrayNotHasKey( 'plugin_hub_disabled_alpha', $GLOBALS['hub_test_options'] );
			$this->assertSame( array( 'delete' ), array_column( $api->get_activity_log(), 'action' ) );
		}

		public function test_delete_failures_are_reported(): void {
			$GLOBALS['hub_test_active_plugins'] = array();

			$GLOBALS['hub_test_delete_result'] = new \WP_Error( 'fs', 'Could not remove files' );
			$this->assertSame( 'fs', $this->api->delete_plugin_by_repo( 'alpha', true )->get_error_code() );

			$GLOBALS['hub_test_delete_result'] = false;
			$this->assertSame( 'plugin_hub_delete_failed', $this->api->delete_plugin_by_repo( 'alpha', true )->get_error_code() );
			$this->assertSame( array(), $this->activity() );
		}

		// ------------------------------------------------------ AJAX handlers

		public static function ajax_handlers(): array {
			return array(
				'install'    => array( 'ajax_install_github_plugin', 'install_plugins' ),
				'activate'   => array( 'ajax_activate_github_plugin', 'activate_plugins' ),
				'deactivate' => array( 'ajax_deactivate_github_plugin', 'deactivate_plugins' ),
				'delete'     => array( 'ajax_delete_github_plugin', 'delete_plugins' ),
				'update'     => array( 'verify_plugin_update', 'update_plugins' ),
				'changelog'  => array( 'ajax_get_changelog', 'install_plugins' ),
				'releases'   => array( 'ajax_get_plugin_releases', 'update_plugins' ),
				'update run' => array( 'ajax_update_github_plugin', 'update_plugins' ),
			);
		}

		#[DataProvider( 'ajax_handlers' )]
		public function test_ajax_handlers_check_nonce_and_capability( string $method, string $capability ): void {
			$GLOBALS['hub_test_nonce_ok'] = false;
			$this->assertSame( 'nonce', $this->ajax( $method )->kind );

			$GLOBALS['hub_test_nonce_ok'] = true;
			$GLOBALS['hub_test_caps']     = array_diff( array( 'install_plugins', 'activate_plugins', 'deactivate_plugins', 'delete_plugins', 'update_plugins' ), array( $capability ) );
			$halt                         = $this->ajax( $method );
			$this->assertSame( 'json_error', $halt->kind );
			$this->assertStringContainsString( 'permission', $halt->data );
			$this->assertSame( array(), $this->calls() );
		}

		public function test_ajax_install(): void {
			$this->head( 'gamma', '3.0.0' );
			$this->installer();
			$_POST = array( 'repo' => 'gamma', 'version' => '3.0.0' );
			$halt  = $this->ajax( 'ajax_install_github_plugin' );
			$this->assertSame( array( 'json_success', 'Plugin installed successfully.' ), array( $halt->kind, $halt->data ) );

			$_POST = array( 'repo' => 'gamma', 'version' => '3.0.0' );
			$halt  = $this->ajax( 'ajax_install_github_plugin' );
			$this->assertSame( array( 'json_error', 'The plugin is already installed.' ), array( $halt->kind, $halt->data ) );
		}

		public function test_ajax_activate_and_deactivate(): void {
			$_POST = array( 'repo' => 'dep' );
			$this->assertSame( 'Plugin activated successfully.', $this->ajax( 'ajax_activate_github_plugin' )->data );

			$_POST = array( 'repo' => 'dep' );
			$this->assertSame( 'Plugin deactivated successfully.', $this->ajax( 'ajax_deactivate_github_plugin' )->data );

			$_POST = array( 'repo' => 'ghost' );
			$this->assertSame( 'Plugin not found.', $this->ajax( 'ajax_activate_github_plugin' )->data );
		}

		public function test_ajax_force_flag_overrides_the_dependent_check(): void {
			$GLOBALS['hub_test_active_plugins'][] = 'dep/dep.php';

			$_POST = array( 'repo' => 'alpha' );
			$this->assertSame( 'json_error', $this->ajax( 'ajax_deactivate_github_plugin' )->kind );

			$_POST = array( 'repo' => 'alpha', 'force' => '1' );
			$this->assertSame( 'json_success', $this->ajax( 'ajax_deactivate_github_plugin' )->kind );
		}

		public function test_network_actions_need_multisite_and_the_network_capability(): void {
			$_POST = array( 'repo' => 'dep', 'network' => '1' );
			$halt  = $this->ajax( 'ajax_activate_github_plugin' );
			$this->assertSame( 'You do not have permission to manage network plugins.', $halt->data, 'Single sites have no network.' );

			$GLOBALS['hub_test_multisite'] = true;
			$GLOBALS['hub_test_caps']      = array( 'activate_plugins' );
			$_POST                         = array( 'repo' => 'dep', 'network' => '1' );
			$this->assertSame( 'json_error', $this->ajax( 'ajax_activate_github_plugin' )->kind );

			$GLOBALS['hub_test_caps'] = array( 'activate_plugins', 'manage_network_plugins' );
			$_POST                    = array( 'repo' => 'dep', 'network' => '1' );
			$this->assertSame( 'json_success', $this->ajax( 'ajax_activate_github_plugin' )->kind );
			$this->assertContains( 'activate_plugin:dep/dep.php:network', $this->calls() );
		}

		public function test_ajax_delete(): void {
			$GLOBALS['hub_test_active_plugins'] = array();
			$_POST                              = array( 'repo' => 'dep' );
			$this->assertSame( 'Plugin deleted successfully.', $this->ajax( 'ajax_delete_github_plugin' )->data );

			$_POST = array( 'repo' => 'dep' );
			$this->assertSame( 'Plugin not found.', $this->ajax( 'ajax_delete_github_plugin' )->data );
		}

		public function test_ajax_update_runs_the_full_pipeline(): void {
			$this->head( 'alpha', '1.5.0' );
			$GLOBALS['hub_test_upgrader'] = array(
				'on_upgrade' => static function () {
					$GLOBALS['hub_test_plugins']['alpha/alpha.php']['Version'] = '1.5.0';
				},
			);
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'backup_enabled' => false, 'auto_rollback' => false );

			$_POST = array( 'repo' => 'alpha', 'version' => '1.5.0' );
			$halt  = $this->ajax( 'ajax_update_github_plugin' );

			$this->assertSame( array( 'json_success', 'Plugin updated successfully to version 1.5.0' ), array( $halt->kind, $halt->data ) );
			$this->assertContains( 'upgrade:alpha/alpha.php', $this->calls() );
			$this->assertSame( array( 'update' ), $this->activity() );
			$this->assertArrayNotHasKey( 'update_plugins', $GLOBALS['hub_test_site_transients'], 'The temporary update entry is removed.' );
		}

		public function test_ajax_update_reports_errors(): void {
			$_POST = array( 'repo' => 'alpha', 'version' => '9.9.9' );
			$halt  = $this->ajax( 'ajax_update_github_plugin' );
			$this->assertSame( 'json_error', $halt->kind );
			$this->assertStringContainsString( 'no longer current', $halt->data );
		}

		public function test_update_aborts_when_no_trusted_package_exists(): void {
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'backup_enabled' => false );
			$_POST                                              = array( 'repo' => 'alpha', 'version' => '1.5.0' );
			$halt                                               = $this->ajax( 'ajax_update_github_plugin' );
			$this->assertSame( 'json_error', $halt->kind );
			$this->assertStringContainsString( 'trusted download URL', $halt->data );
			$this->assertSame( array(), $this->calls() );
		}

		public function test_verify_plugin_update(): void {
			$_POST = array( 'repo' => 'alpha', 'version' => '1.0.0' );
			$this->assertSame( array( 'json_success', 'Plugin version verified: 1.0.0' ), array( $this->ajax( 'verify_plugin_update' )->kind, $this->ajax( 'verify_plugin_update' )->data ) );

			$_POST = array( 'repo' => 'alpha', 'version' => '2.0.0' );
			$this->assertSame( 'Plugin version mismatch. Expected: 2.0.0, Found: 1.0.0', $this->ajax( 'verify_plugin_update' )->data );

			$_POST = array( 'repo' => '../x', 'version' => '2.0.0' );
			$this->assertSame( 'Invalid plugin information.', $this->ajax( 'verify_plugin_update' )->data );
		}

		// ------------------------------------------------------------ changelog

		private function serve_releases( string $repo, array $releases ): void {
			$GLOBALS['hub_test_routes'][ "https://api.github.com/repos/Open-WP-Club/{$repo}/releases?per_page=30" ] = array( 'status' => 200, 'body' => json_encode( $releases ) );
			$GLOBALS['hub_test_routes'][ "https://api.github.com/repos/Open-WP-Club/{$repo}/releases?per_page=10" ] = array( 'status' => 200, 'body' => json_encode( $releases ) );
		}

		public function test_changelog_contains_only_the_versions_in_between(): void {
			$this->serve_releases(
				'alpha',
				array(
					array( 'tag_name' => 'v1.6.0', 'body' => 'Too new' ),
					array( 'tag_name' => 'v1.5.0', 'body' => 'Newest <script>x()</script>change' ),
					array( 'tag_name' => '1.2.0', 'body' => 'Middle change' ),
					array( 'tag_name' => 'v1.0.0', 'body' => 'Already installed' ),
					array( 'tag_name' => 'v1.4.0', 'body' => 'Draft', 'draft' => true ),
					array( 'tag_name' => 'nightly', 'body' => 'Not a version' ),
				)
			);

			$html = $this->api->get_github_changelog( 'alpha', '1.0.0', '1.5.0' );

			$this->assertStringContainsString( '<h4>Version 1.5.0</h4>', $html );
			$this->assertStringContainsString( '<h4>Version 1.2.0</h4>', $html );
			$this->assertStringContainsString( 'Newest change', $html );
			$this->assertStringNotContainsString( 'script', $html, 'Release notes are sanitised.' );
			foreach ( array( 'Too new', 'Already installed', 'Draft', 'Not a version' ) as $excluded ) {
				$this->assertStringNotContainsString( $excluded, $html );
			}
			$this->assertLessThan( strpos( $html, '1.2.0' ), strpos( $html, '1.5.0' ), 'Newest first, as GitHub lists them.' );
		}

		public function test_changelog_failures(): void {
			$this->assertFalse( $this->api->get_github_changelog( '../x', '1.0.0', '1.5.0' ) );
			$this->assertFalse( $this->api->get_github_changelog( 'ghost', '1.0.0', '1.5.0' ) );
			$this->assertFalse( $this->api->get_github_changelog( 'alpha', 'x', '1.5.0' ) );
			$this->assertFalse( $this->api->get_github_changelog( 'alpha', '1.0.0', '1.5.0' ), 'A 404 from GitHub gives no changelog.' );

			$GLOBALS['hub_test_routes']['https://api.github.com/repos/Open-WP-Club/alpha/releases?per_page=30'] = array( 'status' => 200, 'body' => 'not json' );
			$this->assertFalse( $this->api->get_github_changelog( 'alpha', '1.0.0', '1.5.0' ) );
		}

		public function test_ajax_changelog(): void {
			$this->serve_releases( 'alpha', array( array( 'tag_name' => 'v1.5.0', 'body' => 'Notes' ) ) );
			$_POST = array( 'repo' => 'alpha', 'current_version' => '1.0.0', 'new_version' => '1.5.0' );
			$halt  = $this->ajax( 'ajax_get_changelog' );
			$this->assertSame( 'json_success', $halt->kind );
			$this->assertStringContainsString( 'Notes', $halt->data );

			$_POST = array( 'repo' => 'alpha', 'current_version' => '1.5.0', 'new_version' => '1.5.0' );
			$this->assertSame( 'Unable to fetch changelog.', $this->ajax( 'ajax_get_changelog' )->data, 'No releases in range is reported as a failure.' );

			$_POST = array( 'repo' => 'alpha', 'current_version' => '', 'new_version' => '1.5.0' );
			$this->assertSame( 'Invalid plugin information.', $this->ajax( 'ajax_get_changelog' )->data );
		}

		public function test_ajax_release_list_for_rollback(): void {
			$this->serve_releases(
				'alpha',
				array(
					array( 'tag_name' => 'v2.0.0', 'name' => 'Newer than installed' ),
					array( 'tag_name' => 'v1.0.0', 'name' => 'Current', 'published_at' => '2026-01-02T03:04:05Z' ),
					array( 'tag_name' => 'v0.9.0', 'name' => '', 'published_at' => '2025-12-01T00:00:00Z' ),
					array( 'tag_name' => 'v0.8.0', 'name' => 'Draft', 'draft' => true ),
					array( 'tag_name' => 'junk', 'name' => 'Junk' ),
				)
			);
			$_POST = array( 'repo' => 'alpha' );
			$halt  = $this->ajax( 'ajax_get_plugin_releases' );

			$this->assertSame( 'json_success', $halt->kind );
			$this->assertSame(
				array(
					array( 'version' => '1.0.0', 'name' => 'Current', 'date' => '2026-01-02', 'current' => true ),
					array( 'version' => '0.9.0', 'name' => 'v0.9.0', 'date' => '2025-12-01', 'current' => false ),
				),
				$halt->data,
				'Only the installed version and older releases are offered.'
			);
		}

		public function test_ajax_release_list_validation(): void {
			$_POST = array( 'repo' => '../x' );
			$this->assertSame( 'Invalid plugin information.', $this->ajax( 'ajax_get_plugin_releases' )->data );

			$_POST = array( 'repo' => 'gamma' );
			$this->assertSame( 'The requested plugin is not available for rollback.', $this->ajax( 'ajax_get_plugin_releases' )->data, 'Not installed.' );

			$_POST = array( 'repo' => 'alpha' );
			$this->assertSame( 'Unable to fetch releases.', $this->ajax( 'ajax_get_plugin_releases' )->data );
		}
	}
}
