<?php

class Plugin_Hub_Main
{
  private $admin;
  private $api;

  public function __construct()
  {
    $this->load_dependencies();
    $this->define_admin_hooks();
    $this->define_api_hooks();
  }

  private function load_dependencies()
  {
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-admin.php';
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-api.php';

    $this->admin = new Plugin_Hub_Admin();
    $this->api = new Plugin_Hub_API();
  }

  private function define_admin_hooks()
  {
    add_action('admin_menu', array($this->admin, 'add_admin_menu'));
    add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_styles'));
    add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_scripts'));
  }

  private function define_api_hooks()
  {
    add_filter('pre_set_site_transient_update_plugins', array($this->api, 'check_for_plugin_updates'));
    add_action('wp_ajax_install_github_plugin', array($this->api, 'ajax_install_github_plugin'));
    add_action('wp_ajax_activate_github_plugin', array($this->api, 'ajax_activate_github_plugin'));
    add_action('wp_ajax_deactivate_github_plugin', array($this->api, 'ajax_deactivate_github_plugin'));
    add_action('wp_ajax_update_github_plugin', array($this->api, 'ajax_update_github_plugin'));
    add_action('wp_ajax_disable_github_plugin', array($this->api, 'ajax_disable_github_plugin'));
  }

  public function run()
  {
    // Future functionality can be added here
  }
}
