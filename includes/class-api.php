<?php

class Plugin_Hub_API
{
    private $organization = 'Open-WP-Club';
    private $csv_url = 'https://raw.githubusercontent.com/Open-WP-Club/.github/main/plugins.csv?token=';
    private $github_plugins = array();
    private $cache_key = 'plugin_hub_csv_cache';
    private $cache_expiration = 86400; // 24 hours

    public function __construct()
    {
        $this->csv_url .= time();
        $this->load_github_plugins();
    }

    private function load_github_plugins()
    {
        $this->github_plugins = get_option('plugin_hub_github_plugins', array());
    }

    public function get_org_repos()
    {
        $cached_data = get_transient($this->cache_key);
        if ($cached_data !== false) {
            return $cached_data;
        }

        $response = wp_remote_get($this->csv_url);
        if (is_wp_error($response)) {
            error_log('Plugin Hub: Error fetching CSV file: ' . $response->get_error_message());
            return array();
        }

        $csv_content = wp_remote_retrieve_body($response);
        $repos = $this->parse_csv_content($csv_content);

        set_transient($this->cache_key, $repos, $this->cache_expiration);

        return $repos;
    }

    private function parse_csv_content($csv_content)
    {
        $lines = explode("\n", trim($csv_content));
        $repos = array();

        // Remove the header row
        array_shift($lines);

        foreach ($lines as $line) {
            $data = str_getcsv($line);
            if (count($data) >= 5) {
                $repos[] = array(
                    'name' => trim($data[0]),
                    'display_name' => trim($data[1]),
                    'description' => trim($data[2]),
                    'version' => trim($data[3]),
                    'repo_url' => trim($data[4])
                );
            }
        }

        return $repos;
    }

    public function refresh_csv_cache()
    {
        delete_transient($this->cache_key);
        return $this->get_org_repos();
    }

    public function is_plugin_installed($plugin_name)
    {
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

    public function is_plugin_active($plugin_name)
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_file = $this->get_plugin_file($plugin_name);
        return $plugin_file && is_plugin_active($plugin_file);
    }

    public function is_plugin_disabled($plugin_name)
    {
        return get_option("plugin_hub_disabled_{$plugin_name}", false);
    }

    public function get_plugin_file($plugin_name)
    {
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

    public function is_update_available($repo, $installed_version)
    {
        return version_compare($repo['version'], $installed_version, '>');
    }

    public function get_installed_plugin_version($plugin_name)
    {
        $plugin_file = $this->get_plugin_file($plugin_name);
        if ($plugin_file) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            return $plugin_data['Version'];
        }
        return 'Not Installed';
    }

    public function check_for_plugin_updates($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $repos = $this->get_org_repos();

        foreach ($repos as $repo) {
            $plugin_file = $this->get_plugin_file($repo['name']);
            if (!$plugin_file) continue;

            $wp_version = $transient->checked[$plugin_file];
            $csv_version = $repo['version'];

            if (version_compare($csv_version, $wp_version, '>')) {
                $obj = new stdClass();
                $obj->slug = $plugin_file;
                $obj->new_version = $csv_version;
                $obj->url = $repo['repo_url'];
                $obj->package = $this->get_github_release_download_url($repo['name'], $csv_version);
                $transient->response[$plugin_file] = $obj;
            }
        }

        return $transient;
    }

