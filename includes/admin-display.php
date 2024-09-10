<div class="wrap">
  <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

  <div class="plugin-hub-container">
    <div class="plugin-hub-main">
      <ul class="subsubsub">
        <li><a href="?page=plugin-hub&filter=all" <?php echo $filter === 'all' ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo $counts['all']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=active" <?php echo $filter === 'active' ? 'class="current"' : ''; ?>>Active <span class="count">(<?php echo $counts['active']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=inactive" <?php echo $filter === 'inactive' ? 'class="current"' : ''; ?>>Inactive <span class="count">(<?php echo $counts['inactive']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=update" <?php echo $filter === 'update' ? 'class="current"' : ''; ?>>Update Available <span class="count">(<?php echo $counts['update']; ?>)</span></a> |</li>
        <li><a href="?page=plugin-hub&filter=disabled" <?php echo $filter === 'disabled' ? 'class="current"' : ''; ?>>Disabled <span class="count">(<?php echo $counts['disabled']; ?>)</span></a></li>
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
              <option value="disable">Disable</option>
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
              <th scope="col" class="manage-column column-description">Description</th>
              <th scope="col" class="manage-column column-version">Version</th>
              <th scope="col" class="manage-column column-last-update">Last Update</th>
            </tr>
          </thead>
          <tbody id="the-list">
            <?php foreach ($repos as $repo): ?>
              <?php
              $latest_release = $api->get_latest_release($repo['repo_url']);
              $is_installed = $api->is_plugin_installed($repo['name']);
              $is_active = $api->is_plugin_active($repo['name']);
              $is_disabled = $api->is_plugin_disabled($repo['name']);
              $update_available = $api->is_update_available($repo, $latest_release);

              if (($filter === 'active' && !$is_active) ||
                ($filter === 'inactive' && ($is_active || $is_disabled)) ||
                ($filter === 'update' && !$update_available) ||
                ($filter === 'disabled' && !$is_disabled)
              ) {
                continue;
              }
              ?>
              <tr class="<?php echo $is_active ? 'active' : ($is_disabled ? 'disabled' : 'inactive'); ?>">
                <th scope="row" class="check-column">
                  <input type="checkbox" name="checked[]" value="<?php echo esc_attr($repo['name']); ?>">
                </th>
                <td class="plugin-title column-primary">
                  <strong>
                    <a href="<?php echo esc_url($repo['repo_url']); ?>" target="_blank">
                      <?php echo esc_html($repo['display_name']); ?>
                    </a>
                  </strong>
                  <div class="row-actions visible">
                    <?php if (!$is_installed): ?>
                      <span class="install">
                        <a href="#" class="install-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>" data-url="<?php echo esc_url($latest_release ? $latest_release->zipball_url : ''); ?>">
                          Install Now
                        </a>
                      </span>
                    <?php elseif ($update_available): ?>
                      <span class="update">
                        <a href="#" class="update-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>" data-url="<?php echo esc_url($latest_release ? $latest_release->zipball_url : ''); ?>">
                          Update Now
                        </a>
                      </span>
                    <?php elseif ($is_active): ?>
                      <span class="deactivate">
                        <a href="#" class="deactivate-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>">
                          Deactivate
                        </a>
                      </span>
                      <span class="disable">
                        <a href="#" class="disable-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>">
                          Disable
                        </a>
                      </span>
                    <?php elseif ($is_disabled): ?>
                      <span class="enable">
                        <a href="#" class="activate-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>">
                          Enable
                        </a>
                      </span>
                    <?php else: ?>
                      <span class="activate">
                        <a href="#" class="activate-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>">
                          Activate
                        </a>
                      </span>
                      <span class="disable">
                        <a href="#" class="disable-now plugin-action-link" data-repo="<?php echo esc_attr($repo['name']); ?>">
                          Disable
                        </a>
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="column-description desc">
                  <div class="plugin-description">
                    <p><?php echo esc_html($repo['description']); ?></p>
                  </div>
                </td>
                <td class="column-version">
                  <?php echo esc_html($repo['version']); ?>
                  <?php if ($update_available): ?>
                    <br><span class="update-available">Update available</span>
                  <?php endif; ?>
                </td>
                <td class="column-last-update">
                  <?php
                  if ($latest_release) {
                    $last_update = human_time_diff(strtotime($latest_release->published_at), current_time('timestamp'));
                    echo esc_html($last_update . ' ago');
                  } else {
                    echo 'N/A';
                  }
                  ?>
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
    </div>
  </div>
</div>