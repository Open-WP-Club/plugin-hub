<?php
/**
 * Renders the Plugin Hub admin page for every tab.
 *
 * This runs includes/admin-display.php for real, so a PHP error in the template
 * (a missing namespace import, an undefined variable, a typo in a helper call)
 * fails here instead of on a customer's dashboard.
 *
 * @package PluginHub
 */

namespace {
	// sponsorship-display.php has no namespace, so it calls the global functions.
	if ( ! function_exists( 'esc_html_e' ) ) {
		function esc_html_e( $text, $domain = 'default' ) {
			echo htmlspecialchars( $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		function esc_html__( $text, $domain = 'default' ) {
			return htmlspecialchars( $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ) {
			return htmlspecialchars( (string) $url, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'wp_nonce_field' ) ) {
		function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {
			echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	if ( ! class_exists( 'Hub_Test_Locale' ) ) {
		class Hub_Test_Locale {
			public function get_weekday( $n ) {
				return array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' )[ $n ];
			}
			public function get_weekday_abbrev( $day ) {
				return substr( $day, 0, 3 );
			}
		}
	}
}

namespace PluginHub {
	require_once __DIR__ . '/wp-api-stubs.php';
	require_once __DIR__ . '/wp-ajax-stubs.php';
	require_once __DIR__ . '/fs-stubs.php';
	require_once __DIR__ . '/wp-list-table-stub.php';
	require_once dirname( __DIR__ ) . '/includes/class-settings.php';
	require_once dirname( __DIR__ ) . '/includes/class-policy.php';
	require_once dirname( __DIR__ ) . '/includes/class-backups.php';
	require_once dirname( __DIR__ ) . '/includes/class-mu-plugins.php';
	require_once dirname( __DIR__ ) . '/includes/sponsorship.php';
	require_once dirname( __DIR__ ) . '/includes/admin.php';

	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/plugin-hub-plugins-' . getmypid() );
	}
	if ( ! defined( 'PLUGIN_HUB_PLUGIN_DIR' ) ) {
		define( 'PLUGIN_HUB_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
	}
	if ( ! defined( 'PLUGIN_HUB_PLUGIN_URL' ) ) {
		define( 'PLUGIN_HUB_PLUGIN_URL', 'https://example.test/wp-content/plugins/plugin-hub/' );
	}

	if ( ! function_exists( __NAMESPACE__ . '\\wp_nonce_url' ) ) {
		function wp_nonce_url( $url, $action = -1 ) {
			return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=nonce-' . $action;
		}
		function wp_nonce_field( $action = -1, $name = '_wpnonce' ) {
			echo '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		function add_plugins_page( $page_title, $menu_title, $capability, $slug, $callback ) {
			$GLOBALS['hub_test_menu'][] = array( 'plugins.php', $page_title, $menu_title, $capability, $slug, $callback );
			return 'plugins_page_' . $slug;
		}
		function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback ) {
			$GLOBALS['hub_test_menu'][] = array( $parent, $page_title, $menu_title, $capability, $slug, $callback );
			return 'plugins_page_' . $slug . '-network';
		}
		function absint( $value ) {
			return abs( (int) $value );
		}
		function get_admin_page_title() {
			return 'Plugin Hub';
		}
		function settings_errors( $setting = '' ) {
			foreach ( $GLOBALS['hub_test_settings_errors'] ?? array() as $error ) {
				echo '<div class="notice notice-' . $error[3] . '"><p>' . $error[2] . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		function add_settings_error( $setting, $code, $message, $type = 'error' ) {
			$GLOBALS['hub_test_settings_errors'][] = array( $setting, $code, $message, $type );
		}
		function esc_attr_e( $text, $domain = 'default' ) {
			echo htmlspecialchars( $text, ENT_QUOTES ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		function get_current_user_id() {
			return 7;
		}
		function get_user_meta( $user, $key, $single = false ) {
			return $GLOBALS['hub_test_user_meta'][ $key ] ?? '';
		}
		function update_user_meta( $user, $key, $value ) {
			$GLOBALS['hub_test_user_meta'][ $key ] = $value;
			return true;
		}
	}
}

namespace PluginHub\Tests {
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\Admin;
	use PluginHub\API;
	use PluginHub\MU_Plugins;

	/**
	 * API double with one installed plugin and a short activity log.
	 */
	class Render_Test_API extends API {
		public function __construct() {}

		public function get_org_repos() {
			return array(
				array( 'name' => 'alpha', 'display_name' => 'Alpha', 'version' => '1.5.0', 'description' => 'Alpha tool', 'repo_url' => 'https://github.com/Open-WP-Club/alpha', 'available' => true ),
				array( 'name' => 'gamma', 'display_name' => 'Gamma', 'version' => '3.0.0', 'description' => 'Gamma tool', 'repo_url' => 'https://github.com/Open-WP-Club/gamma', 'available' => true ),
			);
		}
		public function is_plugin_installed( $name ) {
			return 'alpha' === $name;
		}
		public function get_plugin_file( $name ) {
			return 'alpha' === $name ? 'alpha/alpha.php' : false;
		}
		public function get_installed_plugin_version( $name ) {
			return 'alpha' === $name ? '1.0.0' : 'Not Installed';
		}
		public function is_plugin_active( $name ) {
			return 'alpha' === $name;
		}
		public function get_dependents( $name ) {
			return array();
		}
		public function get_missing_requirements( $name ) {
			return array();
		}
		public function get_activity_log( $filters = array() ) {
			return array( array( 'id' => 'e1', 'time' => 1700000000, 'action' => 'update', 'plugin' => 'alpha', 'version' => '1.5.0', 'user' => 'admin' ) );
		}
	}

	/**
	 * MU manager double with view data.
	 */
	class Render_Test_MU extends MU_Plugins {
		public array $view = array();

		public function get_view_data() {
			return $this->view;
		}
		public function refresh_catalog() {
			return array();
		}
		public function get_catalog( $force = false ) {
			return array();
		}
	}

	final class AdminRenderTest extends TestCase {

		private Admin $admin;
		private Render_Test_MU $mu;
		private string $uploads;

		protected function setUp(): void {
			$this->uploads                    = sys_get_temp_dir() . '/plugin-hub-render-' . getmypid();
			$GLOBALS['hub_test_uploads']      = $this->uploads;
			$GLOBALS['hub_test_options']      = array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i', 'admin_email' => 'admin@example.test' );
			$GLOBALS['hub_test_user_meta']    = array();
			$GLOBALS['hub_test_settings_errors'] = array();
			$GLOBALS['wp_locale']             = new \Hub_Test_Locale();
			$_GET                             = array();
			$_POST                            = array();
			$api                              = new Render_Test_API();
			$this->mu                         = new Render_Test_MU( $api );
			$this->mu->view                   = array(
				'dir'          => '/var/www/wp-content/mu-plugins',
				'dir_state'    => 'ok',
				'catalog_ok'   => true,
				'file_mods'    => true,
				'multisite'    => false,
				'update_count' => 1,
				'installed'    => array(
					array( 'file' => 'one.php', 'name' => 'One', 'category' => 'admin', 'local_version' => '1.0.0', 'remote_version' => '1.1.0', 'update' => true, 'autoupdate' => false, 'has_backup' => false ),
				),
				'available'    => array(
					array( 'file' => 'two.php', 'name' => 'Two', 'category' => 'seo', 'version' => '2.0.0', 'description' => 'SEO', 'wp' => '', 'php' => '' ),
				),
			);
			$this->admin                      = new Admin( $api, $this->mu );
		}

		protected function tearDown(): void {
			\hub_test_rmtree( $this->uploads );
			foreach ( array( 'hub_test_uploads', 'hub_test_options', 'hub_test_user_meta', 'hub_test_settings_errors', 'wp_locale', 'hub_test_caps', 'hub_test_network_admin', 'hub_test_token_managed', 'hub_test_transients', 'hub_test_menu', 'hub_test_site_transients' ) as $key ) {
				unset( $GLOBALS[ $key ] );
			}
			$_GET  = array();
			$_POST = array();
		}

		private function render(): string {
			ob_start();
			try {
				$this->admin->display_admin_page();
			} finally {
				$html = (string) ob_get_clean();
			}
			return $html;
		}

		public static function tabs(): array {
			return array(
				'plugins'   => array( 'all', 'plugins' ),
				'updates'   => array( 'update', 'plugins' ),
				'mu'        => array( 'mu', 'mu' ),
				'backups'   => array( 'backups', 'backups' ),
				'activity'  => array( 'activity', 'activity' ),
				'settings'  => array( 'settings', 'settings' ),
				'bad value' => array( 'nonsense', 'plugins' ),
			);
		}

		#[DataProvider( 'tabs' )]
		public function test_every_tab_renders_without_errors( string $filter, string $active_tab ): void {
			$_GET['filter'] = $filter;
			$html           = $this->render();

			$this->assertStringContainsString( '<div class="wrap plugin-hub-wrap">', $html );
			$this->assertStringContainsString( 'Install, update and manage plugins published by Open WP Club.', $html );
			$this->assertSame( 1, preg_match_all( '/class="nav-tab nav-tab-active"/', $html ), 'Exactly one tab is active.' );
			$this->assertMatchesRegularExpression( '/filter=' . ( 'plugins' === $active_tab ? 'all' : $active_tab ) . '"[^>]*nav-tab-active/', $html );
			$this->assertStringContainsString( 'id="github-token"', $html, 'The sidebar is always present.' );
			$this->assertStringContainsString( 'plugin-hub-modal-close', $html );
			$this->assertStringNotContainsString( 'Warning:', $html );
			$this->assertStringNotContainsString( 'Fatal error', $html );
		}

		public function test_plugins_tab_lists_the_catalog(): void {
			$html = $this->render();
			$this->assertStringContainsString( 'data-repo="alpha"', $html );
			$this->assertStringContainsString( 'data-repo="gamma"', $html );
			$this->assertStringContainsString( 'subsubsub', $html );
			$this->assertStringContainsString( 'plugin-search-input', $html );
		}

		public function test_settings_tab_shows_the_current_settings(): void {
			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'notify_emails' => 'ops@example.test', 'update_delay_days' => 4, 'notify_mode' => 'failures', 'window_start' => 3 );
			$_GET['filter']                                     = 'settings';
			$html                                               = $this->render();

			$this->assertStringContainsString( 'value="ops@example.test"', $html );
			$this->assertStringContainsString( 'name="settings[update_delay_days]" value="4"', $html );
			$this->assertMatchesRegularExpression( '/<option value="failures"\s+selected=.selected.>/', $html );
			$this->assertMatchesRegularExpression( '/<option value="3"\s+selected=.selected.>03:00/', $html );
			$this->assertStringContainsString( 'plugin-hub-settings-form', $html );
			$this->assertSame( 7, substr_count( $html, 'name="settings[window_days][]"' ) );
			$this->assertStringContainsString( 'Mon', $html );
		}

		public function test_activity_tab_has_filters_export_and_the_log(): void {
			$_GET = array( 'filter' => 'activity', 'log_action' => 'update' );
			$html = $this->render();

			$this->assertStringContainsString( 'admin-post.php', $html );
			$this->assertStringContainsString( 'plugin_hub_export_activity', $html );
			$this->assertStringContainsString( 'log_action=update', $html );
			$this->assertStringContainsString( 'data-id="e1"', $html );
			$this->assertStringContainsString( 'Search activity', $html );
		}

		public function test_backups_tab_shows_usage_and_the_empty_state(): void {
			$_GET['filter'] = 'backups';
			$html           = $this->render();
			$this->assertStringContainsString( 'Search backups', $html );
			$this->assertStringContainsString( 'No backups yet.', $html );
		}

		public function test_mu_tab_shows_installed_available_and_warnings(): void {
			$_GET['filter'] = 'mu';
			$html           = $this->render();
			$this->assertStringContainsString( 'data-file="one.php"', $html );
			$this->assertStringContainsString( 'mu-update-now', $html );
			$this->assertSame( 1, $GLOBALS['hub_test_options']['plugin_hub_mu_update_count'], 'The pending MU update count is remembered for the menu badge.' );

			$_GET['mu_view'] = 'available';
			$html            = $this->render();
			$this->assertStringContainsString( 'data-file="two.php"', $html );
			$this->assertStringContainsString( 'mu-install-now', $html );
		}

		public function test_mu_tab_explains_why_changes_are_not_possible(): void {
			$_GET['filter']                 = 'mu';
			$this->mu->view['file_mods']    = false;
			$this->mu->view['catalog_ok']   = false;
			$this->mu->view['multisite']    = true;
			$html                           = $this->render();
			$this->assertStringNotContainsString( 'mu-update-now', $html );
			$this->assertStringNotContainsString( 'plugin-hub-mu-update-all', $html );
			$this->assertStringContainsString( 'notice', $html );

			$this->mu->view['file_mods'] = true;
			$this->mu->view['dir_state'] = 'missing';
			$this->assertStringContainsString( 'notice', $this->render() );
			$this->mu->view['dir_state'] = 'unwritable';
			$this->assertStringContainsString( 'notice', $this->render() );
		}

		public function test_github_token_panel_states(): void {
			$html = $this->render();
			$this->assertStringContainsString( 'Public API', $html );
			$this->assertStringNotContainsString( 'disabled', $html );

			$GLOBALS['hub_test_token_managed'] = true;
			$this->assertStringContainsString( 'Configured in wp-config.php', $this->render() );
		}

		public function test_rate_limit_is_shown_when_known(): void {
			$GLOBALS['hub_test_transients']['plugin_hub_rate_limit'] = array( 'remaining' => 4, 'limit' => 60, 'reset' => 1700000000 );
			$this->assertStringContainsString( 'is-low', $this->render() );
		}

		public function test_menu_entry_shows_the_pending_update_badge(): void {
			$GLOBALS['hub_test_options']['plugin_hub_mu_update_count'] = 3;
			$GLOBALS['hub_test_site_transients']['update_plugins']     = (object) array(
				'response' => array( 'a/a.php' => (object) array( 'id' => 'https://github.com/Open-WP-Club/a' ) ),
			);
			$api = new Render_Test_API();
			( new Admin( new class() extends Render_Test_API {
				public function count_pending_updates() {
					return 4;
				}
			}, $this->mu ) )->add_admin_menu();

			$entry = $GLOBALS['hub_test_menu'][0];
			$this->assertSame( array( 'plugins.php', 'Plugin Hub', 'manage_options', 'plugin-hub' ), array( $entry[0], $entry[1], $entry[3], $entry[4] ) );
			$this->assertStringContainsString( '<span class="update-plugins count-4"><span class="plugin-count">4</span></span>', $entry[2] );
			$this->assertSame( 'display_admin_page', $entry[5][1] );
			unset( $api );
		}

		public function test_menu_entry_has_no_badge_without_updates(): void {
			$this->admin->add_admin_menu();
			$this->assertSame( 'Plugin Hub', $GLOBALS['hub_test_menu'][0][2] );
		}

		public function test_network_admin_menu_requires_the_network_capability(): void {
			$this->admin->add_network_admin_menu();
			$entry = $GLOBALS['hub_test_menu'][0];
			$this->assertSame( array( 'plugins.php', 'manage_network_plugins', 'plugin-hub' ), array( $entry[0], $entry[3], $entry[4] ) );
		}

		public function test_cache_refresh_notice(): void {
			$_GET['cache_refreshed'] = '1';
			$this->assertStringContainsString( 'Plugin list refreshed successfully.', $this->render() );
		}

		public function test_nothing_is_rendered_without_permission(): void {
			$GLOBALS['hub_test_caps'] = array( 'read' );
			$this->assertSame( '', $this->render() );

			$GLOBALS['hub_test_caps']          = array( 'manage_options' );
			$GLOBALS['hub_test_network_admin'] = true;
			$this->assertSame( '', $this->render(), 'The network screen needs the network capability.' );
		}

		public function test_supporter_thanks_can_be_hidden_and_shown(): void {
			$this->assertStringContainsString( 'Hide supporter thanks', $this->render() );
			$this->assertStringContainsString( 'Open-source supporters', $this->render() );

			$_POST['plugin_hub_supporters_visibility'] = 'hide';
			$html                                       = $this->render();
			$this->assertSame( '1', $GLOBALS['hub_test_user_meta']['plugin_hub_hide_supporters'] );
			$this->assertStringContainsString( 'Show supporter thanks', $html );
			$this->assertStringNotContainsString( 'Open-source supporters', $html );

			$_POST['plugin_hub_supporters_visibility'] = 'show';
			$this->assertStringContainsString( 'Hide supporter thanks', $this->render() );
		}
	}
}
