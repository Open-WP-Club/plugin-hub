<?php
/**
 * Keeps assets/js/script.js and the PHP that renders its markup in sync.
 *
 * The script finds elements by class and id. If PHP renames one, the button
 * silently stops working; these checks fail instead.
 *
 * @package PluginHub
 */

namespace PluginHub\Tests;

use PHPUnit\Framework\TestCase;

final class ScriptContractTest extends TestCase {

	private string $js;
	private string $php;

	protected function setUp(): void {
		$root     = dirname( __DIR__ );
		$this->js = (string) file_get_contents( $root . '/assets/js/script.js' );
		$this->php = '';
		foreach ( array( 'includes/admin-display.php', 'includes/list-tables.php', 'includes/admin.php' ) as $file ) {
			$this->php .= (string) file_get_contents( $root . '/' . $file );
		}
	}

	/**
	 * Class names the script reacts to through delegated events.
	 */
	private function delegated_classes(): array {
		preg_match_all( "/\\\$\( document \)\.on\( '(?:click|change)', '([^']+)'/", $this->js, $matches );
		$classes = array();
		foreach ( $matches[1] as $selector ) {
			preg_match_all( '/\.([a-z][a-z0-9-]*)/', preg_replace( '/\[[^\]]*\]|:[a-z-]+\([^)]*\)/', '', $selector ), $found );
			$classes = array_merge( $classes, $found[1] );
		}
		return array_values( array_unique( $classes ) );
	}

	public function test_every_class_the_script_listens_to_is_rendered_by_php(): void {
		$classes = $this->delegated_classes();
		$this->assertContains( 'install-now', $classes );
		$this->assertContains( 'pin-toggle', $classes );

		$missing = array();
		foreach ( $classes as $class ) {
			if ( ! preg_match( '/class="[^"]*\b' . preg_quote( $class, '/' ) . '\b/', $this->php ) ) {
				$missing[] = $class;
			}
		}
		// Built by the script itself.
		$missing = array_diff( $missing, array( 'rollback-version-item' ) );

		$this->assertSame( array(), array_values( $missing ), 'Classes script.js listens to that no PHP template renders.' );
	}

	public function test_every_id_the_script_binds_to_is_rendered_by_php(): void {
		preg_match_all( "/\\\$\( '([^']*#[^']+)' \)/", $this->js, $matches );
		$ids = array();
		foreach ( $matches[1] as $selector ) {
			preg_match_all( '/#([a-z][a-z0-9-]*)/', $selector, $found );
			$ids = array_merge( $ids, $found[1] );
		}
		$ids = array_unique( $ids );
		$this->assertContains( 'plugin-hub-form', $ids );

		// Created by the script, or by WordPress core's bulk-action markup.
		$ids = array_diff( $ids, array( 'bulk-action-status', 'plugin-hub-messages', 'doaction', 'doaction2' ) );

		$missing = array();
		foreach ( $ids as $id ) {
			if ( false === strpos( $this->php, 'id="' . $id . '"' ) ) {
				$missing[] = $id;
			}
		}

		$this->assertSame( array(), $missing, 'Element ids script.js binds to that no PHP template renders.' );
	}

	public function test_the_bulk_form_tells_the_script_which_table_it_is(): void {
		$display = (string) file_get_contents( dirname( __DIR__ ) . '/includes/admin-display.php' );
		preg_match_all( '/data-table="([^"]*)"/', $display, $rendered );

		// Names the script branches on.
		foreach ( array( 'plugins', 'activity', 'backups' ) as $table ) {
			$this->assertStringContainsString( "'$table' === table", $this->js, "script.js handles the $table table" );
			$this->assertContains( $table, $rendered[1], "admin-display.php renders data-table=\"$table\"" );
		}

		// Everything else is an MU table, which the script treats as one group.
		$this->assertStringContainsString( "'available' === \$mu_view ? 'mu-available' : 'mu-installed'", $display );
	}
}
