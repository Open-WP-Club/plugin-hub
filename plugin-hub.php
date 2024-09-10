<?php

/**
 * Plugin Name: Plugin Hub
 * Description: Manages WordPress plugins from GitHub repositories, focusing on Open-WP-Club
 * Version: 1.4
 * Author: Your Name
 * Author URI: http://yourwebsite.com/
 * License: GPL2
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
