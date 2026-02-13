<?php
/**
 * Admin display template.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.0.0
 */

// Check if this file is being accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div id="poststuff">
		<div id="post-body" class="metabox-holder">
			<div id="post-body-content">
				<div class="wp-filter">
					<div class="search-form">
						<input type="search" id="plugin-search-input" placeholder="<?php esc_attr_e( 'Search installed plugins...', 'plugin-hub' ); ?>" size="40">
					</div>
				</div>

				<form id="plugin-hub-form" method="post">
					<?php wp_nonce_field( 'plugin_hub_bulk_action', 'plugin_hub_nonce' ); ?>

					<ul class="subsubsub">
						<li><a href="?page=plugin-hub&filter=all" <?php echo 'all' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'All', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['all'] ); ?>)</span></a> |</li>
						<li><a href="?page=plugin-hub&filter=active" <?php echo 'active' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Active', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['active'] ); ?>)</span></a> |</li>
						<li><a href="?page=plugin-hub&filter=inactive" <?php echo 'inactive' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Inactive', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['inactive'] ); ?>)</span></a> |</li>
						<li><a href="?page=plugin-hub&filter=update" <?php echo 'update' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Updates Available', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['update'] ); ?>)</span></a> |</li>
						<li><a href="?page=plugin-hub&filter=beta" <?php echo 'beta' === $filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Beta', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['beta'] ); ?>)</span></a></li>
					</ul>

					<div class="tablenav top">
						<div class="alignleft actions bulkactions">
							<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'plugin-hub' ); ?></label>
							<select name="action" id="bulk-action-selector-top">
								<option value="-1"><?php esc_html_e( 'Bulk Actions', 'plugin-hub' ); ?></option>
								<option value="activate"><?php esc_html_e( 'Activate', 'plugin-hub' ); ?></option>
								<option value="deactivate"><?php esc_html_e( 'Deactivate', 'plugin-hub' ); ?></option>
								<option value="update"><?php esc_html_e( 'Update', 'plugin-hub' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete', 'plugin-hub' ); ?></option>
							</select>
							<input type="submit" class="button action" value="<?php esc_attr_e( 'Apply', 'plugin-hub' ); ?>">
						</div>
					</div>

					<table class="wp-list-table widefat plugins">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input id="cb-select-all-1" type="checkbox">
								</td>
								<th scope="col" class="manage-column column-name column-primary"><?php esc_html_e( 'Plugin', 'plugin-hub' ); ?></th>
								<th scope="col" class="manage-column column-description"><?php esc_html_e( 'Description', 'plugin-hub' ); ?></th>
							</tr>
						</thead>

						<tbody id="the-list">
							<?php foreach ( $repos as $repo ) : ?>
								<?php
								$is_installed      = $api->is_plugin_installed( $repo['name'] );
								$is_active         = $api->is_plugin_active( $repo['name'] );
								$installed_version = $api->get_installed_plugin_version( $repo['name'] );
								$update_available  = $api->is_update_available( $repo, $installed_version );
								$is_beta           = version_compare( $repo['version'], '1.0.0', '<' );

								if (
									( 'active' === $filter && ! $is_active ) ||
									( 'inactive' === $filter && ( ! $is_installed || $is_active ) ) ||
									( 'update' === $filter && ! $update_available ) ||
									( 'beta' === $filter && ! $is_beta ) ||
									( ! get_option( 'plugin_hub_show_beta', false ) && $is_beta )
								) {
									continue;
								}
								?>
								<tr class="<?php echo $is_active ? 'active' : 'inactive'; ?>">
									<th scope="row" class="check-column">
										<input type="checkbox" name="checked[]" value="<?php echo esc_attr( $repo['name'] ); ?>">
									</th>
									<td class="plugin-title column-primary">
										<strong><?php echo esc_html( $repo['display_name'] ); ?></strong>
										<div class="row-actions visible">
											<?php if ( ! $is_installed ) : ?>
												<span class="install">
													<a href="#" class="install-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>" data-version="<?php echo esc_attr( $repo['version'] ); ?>"><?php esc_html_e( 'Install Now', 'plugin-hub' ); ?></a>
												</span>
											<?php elseif ( $is_active ) : ?>
												<span class="deactivate">
													<a href="#" class="deactivate-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Deactivate', 'plugin-hub' ); ?></a>
												</span>
											<?php else : ?>
												<span class="activate">
													<a href="#" class="activate-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Activate', 'plugin-hub' ); ?></a>
												</span> |
												<span class="delete">
													<a href="#" class="delete-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Delete', 'plugin-hub' ); ?></a>
												</span>
											<?php endif; ?>
											<?php if ( $update_available ) : ?>
												|
												<span class="update">
													<a href="#" class="update-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>" data-version="<?php echo esc_attr( $repo['version'] ); ?>"><?php esc_html_e( 'Update Now', 'plugin-hub' ); ?></a>
												</span>
											<?php endif; ?>
											| <span class="view">
												<a href="<?php echo esc_url( $repo['repo_url'] ); ?>" target="_blank"><?php esc_html_e( 'View on GitHub', 'plugin-hub' ); ?></a>
											</span>
										</div>
										<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'plugin-hub' ); ?></span></button>
									</td>
									<td class="column-description desc">
										<div class="plugin-description">
											<p><?php echo esc_html( $repo['description'] ); ?></p>
										</div>
										<div class="active second plugin-version-author-uri">
											<?php if ( ! $is_installed ) : ?>
												<?php if ( $is_beta ) : ?>
													<?php esc_html_e( 'Beta version', 'plugin-hub' ); ?>
												<?php else : ?>
													<?php
													/* translators: %s: Plugin version number */
													printf( esc_html__( 'Latest version is %s', 'plugin-hub' ), esc_html( $repo['version'] ) );
													?>
												<?php endif; ?>
											<?php else : ?>
												<?php
												/* translators: %s: Plugin version number */
												printf( esc_html__( 'Version %s', 'plugin-hub' ), esc_html( $installed_version ) );
												?>
												<?php if ( $update_available ) : ?>
													<strong class="update-message">
														<?php
														/* translators: %s: New version number */
														printf( esc_html__( 'Update available (%s)', 'plugin-hub' ), esc_html( $repo['version'] ) );
														?>
													</strong>
												<?php endif; ?>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>

						<tfoot>
							<tr>
								<td class="manage-column column-cb check-column">
									<input id="cb-select-all-2" type="checkbox">
								</td>
								<th scope="col" class="manage-column column-name column-primary"><?php esc_html_e( 'Plugin', 'plugin-hub' ); ?></th>
								<th scope="col" class="manage-column column-description"><?php esc_html_e( 'Description', 'plugin-hub' ); ?></th>
							</tr>
						</tfoot>
					</table>
				</form>
			</div><!-- /post-body-content -->

			<div id="postbox-container-1" class="postbox-container">
				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'GitHub Repository', 'plugin-hub' ); ?></span></h2>
					<div class="inside">
						<a href="https://github.com/<?php echo esc_attr( $this->organization ); ?>" target="_blank" class="button-secondary"><?php esc_html_e( 'View Organization', 'plugin-hub' ); ?></a>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Plugin Settings', 'plugin-hub' ); ?></span></h2>
					<div class="inside">
						<p>
							<label for="show-beta-plugins" class="switch">
								<input type="checkbox" id="show-beta-plugins" name="show_beta_plugins" <?php checked( get_option( 'plugin_hub_show_beta', false ) ); ?>>
								<?php esc_html_e( 'Show Beta Plugins (< 1.0.0)', 'plugin-hub' ); ?>
							</label>
						</p>
						<p>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?page=plugin-hub&action=refresh_cache' ), 'plugin_hub_refresh_cache' ) ); ?>" class="button"><?php esc_html_e( 'Refresh Plugin List', 'plugin-hub' ); ?></a>
						</p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Quick Links', 'plugin-hub' ); ?></span></h2>
					<div class="inside">
						<ul>
							<li><a href="https://github.com/<?php echo esc_attr( $this->organization ); ?>" target="_blank"><?php esc_html_e( 'GitHub Organization', 'plugin-hub' ); ?></a></li>
							<li><a href="https://github.com/<?php echo esc_attr( $this->organization ); ?>/plugin-hub" target="_blank"><?php esc_html_e( 'Documentation', 'plugin-hub' ); ?></a></li>
							<li><a href="https://github.com/<?php echo esc_attr( $this->organization ); ?>/plugin-hub/issues" target="_blank"><?php esc_html_e( 'Support', 'plugin-hub' ); ?></a></li>
						</ul>
					</div>
				</div>
			</div><!-- /postbox-container-1 -->
		</div><!-- /post-body -->
	</div><!-- /poststuff -->
</div><!-- /wrap -->
