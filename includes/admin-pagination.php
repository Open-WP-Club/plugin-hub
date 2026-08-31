<?php
/**
 * Pagination controls for the plugin list table.
 *
 * Expects $filter, $search, $paged, $total_pages and $total_items to be set
 * by the including template.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.4.2
 */

// Check if this file is being accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pagination_base = admin_url( 'plugins.php?page=plugin-hub&filter=' . $filter );
if ( '' !== $search ) {
	$pagination_base = add_query_arg( 's', rawurlencode( $search ), $pagination_base );
}
$prev_page_url = add_query_arg( 'paged', max( 1, $paged - 1 ), $pagination_base );
$next_page_url = add_query_arg( 'paged', min( $total_pages, $paged + 1 ), $pagination_base );
?>
<div class="tablenav-pages">
	<span class="displaying-num">
		<?php
		printf(
			/* translators: %s: Number of plugins. */
			esc_html( _n( '%s plugin', '%s plugins', $total_items, 'plugin-hub' ) ),
			esc_html( number_format_i18n( $total_items ) )
		);
		?>
	</span>
	<span class="pagination-links">
		<?php if ( $paged > 1 ) : ?>
			<a class="prev-page button" href="<?php echo esc_url( $prev_page_url ); ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'plugin-hub' ); ?></span>
				<span aria-hidden="true">&lsaquo;</span>
			</a>
		<?php else : ?>
			<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
		<?php endif; ?>
		<span class="paging-input">
			<span class="tablenav-paging-text">
				<?php
				printf(
					/* translators: 1: Current page number, 2: Total number of pages. */
					esc_html__( '%1$s of %2$s', 'plugin-hub' ),
					esc_html( number_format_i18n( $paged ) ),
					'<span class="total-pages">' . esc_html( number_format_i18n( $total_pages ) ) . '</span>'
				);
				?>
			</span>
		</span>
		<?php if ( $paged < $total_pages ) : ?>
			<a class="next-page button" href="<?php echo esc_url( $next_page_url ); ?>">
				<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'plugin-hub' ); ?></span>
				<span aria-hidden="true">&rsaquo;</span>
			</a>
		<?php else : ?>
			<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
		<?php endif; ?>
	</span>
</div>
