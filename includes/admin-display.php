<?php
/**
 * Admin display template.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.0.0
 */

namespace PluginHub;

// Check if this file is being accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$plugin_hub_tabs = array(
	'plugins'  => array( __( 'Plugins', 'plugin-hub' ), 'all' ),
	'mu'       => array( __( 'MU plugins', 'plugin-hub' ), 'mu' ),
	'backups'  => array( __( 'Backups', 'plugin-hub' ), 'backups' ),
	'activity' => array( __( 'Activity', 'plugin-hub' ), 'activity' ),
	'settings' => array( __( 'Settings', 'plugin-hub' ), 'settings' ),
);
?>

<div class="wrap plugin-hub-wrap">
	<header class="plugin-hub-header">
		<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<a href="<?php echo esc_url( wp_nonce_url( page_url( array( 'action' => 'refresh_cache' ) ), 'plugin_hub_refresh_cache' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Refresh Open WP Club catalog', 'plugin-hub' ); ?></a>
		<hr class="wp-header-end">
		<p class="plugin-hub-subtitle"><?php esc_html_e( 'Install, update and manage plugins published by Open WP Club.', 'plugin-hub' ); ?></p>
	</header>

	<?php settings_errors( 'plugin_hub_messages' ); ?>

	<nav class="nav-tab-wrapper wp-clearfix plugin-hub-tabs" aria-label="<?php esc_attr_e( 'Plugin Hub sections', 'plugin-hub' ); ?>">
		<?php foreach ( $plugin_hub_tabs as $plugin_hub_tab_key => $plugin_hub_tab ) : ?>
			<a href="<?php echo esc_url( page_url( array( 'filter' => $plugin_hub_tab[1] ) ) ); ?>" class="nav-tab<?php echo $plugin_hub_tab_key === $tab ? ' nav-tab-active' : ''; ?>"<?php echo $plugin_hub_tab_key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $plugin_hub_tab[0] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="plugin-hub-layout">
		<main class="plugin-hub-main">

			<?php if ( 'settings' === $tab ) : ?>

			<!-- ===== Settings ===== -->
				<?php
				$plugin_hub_hours = array();
				for ( $plugin_hub_hour = 0; $plugin_hub_hour < 24; $plugin_hub_hour++ ) {
					$plugin_hub_hours[ $plugin_hub_hour ] = sprintf( '%02d:00', $plugin_hub_hour );
				}
				$plugin_hub_usage = Backups::usage();
				?>
			<form id="plugin-hub-settings-form" class="plugin-hub-settings" method="post">
				<h2><?php esc_html_e( 'Notifications', 'plugin-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ph-notify-emails"><?php esc_html_e( 'Email recipients', 'plugin-hub' ); ?></label></th>
						<td>
							<input type="text" id="ph-notify-emails" class="regular-text" name="settings[notify_emails]" value="<?php echo esc_attr( $settings['notify_emails'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Comma-separated. Leave empty to use the site administrator email.', 'plugin-hub' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ph-notify-mode"><?php esc_html_e( 'Send reports', 'plugin-hub' ); ?></label></th>
						<td>
							<select id="ph-notify-mode" name="settings[notify_mode]">
								<option value="all" <?php selected( $settings['notify_mode'], 'all' ); ?>><?php esc_html_e( 'For every change (updates, available updates, failures)', 'plugin-hub' ); ?></option>
								<option value="failures" <?php selected( $settings['notify_mode'], 'failures' ); ?>><?php esc_html_e( 'Only when something fails or is rolled back', 'plugin-hub' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ph-webhook-url"><?php esc_html_e( 'Webhook URL', 'plugin-hub' ); ?></label></th>
						<td>
							<input type="url" id="ph-webhook-url" class="regular-text" name="settings[webhook_url]" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" placeholder="https://hooks.slack.com/services/…">
							<button type="button" id="plugin-hub-test-notification" class="button"><?php esc_html_e( 'Send test notification', 'plugin-hub' ); ?></button>
							<p class="description"><?php esc_html_e( 'Slack, Discord or any HTTPS endpoint that accepts JSON. Save the settings before sending a test.', 'plugin-hub' ); ?></p>
							<span id="plugin-hub-test-status" role="status" aria-live="polite"></span>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Update schedule', 'plugin-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Maintenance window', 'plugin-hub' ); ?></th>
						<td>
							<input type="hidden" name="settings[window_enabled]" value="0">
							<label><input type="checkbox" name="settings[window_enabled]" value="1" <?php checked( $settings['window_enabled'] ); ?>> <?php esc_html_e( 'Only run automatic updates inside this window', 'plugin-hub' ); ?></label>
							<p>
								<?php for ( $plugin_hub_day = 0; $plugin_hub_day < 7; $plugin_hub_day++ ) : ?>
									<label class="plugin-hub-inline-check"><input type="checkbox" name="settings[window_days][]" value="<?php echo (int) $plugin_hub_day; ?>" <?php checked( in_array( $plugin_hub_day, $settings['window_days'], true ) ); ?>> <?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $plugin_hub_day ) ) ); ?></label>
								<?php endfor; ?>
							</p>
							<p>
								<label for="ph-window-start"><?php esc_html_e( 'From', 'plugin-hub' ); ?></label>
								<select id="ph-window-start" name="settings[window_start]">
									<?php foreach ( $plugin_hub_hours as $plugin_hub_hour => $plugin_hub_label ) : ?>
										<option value="<?php echo (int) $plugin_hub_hour; ?>" <?php selected( (int) $settings['window_start'], $plugin_hub_hour ); ?>><?php echo esc_html( $plugin_hub_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<label for="ph-window-end"><?php esc_html_e( 'until', 'plugin-hub' ); ?></label>
								<select id="ph-window-end" name="settings[window_end]">
									<?php foreach ( $plugin_hub_hours as $plugin_hub_hour => $plugin_hub_label ) : ?>
										<option value="<?php echo (int) $plugin_hub_hour; ?>" <?php selected( (int) $settings['window_end'], $plugin_hub_hour ); ?>><?php echo esc_html( $plugin_hub_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'Site time.', 'plugin-hub' ); ?></span>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ph-delay"><?php esc_html_e( 'Release delay', 'plugin-hub' ); ?></label></th>
						<td>
							<input type="number" id="ph-delay" class="small-text" min="0" max="60" name="settings[update_delay_days]" value="<?php echo (int) $settings['update_delay_days']; ?>">
							<?php esc_html_e( 'days', 'plugin-hub' ); ?>
							<p class="description"><?php esc_html_e( 'Automatic updates wait until a release is this old, so a faulty release can be caught first. 0 disables the delay.', 'plugin-hub' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Safety', 'plugin-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic rollback', 'plugin-hub' ); ?></th>
						<td>
							<input type="hidden" name="settings[auto_rollback]" value="0">
							<label><input type="checkbox" name="settings[auto_rollback]" value="1" <?php checked( $settings['auto_rollback'] ); ?>> <?php esc_html_e( 'Check the site after an update and restore the previous version if it fails', 'plugin-hub' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Package integrity', 'plugin-hub' ); ?></th>
						<td>
							<input type="hidden" name="settings[require_checksum]" value="0">
							<label><input type="checkbox" name="settings[require_checksum]" value="1" <?php checked( $settings['require_checksum'] ); ?>> <?php esc_html_e( 'Refuse installs and updates whose catalog entry has no SHA-256 checksum', 'plugin-hub' ); ?></label>
							<p class="description"><?php esc_html_e( 'When the catalog publishes a checksum, packages are always verified.', 'plugin-hub' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Backups', 'plugin-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Before updates', 'plugin-hub' ); ?></th>
						<td>
							<input type="hidden" name="settings[backup_enabled]" value="0">
							<label><input type="checkbox" name="settings[backup_enabled]" value="1" <?php checked( $settings['backup_enabled'] ); ?>> <?php esc_html_e( 'Back up the plugin folder before every update', 'plugin-hub' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ph-backup-keep"><?php esc_html_e( 'Keep', 'plugin-hub' ); ?></label></th>
						<td>
							<input type="number" id="ph-backup-keep" class="small-text" min="1" max="20" name="settings[backup_keep]" value="<?php echo (int) $settings['backup_keep']; ?>">
							<?php esc_html_e( 'backups per plugin, for at most', 'plugin-hub' ); ?>
							<input type="number" id="ph-backup-days" class="small-text" min="0" max="365" name="settings[backup_days]" value="<?php echo (int) $settings['backup_days']; ?>" aria-label="<?php esc_attr_e( 'Days to keep backups', 'plugin-hub' ); ?>">
							<?php esc_html_e( 'days (0 = no age limit)', 'plugin-hub' ); ?>
							<p class="description">
								<?php
								printf(
									/* translators: 1: Number of backups, 2: Disk space used. */
									esc_html__( 'Currently %1$s backup(s) using %2$s.', 'plugin-hub' ),
									esc_html( number_format_i18n( (int) $plugin_hub_usage['count'] ) ),
									esc_html( Backups::format_size( (int) $plugin_hub_usage['total'] ) )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Activity log', 'plugin-hub' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ph-log-max"><?php esc_html_e( 'Retention', 'plugin-hub' ); ?></label></th>
						<td>
							<?php esc_html_e( 'Keep the last', 'plugin-hub' ); ?>
							<input type="number" id="ph-log-max" class="small-text" min="50" max="2000" name="settings[log_max]" value="<?php echo (int) $settings['log_max']; ?>">
							<?php esc_html_e( 'entries, for at most', 'plugin-hub' ); ?>
							<input type="number" id="ph-log-days" class="small-text" min="0" max="3650" name="settings[log_days]" value="<?php echo (int) $settings['log_days']; ?>" aria-label="<?php esc_attr_e( 'Days to keep log entries', 'plugin-hub' ); ?>">
							<?php esc_html_e( 'days (0 = no age limit)', 'plugin-hub' ); ?>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" id="plugin-hub-save-settings"><?php esc_html_e( 'Save settings', 'plugin-hub' ); ?></button>
					<span id="plugin-hub-settings-status" role="status" aria-live="polite"></span>
				</p>
			</form>

			<h2><?php esc_html_e( 'Export and import', 'plugin-hub' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Move your Plugin Hub setup to another site: installed plugins, auto-update choices, version pins, MU plugins and settings. The GitHub token is never exported.', 'plugin-hub' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=plugin_hub_export_config' ), 'plugin_hub_export_config' ) ); ?>"><?php esc_html_e( 'Export configuration', 'plugin-hub' ); ?></a>
			</p>
			<p>
				<label for="plugin-hub-import-file" class="screen-reader-text"><?php esc_html_e( 'Configuration file', 'plugin-hub' ); ?></label>
				<input type="file" id="plugin-hub-import-file" accept="application/json,.json">
				<button type="button" id="plugin-hub-import" class="button"><?php esc_html_e( 'Import configuration', 'plugin-hub' ); ?></button>
			</p>
			<div id="plugin-hub-import-result" role="status" aria-live="polite"></div>

			<?php elseif ( 'activity' === $tab ) : ?>

			<!-- ===== Activity Log ===== -->
			<div class="plugin-hub-activity">
				<p class="description">
					<?php esc_html_e( 'A history of plugin changes made from Plugin Hub.', 'plugin-hub' ); ?>
					<?php
					$plugin_hub_export_args = array(
						'action'     => 'plugin_hub_export_activity',
						'log_action' => $table->get_filters()['action'],
						'log_user'   => $table->get_filters()['user'],
						's'          => $table->get_filters()['search'],
						'log_from'   => $table->get_filters()['from'],
						'log_to'     => $table->get_filters()['to'],
					);
					?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array_filter( $plugin_hub_export_args ), admin_url( 'admin-post.php' ) ), 'plugin_hub_export_activity' ) ); ?>"><?php esc_html_e( 'Export as CSV', 'plugin-hub' ); ?></a>
				</p>
				<form id="plugin-hub-form" method="get" data-table="activity">
					<input type="hidden" name="page" value="plugin-hub">
					<input type="hidden" name="filter" value="activity">
					<?php
					$table->search_box( __( 'Search activity', 'plugin-hub' ), 'plugin-hub-activity-search' );
					$table->display();
					?>
				</form>
			</div>

			<?php elseif ( 'backups' === $tab ) : ?>

			<!-- ===== Backups ===== -->
			<div class="plugin-hub-backups">
				<p class="description">
					<?php
					printf(
						/* translators: 1: Number of backups, 2: Plugin backups size, 3: MU backups size, 4: Total size. */
						esc_html__( '%1$s backup(s) use %4$s in total (plugins: %2$s, MU plugins: %3$s). Retention is configured in Settings.', 'plugin-hub' ),
						esc_html( number_format_i18n( (int) $backup_usage['count'] ) ),
						esc_html( Backups::format_size( (int) $backup_usage['plugins'] ) ),
						esc_html( Backups::format_size( (int) $backup_usage['mu'] ) ),
						esc_html( Backups::format_size( (int) $backup_usage['total'] ) )
					);
					?>
				</p>
				<form id="plugin-hub-form" method="get" data-table="backups">
					<input type="hidden" name="page" value="plugin-hub">
					<input type="hidden" name="filter" value="backups">
					<?php
					$table->search_box( __( 'Search backups', 'plugin-hub' ), 'plugin-hub-backup-search' );
					$table->display();
					?>
				</form>
			</div>

			<?php elseif ( 'mu' === $tab ) : ?>

			<!-- ===== MU plugins ===== -->
			<div class="plugin-hub-mu">
				<p class="description">
					<?php
					printf(
						/* translators: %s: MU plugin directory path. */
						esc_html__( 'Must-use plugins from the Open WP Club mu-plugin repository, managed in %s. Only files whose name matches a catalog file are updated.', 'plugin-hub' ),
						'<code>' . esc_html( $mu_data['dir'] ) . '</code>'
					);
					?>
				</p>

				<?php if ( ! $mu_data['file_mods'] ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'File modifications are disabled on this site, so MU plugins cannot be installed or updated here.', 'plugin-hub' ); ?></p></div>
				<?php elseif ( 'missing' === $mu_data['dir_state'] ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'The MU plugin directory does not exist yet. It will be created on the first install if the parent directory is writable.', 'plugin-hub' ); ?></p></div>
				<?php elseif ( 'unwritable' === $mu_data['dir_state'] ) : ?>
					<div class="notice notice-error inline"><p><?php esc_html_e( 'The MU plugin directory is not writable. Fix its permissions to install or update MU plugins.', 'plugin-hub' ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! $mu_data['catalog_ok'] ) : ?>
					<div class="notice notice-error inline"><p><?php esc_html_e( 'The MU plugin catalog could not be loaded from GitHub. Try again in a few minutes.', 'plugin-hub' ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! empty( $mu_data['multisite'] ) ) : ?>
					<div class="notice notice-info inline"><p><?php esc_html_e( 'This is a multisite network: MU plugins are shared by every site, and only network administrators can change them.', 'plugin-hub' ); ?></p></div>
				<?php endif; ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'MU plugins load automatically on every request and cannot be deactivated from the admin. Some change core behaviour, for example blocking plugin installs, comments, search or email. Read the description before installing and test on a staging site first.', 'plugin-hub' ); ?></p></div>

				<ul class="subsubsub">
					<li><a href="<?php echo esc_url( page_url( array( 'filter' => 'mu' ) ) ); ?>" <?php echo 'installed' === $mu_view ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Installed', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( count( $mu_data['installed'] ) ); ?>)</span></a> |</li>
					<li><a href="
					<?php
					echo esc_url(
						page_url(
							array(
								'filter'  => 'mu',
								'mu_view' => 'available',
							)
						)
					);
					?>
									" <?php echo 'available' === $mu_view ? 'class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Available', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( count( $mu_data['available'] ) ); ?>)</span></a></li>
					<?php if ( $mu_data['update_count'] > 0 && 'installed' === $mu_view ) : ?>
						<li>| <a href="
						<?php
						echo esc_url(
							page_url(
								array(
									'filter'    => 'mu',
									'mu_filter' => 'update',
								)
							)
						);
						?>
										"><?php esc_html_e( 'Updates', 'plugin-hub' ); ?> <span class="count">(<?php echo absint( $mu_data['update_count'] ); ?>)</span></a></li>
					<?php endif; ?>
				</ul>
				<?php if ( $mu_data['update_count'] > 0 && $mu_data['file_mods'] ) : ?>
					<p class="plugin-hub-mu-update-all"><button type="button" id="mu-update-all" class="button button-primary"><?php esc_html_e( 'Update all', 'plugin-hub' ); ?> (<?php echo absint( $mu_data['update_count'] ); ?>)</button></p>
				<?php endif; ?>

				<form id="plugin-hub-form" method="get" data-table="<?php echo 'available' === $mu_view ? 'mu-available' : 'mu-installed'; ?>">
					<input type="hidden" name="page" value="plugin-hub">
					<input type="hidden" name="filter" value="mu">
					<input type="hidden" name="mu_view" value="<?php echo esc_attr( $mu_view ); ?>">
					<?php
					$table->search_box( __( 'Search MU plugins', 'plugin-hub' ), 'plugin-hub-mu-search' );
					$table->display();
					?>
				</form>
			</div>

			<?php else : ?>

			<!-- ===== Plugin list ===== -->
				<?php $table->views(); ?>
			<form id="plugin-hub-form" method="get" data-table="plugins">
				<input type="hidden" name="page" value="plugin-hub">
				<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>">
				<?php
				$table->search_box( __( 'Search plugins', 'plugin-hub' ), 'plugin-search-input' );
				$table->display();
				?>
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

			<?php require PLUGIN_HUB_PLUGIN_DIR . 'includes/sponsorship-display.php'; ?>

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
	<div id="plugin-hub-modal-footer" hidden></div>
</div>
