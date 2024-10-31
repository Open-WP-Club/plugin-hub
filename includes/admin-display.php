<?php
// Check if this file is being accessed directly
if (!defined('ABSPATH')) {
  exit;
}
?>

<div class="wrap">
  <h1 class="wp-heading-inline"><?php echo esc_html(get_admin_page_title()); ?></h1>

  <div id="poststuff">
    <div id="post-body" class="metabox-holder">
      <div id="post-body-content">
        <div class="wp-filter">
          <div class="search-form">
            <input type="search" id="plugin-search-input" placeholder="Search installed plugins..." size="40">
          </div>
        </div>

        <form id="plugin-hub-form" method="post">
          <?php wp_nonce_field('plugin_hub_bulk_action', 'plugin_hub_nonce'); ?>

          <ul class="subsubsub">
            <li><a href="?page=plugin-hub&filter=all" <?php echo $filter === 'all' ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo $counts['all']; ?>)</span></a> |</li>
            <li><a href="?page=plugin-hub&filter=active" <?php echo $filter === 'active' ? 'class="current"' : ''; ?>>Active <span class="count">(<?php echo $counts['active']; ?>)</span></a> |</li>
            <li><a href="?page=plugin-hub&filter=inactive" <?php echo $filter === 'inactive' ? 'class="current"' : ''; ?>>Inactive <span class="count">(<?php echo $counts['inactive']; ?>)</span></a> |</li>
            <li><a href="?page=plugin-hub&filter=update" <?php echo $filter === 'update' ? 'class="current"' : ''; ?>>Updates Available <span class="count">(<?php echo $counts['update']; ?>)</span></a> |</li>
            <li><a href="?page=plugin-hub&filter=beta" <?php echo $filter === 'beta' ? 'class="current"' : ''; ?>>Beta <span class="count">(<?php echo $counts['beta']; ?>)</span></a></li>
          </ul>

          <div class="tablenav top">
            <div class="alignleft actions bulkactions">
              <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
              <select name="action" id="bulk-action-selector-top">
                <option value="-1">Bulk Actions</option>
                <option value="activate">Activate</option>
                <option value="deactivate">Deactivate</option>
                <option value="update">Update</option>
                <option value="delete">Delete</option>
              </select>
              <input type="submit" class="button action" value="Apply">
            </div>
          </div>

          <table class="wp-list-table widefat plugins">
            <thead>
              <tr>
                <td class="manage-column column-cb check-column">
                  <input id="cb-select-all-1" type="checkbox">
                </td>
                <th scope="col" class="manage-column column-name column-primary">Plugin</th>
                <th scope="col" class="manage-column column-description">Description</th>
              </tr>
            </thead>

            <tbody id="the-list">
              <?php foreach ($repos as $repo): ?>
                <?php
                $is_installed = $api->is_plugin_installed($repo['name']);
                $is_active = $api->is_plugin_active($repo['name']);
                $installed_version = $api->get_installed_plugin_version($repo['name']);
                $update_available = $api->is_update_available($repo, $installed_version);
                $is_beta = version_compare($repo['version'], '1.0.0', '<');

                if (($filter === 'active' && !$is_active) ||
                  ($filter === 'inactive' && $is_active) ||
                  ($filter === 'update' && !$update_available) ||
                  ($filter === 'beta' && !$is_beta) ||
                  (!get_option('plugin_hub_show_beta', false) && $is_beta)
                ) {
                  continue;
                }
                ?>
                <tr class="<?php echo $is_active ? 'active' : 'inactive'; ?>">
                  <th scope="row" class="check-column">
                    <input type="checkbox" name="checked[]" value="<?php echo esc_attr($repo['name']); ?>">
                  </th>
                  <td class="plugin-title column-primary">
                    <strong><?php echo esc_html($repo['display_name']); ?></strong>
                    <div class="row-actions visible">
                      <?php if (!$is_installed): ?>
                        <span class="install">
                          <a href="#" class="install-now" data-repo="<?php echo esc_attr($repo['name']); ?>" data-version="<?php echo esc_attr($repo['version']); ?>">Install Now</a>
                        </span>
                      <?php elseif ($is_active): ?>
                        <span class="deactivate">
                          <a href="#" class="deactivate-now" data-repo="<?php echo esc_attr($repo['name']); ?>">Deactivate</a>
                        </span>
                      <?php else: ?>
                        <span class="activate">
                          <a href="#" class="activate-now" data-repo="<?php echo esc_attr($repo['name']); ?>">Activate</a>
                        </span> |
                        <span class="delete">
                          <a href="#" class="delete-now" data-repo="<?php echo esc_attr($repo['name']); ?>" class="delete">Delete</a>
                        </span>
                      <?php endif; ?>
                      <?php if ($update_available): ?> |
                        <span class="update">
                          <a href="#" class="update-now" data-repo="<?php echo esc_attr($repo['name']); ?>" data-version="<?php echo esc_attr($repo['version']); ?>">Update Now</a>
                        </span>
                      <?php endif; ?> |
                      <span class="view">
                        <a href="<?php echo esc_url($repo['repo_url']); ?>" target="_blank">View on GitHub</a>
                      </span>
                    </div>
                    <button type="button" class="toggle-row"><span class="screen-reader-text">Show more details</span></button>
                  </td>
                  <td class="column-description desc">
                    <div class="plugin-description">
                      <p><?php echo esc_html($repo['description']); ?></p>
                    </div>
                    <div class="active second plugin-version-author-uri">
                      <?php if (!$is_installed): ?>
                        <?php if ($installed_version === 'N/A' || $installed_version === 'Not Installed'): ?>
                          Beta version
                        <?php else: ?>
                          Latest version is <?php echo esc_html($repo['version']); ?>
                        <?php endif; ?>
                      <?php else: ?>
                        Version <?php echo esc_html($installed_version); ?>
                        <?php if ($update_available): ?>
                          <strong class="update-message">Update available (<?php echo esc_html($repo['version']); ?>)</strong>
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
                <th scope="col" class="manage-column column-name column-primary">Plugin</th>
                <th scope="col" class="manage-column column-description">Description</th>
              </tr>
            </tfoot>
          </table>
        </form>
      </div><!-- /post-body-content -->

      <div id="postbox-container-1" class="postbox-container">
        <div class="postbox">
          <h2 class="hndle"><span>GitHub Repository</span></h2>
          <div class="inside">
            <a href="https://github.com/<?php echo esc_attr($this->organization); ?>" target="_blank" class="button-secondary">View Organization</a>
          </div>
        </div>

        <div class="postbox">
          <h2 class="hndle"><span>Plugin Settings</span></h2>
          <div class="inside">
            <p>
              <label for="show-beta-plugins" class="switch">
                <input type="checkbox" id="show-beta-plugins" name="show_beta_plugins" <?php checked(get_option('plugin_hub_show_beta', false)); ?>>
                Show Beta Plugins (< 1.0.0)
                  </label>
            </p>
            <p>
              <a href="<?php echo wp_nonce_url(admin_url('plugins.php?page=plugin-hub&action=refresh_cache'), 'plugin_hub_refresh_cache'); ?>" class="button">Refresh Plugin List</a>
            </p>
          </div>
        </div>

        <div class="postbox">
          <h2 class="hndle"><span>Quick Links</span></h2>
          <div class="inside">
            <ul>
              <li><a href="https://github.com/<?php echo esc_attr($this->organization); ?>" target="_blank">GitHub Organization</a></li>
              <li><a href="https://example.com/documentation" target="_blank">Documentation</a></li>
              <li><a href="https://example.com/support" target="_blank">Support</a></li>
            </ul>
          </div>
        </div>
      </div><!-- /postbox-container-1 -->
    </div><!-- /post-body -->
  </div><!-- /poststuff -->
</div><!-- /wrap -->