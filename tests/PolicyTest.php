<?php
/**
 * Unit tests for PluginHub\Policy.
 *
 * @package PluginHub
 */

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\wp_json_encode' ) ) {
		function wp_json_encode( $data ) {
			return json_encode( $data );
		}
	}
}

namespace PluginHub\Tests {

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use PluginHub\Policy;

	require_once dirname( __DIR__ ) . '/includes/class-policy.php';

	final class PolicyTest extends TestCase {

		#[DataProvider( 'pin_provider' )]
		public function test_pin_allows( string $pin, string $version, bool $expected ): void {
			$this->assertSame( $expected, Policy::pin_allows( $pin, $version ) );
		}

		public static function pin_provider(): array {
			return array(
				'no pin'               => array( '', '9.0.0', true ),
				'exact match'          => array( '1.2.3', '1.2.3', true ),
				'exact mismatch'       => array( '1.2.3', '1.2.4', false ),
				'major same'           => array( '1.x', '1.9.2', true ),
				'major different'      => array( '1.x', '2.0.0', false ),
				'minor same'           => array( '1.2.x', '1.2.9', true ),
				'minor different'      => array( '1.2.x', '1.3.0', false ),
				'10 is not 1'          => array( '1.x', '10.0.0', false ),
				'invalid pin blocks'   => array( 'latest', '1.0.0', false ),
			);
		}

		public function test_is_valid_pin(): void {
			foreach ( array( '', '1.2.3', '1.x', '1.2.x', '1.2.3-beta.1' ) as $pin ) {
				$this->assertTrue( Policy::is_valid_pin( $pin ), $pin );
			}
			foreach ( array( 'x', '1.x.x', '^1.0', '1.2.3; rm', 'v1.2.3', '1.x ' ) as $pin ) {
				$this->assertFalse( Policy::is_valid_pin( $pin ), $pin );
			}
		}

		#[DataProvider( 'window_provider' )]
		public function test_window_open( array $days, int $start, int $end, int $weekday, int $hour, bool $expected ): void {
			$this->assertSame( $expected, Policy::window_open( $days, $start, $end, $weekday, $hour ) );
		}

		public static function window_provider(): array {
			$all = array( 0, 1, 2, 3, 4, 5, 6 );
			return array(
				'inside'             => array( $all, 1, 5, 3, 2, true ),
				'start inclusive'    => array( $all, 1, 5, 3, 1, true ),
				'end exclusive'      => array( $all, 1, 5, 3, 5, false ),
				'outside'            => array( $all, 1, 5, 3, 12, false ),
				'wrap evening'       => array( $all, 22, 4, 3, 23, true ),
				'wrap morning'       => array( $all, 22, 4, 3, 3, true ),
				'wrap closed'        => array( $all, 22, 4, 3, 12, false ),
				'equal is all day'   => array( $all, 0, 0, 3, 13, true ),
				'weekday not listed' => array( array( 1, 2 ), 0, 0, 3, 13, false ),
				'no weekdays'        => array( array(), 0, 0, 3, 13, false ),
			);
		}

		public function test_is_healthy_response(): void {
			$this->assertTrue( Policy::is_healthy_response( 200, '<html>ok</html>' ) );
			$this->assertTrue( Policy::is_healthy_response( 302, '' ) );
			$this->assertFalse( Policy::is_healthy_response( 0, '' ) );
			$this->assertFalse( Policy::is_healthy_response( 500, 'x' ) );
			$this->assertFalse( Policy::is_healthy_response( 200, 'There has been a critical error on this website.' ) );
			$this->assertFalse( Policy::is_healthy_response( 200, 'PHP Fatal error: x' ) );
		}

		public function test_release_old_enough(): void {
			$now = 1000000;
			$this->assertTrue( Policy::release_old_enough( 0, 0, $now ) );
			$this->assertFalse( Policy::release_old_enough( 0, 3, $now ) );
			$this->assertFalse( Policy::release_old_enough( $now - 2 * 86400, 3, $now ) );
			$this->assertTrue( Policy::release_old_enough( $now - 3 * 86400, 3, $now ) );
		}

		public function test_dependents_and_missing_requirements(): void {
			$plugins = array(
				'base/base.php'   => array( 'RequiresPlugins' => '' ),
				'addon/addon.php' => array( 'RequiresPlugins' => 'base, other' ),
				'solo/solo.php'   => array( 'RequiresPlugins' => 'other' ),
				'odd/odd.php'     => array(),
			);

			$this->assertSame( array( 'addon/addon.php' ), Policy::dependents_of( 'base', $plugins ) );
			$this->assertSame( array(), Policy::dependents_of( 'nobody', $plugins ) );
			$this->assertSame( array( 'other' ), Policy::missing_requirements( $plugins['addon/addon.php'], array( 'base', 'addon' ) ) );
			$this->assertSame( array(), Policy::missing_requirements( $plugins['odd/odd.php'], array() ) );
		}

		public function test_activity_ids_are_unique_and_stable(): void {
			$entry = array( 'time' => 5, 'user' => 'a', 'action' => 'update', 'plugin' => 'x', 'version' => '1' );
			$log   = array( $entry, $entry, array_merge( $entry, array( 'plugin' => 'y' ) ) );

			$first  = Policy::activity_ids( $log );
			$second = Policy::activity_ids( $log );

			$this->assertCount( 3, array_unique( array_column( $first, 'id' ) ) );
			$this->assertSame( $first, $second );
			$this->assertSame( 'x', $first[0]['plugin'] );
		}

		public function test_filter_activity(): void {
			$entries = array(
				array( 'time' => strtotime( '2026-01-10 12:00:00 UTC' ), 'user' => 'ann', 'action' => 'update', 'plugin' => 'alpha', 'version' => '1.0.0' ),
				array( 'time' => strtotime( '2026-01-20 12:00:00 UTC' ), 'user' => 'bob', 'action' => 'delete', 'plugin' => 'beta', 'version' => '2.0.0' ),
			);

			$this->assertCount( 1, Policy::filter_activity( $entries, array( 'action' => 'delete' ) ) );
			$this->assertCount( 1, Policy::filter_activity( $entries, array( 'user' => 'ann' ) ) );
			$this->assertCount( 1, Policy::filter_activity( $entries, array( 'search' => 'BET' ) ) );
			$this->assertCount( 1, Policy::filter_activity( $entries, array( 'from' => '2026-01-15' ) ) );
			$this->assertCount( 1, Policy::filter_activity( $entries, array( 'to' => '2026-01-10' ) ) );
			$this->assertCount( 2, Policy::filter_activity( $entries, array() ) );
			$this->assertCount( 0, Policy::filter_activity( $entries, array( 'action' => 'delete', 'user' => 'ann' ) ) );
		}
	}
}
