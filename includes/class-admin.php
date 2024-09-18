<?php

class Plugin_Hub_Admin
{
  private $organization = 'Open-WP-Club';

  public function add_admin_menu()
  {
    add_plugins_page(
      'Plugin Hub',
      'Plugin Hub',
      'manage_options',
      'plugin-hub',
      array($this, 'display_admin_page')
    );
  }

  public function enqueue_styles($hook)
  {
    if ('plugins_page_plugin-hub' !== $hook) {
      return;
    }
    wp_enqueue_style('plugin-hub-style', PLUGIN_HUB_PLUGIN_URL . 'assets/css/style.css', array(), PLUGIN_HUB_VERSION);
  }

  public function enqueue_scripts($hook)
  {
    if ('plugins_page_plugin-hub' !== $hook) {
      return;
    }
    wp_enqueue_script('plugin-hub-script', PLUGIN_HUB_PLUGIN_URL . 'assets/js/script.js', array('jquery'), PLUGIN_HUB_VERSION, true);
    wp_localize_script('plugin-hub-script', 'pluginHubAjax', array(
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('plugin-hub-nonce')
    ));
  }

  public function handle_refresh_cache()
  {
    if (isset($_GET['action']) && $_GET['action'] === 'refresh_cache') {
      check_admin_referer('plugin_hub_refresh_cache');
      $api = new Plugin_Hub_API();
      $api->refresh_csv_cache();
      wp_redirect(admin_url('plugins.php?page=plugin-hub&cache_refreshed=1'));
      exit;
    }
  }

  public function display_admin_page()
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    if (isset($_GET['cache_refreshed']) && $_GET['cache_refreshed'] === '1') {
      add_settings_error('plugin_hub_messages', 'plugin_hub_message', __('Plugin list refreshed successfully.', 'plugin-hub'), 'updated');
    }

    $api = new Plugin_Hub_API();
    $repos = $api->get_org_repos();

    // Add error logging
    if (empty($repos)) {
      error_log('Plugin Hub: No repositories found or error occurred while fetching repositories.');
    } else {
      error_log('Plugin Hub: Fetched ' . count($repos) . ' repositories.');
    }

    $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
    $counts = $this->get_plugin_counts($repos);

    include PLUGIN_HUB_PLUGIN_DIR . 'includes/admin-display.php';
  }

  private function get_plugin_counts($repos)
  {
    $counts = array(
      'all' => 0,
      'active' => 0,
      'inactive' => 0,
      'update' => 0,
      'beta' => 0
    );

    $api = new Plugin_Hub_API();
    $show_beta = get_option('plugin_hub_show_beta', false);

    foreach ($repos as $repo) {
      $is_installed = $api->is_plugin_installed($repo['name']);
      $is_active = $api->is_plugin_active($repo['name']);
      $installed_version = $api->get_installed_plugin_version($repo['name']);
      $update_available = $api->is_update_available($repo, $installed_version);
      $is_beta = version_compare($repo['version'], '1.0.0', '<');

      if (!$show_beta && $is_beta) {
        continue;
      }

      $counts['all']++;

      if ($is_active) {
        $counts['active']++;
      } elseif ($is_installed) {
        $counts['inactive']++;
      }

      if ($update_available) {
        $counts['update']++;
      }

      if ($is_beta) {
        $counts['beta']++;
      }
    }

    return $counts;
  }

  public function ajax_toggle_beta_plugins()
  {
    check_ajax_referer('plugin-hub-nonce', 'nonce');

    if (!current_user_can('manage_options')) {
      wp_send_json_error('You do not have permission to change this setting.');
    }

    $show_beta = isset($_POST['show_beta']) ? filter_var($_POST['show_beta'], FILTER_VALIDATE_BOOLEAN) : false;
    update_option('plugin_hub_show_beta', $show_beta);

    wp_send_json_success('Setting updated successfully.');
  }
}