    public function get_github_release_download_url($repo_name, $version)
    {
        $api_url = "https://api.github.com/repos/{$this->organization}/{$repo_name}/releases/tags/v{$version}";
        
        $args = array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/Plugin-Hub'
            )
        );

        $response = wp_remote_get($api_url, $args);

        if (is_wp_error($response)) {
            error_log("Plugin Hub: Error fetching GitHub release: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $release = json_decode($body, true);

        if (isset($release['zipball_url'])) {
            return $release['zipball_url'];
        } else {
            error_log("Plugin Hub: Unable to find zipball_url in GitHub API response for {$repo_name} v{$version}");
            error_log("Plugin Hub: GitHub API response: " . print_r($release, true));
            return false;
        }
    }

    public function ajax_install_github_plugin()
    {
        check_ajax_referer('plugin-hub-nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_send_json_error('You do not have permission to install plugins.');
        }

        $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';
        $version = isset($_POST['version']) ? sanitize_text_field($_POST['version']) : '';

        if (empty($repo_name) || empty($version)) {
            wp_send_json_error('Invalid plugin information.');
        }

        $download_url = $this->get_github_release_download_url($repo_name, $version);

        if (!$download_url) {
            $error_message = "Unable to fetch download URL for {$repo_name} v{$version}. ";
            $error_message .= "Please check the error log for more details.";
            wp_send_json_error($error_message);
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
            delete_option("plugin_hub_disabled_{$repo_name}");
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
        $version = isset($_POST['version']) ? sanitize_text_field($_POST['version']) : '';

        if (empty($repo_name) || empty($version)) {
            wp_send_json_error('Invalid plugin information.');
        }

        $download_url = $this->get_github_release_download_url($repo_name, $version);

        if (!$download_url) {
            $error_message = "Unable to fetch download URL for {$repo_name} v{$version}. ";
            $error_message .= "Please check the error log for more details.";
            wp_send_json_error($error_message);
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $plugin_file = $this->get_plugin_file($repo_name);

        if (!$plugin_file) {
            wp_send_json_error('Plugin not found.');
        }

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        
        // Clear the plugin update transient
        delete_site_transient('update_plugins');
        
        $result = $upgrader->upgrade($plugin_file, array('package' => $download_url));

        if (is_wp_error($result)) {
            error_log('Plugin Hub: Update failed for ' . $repo_name . '. Error: ' . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        } elseif ($result === false) {
            error_log('Plugin Hub: Update failed for ' . $repo_name . '. No error message provided.');
            wp_send_json_error('Update failed. Please check the error log for more details.');
        } else {
            // Force refresh of plugin update information
            wp_clean_plugins_cache();
            // Verify the update
            $new_version = $this->get_installed_plugin_version($repo_name);
            if (version_compare($new_version, $version, '>=')) {
                wp_send_json_success('Plugin updated successfully to version ' . $new_version);
            } else {
                error_log('Plugin Hub: Update reported success but version mismatch for ' . $repo_name . '. Expected: ' . $version . ', Actual: ' . $new_version);
                wp_send_json_error('Update reported success but version mismatch. Please check the error log for more details.');
            }
        }
    }

    public function ajax_delete_github_plugin()
    {
        check_ajax_referer('plugin-hub-nonce', 'nonce');

        if (!current_user_can('delete_plugins')) {
            wp_send_json_error('You do not have permission to delete plugins.');
        }

        $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';

        if (empty($repo_name)) {
            wp_send_json_error('Invalid plugin information.');
        }

        $plugin_file = $this->get_plugin_file($repo_name);

        if (!$plugin_file) {
            wp_send_json_error('Plugin not found.');
        }

        if (is_plugin_active($plugin_file)) {
            wp_send_json_error('Please deactivate the plugin before deleting.');
        }

        $deleted = delete_plugins(array($plugin_file));

        if ($deleted) {
            wp_send_json_success('Plugin deleted successfully.');
        } else {
            wp_send_json_error('Failed to delete the plugin.');
        }
    }

    public function ajax_disable_github_plugin()
    {
        check_ajax_referer('plugin-hub-nonce', 'nonce');

        if (!current_user_can('deactivate_plugins')) {
            wp_send_json_error('You do not have permission to disable plugins.');
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
        update_option("plugin_hub_disabled_{$repo_name}", true);

        wp_send_json_success('Plugin disabled successfully.');
    }

  public function verify_plugin_update()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    if (!current_user_can('update_plugins')) {
      wp_send_json_error('You do not have permission to verify plugin updates.');
    }

    $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';
    $expected_version = isset($_POST['version']) ? sanitize_text_field($_POST['version']) : '';

    if (empty($repo_name) || empty($expected_version)) {
      wp_send_json_error('Invalid plugin information.');
    }

    $installed_version = $this->get_installed_plugin_version($repo_name);

    if (version_compare($installed_version, $expected_version, '>=')) {
      wp_send_json_success("Plugin version verified: {$installed_version}");
    } else {
      wp_send_json_error("Plugin version mismatch. Expected: {$expected_version}, Found: {$installed_version}");
    }
  }

  // Helper method to get the latest version from GitHub
  private function get_latest_github_version($repo_name)
  {
    $api_url = "https://api.github.com/repos/{$this->organization}/{$repo_name}/releases/latest";

    $args = array(
      'headers' => array(
        'Accept' => 'application/vnd.github.v3+json',
        'User-Agent' => 'WordPress/Plugin-Hub'
      )
    );

    $response = wp_remote_get($api_url, $args);

    if (is_wp_error($response)) {
      error_log("Plugin Hub: Error fetching latest GitHub release: " . $response->get_error_message());
      return false;
    }

    $body = wp_remote_retrieve_body($response);
    $release = json_decode($body, true);

    if (isset($release['tag_name'])) {
      return ltrim($release['tag_name'], 'v');
    } else {
      error_log("Plugin Hub: Unable to find tag_name in GitHub API response for {$repo_name}");
      return false;
    }
  }

  // Method to force refresh all plugin information
  public function force_refresh_plugins()
  {
    $repos = $this->get_org_repos();
    $updated = false;

    foreach ($repos as &$repo) {
      $latest_version = $this->get_latest_github_version($repo['name']);
      if ($latest_version && $latest_version !== $repo['version']) {
        $repo['version'] = $latest_version;
        $updated = true;
      }
    }

    if ($updated) {
      set_transient($this->cache_key, $repos, $this->cache_expiration);
    }

    // Clear WordPress plugin update cache
    wp_clean_plugins_cache();
    delete_site_transient('update_plugins');

    return $updated;
  }

  // Method to handle manual refresh request
  public function ajax_force_refresh_plugins()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    if (!current_user_can('update_plugins')) {
      wp_send_json_error('You do not have permission to refresh plugin information.');
    }

    $updated = $this->force_refresh_plugins();

    if ($updated) {
      wp_send_json_success('Plugin information refreshed successfully.');
    } else {
      wp_send_json_success('No updates found. Plugin information is already up to date.');
    }
  }

  // Method to get changelog from GitHub
  public function get_github_changelog($repo_name, $current_version, $new_version)
  {
    $api_url = "https://api.github.com/repos/{$this->organization}/{$repo_name}/releases";

    $args = array(
      'headers' => array(
        'Accept' => 'application/vnd.github.v3+json',
        'User-Agent' => 'WordPress/Plugin-Hub'
      )
    );

    $response = wp_remote_get($api_url, $args);

    if (is_wp_error($response)) {
      error_log("Plugin Hub: Error fetching GitHub releases: " . $response->get_error_message());
      return false;
    }

    $body = wp_remote_retrieve_body($response);
    $releases = json_decode($body, true);

    $changelog = "";
    foreach ($releases as $release) {
      $release_version = ltrim($release['tag_name'], 'v');
      if (
        version_compare($release_version, $current_version, '>') &&
        version_compare($release_version, $new_version, '<=')
      ) {
        $changelog .= "<h4>Version {$release_version}</h4>";
        $changelog .= wpautop($release['body']);
      }
    }

    return $changelog;
  }

  // Method to display changelog
  public function ajax_get_changelog()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    $repo_name = isset($_POST['repo']) ? sanitize_text_field($_POST['repo']) : '';
    $current_version = isset($_POST['current_version']) ? sanitize_text_field($_POST['current_version']) : '';
    $new_version = isset($_POST['new_version']) ? sanitize_text_field($_POST['new_version']) : '';

    if (empty($repo_name) || empty($current_version) || empty($new_version)) {
      wp_send_json_error('Invalid plugin information.');
    }

    $changelog = $this->get_github_changelog($repo_name, $current_version, $new_version);

    if ($changelog) {
      wp_send_json_success($changelog);
    } else {
      wp_send_json_error('Unable to fetch changelog.');
    }
  }
}