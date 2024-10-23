<?php
// Check if this file is being accessed directly
if (!defined('ABSPATH')) {
  exit;
}
?>

<div class="wrap">
  <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

  <div class="plugin-hub-container">
    <div class="plugin-hub-main">
      <div class="tablenav top">
        <div class="alignleft actions">
          <a href="<?php echo wp_nonce_url(admin_url('plugins.php?page=plugin-hub&action=refresh_cache'), 'plugin_hub_refresh_cache'); ?>" class="button">Refresh Plugin List</a>
        </div>
        <div class="alignright">
          <input type="search" id="plugin-search-input" class="wp-filter-search" placeholder="Search plugins..." aria-describedby="live-search-desc">
        </div>
      </div>

      <ul class="subsubsub">
        <li><a href="?page=plugin-hub&filter=all" <?php echo $filter === 'all' ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo $counts['all']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=active" <?php echo $filter === 'active' ? 'class="current"' : ''; ?>>Active <span class="count">(<?php echo $counts['active']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=inactive" <?php echo $filter === 'inactive' ? 'class="current"' : ''; ?>>Inactive <span class="count">(<?php echo $counts['inactive']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=update" <?php echo $filter === 'update' ? 'class="current"' : ''; ?>>Update Available <span class="count">(<?php echo $counts['update']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=beta" <?php echo $filter === 'beta' ? 'class="current"' : ''; ?>>Beta <span class="count">(<?php echo $counts['beta']; ?>)</span></a></li>
      </ul>

      <form id="plugin-hub-form" method="post">
        <?php wp_nonce_field('plugin_hub_bulk_action', 'plugin_hub_nonce'); ?>
        <div class="tablenav top">
          <div class="alignleft actions bulkactions">
            <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
            <select name="action" id="bulk-action-selector-top">
              <option value="-1">Bulk Actions</option>
              <option value="install">Install</option>
              <option value="activate">Activate</option>
              <option value="deactivate">Deactivate</option>
              <option value="update">Update</option>
              <option value="delete">Delete</option>
            </select>
            <input type="submit" id="doaction" class="button action" value="Apply">
          </div>
        </div>
        <table class="wp-list-table widefat plugins">
          <thead>
            <tr>
              <td id="cb" class="manage-column column-cb check-column">
                <input id="cb-select-all-1" type="checkbox">
              </td>
              <th scope="col" class="manage-column column-name column-primary">Plugin</th>
              <th scope="col" class="manage-column column-actions">Actions</th>
              <th scope="col" class="manage-column column-description">Description</th>
              <th scope="col" class="manage-column column-version">Version</th>
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
                    <span class="repo"><a href="<?php echo esc_url($repo['repo_url']); ?>" target="_blank">View on GitHub</a></span>
                  </div>
                </td>
                <td class="column-actions">
                  <?php if (!$is_installed): ?>
                    <a href="#" class="button install-now" data-repo="<?php echo esc_attr($repo['name']); ?>" data-version="<?php echo esc_attr($repo['version']); ?>">Install Now</a>
                  <?php elseif ($update_available): ?>
                    <a href="#" class="button update-now" data-repo="<?php echo esc_attr($repo['name']); ?>" data-version="<?php echo esc_attr($repo['version']); ?>">Update Now</a>
                  <?php elseif ($is_active): ?>
                    <a href="#" class="button deactivate-now" data-repo="<?php echo esc_attr($repo['name']); ?>">Deactivate</a>
                  <?php else: ?>
                    <a href="#" class="button activate-now" data-repo="<?php echo esc_attr($repo['name']); ?>">Activate</a>
                    <a href="#" class="button delete-now" data-repo="<?php echo esc_attr($repo['name']); ?>">Delete</a>
                  <?php endif; ?>
                </td>
                <td class="column-description desc">
                  <div class="plugin-description">
                    <p><?php echo esc_html($repo['description']); ?></p>
                  </div>
                </td>
                <td class="column-version">
                  <?php if (!$is_installed): ?>
                    Latest version is <?php echo esc_html($repo['version']); ?>
                  <?php else: ?>
                    Installed version is <?php echo esc_html($installed_version); ?>
                    <?php if ($update_available): ?>
                      <br>Update available (<?php echo esc_html($repo['version']); ?>)
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </form>
    </div>
    <div class="plugin-hub-sidebar">
      <h3>Organization Links</h3>
      <ul>
        <li><a href="https://github.com/<?php echo esc_attr($this->organization); ?>" target="_blank">GitHub Organization</a></li>
        <li><a href="https://example.com/documentation" target="_blank">Documentation</a></li>
        <li><a href="https://example.com/support" target="_blank">Support</a></li>
        <li><a href="https://example.com/blog" target="_blank">Blog</a></li>
      </ul>

      <h3>Beta Plugins</h3>
      <label for="show-beta-plugins">
        <input type="checkbox" id="show-beta-plugins" name="show_beta_plugins" <?php checked(get_option('plugin_hub_show_beta', false)); ?>>
        Show Beta Plugins (< 1.0.0)
          </label>
    </div>
  </div>
</div>