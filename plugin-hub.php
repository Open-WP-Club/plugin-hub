<?php
/**
 * Plugin Name: Plugin Hub
 * Description: Manages WordPress plugins from GitHub repositories, focusing on Open-WP-Club
 * Version: 1.2
 * Author: Your Name
 * Author URI: http://yourwebsite.com/
 * License: GPL2
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Plugin_Hub {
    private $github_plugins = [];
    private $organization = 'Open-WP-Club';

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_plugin_updates'));
        add_action('wp_ajax_install_github_plugin', array($this, 'ajax_install_github_plugin'));
        add_action('wp_ajax_activate_github_plugin', array($this, 'ajax_activate_github_plugin'));
        add_action('wp_ajax_deactivate_github_plugin', array($this, 'ajax_deactivate_github_plugin'));
        add_action('wp_ajax_update_github_plugin', array($this, 'ajax_update_github_plugin'));
        $this->load_github_plugins();
    }

    public function add_admin_menu() {
        add_plugins_page(
            'Plugin Hub',
            'Plugin Hub',
            'manage_options',
            'plugin-hub',
            array($this, 'admin_page')
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('plugins_page_plugin-hub' !== $hook) {
            return;
        }
        wp_enqueue_style('plugin-hub-style', plugin_dir_url(__FILE__) . 'plugin-hub-style.css');
        wp_enqueue_script('plugin-hub-script', plugin_dir_url(__FILE__) . 'plugin-hub-script.js', array('jquery'), '1.0', true);
        wp_localize_script('plugin-hub-script', 'pluginHubAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('plugin-hub-nonce')
        ));
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $repos = $this->get_org_repos();
        $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';

        $counts = $this->get_plugin_counts($repos);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <ul class="subsubsub">
                <li><a href="?page=plugin-hub&filter=all" <?php echo $filter === 'all' ? 'class="current"' : ''; ?>>All <span class="count">(<?php echo $counts['all']; ?>)</span></a> |</li>
                <li><a href="?page=plugin-hub&filter=active" <?php echo $filter === 'active' ? 'class="current"' : ''; ?>>Active <span class="count">(<?php echo $counts['active']; ?>)</span></a> |</li>
                <li><a href="?page=plugin-hub&filter=inactive" <?php echo $filter === 'inactive' ? 'class="current"' : ''; ?>>Inactive <span class="count">(<?php echo $counts['inactive']; ?>)</span></a> |</li>
                <li><a href="?page=plugin-hub&filter=update" <?php echo $filter === 'update' ? 'class="current"' : ''; ?>>Update Available <span class="count">(<?php echo $counts['update']; ?>)</span></a></li>
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
                            <th scope="col" class="manage-column column-status">Status</th>
                        </tr>
                    </thead>
                    <tbody id="the-list">
                        <?php foreach ($repos as $repo): ?>
                            <?php 
                            $latest_release = $this->get_latest_release($repo->name);
                            $is_installed = $this->is_plugin_installed($repo->name);
                            $is_active = $this->is_plugin_active($repo->name);
                            $update_available = $this->is_update_available($repo->name, $latest_release);

                            if (($filter === 'active' && !$is_active) ||
                                ($filter === 'inactive' && $is_active) ||
                                ($filter === 'update' && !$update_available)) {
                                continue;
                            }
                            ?>
                            <tr class="<?php echo $is_active ? 'active' : 'inactive'; ?>">
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="checked[]" value="<?php echo esc_attr($repo->name); ?>">
                                </th>
                                <td class="plugin-title column-primary">
                                    <strong><?php echo esc_html($repo->name); ?></strong>
                                    <div class="row-actions visible">
                                        <span class="install">
                                            <?php if (!$is_installed): ?>
                                                <a href="#" class="install-now" data-repo="<?php echo esc_attr($repo->name); ?>" data-url="<?php echo esc_url($latest_release ? $latest_release->zipball_url : ''); ?>">
                                                    Install Now
                                                </a>
                                            <?php elseif ($update_available): ?>
                                                <a href="#" class="update-now" data-repo="<?php echo esc_attr($repo->name); ?>" data-url="<?php echo esc_url($latest_release ? $latest_release->zipball_url : ''); ?>">
                                                    Update Now
                                                </a>
                                            <?php elseif ($is_active): ?>
                                                <a href="#" class="deactivate-now" data-repo="<?php echo esc_attr($repo->name); ?>">
                                                    Deactivate
                                                </a>
                                            <?php else: ?>
                                                <a href="#" class="activate-now" data-repo="<?php echo esc_attr($repo->name); ?>">
                                                    Activate
                                                </a>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="column-description desc">
                                    <div class="plugin-description">
                                        <p><?php echo esc_html($repo->description); ?></p>
                                    </div>
                                </td>
                                <td class="column-version">
                                    <?php echo esc_html($latest_release ? ltrim($latest_release->tag_name, 'v') : 'N/A'); ?>
                                </td>
                                <td class="column-status">
                                    <?php if ($is_active): ?>
                                        <span class="active">Active</span>
                                    <?php elseif ($is_installed): ?>
                                        <span class="inactive">Inactive</span>
                                    <?php else: ?>
                                        <span class="not-installed">Not Installed</span>
                                    <?php endif; ?>
                                    <?php if ($update_available): ?>
                                        <span class="update">Update Available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }

    private function get_org_repos() {
        $url = "https://api.github.com/orgs/{$this->organization}/repos";
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return array();
        }
        return json_decode(wp_remote_retrieve_body($response));
    }

    private function get_latest_release($repo) {
        $url = "https://api.github.com/repos/{$this->organization}/{$repo}/releases/latest";
        $response = wp_remote_get($url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($response));
    }

    private function is_plugin_installed($plugin_name) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $plugin_name . '/') === 0) {
                return true;
            }
        }
        return false;
    }

    private function is_plugin_active($plugin_name) {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_file = $this->get_plugin_file($plugin_name);
        return $plugin_file && is_plugin_active($plugin_file);
    }

    private function get_plugin_file($plugin_name) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (strpos($plugin_file, $plugin_name . '/') === 0) {
                return $plugin_file;
            }
        }
        return false;
    }

    private function is_update_available($plugin_name, $latest_release) {
        $plugin_file = $this->get_plugin_file($plugin_name);
        if (!$plugin_file || !$latest_release) {
            return false;
        }
        $installed_plugin = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        $installed_version = $installed_plugin['Version'];
        $latest_version = ltrim($latest_release->tag_name, 'v');
        return version_compare($latest_version, $installed_version, '>');
    }

    private function get_plugin_counts($repos) {
        $counts = array(
            'all' => count($repos),
            'active' => 0,
            'inactive' => 0,
            'update' => 0
        );

        foreach ($repos as $repo) {
            $latest_release = $this->get_latest_release($repo->name);
            $is_installed = $this->is_plugin_installed($repo->name);
            $is_active = $this->is_plugin_active($repo->name);
            $update_available = $this->is_update_available($repo->name, $latest_release);

            if ($is_active) {
                $counts['active']++;
            } elseif ($is_installed) {
                $counts['inactive']++;
            }

            if ($update_available) {
                $counts['update']++;
            }
        }

        return $counts;
    }

    public function check_for_plugin_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        foreach ($this->github_plugins as $plugin_file => $github_data) {
            $latest_release = $this->get_latest_release($github_data['repo']);
            if (!$latest_release) continue;

            $github_version = ltrim($latest_release->tag_name, 'v');
            $wp_version = $transient->checked[$plugin_file];

            if (version_compare($github_version, $wp_version, '>')) {
                $obj = new stdClass();
                $obj->slug = $plugin_file;
                $obj->new_version = $github_version;
                $obj->url = $github_data['repo'];
                $obj->package = $latest_release->zipball_url;
                $transient->response[$plugin_file] = $obj;
            }
        }

        return $transient;
    }

    private function load_github_plugins() {
        $this->github_plugins = get_option('plugin_hub_github_plugins', array());
    }

    public function ajax_install_github_plugin() {
        check_ajax_referer('plugin-hub-nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error('You do not have permission to install plugins.');
        }

        $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';
        $download_url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($repo_name) || empty($download_url)) {
            wp_send_json_error('Invalid plugin information.');
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $installed = $upgrader->install($download_url);

    if (is_wp_error($installed)) {
      wp_send_json_error($installed->get_error_message());
    } else {
      $this->github_plugins[$repo_name] = array(
        'repo' => $repo_name,
        'file' => $upgrader->plugin_info()
      );
      update_option('plugin_hub_github_plugins', $this->github_plugins);

      wp_send_json_success('Plugin installed successfully.');
    }
  }

  public function ajax_activate_github_plugin()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    if (!current_user_can('activate_plugins')) {
      wp_send_json_error('You do not have permission to activate plugins.');
    }

    $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';

    if (empty($repo_name)) {
      wp_send_json_error('Invalid plugin information.');
    }

    $plugin_file = $this->get_plugin_file($repo_name);

    if (!$plugin_file) {
      wp_send_json_error('Plugin not found.');
    }

    $result = activate_plugin($plugin_file);

    if (is_wp_error($result)) {
      wp_send_json_error($result->get_error_message());
    } else {
      wp_send_json_success('Plugin activated successfully.');
    }
  }

  public function ajax_deactivate_github_plugin()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    if (!current_user_can('deactivate_plugins')) {
      wp_send_json_error('You do not have permission to deactivate plugins.');
    }

    $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';

    if (empty($repo_name)) {
      wp_send_json_error('Invalid plugin information.');
    }

    $plugin_file = $this->get_plugin_file($repo_name);

    if (!$plugin_file) {
      wp_send_json_error('Plugin not found.');
    }

    deactivate_plugins($plugin_file);

    wp_send_json_success('Plugin deactivated successfully.');
  }

  public function ajax_update_github_plugin()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    if (!current_user_can('update_plugins')) {
      wp_send_json_error('You do not have permission to update plugins.');
    }

    $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';
    $download_url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

    if (empty($repo_name) || empty($download_url)) {
      wp_send_json_error('Invalid plugin information.');
    }

    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

    $plugin_file = $this->get_plugin_file($repo_name);

    if (!$plugin_file) {
      wp_send_json_error('Plugin not found.');
    }

    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $result = $upgrader->upgrade($plugin_file);

    if (is_wp_error($result)) {
      wp_send_json_error($result->get_error_message());
    } else {
      wp_send_json_success('Plugin updated successfully.');
    }
  }
}

$plugin_hub = new Plugin_Hub();