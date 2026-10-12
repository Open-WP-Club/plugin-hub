<?php
/**
 * Dismissible supporter thanks, limited to the Plugin Hub page.
 *
 * @package PluginHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( defined( 'PLUGIN_HUB_HIDE_SUPPORTERS' ) && PLUGIN_HUB_HIDE_SUPPORTERS ) {
	return;
}

$supporters      = $supporters ?? array();
$hide_supporters = $hide_supporters ?? true;
?>
<div class="postbox plugin-hub-supporters">
	<div class="inside">
		<?php if ( ! $hide_supporters ) : ?>
			<h2><?php esc_html_e( 'Open-source supporters', 'plugin-hub' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Their support helps keep our plugins free and maintained.', 'plugin-hub' ); ?></p>
			<?php foreach ( $supporters as $supporter ) : ?>
				<a class="plugin-hub-supporter" href="<?php echo esc_url( $supporter['url'] ); ?>" target="_blank" rel="sponsored noopener noreferrer">
					<img src="<?php echo esc_attr( $supporter['logoData'] ); ?>" alt="" width="48" height="48">
					<span><strong><?php echo esc_html( $supporter['name'] ); ?></strong><small><?php echo esc_html( $supporter['description'] ); ?></small></span>
				</a>
			<?php endforeach; ?>
			<p><a href="https://openwpclub.com/sponsors/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'All supporters and sponsorship options', 'plugin-hub' ); ?></a></p>
		<?php endif; ?>
		<?php if ( ! defined( 'PLUGIN_HUB_HIDE_SUPPORTERS' ) || ! PLUGIN_HUB_HIDE_SUPPORTERS ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'plugins.php?page=plugin-hub' ) ); ?>">
				<?php wp_nonce_field( 'plugin_hub_supporters_visibility' ); ?>
				<button type="submit" class="button button-link" name="plugin_hub_supporters_visibility" value="<?php echo $hide_supporters ? 'show' : 'hide'; ?>"><?php echo $hide_supporters ? esc_html__( 'Show supporter thanks', 'plugin-hub' ) : esc_html__( 'Hide supporter thanks', 'plugin-hub' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
