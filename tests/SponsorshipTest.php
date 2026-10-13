<?php
/** @package PluginHub */
namespace PluginHub\Tests;

use PHPUnit\Framework\TestCase;
use PluginHub\Sponsorship;

final class SponsorshipTest extends TestCase {
	private function entry( $id = 'test' ) {
		return array(
			'tier' => 'partner', 'id' => $id, 'name' => 'Test', 'url' => 'https://example.com/',
			'description' => 'Open-source supporter', 'active' => true, 'priority' => 10,
			'logoData' => 'data:image/png;base64,' . base64_encode( "\x89PNG\r\n\x1a\n" ),
			'startsAt' => '2026-10-01T00:00:00Z', 'endsAt' => null,
		);
	}
	private function feed( $entries ) {
		return array( 'schemaVersion' => 1, 'maxPositions' => 3, 'supporters' => $entries );
	}
	public function test_successful_empty_feed_clears_supporters() {
		$this->assertSame( array(), Sponsorship::validate( $this->feed( array() ) ) );
		$this->assertNull( Sponsorship::validate( array( 'schemaVersion' => 2 ) ) );
	}
	public function test_expired_and_future_supporters_are_removed_locally() {
		$expired = $this->entry( 'expired' ); $expired['endsAt'] = '2026-10-10T00:00:00Z';
		$future = $this->entry( 'future' ); $future['startsAt'] = '2026-11-01T00:00:00Z';
		$valid = Sponsorship::validate( $this->feed( array( $expired, $future, $this->entry( 'current' ) ) ) );
		$this->assertSame( 'current', Sponsorship::active( $valid, strtotime( '2026-10-15T00:00:00Z' ) )[0]['id'] );
		$this->assertCount( 1, Sponsorship::active( $valid, strtotime( '2026-10-15T00:00:00Z' ) ) );
	}
	public function test_no_external_image_or_unsafe_link_can_enter_ui() {
		foreach ( array( array( 'url', 'javascript:alert(1)' ), array( 'url', 'https://user:secret@example.com/' ), array( 'name', '<script>' ), array( 'logoData', 'https://tracker.example/pixel.png' ), array( 'logoData', 'data:image/svg+xml;base64,PHN2Zz4=' ), array( 'startsAt', '2026-02-31T00:00:00Z' ) ) as $change ) {
			$entry = $this->entry(); $entry[ $change[0] ] = $change[1];
			$this->assertNull( Sponsorship::validate( $this->feed( array( $entry ) ) ) );
		}
	}
	public function test_caps_positions_and_never_shows_campaigns() {
		$feed = $this->feed( array_map( fn( $i ) => $this->entry( 'test-' . $i ), range( 1, 4 ) ) );
		$feed['campaigns'] = array( $this->entry( 'advertiser' ) );
		$this->assertCount( 3, Sponsorship::active( Sponsorship::validate( $feed ), strtotime( '2026-10-15T00:00:00Z' ) ) );
		$this->assertNotContains( 'advertiser', array_column( Sponsorship::validate( $feed ), 'id' ) );
	}
	public function test_supporter_and_legacy_catalogs_do_not_receive_company_placements() {
		$basic = $this->entry( 'basic' ); $basic['tier'] = 'supporter';
		$legacy = $this->entry( 'legacy' ); unset( $legacy['tier'] );
		$champion = $this->entry( 'champion' ); $champion['tier'] = 'champion'; $champion['priority'] = 0;
		$entries = Sponsorship::validate( $this->feed( array( $basic, $legacy, $this->entry( 'partner' ), $champion ) ) );
		$active = Sponsorship::active( $entries, strtotime( '2026-10-15T00:00:00Z' ) );
		$this->assertSame( array( 'champion', 'partner' ), array_column( $active, 'id' ) );
	}

}
