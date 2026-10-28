<?php
/**
 * Runs plugin-hub.php or uninstall.php in a clean process with a recording
 * WordPress stub and prints what happened as JSON.
 *
 * Usage: php runner.php <load|load-cli|uninstall> [uploads-dir]
 *
 * @package PluginHub
 */

// phpcs:disable

$scenario = $argv[1] ?? 'load';
$uploads  = $argv[2] ?? sys_get_temp_dir();
$root     = dirname( __DIR__, 2 );
$rec      = array(
	'actions'   => array(),
	'filters'   => array(),
	'commands'  => array(),
	'options'   => array(),
	'deleted'   => array(),
	'scheduled' => array(),
	'cleared'   => array(),
	'hooks'     => array(),
	'queries'   => array(),
	'user_meta' => array(),
	'uncallable' => array(),
);
$GLOBALS['rec'] = &$rec;
$store          = array();

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINC', 'wp-includes' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

function plugin_dir_path( $file ) {
	return rtrim( dirname( $file ), '/' ) . '/';
}
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/plugin-hub/';
}
function plugin_basename( $file ) {
	return 'plugin-hub/plugin-hub.php';
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	if ( ! is_callable( $callback ) ) {
		$GLOBALS['rec']['uncallable'][] = $hook . '|' . hub_name( $callback );
	}
	$GLOBALS['rec']['actions'][] = $hook . '|' . hub_name( $callback );
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	if ( ! is_callable( $callback ) ) {
		$GLOBALS['rec']['uncallable'][] = $hook . '|' . hub_name( $callback );
	}
	$GLOBALS['rec']['filters'][] = $hook . '|' . hub_name( $callback ) . '|' . $priority . '|' . $args;
}
function hub_name( $callback ) {
	if ( is_array( $callback ) ) {
		return ( is_object( $callback[0] ) ? get_class( $callback[0] ) : $callback[0] ) . '::' . $callback[1];
	}
	return (string) $callback;
}
function register_activation_hook( $file, $callback ) {
	$GLOBALS['rec']['hooks']['activation'] = $callback;
}
function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['rec']['hooks']['deactivation'] = $callback;
}
function get_option( $key, $default = false ) {
	global $store;
	return $store[ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	global $store;
	$store[ $key ]                       = $value;
	$GLOBALS['rec']['options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	$GLOBALS['rec']['deleted'][] = 'option:' . $key;
	return true;
}
function delete_transient( $key ) {
	$GLOBALS['rec']['deleted'][] = 'transient:' . $key;
	return true;
}
function delete_metadata( $type, $id, $key, $value, $all ) {
	$GLOBALS['rec']['user_meta'][] = array( $type, $id, $key, $all );
	return true;
}
function wp_next_scheduled( $hook ) {
	return $GLOBALS['rec']['next_scheduled'] ?? false;
}
function wp_schedule_event( $time, $recurrence, $hook ) {
	$GLOBALS['rec']['scheduled'][] = array( $recurrence, $hook, $time > time() );
	return true;
}
function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['rec']['cleared'][] = $hook;
}
function load_plugin_textdomain( $domain, $deprecated, $path ) {
	$GLOBALS['rec']['textdomain'] = array( $domain, $path );
	return true;
}
function wp_upload_dir( $time = null, $create = true ) {
	return array( 'basedir' => $GLOBALS['uploads'] );
}
function wp_delete_file( $file ) {
	if ( is_file( $file ) ) {
		unlink( $file );
	}
}
$GLOBALS['uploads'] = $uploads;

if ( 'load-cli' === $scenario ) {
	define( 'WP_CLI', true );
	class WP_CLI {
		public static function add_command( $name, $command, $args = array() ) {
			$GLOBALS['rec']['commands'][] = $name . '|' . get_class( $command );
		}
	}
}

if ( 'uninstall' === $scenario ) {
	define( 'WP_UNINSTALL_PLUGIN', 'plugin-hub/plugin-hub.php' );
	$wpdb = new class() {
		public $options = 'wp_options';
		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}
		public function prepare( $query, ...$args ) {
			return array( $query, $args );
		}
		public function query( $prepared ) {
			$GLOBALS['rec']['queries'][] = $prepared;
		}
	};
	require $root . '/uninstall.php';
} else {
	require $root . '/plugin-hub.php';

	$rec['constants'] = array(
		'version' => PLUGIN_HUB_VERSION,
		'org'     => PLUGIN_HUB_ORGANIZATION,
		'dir'     => PLUGIN_HUB_PLUGIN_DIR === $root . '/',
	);

	// Exercise the helpers and lifecycle callbacks the file defines.
	$rec['token_empty']   = PluginHub\get_github_token();
	$store['plugin_hub_github_token'] = '  ghp_stored  ';
	$rec['token_stored']  = PluginHub\get_github_token();
	$rec['managed_before'] = PluginHub\is_github_token_managed_by_config();
	define( 'PLUGIN_HUB_GITHUB_TOKEN', ' ghp_config ' );
	$rec['token_config']   = PluginHub\get_github_token();
	$rec['managed_after']  = PluginHub\is_github_token_managed_by_config();

	$rec['scheduled'] = array();
	PluginHub\activate_plugin_hub();
	$rec['activate_first'] = count( $rec['scheduled'] );
	$GLOBALS['rec']['next_scheduled'] = 12345;
	PluginHub\activate_plugin_hub();
	$rec['activate_second'] = count( $rec['scheduled'] );

	$rec['scheduled'] = array();
	$rec['cleared']   = array();
	PluginHub\maybe_upgrade();
	$rec['upgrade_first'] = array( 'scheduled' => count( $rec['scheduled'] ), 'cleared' => count( $rec['cleared'] ), 'version' => $store['plugin_hub_db_version'] ?? null );
	PluginHub\maybe_upgrade();
	$rec['upgrade_second'] = array( 'scheduled' => count( $rec['scheduled'] ), 'cleared' => count( $rec['cleared'] ) );

	$rec['cleared'] = array();
	PluginHub\deactivate_plugin_hub();
	$rec['deactivate_cleared'] = $rec['cleared'];

	// A second Main registers everything again; keep the first load's records.
	$snapshot = array( $rec['actions'], $rec['filters'], $rec['commands'] );
	( new PluginHub\Main() )->load_textdomain();
	list( $rec['actions'], $rec['filters'], $rec['commands'] ) = $snapshot;

	$rec['registered'] = array(
		'activation'   => $rec['hooks']['activation'] ?? null,
		'deactivation' => $rec['hooks']['deactivation'] ?? null,
	);
}

echo json_encode( $rec );
