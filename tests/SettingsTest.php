<?php
/**
 * Unit tests for PluginHub\Settings.
 *
 * @package PluginHub
 */

namespace PluginHub {
	require_once __DIR__ . '/stubs.php';

	if ( ! function_exists( __NAMESPACE__ . '\\is_email' ) ) {
		function is_email( $email ) {
			return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
		}
	}
}

namespace PluginHub\Tests {

	use PHPUnit\Framework\TestCase;
	use PluginHub\Settings;

	require_once dirname( __DIR__ ) . '/includes/class-settings.php';

	final class SettingsTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['hub_test_options'] = array();
		}

		public function test_defaults_when_nothing_stored(): void {
			$this->assertSame( Settings::defaults(), Settings::all() );
			$this->assertSame( 3, Settings::get( 'backup_keep' ) );
			$this->assertNull( Settings::get( 'nope' ) );
		}

		public function test_sanitize_clamps_and_drops_unknown_keys(): void {
			$out = Settings::sanitize(
				array(
					'backup_keep'       => 99,
					'backup_days'       => -5,
					'window_start'      => 30,
					'update_delay_days' => '7',
					'log_max'           => 1,
					'evil'              => 'x',
				)
			);

			$this->assertSame(
				array(
					'backup_keep'       => 20,
					'backup_days'       => 0,
					'window_start'      => 23,
					'update_delay_days' => 7,
					'log_max'           => 50,
				),
				$out
			);
		}

		public function test_sanitize_only_returns_present_keys(): void {
			$this->assertSame( array(), Settings::sanitize( array() ) );
		}

		public function test_sanitize_booleans_and_mode(): void {
			$out = Settings::sanitize( array( 'auto_rollback' => '0', 'require_checksum' => '1', 'notify_mode' => 'bogus' ) );

			$this->assertFalse( $out['auto_rollback'] );
			$this->assertTrue( $out['require_checksum'] );
			$this->assertSame( 'all', $out['notify_mode'] );
			$this->assertSame( 'failures', Settings::sanitize( array( 'notify_mode' => 'failures' ) )['notify_mode'] );
		}

		public function test_sanitize_emails_and_webhook(): void {
			$out = Settings::sanitize(
				array(
					'notify_emails' => 'a@example.com; bad, a@example.com b@example.com',
					'webhook_url'   => 'http://insecure.example.com/hook',
				)
			);
			$this->assertSame( 'a@example.com, b@example.com', $out['notify_emails'] );
			$this->assertSame( '', $out['webhook_url'] );

			$out = Settings::sanitize( array( 'webhook_url' => 'https://hooks.slack.com/services/x' ) );
			$this->assertSame( 'https://hooks.slack.com/services/x', $out['webhook_url'] );
		}

		public function test_sanitize_window_days(): void {
			$this->assertSame( array( 1, 3 ), Settings::sanitize( array( 'window_days' => array( '3', 1, 9, 'x', 3 ) ) )['window_days'] );
			$this->assertSame( array( 0, 1, 2, 3, 4, 5, 6 ), Settings::sanitize( array( 'window_days' => array() ) )['window_days'] );
		}

		public function test_save_merges_into_stored_settings(): void {
			Settings::save( array( 'backup_keep' => 5 ) );
			$saved = Settings::save( array( 'log_days' => 30 ) );

			$this->assertSame( 5, $saved['backup_keep'] );
			$this->assertSame( 30, Settings::get( 'log_days' ) );
			$this->assertSame( 5, Settings::get( 'backup_keep' ) );
		}
	}
}
