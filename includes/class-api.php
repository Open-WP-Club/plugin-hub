<?php

class Plugin_Hub_API
{
  private $organization = 'Open-WP-Club';
  private $github_plugins = [];

  public function __construct()
  {
    $this->load_github_plugins();
  }

  private function load_github_plugins()
  {
    $this->github_plugins = get_option('plugin_hub_github_plugins', array());
  }

  public function get_org_repos()
  {
    $url = "https://api.github.com/orgs/{$this->organization}/repos";
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
      return array();
    }
    $repos = json_decode(wp_remote_retrieve_body($response));

    // Handle case where only one repo is returned (as an object, not an array)
    if (is_object($repos) && isset($repos->name)) {
      return array($repos->name);
    }

    // Handle case where multiple repos are returned (as an array of objects)
    if (is_array($repos)) {
      return array_map(function ($repo) {
        return $repo->name;
      }, $repos);
    }

    // If we get here, something unexpected happened, so return an empty array
    return array();
  }
  public function get_repo_description($repo_name)
  {
    $url = "https://api.github.com/repos/{$this->organization}/{$repo_name}";
    $response = wp_remote_get($url);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      return '';
    }
    $repo_data = json_decode(wp_remote_retrieve_body($response));
    return $repo_data->description ?? '';
  }

  public function get_latest_release($repo)
  {
    $url = "https://api.github.com/repos/{$this->organization}/{$repo}/releases/latest";
    $response = wp_remote_get($url);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
      return null;
    }
    return json_decode(wp_remote_retrieve_body($response));
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

  public function is_update_available($plugin_name, $latest_release)
  {
    $plugin_file = $this->get_plugin_file($plugin_name);
    if (!$plugin_file || !$latest_release) {
      return false;
    }
    $installed_plugin = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
    $installed_version = $installed_plugin['Version'];
    $latest_version = ltrim($latest_release->tag_name, 'v');
    return version_compare($latest_version, $installed_version, '>');
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

  public function ajax_install_github_plugin()
  {
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
}
