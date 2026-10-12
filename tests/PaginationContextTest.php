<?php
/** @package PluginHub */
namespace PluginHub\Tests;

use PHPUnit\Framework\TestCase;

final class PaginationContextTest extends TestCase {
	private function render( array $context ): string {
		extract( $context, EXTR_SKIP );
		ob_start();
		require dirname( __DIR__ ) . '/includes/admin-pagination.php';
		return ob_get_clean();
	}

	public function test_missing_include_context_produces_no_markup_or_undefined_variable_warnings(): void {
		$this->assertSame( '', $this->render( array() ) );
		$this->assertSame( '', $this->render( array( 'filter' => 'all' ) ) );
	}

	public function test_malformed_pagination_context_is_not_rendered(): void {
		$context = array( 'filter' => 'all', 'search' => '', 'paged' => 1, 'total_pages' => 2, 'total_items' => 30 );
		foreach ( array( array( 'paged', 0 ), array( 'paged', 3 ), array( 'total_pages', 0 ), array( 'total_items', -1 ), array( 'search', array() ) ) as $invalid ) {
			$this->assertSame( '', $this->render( array_replace( $context, array( $invalid[0] => $invalid[1] ) ) ) );
		}
	}
}
