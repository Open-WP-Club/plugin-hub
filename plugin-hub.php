<?php

/**
 * Plugin Name:             Plugin Hub
 * Plugin URI:              https://github.com/Open-WP-Club/plugin-hub
 * Description:             Manages WordPress plugins from GitHub repositories, focusing on Open-WP-Club
 * Version:                 1.0.2
 * Author:                  Gabriel Kanev
 * Author URI:              https://gkanev.com
 * License:                 GPL-2.0 License
 * Requires at least:       6.0
 * Requires PHP:            7.4
 * Tested up to:            6.6.2
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

define('PLUGIN_HUB_VERSION', '1.4');
define('PLUGIN_HUB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PLUGIN_HUB_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include the main class.
require_once PLUGIN_HUB_PLUGIN_DIR . 'includes/class-main.php';

/**
 * Begins execution of the plugin.
 */
function run_plugin_hub()
{
    $plugin = new Plugin_Hub_Main();
    $plugin->run();
}
run_plugin_hub();
