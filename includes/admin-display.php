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

<div class="wrap plugin-hub-wrap">
	<header class="plugin-hub-header">
		<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?page=plugin-hub&action=refresh_cache' ), 'plugin_hub_refresh_cache' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Refresh Open WP Club catalog', 'plugin-hub' ); ?></a>
		<hr class="wp-header-end">
		<p class="plugin-hub-subtitle"><?php esc_html_e( 'Install, update and manage plugins published by Open WP Club.', 'plugin-hub' ); ?></p>
	</header>

	<?php $search_suffix = '' !== $search ? '&amp;s=' . esc_attr( rawurlencode( $search ) ) : ''; ?>
	<nav aria-label="<?php esc_attr_e( 'Open WP Club plugin views', 'plugin-hub' ); ?>">
		<ul class="subsubsub plugin-hub-tabs">
			<li><a href="?page=plugin-hub&amp;filter=all<?php echo $search_suffix; ?>" <?php echo 'all' === $filter ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['all'] ); ?>)</span></a> |</li>
			<li><a href="?page=plugin-hub&amp;filter=active<?php echo $search_suffix; ?>" <?php echo 'active' === $filter ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Active', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['active'] ); ?>)</span></a> |</li>
			<li><a href="?page=plugin-hub&amp;filter=inactive<?php echo $search_suffix; ?>" <?php echo 'inactive' === $filter ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Inactive', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['inactive'] ); ?>)</span></a> |</li>
			<li><a href="?page=plugin-hub&amp;filter=update<?php echo $search_suffix; ?>" <?php echo 'update' === $filter ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Updates', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['update'] ); ?>)</span></a> |</li>
			<li><a href="?page=plugin-hub&amp;filter=beta<?php echo $search_suffix; ?>" <?php echo 'beta' === $filter ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Beta', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $counts['beta'] ); ?>)</span></a> |</li>
			<li><a href="?page=plugin-hub&amp;filter=activity" <?php echo 'activity' === $filter ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Activity', 'plugin-hub' ); ?></a></li>
		</ul>
	</nav>

	<div class="plugin-hub-layout">
		<main class="plugin-hub-main">

				<?php if ( 'activity' === $filter ) : ?>

				<!-- ===== Activity Log ===== -->
				<div class="plugin-hub-activity">
					<p class="description"><?php esc_html_e( 'A history of plugin changes made from Plugin Hub.', 'plugin-hub' ); ?></p>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date / Time', 'plugin-hub' ); ?></th>
							<th><?php esc_html_e( 'Action', 'plugin-hub' ); ?></th>
							<th><?php esc_html_e( 'Plugin', 'plugin-hub' ); ?></th>
							<th><?php esc_html_e( 'Version', 'plugin-hub' ); ?></th>
							<th><?php esc_html_e( 'User', 'plugin-hub' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $activity_log ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No activity recorded yet.', 'plugin-hub' ); ?></td>
						</tr>
						<?php else : ?>
							<?php
							$action_labels  = array(
								'install'     => __( 'Install', 'plugin-hub' ),
								'update'      => __( 'Update', 'plugin-hub' ),
								'rollback'    => __( 'Rollback', 'plugin-hub' ),
								'activate'    => __( 'Activate', 'plugin-hub' ),
								'deactivate'  => __( 'Deactivate', 'plugin-hub' ),
								'delete'      => __( 'Delete', 'plugin-hub' ),
								'auto_update' => __( 'Auto-Update', 'plugin-hub' ),
							);
							$action_classes = array(
								'install'     => 'activity-install',
								'update'      => 'activity-update',
								'rollback'    => 'activity-rollback',
								'activate'    => 'activity-activate',
								'deactivate'  => 'activity-deactivate',
								'delete'      => 'activity-delete',
								'auto_update' => 'activity-auto-update',
							);
							foreach ( $activity_log as $entry ) :
								$label = isset( $action_labels[ $entry['action'] ] ) ? $action_labels[ $entry['action'] ] : esc_html( $entry['action'] );
								$class = isset( $action_classes[ $entry['action'] ] ) ? $action_classes[ $entry['action'] ] : '';
								?>
							<tr>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?></td>
								<td><span class="activity-badge <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span></td>
								<td><?php echo esc_html( $entry['plugin'] ); ?></td>
								<td><?php echo esc_html( $entry['version'] ); ?></td>
								<td><?php echo esc_html( $entry['user'] ); ?></td>
							</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				</div>

				<?php else : ?>

				<!-- ===== Plugin list ===== -->
				<form method="get" class="search-form search-plugins">
					<input type="hidden" name="page" value="plugin-hub">
					<?php if ( 'all' !== $filter ) : ?>
						<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
					<?php endif; ?>
					<p class="search-box">
						<label class="screen-reader-text" for="plugin-search-input"><?php esc_html_e( 'Search plugins', 'plugin-hub' ); ?></label>
						<input type="search" id="plugin-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search Open WP Club plugins…', 'plugin-hub' ); ?>">
						<input type="submit" id="search-submit" class="button" value="<?php esc_attr_e( 'Search Plugins', 'plugin-hub' ); ?>">
					</p>
				</form>

				<form id="plugin-hub-form" method="post">
					<?php wp_nonce_field( 'plugin_hub_bulk_action', 'plugin_hub_nonce' ); ?>

					<div class="tablenav top plugin-hub-toolbar">
						<div class="alignleft actions bulkactions plugin-hub-bulk-actions">
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
						<?php if ( $total_pages > 1 ) : ?>
							<div class="alignright">
								<?php require PLUGIN_HUB_PLUGIN_DIR . 'includes/admin-pagination.php'; ?>
							</div>
						<?php endif; ?>
					</div>

					<?php if ( '' !== $search ) : ?>
						<p class="plugin-hub-search-results-info">
							<?php
							printf(
								/* translators: %s: Search term. */
								esc_html__( 'Search results for "%s"', 'plugin-hub' ),
								'<strong>' . esc_html( $search ) . '</strong>'
							);
							?>
						</p>
					<?php endif; ?>

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
								$is_available      = ! empty( $repo['available'] );
								$is_beta           = $is_available && version_compare( $repo['version'], '1.0.0', '<' );
								$auto_update_on    = in_array( $repo['name'], $autoupdate_plugins, true );
								?>
									<tr class="<?php echo $is_active ? 'active' : 'inactive'; ?>">
										<td class="check-column">
											<input type="checkbox" name="checked[]" value="<?php echo esc_attr( $repo['name'] ); ?>">
										</td>
										<th scope="row" class="plugin-title column-primary">
										<strong><?php echo esc_html( $repo['display_name'] ); ?></strong>
										<div class="row-actions visible">
										<?php if ( ! $is_installed && $is_available ) : ?>
											<span class="install">
												<a href="#" class="install-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>" data-version="<?php echo esc_attr( $repo['version'] ); ?>"><?php esc_html_e( 'Install now', 'plugin-hub' ); ?></a>
											</span>
										<?php elseif ( ! $is_installed ) : ?>
											<span class="unavailable"><?php esc_html_e( 'No release available', 'plugin-hub' ); ?></span>
											<?php elseif ( $is_active ) : ?>
												<span class="deactivate">
													<a href="#" class="deactivate-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Deactivate', 'plugin-hub' ); ?></a>
												</span>
											<?php else : ?>
												<span class="activate">
													<a href="#" class="activate-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Activate', 'plugin-hub' ); ?></a>
												</span>
												<span class="delete">
													<a href="#" class="delete-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Delete', 'plugin-hub' ); ?></a>
												</span>
											<?php endif; ?>
											<?php if ( $update_available ) : ?>
												<span class="update">
													<a href="#" class="update-now" data-repo="<?php echo esc_attr( $repo['name'] ); ?>" data-version="<?php echo esc_attr( $repo['version'] ); ?>"><?php esc_html_e( 'Update now', 'plugin-hub' ); ?></a>
												</span>
												<span class="view-changelog">
													<a href="#" class="open-changelog"
														data-repo="<?php echo esc_attr( $repo['name'] ); ?>"
														data-current-version="<?php echo esc_attr( $installed_version ); ?>"
														data-new-version="<?php echo esc_attr( $repo['version'] ); ?>"
													><?php esc_html_e( "What's new?", 'plugin-hub' ); ?></a>
												</span>
											<?php endif; ?>
											<span class="view">
												<a href="<?php echo esc_url( $repo['repo_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View on GitHub', 'plugin-hub' ); ?></a>
											</span>
											<?php if ( $is_installed ) : ?>
												<span class="rollback">
													<a href="#" class="rollback-toggle" data-repo="<?php echo esc_attr( $repo['name'] ); ?>"><?php esc_html_e( 'Versions', 'plugin-hub' ); ?></a>
													<span class="rollback-list" style="display:none;"></span>
												</span>
											<?php endif; ?>
										</div>
										<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'plugin-hub' ); ?></span></button>
										</th>
									<td class="column-description desc">
										<div class="plugin-description">
											<p><?php echo esc_html( $repo['description'] ); ?></p>
										</div>
										<div class="active second plugin-version-author-uri">
											<?php if ( ! $is_installed ) : ?>
												<?php if ( ! $is_available ) : ?>
													<?php esc_html_e( 'No published release', 'plugin-hub' ); ?>
												<?php elseif ( $is_beta ) : ?>
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
													<span class="update-message notice inline notice-warning notice-alt">
														<?php
														/* translators: %s: New version number */
														printf( esc_html__( 'Update available (%s)', 'plugin-hub' ), esc_html( $repo['version'] ) );
														?>
													</span>
												<?php endif; ?>
												<label class="plugin-autoupdate-label">
													<input
														type="checkbox"
														class="autoupdate-toggle"
														data-repo="<?php echo esc_attr( $repo['name'] ); ?>"
														<?php checked( $auto_update_on ); ?>
													>
													<?php esc_html_e( 'Auto-update', 'plugin-hub' ); ?>
												</label>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if ( 0 === $total_items ) : ?>
								<tr class="no-items">
									<td colspan="3"><?php esc_html_e( 'No plugins match the current view. Refresh the catalog or adjust the filter.', 'plugin-hub' ); ?></td>
								</tr>
							<?php endif; ?>
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

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav bottom plugin-hub-toolbar plugin-hub-toolbar--pagination">
							<?php require PLUGIN_HUB_PLUGIN_DIR . 'includes/admin-pagination.php'; ?>
						</div>
					<?php endif; ?>
				</form>

				<?php endif; ?>
		</main>

		<aside class="plugin-hub-sidebar" aria-label="<?php esc_attr_e( 'Plugin Hub settings', 'plugin-hub' ); ?>">
			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'GitHub access', 'plugin-hub' ); ?></span></h2>
				<div class="inside">
					<span class="plugin-hub-connection <?php echo $has_github_token ? 'is-connected' : ''; ?>">
						<?php echo $has_github_token ? esc_html__( 'Connected', 'plugin-hub' ) : esc_html__( 'Public API', 'plugin-hub' ); ?>
					</span>
					<label class="plugin-hub-field-label" for="github-token"><?php esc_html_e( 'Personal access token', 'plugin-hub' ); ?></label>
					<?php if ( $github_token_from_config ) : ?>
						<p class="description"><?php esc_html_e( 'Managed by PLUGIN_HUB_GITHUB_TOKEN in wp-config.php.', 'plugin-hub' ); ?></p>
						<input type="password" id="github-token" class="regular-text" value="" placeholder="<?php esc_attr_e( 'Configured in wp-config.php', 'plugin-hub' ); ?>" disabled>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Optional. Add a token for a higher GitHub API rate limit.', 'plugin-hub' ); ?></p>
						<input type="password" id="github-token" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_github_token ? __( 'Token configured — enter a replacement', 'plugin-hub' ) : __( 'github_pat_…', 'plugin-hub' ) ); ?>">
						<div class="plugin-hub-button-row">
							<button type="button" id="save-github-token" class="button button-primary"><?php esc_html_e( 'Save token', 'plugin-hub' ); ?></button>
						<?php if ( $has_github_token ) : ?>
							<button type="button" id="remove-github-token" class="button button-link-delete"><?php esc_html_e( 'Remove', 'plugin-hub' ); ?></button>
						<?php endif; ?>
						</div>
					<?php endif; ?>
					<span id="token-status" role="status" aria-live="polite"></span>
							<?php
							$rate_limit = get_transient( 'plugin_hub_rate_limit' );
							if ( $rate_limit ) :
								$pct = $rate_limit['limit'] > 0 ? min( 1, max( 0, $rate_limit['remaining'] / $rate_limit['limit'] ) ) : 1;
								/* translators: %1$d: remaining requests, %2$d: total limit. */
								$rate_limit_message = sprintf( __( '%1$d of %2$d API requests remaining', 'plugin-hub' ), (int) $rate_limit['remaining'], (int) $rate_limit['limit'] );
								$rate_limit_reset   = ! empty( $rate_limit['reset'] ) ? wp_date( get_option( 'time_format' ), (int) $rate_limit['reset'] ) : '';
								?>
					<div class="plugin-hub-rate-limit <?php echo $pct < 0.1 ? 'is-low' : ''; ?>" id="rate-limit-info">
						<div class="plugin-hub-rate-limit__track"><span style="width: <?php echo esc_attr( round( $pct * 100 ) ); ?>%"></span></div>
						<p class="description">
								<?php echo esc_html( $rate_limit_message ); ?>
								<?php if ( $rate_limit_reset ) : ?>
									<span class="plugin-hub-rate-limit__reset">
										<?php
										/* translators: %s: GitHub API rate-limit reset time. */
										printf( esc_html__( 'Resets at %s.', 'plugin-hub' ), esc_html( $rate_limit_reset ) );
										?>
									</span>
								<?php endif; ?>
						</p>
					</div>
						<?php endif; ?>
				</div>
			</div>

			<div class="postbox">
				<h2 class="hndle"><span><?php esc_html_e( 'Catalog settings', 'plugin-hub' ); ?></span></h2>
				<div class="inside">
					<label for="show-beta-plugins" class="plugin-hub-toggle-row">
						<span>
							<strong><?php esc_html_e( 'Show beta plugins', 'plugin-hub' ); ?></strong>
							<small><?php esc_html_e( 'Include releases below version 1.0.0.', 'plugin-hub' ); ?></small>
						</span>
						<input type="checkbox" id="show-beta-plugins" name="show_beta_plugins" <?php checked( get_option( 'plugin_hub_show_beta', false ) ); ?>>
					</label>
					<div class="plugin-hub-maintenance-actions">
						<a href="<?php echo esc_url( 'https://github.com/' . PLUGIN_HUB_ORGANIZATION ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-external" aria-hidden="true"></span><?php esc_html_e( 'Open GitHub organization', 'plugin-hub' ); ?></a>
						<a href="<?php echo esc_url( 'https://github.com/' . PLUGIN_HUB_ORGANIZATION . '/plugin-hub' ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-book" aria-hidden="true"></span><?php esc_html_e( 'Documentation', 'plugin-hub' ); ?></a>
						<a href="<?php echo esc_url( 'https://github.com/' . PLUGIN_HUB_ORGANIZATION . '/plugin-hub/issues' ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Support', 'plugin-hub' ); ?></a>
					</div>
				</div>
			</div>

			<div class="postbox plugin-hub-danger-zone">
				<div class="inside">
					<strong><?php esc_html_e( 'Activity history', 'plugin-hub' ); ?></strong>
					<p class="description"><?php esc_html_e( 'Permanently remove all recorded plugin actions.', 'plugin-hub' ); ?></p>
					<button type="button" id="clear-activity-log" class="button button-link-delete"><?php esc_html_e( 'Clear activity log', 'plugin-hub' ); ?></button>
				</div>
			</div>
		</aside>
	</div>
</div><!-- /wrap -->

<!-- Changelog modal -->
<div id="plugin-hub-modal-overlay" hidden></div>
<div id="plugin-hub-modal" role="dialog" aria-modal="true" aria-labelledby="plugin-hub-modal-title" tabindex="-1" hidden>
	<div class="modal-header">
		<h2 id="plugin-hub-modal-title"></h2>
		<button type="button" id="plugin-hub-modal-close" class="modal-close" aria-label="<?php esc_attr_e( 'Close', 'plugin-hub' ); ?>">&times;</button>
	</div>
	<div id="plugin-hub-modal-content"></div>
</div>
