<?php
/**
 * The core plugin class.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.0.0
 */

namespace PluginHub;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * Orchestrates the plugin functionality by loading dependencies
 * and defining hooks for the admin area and API.
 *
 * @since 1.0.0
 */
class Main {

	/**
	 * The admin instance.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    Admin
	 */
	private $admin;

	/**
	 * The API instance.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    API
	 */
	private $api;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_api_hooks();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-admin.php';
		require_once plugin_dir_path( __DIR__ ) . 'includes/class-api.php';

		$this->api   = new API();
		$this->admin = new Admin( $this->api );
	}

	/**
	 * Register all of the hooks related to the admin area functionality.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function define_admin_hooks() {
		add_action( 'admin_menu', array( $this->admin, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this->admin, 'handle_refresh_cache' ) );
	}

	/**
	 * Register all of the hooks related to the API functionality.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function define_api_hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this->api, 'check_for_plugin_updates' ) );
		add_action( 'wp_ajax_install_github_plugin', array( $this->api, 'ajax_install_github_plugin' ) );
		add_action( 'wp_ajax_activate_github_plugin', array( $this->api, 'ajax_activate_github_plugin' ) );
		add_action( 'wp_ajax_deactivate_github_plugin', array( $this->api, 'ajax_deactivate_github_plugin' ) );
		add_action( 'wp_ajax_update_github_plugin', array( $this->api, 'ajax_update_github_plugin' ) );
		add_action( 'wp_ajax_delete_github_plugin', array( $this->api, 'ajax_delete_github_plugin' ) );
		add_action( 'wp_ajax_disable_github_plugin', array( $this->api, 'ajax_disable_github_plugin' ) );
		add_action( 'wp_ajax_verify_plugin_update', array( $this->api, 'verify_plugin_update' ) );
		add_action( 'wp_ajax_force_refresh_plugins', array( $this->api, 'ajax_force_refresh_plugins' ) );
		add_action( 'wp_ajax_get_changelog', array( $this->api, 'ajax_get_changelog' ) );
		add_action( 'wp_ajax_toggle_beta_plugins', array( $this->admin, 'ajax_toggle_beta_plugins' ) );
	}

	/**
	 * Run the plugin.
	 *
	 * @since 1.0.0
	 */
	public function run() {
		// Future functionality can be added here.
	}
}
