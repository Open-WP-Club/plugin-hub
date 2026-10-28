<?php
/**
 * Tests the admin list tables: filtering, sorting, pagination, bulk actions
 * and the markup of every column and row action.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';
	require_once __DIR__ . '/fs-stubs.php';
	require_once __DIR__ . '/wp-list-table-stub.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';
	require_once dirname( __DIR__ ) . '/includes/admin.php';
	require_once dirname( __DIR__ ) . '/includes/list-tables.php';

	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-plugins-' . getmypid() );
	}
	if ( ! defined( 'PLUGIN_HUB_PLUGIN_DIR' ) ) {
		define( 'PLUGIN_HUB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}
	if ( ! function_exists( __NAMESPACE__ . '\\size_format' ) ) {
		function size_format( $bytes, $decimals = 0 ) {
			return $bytes . ' B';
		}
	}
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
	if ( ! function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			return trim( strip_tags( (string) $value ) );
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\Activity_List_Table;
	use PluginHub\Admin;
	use PluginHub\API;
	use PluginHub\Backups;
	use PluginHub\Backups_List_Table;
	use PluginHub\MU_Available_List_Table;
	use PluginHub\MU_Installed_List_Table;
	use PluginHub\MU_Plugins;
	use PluginHub\Plugins_List_Table;

	/**
	 * API double with a scripted catalog and installation state.
	 */
	class Tables_Test_API extends API {
		public array $repos      = array();
		public array $installed  = array();
		public array $active     = array();
		public array $pins       = array();
		public array $dependents = array();
		public array $missing    = array();
		public array $log        = array();
		public array $filters    = array();

		public function __construct() {}

		public function get_org_repos() {
			return $this->repos;
		}
		public function get_cached_repos() {
			return $this->repos;
		}
		public function is_plugin_installed( $name ) {
			return isset( $this->installed[ $name ] );
		}
		public function get_plugin_file( $name ) {
			return "$name/$name.php";
		}
		public function get_installed_plugin_version( $name ) {
			return $this->installed[ $name ] ?? 'Not Installed';
		}
		public function is_plugin_active( $name ) {
			return in_array( $name, $this->active, true );
		}
		public function get_pin( $repo ) {
			return $this->pins[ $repo ] ?? '';
		}
		public function get_dependents( $name ) {
			return $this->dependents[ $name ] ?? array();
		}
		public function get_missing_requirements( $name ) {
			return $this->missing[ $name ] ?? array();
		}
		public function is_update_available( $repo, $installed_version ) {
			return version_compare( $repo['version'], $installed_version, '>' );
		}
		public function is_update_allowed( $repo, $installed_version ) {
			return $this->is_update_available( $repo, $installed_version ) && '' === ( $this->pins[ $repo['name'] ] ?? '' );
		}
		public function get_activity_log( $filters = array() ) {
			$this->filters = $filters;
			return $this->log;
		}
		public function count_pending_updates() {
			return 0;
		}
	}

	final class ListTablesTest extends TestCase {

		private Tables_Test_API $api;
		private string $uploads;

		protected function setUp(): void {
			$this->uploads                   = sys_get_temp_dir() . '/plugin-hub-tables-' . getmypid();
			$GLOBALS['hub_test_uploads']     = $this->uploads;
			$GLOBALS['hub_test_options']     = array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i' );
			$GLOBALS['hub_test_multisite']   = false;
			$_GET                            = array();
			$this->api                       = new Tables_Test_API();
			$this->api->repos                = array(
				$this->repo( 'alpha', '1.5.0', 'Alpha tool' ),
				$this->repo( 'beta', '2.0.0', 'Beta <b>bold</b> helper' ),
				$this->repo( 'gamma', '3.0.0', 'Gamma thing' ),
				$this->repo( 'early', '0.3.0', 'Early preview' ),
				$this->repo( 'norelease', '', 'Nothing published', false ),
			);
			$this->api->installed            = array( 'alpha' => '1.0.0', 'beta' => '2.0.0' );
			$this->api->active               = array( 'alpha' );
		}

		protected function tearDown(): void {
			\hub_test_rmtree( $this->uploads );
			\hub_test_rmtree( WP_PLUGIN_DIR );
			foreach ( array( 'hub_test_uploads', 'hub_test_options', 'hub_test_multisite', 'hub_test_caps', 'hub_test_network_active', 'hub_test_network_admin' ) as $key ) {
				unset( $GLOBALS[ $key ] );
			}
			$_GET = array();
		}

		private function repo( string $name, string $version, string $description = '', bool $available = true ): array {
			return array(
				'name'         => $name,
				'display_name' => ucfirst( $name ),
				'version'      => $version,
				'description'  => $description,
				'repo_url'     => 'https://github.com/Open-WP-Club/' . $name,
				'available'    => $available,
			);
		}

		private function plugins_table( string $filter = 'all' ): Plugins_List_Table {
			$table = new Plugins_List_Table( $this->api, $filter );
			$table->prepare_items();
			return $table;
		}

		private function names( \WP_List_Table $table, string $key = 'name' ): array {
			return array_column( $table->items, $key );
		}

		/**
		 * Render a protected method of a table.
		 */
		private function call( object $table, string $method, ...$args ) {
			$m = new \ReflectionMethod( $table, $method );
			return $m->invoke( $table, ...$args );
		}

		private function row_html( \WP_List_Table $table, int $index = 0 ): string {
			ob_start();
			$table->single_row( $table->items[ $index ] );
			return (string) ob_get_clean();
		}

		// ------------------------------------------------------- Plugins table

		public function test_beta_releases_are_hidden_until_installed_or_enabled(): void {
			$this->assertSame( array( 'alpha', 'beta', 'gamma', 'norelease' ), $this->names( $this->plugins_table() ) );

			$GLOBALS['hub_test_options']['plugin_hub_show_beta'] = true;
			$this->assertContains( 'early', $this->names( $this->plugins_table() ) );

			unset( $GLOBALS['hub_test_options']['plugin_hub_show_beta'] );
			$this->api->installed['early'] = '0.2.0';
			$this->assertContains( 'early', $this->names( $this->plugins_table() ), 'An installed beta stays visible.' );
		}

		public static function views(): array {
			return array(
				'all'       => array( 'all', array( 'alpha', 'beta', 'gamma', 'norelease' ) ),
				'active'    => array( 'active', array( 'alpha' ) ),
				'inactive'  => array( 'inactive', array( 'beta' ) ),
				'installed' => array( 'installed', array( 'alpha', 'beta' ) ),
				'available' => array( 'available', array( 'gamma', 'norelease' ) ),
				'update'    => array( 'update', array( 'alpha' ) ),
				'pinned'    => array( 'pinned', array( 'beta' ) ),
				'unknown'   => array( 'whatever', array( 'alpha', 'beta', 'gamma', 'norelease' ) ),
			);
		}

		#[DataProvider( 'views' )]
		public function test_views_filter_rows( string $view, array $expected ): void {
			$this->api->pins['beta'] = '2.x';
			$this->assertSame( $expected, $this->names( $this->plugins_table( $view ) ) );
		}

		public function test_beta_view_lists_only_beta_releases(): void {
			$GLOBALS['hub_test_options']['plugin_hub_show_beta'] = true;
			$this->assertSame( array( 'early' ), $this->names( $this->plugins_table( 'beta' ) ) );
		}

		public function test_a_pinned_plugin_does_not_count_as_an_update(): void {
			$this->api->pins['alpha'] = '1.0.0';
			$this->assertSame( array(), $this->names( $this->plugins_table( 'update' ) ) );
		}

		public function test_view_links_carry_counts_search_and_the_current_marker(): void {
			$_GET['s'] = 'tool';
			$table     = $this->plugins_table( 'installed' );
			$views     = $this->call( $table, 'get_views' );

			$this->assertSame( array( 'all', 'active', 'inactive', 'installed', 'available', 'update', 'beta', 'pinned' ), array_keys( $views ) );
			$this->assertStringContainsString( 'class="current" aria-current="page"', $views['installed'] );
			$this->assertStringNotContainsString( 'class="current"', $views['all'] );
			$this->assertStringContainsString( '(4)', $views['all'] );
			$this->assertStringContainsString( '(2)', $views['installed'] );
			$this->assertStringContainsString( 'filter=update', $views['update'] );
			$this->assertStringContainsString( 's=tool', $views['update'] );
			$this->assertStringContainsString( 'page=plugin-hub', $views['update'] );
		}

		public function test_search_matches_name_title_and_description_case_insensitively(): void {
			$_GET['s'] = 'BOLD';
			$this->assertSame( array( 'beta' ), $this->names( $this->plugins_table() ) );

			$_GET['s'] = 'gam';
			$this->assertSame( array( 'gamma' ), $this->names( $this->plugins_table() ) );

			$_GET['s'] = 'no-such-thing';
			$this->assertSame( array(), $this->plugins_table()->items );
		}

		public function test_sorting_by_column_and_direction(): void {
			$_GET['orderby'] = 'version';
			$_GET['order']   = 'desc';
			// Installed rows sort by installed version, others by the catalog version.
			$this->assertSame( array( 'gamma', 'beta', 'alpha', 'norelease' ), $this->names( $this->plugins_table() ) );

			$_GET['orderby'] = 'status';
			$_GET['order']   = 'asc';
			$this->assertSame( 'alpha', $this->names( $this->plugins_table() )[0], 'Active plugins first.' );
		}

		public function test_unknown_sort_columns_fall_back_to_the_default(): void {
			$_GET['orderby'] = 'description; DROP TABLE';
			$_GET['order']   = 'sideways';
			$this->assertSame( array( 'alpha', 'beta', 'gamma', 'norelease' ), $this->names( $this->plugins_table() ) );
		}

		public function test_pagination_slices_pages(): void {
			$this->api->repos = array();
			for ( $i = 1; $i <= 30; $i++ ) {
				$this->api->repos[] = $this->repo( sprintf( 'p%02d', $i ), '1.0.0' );
			}

			$table = $this->plugins_table();
			$this->assertCount( 25, $table->items );
			$this->assertSame( 30, $table->get_pagination_arg( 'total_items' ) );
			$this->assertSame( 2, $table->get_pagination_arg( 'total_pages' ) );

			$_GET['paged'] = '2';
			$page2         = $this->plugins_table();
			$this->assertSame( array( 'p26', 'p27', 'p28', 'p29', 'p30' ), $this->names( $page2 ) );
		}

		public function test_empty_catalog_has_one_page(): void {
			$this->api->repos = array();
			$table            = $this->plugins_table();
			$this->assertSame( 1, $table->get_pagination_arg( 'total_pages' ) );
			ob_start();
			$table->no_items();
			$this->assertStringContainsString( 'No plugins match the current view.', (string) ob_get_clean() );
		}

		public function test_bulk_actions_add_network_actions_only_for_network_admins(): void {
			$table = $this->plugins_table();
			$this->assertSame( array( 'install', 'activate', 'deactivate', 'update', 'delete', 'enable_autoupdate', 'disable_autoupdate' ), array_keys( $this->call( $table, 'get_bulk_actions' ) ) );

			$GLOBALS['hub_test_multisite'] = true;
			$GLOBALS['hub_test_caps']      = array( 'manage_options' );
			$this->assertArrayNotHasKey( 'network_activate', $this->call( $table, 'get_bulk_actions' ) );

			$GLOBALS['hub_test_caps'] = array( 'manage_network_plugins' );
			$actions                  = $this->call( $table, 'get_bulk_actions' );
			$this->assertArrayHasKey( 'network_activate', $actions );
			$this->assertArrayHasKey( 'network_deactivate', $actions );
		}

		public function test_rows_carry_wordpress_state_classes_and_the_repo(): void {
			$html = $this->row_html( $this->plugins_table(), 0 ); // alpha: active with an update.
			$this->assertStringContainsString( '<tr class="active update" data-repo="alpha">', $html );

			$html = $this->row_html( $this->plugins_table(), 1 ); // beta: inactive, current.
			$this->assertStringContainsString( '<tr class="inactive" data-repo="beta">', $html );
		}

		public function test_active_plugin_actions(): void {
			$this->api->dependents['alpha'] = array( array( 'name' => 'Zeta', 'active' => true ), array( 'name' => 'Idle', 'active' => false ) );
			$html                           = $this->row_html( $this->plugins_table(), 0 );

			$this->assertStringContainsString( 'class="deactivate-now" data-repo="alpha" data-dependents="Zeta"', $html );
			$this->assertStringContainsString( 'class="update-now" data-repo="alpha" data-version="1.5.0" data-current-version="1.0.0"', $html );
			$this->assertStringContainsString( 'class="open-changelog" data-repo="alpha" data-current-version="1.0.0" data-new-version="1.5.0"', $html );
			$this->assertStringContainsString( 'class="pin-toggle" data-repo="alpha" data-version="1.0.0" data-pin=""', $html );
			$this->assertStringContainsString( 'Pin version', $html );
			$this->assertStringContainsString( 'class="rollback-toggle" data-repo="alpha"', $html );
			$this->assertStringNotContainsString( 'delete-now', $html, 'An active plugin cannot be deleted.' );
			$this->assertStringNotContainsString( 'class="activate-now"', $html );
		}

		public function test_inactive_plugin_actions(): void {
			$html = $this->row_html( $this->plugins_table(), 1 );
			$this->assertStringContainsString( 'class="activate-now" data-repo="beta"', $html );
			$this->assertStringContainsString( 'class="delete-now" data-repo="beta"', $html );
			$this->assertStringNotContainsString( 'update-now', $html );
			$this->assertStringNotContainsString( 'network-activate-now', $html );

			$GLOBALS['hub_test_multisite'] = true;
			$this->assertStringContainsString( 'network-activate-now', $this->row_html( $this->plugins_table(), 1 ) );
		}

		public function test_network_active_plugins_can_only_be_deactivated_by_network_admins(): void {
			$GLOBALS['hub_test_multisite']      = true;
			$GLOBALS['hub_test_network_active'] = array( 'alpha/alpha.php' );

			$GLOBALS['hub_test_caps'] = array( 'manage_options' );
			$this->assertStringNotContainsString( 'deactivate-now', $this->row_html( $this->plugins_table(), 0 ) );

			$GLOBALS['hub_test_caps'] = array( 'manage_network_plugins' );
			$this->assertStringContainsString( 'data-network="1" data-repo="alpha"', $this->row_html( $this->plugins_table(), 0 ) );
			$this->assertStringContainsString( 'Network active', $this->call( $this->plugins_table(), 'column_status', $this->plugins_table()->items[0] ) );
		}

		public function test_not_installed_plugin_actions(): void {
			$table = $this->plugins_table( 'available' );
			$html  = $this->row_html( $table, 0 ); // gamma.
			$this->assertStringContainsString( 'class="install-now" data-repo="gamma" data-version="3.0.0"', $html );
			$this->assertStringNotContainsString( 'rollback-toggle', $html );
			$this->assertStringNotContainsString( 'pin-toggle', $html );
			$this->assertStringContainsString( 'href="https://github.com/Open-WP-Club/gamma"', $html );

			$html = $this->row_html( $table, 1 ); // norelease.
			$this->assertStringContainsString( 'No release available', $html );
			$this->assertStringNotContainsString( 'install-now', $html );
		}

		public function test_pinned_and_blocked_updates_show_their_reason(): void {
			$this->api->pins['alpha'] = '1.x';
			$table                    = $this->plugins_table();
			$html                     = $this->row_html( $table, 0 );

			$this->assertStringNotContainsString( 'update-now', $html );
			$this->assertStringContainsString( 'open-changelog', $html, 'The changelog is still available.' );
			$this->assertStringContainsString( 'Change pin', $html );
			$this->assertStringContainsString( 'Pinned: 1.x', $html );
			$this->assertStringContainsString( 'Update to 1.5.0 is blocked by the pin or a failed earlier attempt.', $html );
		}

		public function test_status_and_version_columns(): void {
			$table = $this->plugins_table();
			$alpha = $table->items[0];
			$gamma = $this->plugins_table( 'available' )->items[0];
			$none  = $this->plugins_table( 'available' )->items[1];

			$this->assertSame( 'Active', $this->call( $table, 'column_status', $alpha ) );
			$this->assertSame( 'Inactive', $this->call( $table, 'column_status', $table->items[1] ) );
			$this->assertSame( 'Not installed', $this->call( $table, 'column_status', $gamma ) );

			$version = $this->call( $table, 'column_version', $alpha );
			$this->assertStringContainsString( 'Version 1.0.0', $version );
			$this->assertStringContainsString( 'Update available (1.5.0)', $version );
			$this->assertSame( 'Latest version is 3.0.0', $this->call( $table, 'column_version', $gamma ) );
			$this->assertSame( 'No published release', $this->call( $table, 'column_version', $none ) );
		}

		public function test_beta_badge_is_shown(): void {
			$GLOBALS['hub_test_options']['plugin_hub_show_beta'] = true;
			$early = $this->plugins_table( 'beta' )->items[0];
			$this->assertStringContainsString( 'plugin-hub-badge is-beta', $this->call( $this->plugins_table(), 'column_status', $early ) );
		}

		public function test_autoupdate_column(): void {
			$GLOBALS['hub_test_options']['plugin_hub_autoupdate_plugins'] = array( 'beta' );
			$table                                                        = $this->plugins_table();

			$this->assertStringNotContainsString( 'checked', $this->call( $table, 'column_autoupdate', $table->items[0] ) );
			$this->assertStringContainsString( 'checked="checked"', $this->call( $table, 'column_autoupdate', $table->items[1] ) );
			$this->assertSame( '&mdash;', $this->call( $table, 'column_autoupdate', $this->plugins_table( 'available' )->items[0] ) );
		}

		public function test_description_shows_requirements_and_dependents_and_escapes_html(): void {
			$this->api->missing['alpha']    = array( 'base-plugin', 'other' );
			$this->api->dependents['alpha'] = array( array( 'name' => 'Zeta', 'active' => true ) );
			$table                          = $this->plugins_table();

			$html = $this->call( $table, 'column_description', $table->items[0] );
			$this->assertStringContainsString( 'Requires plugins that are not installed: base-plugin, other', $html );
			$this->assertStringContainsString( 'Required by active plugins: Zeta', $html );

			$html = $this->call( $table, 'column_description', $table->items[1] );
			$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt;', $html );
			$this->assertStringNotContainsString( '<b>', $html );
		}

		public function test_names_and_checkboxes_are_escaped(): void {
			$this->api->repos[0]['display_name'] = '<script>alert(1)</script>';
			$table                               = $this->plugins_table();
			$row                                 = $this->row_html( $table, 0 );

			$this->assertStringNotContainsString( '<script>', $row );
			$this->assertStringContainsString( '&lt;script&gt;', $row );
			$this->assertStringContainsString( 'value="alpha"', $this->call( $table, 'column_cb', $table->items[0] ) );
		}

		// ------------------------------------------------------ MU tables

		private function mu_data( bool $file_mods = true ): array {
			return array(
				'file_mods' => $file_mods,
				'installed' => array(
					array( 'file' => 'two-factor.php', 'name' => 'Two Factor', 'category' => 'security', 'local_version' => '1.0.0', 'remote_version' => '1.2.0', 'update' => true, 'autoupdate' => true, 'has_backup' => false ),
					array( 'file' => 'cache.php', 'name' => 'Cache', 'category' => 'performance', 'local_version' => '2.0.0', 'remote_version' => '2.0.0', 'update' => false, 'autoupdate' => false, 'has_backup' => true ),
					array( 'file' => '000-helpers.php', 'name' => 'Helpers', 'category' => '', 'local_version' => '0.5.0', 'remote_version' => '0.5.0', 'update' => false, 'autoupdate' => false, 'has_backup' => false ),
				),
				'available' => array(
					array( 'file' => 'seo.php', 'name' => 'SEO', 'category' => 'marketing', 'version' => '1.0.0', 'description' => 'Search tags', 'wp' => '6.6+', 'php' => '7.4+' ),
					array( 'file' => 'mail.php', 'name' => 'mail.php', 'category' => 'admin', 'version' => '3.1.0', 'description' => 'Sends mail', 'wp' => '', 'php' => '' ),
				),
			);
		}

		private function mu_installed( string $view = 'all', bool $file_mods = true ): MU_Installed_List_Table {
			$table = new MU_Installed_List_Table( $this->mu_data( $file_mods ), $view );
			$table->prepare_items();
			return $table;
		}

		public function test_mu_installed_views_category_and_search(): void {
			$this->assertSame( array( 'cache.php', '000-helpers.php', 'two-factor.php' ), $this->names( $this->mu_installed(), 'file' ), 'Sorted by name: Cache, Helpers, Two Factor.' );
			$this->assertSame( array( 'two-factor.php' ), $this->names( $this->mu_installed( 'update' ), 'file' ) );

			$_GET['mu_category'] = 'performance';
			$this->assertSame( array( 'cache.php' ), $this->names( $this->mu_installed(), 'file' ) );

			$_GET = array( 's' => 'factor' );
			$this->assertSame( array( 'two-factor.php' ), $this->names( $this->mu_installed(), 'file' ) );
		}

		public function test_mu_installed_sorting(): void {
			$_GET['orderby'] = 'version';
			$_GET['order']   = 'desc';
			$this->assertSame( array( 'cache.php', 'two-factor.php', '000-helpers.php' ), $this->names( $this->mu_installed(), 'file' ) );

			$_GET['orderby'] = 'category';
			$_GET['order']   = 'asc';
			$this->assertSame( '000-helpers.php', $this->names( $this->mu_installed(), 'file' )[0], 'Shared (empty) category sorts first.' );
		}

		public function test_mu_installed_row_actions_follow_file_mods(): void {
			$table = $this->mu_installed();
			$rows  = array_column( $table->items, null, 'file' );

			$update = $this->call( $table, 'handle_row_actions', $rows['two-factor.php'], 'name', 'name' );
			$this->assertStringContainsString( 'class="mu-update-now" data-file="two-factor.php"', $update );
			$this->assertStringNotContainsString( 'mu-rollback-now', $update );

			$rollback = $this->call( $table, 'handle_row_actions', $rows['cache.php'], 'name', 'name' );
			$this->assertStringContainsString( 'class="mu-rollback-now" data-file="cache.php"', $rollback );

			$this->assertStringContainsString( 'Up to date', $this->call( $table, 'handle_row_actions', $rows['000-helpers.php'], 'name', 'name' ) );
			$this->assertSame( '', $this->call( $table, 'handle_row_actions', $rows['cache.php'], 'version', 'name' ), 'Only the primary column has actions.' );

			$locked = $this->mu_installed( 'all', false );
			$rows   = array_column( $locked->items, null, 'file' );
			$html   = $this->call( $locked, 'handle_row_actions', $rows['two-factor.php'], 'name', 'name' );
			$this->assertStringNotContainsString( 'mu-update-now', $html );
			$this->assertStringContainsString( 'Up to date', $html );
		}

		public function test_mu_installed_columns(): void {
			$table = $this->mu_installed();
			$rows  = array_column( $table->items, null, 'file' );

			$this->assertSame( 'shared', $this->call( $table, 'column_category', $rows['000-helpers.php'] ) );
			$this->assertSame( 'security', $this->call( $table, 'column_category', $rows['two-factor.php'] ) );
			$this->assertSame( '1.2.0', $this->call( $table, 'column_new_version', $rows['two-factor.php'] ) );
			$this->assertSame( '&mdash;', $this->call( $table, 'column_new_version', $rows['cache.php'] ) );
			$this->assertSame( '1.0.0', $this->call( $table, 'column_version', $rows['two-factor.php'] ) );
			$this->assertStringContainsString( 'checked="checked"', $this->call( $table, 'column_autoupdate', $rows['two-factor.php'] ) );
			$this->assertStringContainsString( '<code>two-factor.php</code>', $this->call( $table, 'column_name', $rows['two-factor.php'] ) );
			$this->assertSame( array( 'update', 'rollback', 'enable_autoupdate', 'disable_autoupdate' ), array_keys( $this->call( $table, 'get_bulk_actions' ) ) );
		}

		public function test_mu_category_filter_lists_categories_and_marks_the_selected_one(): void {
			$_GET['mu_category'] = 'security';
			$html               = $this->mu_installed()->tablenav_html( 'top' );

			$this->assertStringContainsString( '<option value="performance">', $html );
			$this->assertStringContainsString( '<option value="security" selected="selected">', $html );
			$this->assertStringNotContainsString( 'value="marketing"', $html );
			$this->assertSame( '', $this->mu_installed()->tablenav_html( 'bottom' ) );
		}

		public function test_mu_available_filters_and_actions(): void {
			$table = new MU_Available_List_Table( $this->mu_data() );
			$table->prepare_items();
			$this->assertSame( array( 'mail.php', 'seo.php' ), $this->names( $table, 'file' ) );
			$this->assertSame( array( 'install' => 'Install' ), $this->call( $table, 'get_bulk_actions' ) );

			$_GET['s'] = 'search tags';
			$table->prepare_items();
			$this->assertSame( array( 'seo.php' ), $this->names( $table, 'file' ), 'The description is searched.' );

			$_GET = array( 'mu_category' => 'admin' );
			$table->prepare_items();
			$this->assertSame( array( 'mail.php' ), $this->names( $table, 'file' ) );
		}

		public function test_mu_available_without_file_mods_offers_no_install(): void {
			$table = new MU_Available_List_Table( $this->mu_data( false ) );
			$table->prepare_items();
			$this->assertSame( array(), $this->call( $table, 'get_bulk_actions' ) );
			$this->assertSame( '', $this->call( $table, 'handle_row_actions', $table->items[0], 'name', 'name' ) );
		}

		public function test_mu_available_columns(): void {
			$table = new MU_Available_List_Table( $this->mu_data() );
			$table->prepare_items();
			$rows = array_column( $table->items, null, 'file' );

			$this->assertStringContainsString( 'class="mu-install-now" data-file="seo.php"', $this->call( $table, 'handle_row_actions', $rows['seo.php'], 'name', 'name' ) );
			$this->assertStringContainsString( 'WP 6.6+ · PHP 7.4+', $this->call( $table, 'column_version', $rows['seo.php'] ) );
			$this->assertSame( '3.1.0', $this->call( $table, 'column_version', $rows['mail.php'] ) );
			$this->assertStringContainsString( '<code>seo.php</code>', $this->call( $table, 'column_name', $rows['seo.php'] ) );
			$this->assertStringNotContainsString( '<code>', $this->call( $table, 'column_name', $rows['mail.php'] ), 'The file is not repeated when it is the title.' );
			ob_start();
			$table->no_items();
			$this->assertStringContainsString( 'every catalog MU plugin is already installed', (string) ob_get_clean() );
		}

		// ------------------------------------------------------- Activity table

		private function log_entries(): array {
			return array(
				array( 'id' => 'a1', 'time' => 1700000000, 'action' => 'update', 'plugin' => 'alpha', 'version' => '1.5.0', 'user' => 'bob' ),
				array( 'id' => 'a2', 'time' => 1700003600, 'action' => 'auto_rollback', 'plugin' => 'beta', 'version' => '2.0.0', 'user' => 'alice' ),
				array( 'id' => 'a3', 'time' => 1699990000, 'action' => 'weird_action', 'plugin' => 'gamma', 'version' => '', 'user' => 'bob' ),
			);
		}

		private function activity_table(): Activity_List_Table {
			$this->api->log = $this->log_entries();
			$table          = new Activity_List_Table( $this->api );
			$table->prepare_items();
			return $table;
		}

		public function test_activity_requests_are_sanitized_into_api_filters(): void {
			$_GET = array(
				'log_action' => 'Auto_Rollback<script>',
				'log_user'   => 'alice',
				's'          => 'beta',
				'log_from'   => '2023-11-01',
				'log_to'     => '2023-11-30',
			);
			$this->activity_table();

			$this->assertSame(
				array( 'action' => 'auto_rollback', 'user' => 'alice', 'search' => 'beta', 'from' => '2023-11-01', 'to' => '2023-11-30' ),
				$this->api->filters
			);
		}

		public function test_activity_is_newest_first_by_default_and_sortable(): void {
			$this->assertSame( array( 'a2', 'a1', 'a3' ), $this->names( $this->activity_table(), 'id' ) );

			$_GET['orderby'] = 'plugin';
			$_GET['order']   = 'desc';
			$this->assertSame( array( 'a3', 'a2', 'a1' ), $this->names( $this->activity_table(), 'id' ) );

			$_GET['orderby'] = 'user';
			$_GET['order']   = 'asc';
			$this->assertSame( 'a2', $this->names( $this->activity_table(), 'id' )[0] );
		}

		public function test_activity_columns(): void {
			$table = $this->activity_table();
			$rows  = array_column( $table->items, null, 'id' );

			$this->assertSame( '2023-11-14 22:13', $this->call( $table, 'column_time', $rows['a1'] ) );
			$this->assertStringContainsString( 'activity-badge activity-update', $this->call( $table, 'column_action', $rows['a1'] ) );
			$this->assertStringContainsString( 'activity-rollback', $this->call( $table, 'column_action', $rows['a2'] ) );
			$unknown = $this->call( $table, 'column_action', $rows['a3'] );
			$this->assertStringContainsString( 'weird_action', $unknown, 'Unknown actions show their raw key.' );
			$this->assertStringContainsString( 'activity-badge ">', $unknown );
			$this->assertSame( 'bob', $this->call( $table, 'column_default', $rows['a1'], 'user' ) );
			$this->assertSame( '', $this->call( $table, 'column_default', $rows['a1'], 'nonexistent' ) );
			$this->assertStringContainsString( 'data-id="a1"', $this->call( $table, 'handle_row_actions', $rows['a1'], 'time', 'time' ) );
			$this->assertSame( '', $this->call( $table, 'handle_row_actions', $rows['a1'], 'plugin', 'time' ) );
			$this->assertSame( 'time', $this->call( $table, 'get_default_primary_column_name' ) );
		}

		public function test_activity_filter_form_lists_known_users_and_keeps_the_selection(): void {
			$_GET['log_user']   = 'bob';
			$_GET['log_action'] = 'update';
			$html               = $this->activity_table()->tablenav_html( 'top' );

			$this->assertSame( 1, substr_count( $html, '<option value="alice"' ) );
			$this->assertSame( 1, substr_count( $html, '<option value="bob" selected="selected">' ) );
			$this->assertStringContainsString( '<option value="update" selected="selected">', $html );
			$this->assertStringContainsString( 'name="log_from"', $html );
		}

		public function test_every_logged_action_has_a_label(): void {
			$labels = Activity_List_Table::action_labels();
			$source = '';
			foreach ( glob( dirname( __DIR__ ) . '/includes/*.php' ) as $file ) {
				$source .= (string) file_get_contents( $file );
			}

			preg_match_all( "/log_activity\(\s*'([a-z_]+)'/", $source, $direct );
			preg_match_all( "/\\\$log_action\s*=\s*'([a-z_]+)'/", $source, $defaults );
			preg_match_all( "/'log_action'\s*=>\s*'([a-z_]+)'/", $source, $arrays );
			preg_match_all( "/\\\$log_action\s*=\s*'([a-z_]+)'\s*\)/", $source, $params );
			$used = array_unique( array_merge( $direct[1], $defaults[1], $params[1], $arrays[1] ) );

			$this->assertNotEmpty( $used );
			$this->assertSame( array(), array_values( array_diff( $used, array_keys( $labels ) ) ), 'Actions written to the log without a label in Activity_List_Table::action_labels().' );
		}

		// ------------------------------------------------------- Backups table

		private function make_backup( string $repo, string $version, string $payload = 'x' ): void {
			$dir = Backups::get_dir() . '/' . $repo;
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0755, true );
			}
			file_put_contents( $dir . '/' . $version . '_' . ( 1700000000 + strlen( $payload ) ) . '.zip', $payload );
		}

		public function test_backups_table_lists_filters_and_sorts(): void {
			$this->make_backup( 'alpha', '1.0.0', 'aa' );
			$this->make_backup( 'beta', '2.0.0', 'bbbbbb' );
			$this->make_backup( 'beta', '1.9.0', 'b' );

			$table = new Backups_List_Table();
			$table->prepare_items();
			$this->assertSame( array( 'beta__2.0.0_1700000006.zip', 'alpha__1.0.0_1700000002.zip', 'beta__1.9.0_1700000001.zip' ), $this->names( $table, 'id' ) );

			$_GET['orderby'] = 'size';
			$_GET['order']   = 'desc';
			$table->prepare_items();
			$this->assertSame( 'beta__2.0.0_1700000006.zip', $this->names( $table, 'id' )[0] );

			$_GET = array( 's' => 'ALP' );
			$table->prepare_items();
			$this->assertSame( array( 'alpha' ), $this->names( $table, 'repo' ) );
		}

		public function test_backups_table_columns_and_actions(): void {
			$this->make_backup( 'alpha', '1.0.0', 'aa' );
			$table = new Backups_List_Table();
			$table->prepare_items();
			$row = $table->items[0];

			$actions = $this->call( $table, 'handle_row_actions', $row, 'name', 'name' );
			$this->assertStringContainsString( 'class="restore-backup-now" data-id="alpha__1.0.0_1700000002.zip" data-version="1.0.0"', $actions );
			$this->assertStringContainsString( 'class="delete-backup-now" data-id="alpha__1.0.0_1700000002.zip"', $actions );
			$this->assertSame( '', $this->call( $table, 'handle_row_actions', $row, 'size', 'name' ) );
			$this->assertSame( '2023-11-14 22:13', $this->call( $table, 'column_created', $row ) );
			$this->assertSame( '2 B', $this->call( $table, 'column_size', $row ) );
			$this->assertSame( '1.0.0', $this->call( $table, 'column_version', $row ) );
			$this->assertStringContainsString( 'value="alpha__1.0.0_1700000002.zip"', $this->call( $table, 'column_cb', $row ) );
			$this->assertSame( array( 'delete' ), array_keys( $this->call( $table, 'get_bulk_actions' ) ) );
		}

		public function test_empty_backups_table_explains_when_backups_are_made(): void {
			$table = new Backups_List_Table();
			$table->prepare_items();
			$this->assertSame( array(), $table->items );
			ob_start();
			$table->no_items();
			$this->assertStringContainsString( 'A backup is created before each plugin update', (string) ob_get_clean() );
		}

		// ------------------------------------------------------ Shared behaviour

		/**
		 * Every table builds, styles and labels itself consistently.
		 */
		public function test_every_table_has_columns_classes_and_an_empty_message(): void {
			$this->api->repos = array();
			$tables           = array(
				new Plugins_List_Table( $this->api ),
				new MU_Installed_List_Table( array( 'installed' => array(), 'available' => array(), 'file_mods' => true ) ),
				new MU_Available_List_Table( array( 'installed' => array(), 'available' => array(), 'file_mods' => true ) ),
				new Activity_List_Table( $this->api ),
				new Backups_List_Table(),
			);

			foreach ( $tables as $table ) {
				$name = get_class( $table );
				$table->prepare_items();

				$columns = $table->get_columns();
				$this->assertSame( 'cb', array_key_first( $columns ), $name . ': the checkbox column comes first.' );
				$this->assertContains( $this->call( $table, 'get_default_primary_column_name' ), array_keys( $columns ), $name . ': the primary column exists.' );
				foreach ( array_keys( $this->call( $table, 'get_sortable_columns' ) ) as $sortable ) {
					$this->assertArrayHasKey( $sortable, $columns, $name . ': sortable column ' . $sortable . ' is displayed.' );
				}
				$this->assertContains( 'plugin-hub-table', $this->call( $table, 'get_table_classes' ), $name );
				$this->assertIsString( $this->call( $table, 'column_default', array(), 'unknown' ), $name );

				ob_start();
				$table->no_items();
				$this->assertNotSame( '', (string) ob_get_clean(), $name . ': explains the empty state.' );
			}
		}

		public function test_tables_render_completely_through_display(): void {
			$this->api->log = $this->log_entries();
			$this->make_backup( 'alpha', '1.0.0', 'aa' );
			$data = $this->mu_data();

			$tables = array(
				new Plugins_List_Table( $this->api ),
				new MU_Installed_List_Table( $data ),
				new MU_Available_List_Table( $data ),
				new Activity_List_Table( $this->api ),
				new Backups_List_Table(),
			);
			foreach ( $tables as $table ) {
				$table->prepare_items();
				ob_start();
				$table->display();
				$html = (string) ob_get_clean();

				$this->assertStringContainsString( '<table', $html, get_class( $table ) );
				$this->assertStringContainsString( 'type="checkbox"', $html, get_class( $table ) . ' has row checkboxes.' );
				$this->assertSame( substr_count( $html, '<tr' ), substr_count( $html, '</tr>' ), get_class( $table ) . ': balanced rows.' );
			}
		}

		// ------------------------------------------------------ Dashboard widget

		private function widget_html(): string {
			$admin = new Admin( $this->api, new class( $this->api ) extends MU_Plugins {} );
			ob_start();
			$admin->render_dashboard_widget();
			return (string) ob_get_clean();
		}

		public function test_dashboard_widget_summarises_updates_and_activity(): void {
			$this->api->log                                     = $this->log_entries();
			$GLOBALS['hub_test_options']['plugin_hub_mu_update_count'] = 2;

			$html = $this->widget_html();
			$this->assertStringContainsString( '2 Open WP Club plugins installed.', $html );
			$this->assertStringContainsString( '<li>Alpha: 1.0.0 → 1.5.0</li>', $html );
			$this->assertStringContainsString( '2 MU plugin updates', $html );
			$this->assertStringContainsString( 'Recent activity', $html );
			$this->assertStringContainsString( 'Auto-Rollback: beta 2.0.0', $html );
			$this->assertStringContainsString( 'filter=update', $html );
			$this->assertStringNotContainsString( 'Everything is up to date.', $html );
		}

		public function test_dashboard_widget_when_everything_is_current(): void {
			$this->api->installed = array( 'beta' => '2.0.0' );
			$html                 = $this->widget_html();

			$this->assertStringContainsString( '1 Open WP Club plugin installed.', $html );
			$this->assertStringContainsString( 'Everything is up to date.', $html );
			$this->assertStringContainsString( 'filter=all', $html );
			$this->assertStringNotContainsString( 'Recent activity', $html );
		}

		public function test_dashboard_widget_hides_pinned_updates(): void {
			$this->api->pins['alpha'] = '1.0.0';
			$this->assertStringNotContainsString( 'Alpha:', $this->widget_html() );
		}
	}
}
