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

  public function display_admin_page()
  {
    if (!current_user_can('manage_options')) {
      return;
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
    $counts = $this->get_plugin_counts($repos, true);

    include PLUGIN_HUB_PLUGIN_DIR . 'includes/admin-display.php';
  }

  private function get_plugin_counts($repos, $show_beta)
  {
    $counts = array(
      'all' => 0,
      'active' => 0,
      'inactive' => 0,
      'update' => 0,
      'disabled' => 0
    );

    $api = new Plugin_Hub_API();

    foreach ($repos as $repo_name) {
      $latest_release = $api->get_latest_release($repo_name);
      $is_installed = $api->is_plugin_installed($repo_name);
      $is_active = $api->is_plugin_active($repo_name);
      $is_disabled = $api->is_plugin_disabled($repo_name);
      $update_available = $api->is_update_available($repo_name, $latest_release);
      $is_beta = $latest_release && version_compare(ltrim($latest_release->tag_name, 'v'), '1.0.0', '<');

      if (!$show_beta && $is_beta) {
        continue;
      }

      $counts['all']++;

      if ($is_active) {
        $counts['active']++;
      } elseif ($is_installed && !$is_disabled) {
        $counts['inactive']++;
      } elseif ($is_disabled) {
        $counts['disabled']++;
      }

      if ($update_available) {
        $counts['update']++;
      }
    }

    return $counts;
  }
}
