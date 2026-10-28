<?php
/**
 * Unit tests for PluginHub\Notifier.
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
	if ( ! function_exists( __NAMESPACE__ . '\\get_bloginfo' ) ) {
		function get_bloginfo( $show = '' ) {
			return 'Test Site';
		}
	}
}

namespace PluginHub\Tests {

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\Notifier;

	require_once dirname( __DIR__ ) . '/includes/class-notifier.php';

	final class NotifierTest extends TestCase {

		protected function setUp(): void {
			$GLOBALS['hub_test_options'] = array();
		}

		#[DataProvider( 'notify_provider' )]
		public function test_should_notify( string $mode, array $u, array $a, array $f, bool $expected ): void {
			$this->assertSame( $expected, Notifier::should_notify( $mode, $u, $a, $f ) );
		}

		public static function notify_provider(): array {
			return array(
				'all, nothing'       => array( 'all', array(), array(), array(), false ),
				'all, updated'       => array( 'all', array( 'x' ), array(), array(), true ),
				'all, available'     => array( 'all', array(), array( 'x' ), array(), true ),
				'failures, updated'  => array( 'failures', array( 'x' ), array( 'y' ), array(), false ),
				'failures, failed'   => array( 'failures', array(), array(), array( 'z' ), true ),
			);
		}

		public function test_detect_format(): void {
			$this->assertSame( 'slack', Notifier::detect_format( 'https://hooks.slack.com/services/T/B/x' ) );
			$this->assertSame( 'discord', Notifier::detect_format( 'https://discord.com/api/webhooks/1/abc' ) );
			$this->assertSame( 'discord', Notifier::detect_format( 'https://discordapp.com/api/webhooks/1/abc' ) );
			$this->assertSame( 'generic', Notifier::detect_format( 'https://discord.com/other' ) );
			$this->assertSame( 'generic', Notifier::detect_format( 'https://hooks.slack.com.evil.test/x' ) );
		}

		public function test_build_payload(): void {
			$this->assertSame( array( 'text' => "*S*\nB" ), Notifier::build_payload( 'slack', 'S', 'B' ) );
			$this->assertSame( array( 'content' => "**S**\nB" ), Notifier::build_payload( 'discord', 'S', 'B' ) );
			$this->assertSame( 1900, strlen( Notifier::build_payload( 'discord', 'S', str_repeat( 'x', 5000 ) )['content'] ) );

			$generic = Notifier::build_payload( 'generic', 'S', 'B' );
			$this->assertSame( 'S', $generic['subject'] );
			$this->assertSame( 'B', $generic['text'] );
			$this->assertSame( 'Test Site', $generic['site'] );
			$this->assertSame( 'https://example.test/', $generic['url'] );
		}

		public function test_recipients_fall_back_to_admin_email(): void {
			$GLOBALS['hub_test_options']['admin_email'] = 'admin@example.com';
			$this->assertSame( array( 'admin@example.com' ), Notifier::recipients() );

			$GLOBALS['hub_test_options']['plugin_hub_settings'] = array( 'notify_emails' => 'a@example.com, nope, b@example.com' );
			$this->assertSame( array( 'a@example.com', 'b@example.com' ), Notifier::recipients() );
		}
	}
}
