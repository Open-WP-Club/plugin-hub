<?php
/**
 * WP-CLI commands.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.8.0
 */

namespace PluginHub;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Open WP Club plugins from the command line.
 *
 * @since 1.8.0
 */
class CLI {

	/**
	 * The API instance.
	 *
	 * @var API
	 */
	private $api;

	/**
	 * The MU plugin manager.
	 *
	 * @var MU_Plugins
	 */
	private $mu;

	/**
	 * Constructor.
	 *
	 * @since 1.8.0
	 * @param API        $api API instance.
	 * @param MU_Plugins $mu  MU plugin manager.
	 */
	public function __construct( API $api, MU_Plugins $mu ) {
		$this->api = $api;
		$this->mu  = $mu;
	}

	/**
	 * List catalog plugins.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by status.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - active
	 *   - inactive
	 *   - installed
	 *   - available
	 *   - update
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated fields to show.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp plugin-hub list --status=update
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_plugins( $args, $assoc_args ) {
		unset( $args );
		$status = $assoc_args['status'] ?? 'all';
		$fields = isset( $assoc_args['fields'] ) ? explode( ',', $assoc_args['fields'] ) : array( 'name', 'status', 'version', 'latest', 'update', 'autoupdate', 'pin' );
		$rows   = array();

		$autoupdate = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$autoupdate = is_array( $autoupdate ) ? $autoupdate : array();

		foreach ( $this->api->get_org_repos() as $repo ) {
			$installed = $this->api->is_plugin_installed( $repo['name'] );
			$active    = $installed && $this->api->is_plugin_active( $repo['name'] );
			$version   = $installed ? $this->api->get_installed_plugin_version( $repo['name'] ) : '';
			$update    = $installed && $this->api->is_update_available( $repo, $version );

			if (
				( 'active' === $status && ! $active ) ||
				( 'inactive' === $status && ( ! $installed || $active ) ) ||
				( 'installed' === $status && ! $installed ) ||
				( 'available' === $status && ( $installed || empty( $repo['available'] ) ) ) ||
				( 'update' === $status && ! $update )
			) {
				continue;
			}

			$rows[] = array(
				'name'       => $repo['name'],
				'status'     => $active ? 'active' : ( $installed ? 'inactive' : 'not installed' ),
				'version'    => $version,
				'latest'     => $repo['version'],
				'update'     => $update ? 'available' : 'none',
				'autoupdate' => in_array( $repo['name'], $autoupdate, true ) ? 'on' : 'off',
				'pin'        => $this->api->get_pin( $repo['name'] ),
			);
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, $fields );
	}

	/**
	 * Install one or more catalog plugins at their current catalog version.
	 *
	 * ## OPTIONS
	 *
	 * <repo>...
	 * : Repository names.
	 *
	 * [--activate]
	 * : Activate after installing.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function install( $args, $assoc_args ) {
		$failed = 0;
		foreach ( $args as $name ) {
			$version = $this->catalog_version( $name );
			if ( '' === $version ) {
				\WP_CLI::warning( "$name: not in the catalog or has no release." );
				++$failed;
				continue;
			}
			$result = $this->api->install_plugin( $name, $version, true );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( "$name: " . $result->get_error_message() );
				++$failed;
				continue;
			}
			\WP_CLI::success( "Installed $name $version." );
			if ( ! empty( $assoc_args['activate'] ) ) {
				$this->report( $this->api->activate_plugin_by_repo( $name ), "Activated $name." );
			}
		}
		$this->finish( $failed );
	}

	/**
	 * Update plugins to their current catalog version.
	 *
	 * ## OPTIONS
	 *
	 * [<repo>...]
	 * : Repository names.
	 *
	 * [--all]
	 * : Update every plugin with an available update.
	 *
	 * [--dry-run]
	 * : Only show what would be updated.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function update( $args, $assoc_args ) {
		$all     = ! empty( $assoc_args['all'] );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$names   = $args;

		if ( $all ) {
			$names = array();
			foreach ( $this->api->get_org_repos() as $repo ) {
				if ( $this->api->is_plugin_installed( $repo['name'] ) ) {
					$names[] = $repo['name'];
				}
			}
		} elseif ( empty( $names ) ) {
			\WP_CLI::error( 'Specify one or more plugins or use --all.' );
		}

		$failed  = 0;
		$updated = 0;
		$pending = 0;
		foreach ( $names as $name ) {
			$repo      = $this->catalog_repo( $name );
			$installed = $repo ? $this->api->get_installed_plugin_version( $name ) : '';
			if ( ! $repo || ! $this->api->is_plugin_installed( $name ) ) {
				\WP_CLI::warning( "$name: not an installed catalog plugin." );
				++$failed;
				continue;
			}
			if ( ! $this->api->is_update_available( $repo, $installed ) ) {
				if ( ! $all ) {
					\WP_CLI::log( "$name is up to date ($installed)." );
				}
				continue;
			}
			if ( ! $this->api->is_update_allowed( $repo, $installed ) ) {
				\WP_CLI::warning( "$name: update to {$repo['version']} is blocked by a pin or a previously failed version." );
				continue;
			}
			++$pending;
			if ( $dry_run ) {
				\WP_CLI::log( "Would update $name $installed -> {$repo['version']}." );
				continue;
			}
			$result = $this->api->update_plugin( $name, $repo['version'], false, true );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( "$name: " . $result->get_error_message() );
				++$failed;
				continue;
			}
			++$updated;
			\WP_CLI::success( "Updated $name to {$repo['version']}." );
		}

		if ( $all && 0 === $pending && 0 === $failed ) {
			\WP_CLI::success( 'Everything is up to date.' );
		}
		$this->finish( $failed );
	}

	/**
	 * Install an older published release of a plugin.
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository name.
	 *
	 * <version>
	 * : Version to install.
	 *
	 * @param array $args Positional arguments.
	 */
	public function rollback( $args ) {
		list( $name, $version ) = $args;
		$this->report( $this->api->update_plugin( $name, $version, true, true ), "Rolled $name back to $version." );
	}

	/**
	 * List local plugin backups.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function backups( $args, $assoc_args ) {
		unset( $args );
		$rows = array();
		foreach ( Backups::all() as $backup ) {
			$rows[] = array(
				'id'      => $backup['id'],
				'repo'    => $backup['repo'],
				'version' => $backup['version'],
				'created' => gmdate( 'Y-m-d H:i:s', (int) $backup['created'] ),
				'size'    => Backups::format_size( (int) $backup['size'] ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'repo', 'version', 'created', 'size' ) );
	}

	/**
	 * Restore a plugin from a local backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id (see `wp plugin-hub backups`).
	 *
	 * @subcommand restore-backup
	 * @param array $args Positional arguments.
	 */
	public function restore_backup( $args ) {
		$this->report( Backups::restore( $args[0] ), 'Backup restored.' );
	}

	/**
	 * Activate plugins.
	 *
	 * ## OPTIONS
	 *
	 * <repo>...
	 * : Repository names.
	 *
	 * [--network]
	 * : Network activate (multisite).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function activate( $args, $assoc_args ) {
		$failed = 0;
		foreach ( $args as $name ) {
			$failed += $this->report( $this->api->activate_plugin_by_repo( $name, ! empty( $assoc_args['network'] ) ), "Activated $name.", false ) ? 0 : 1;
		}
		$this->finish( $failed );
	}

	/**
	 * Deactivate plugins.
	 *
	 * ## OPTIONS
	 *
	 * <repo>...
	 * : Repository names.
	 *
	 * [--network]
	 * : Network deactivate (multisite).
	 *
	 * [--force]
	 * : Deactivate even if other active plugins depend on it.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function deactivate( $args, $assoc_args ) {
		$failed = 0;
		foreach ( $args as $name ) {
			$result  = $this->api->deactivate_plugin_by_repo( $name, ! empty( $assoc_args['network'] ), ! empty( $assoc_args['force'] ) );
			$failed += $this->report( $result, "Deactivated $name.", false ) ? 0 : 1;
		}
		$this->finish( $failed );
	}

	/**
	 * Delete inactive plugins.
	 *
	 * ## OPTIONS
	 *
	 * <repo>...
	 * : Repository names.
	 *
	 * [--force]
	 * : Delete even if other plugins depend on it.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		$failed = 0;
		foreach ( $args as $name ) {
			$result  = $this->api->delete_plugin_by_repo( $name, ! empty( $assoc_args['force'] ) );
			$failed += $this->report( $result, "Deleted $name.", false ) ? 0 : 1;
		}
		$this->finish( $failed );
	}

	/**
	 * Show, set or clear the version pin of a plugin.
	 *
	 * Pins: `1.2.3` (hold), `1.x` (stay on major), `1.2.x` (stay on minor).
	 *
	 * ## OPTIONS
	 *
	 * <repo>
	 * : Repository name.
	 *
	 * [<pin>]
	 * : Pin to set. Omit to show the current pin.
	 *
	 * [--clear]
	 * : Remove the pin.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function pin( $args, $assoc_args ) {
		$name = $args[0];
		if ( ! empty( $assoc_args['clear'] ) ) {
			$this->report( $this->api->set_pin( $name, '' ), "Pin removed from $name." );
			return;
		}
		if ( ! isset( $args[1] ) ) {
			$pin = $this->api->get_pin( $name );
			\WP_CLI::log( '' === $pin ? "$name is not pinned." : "$name is pinned to $pin." );
			return;
		}
		$this->report( $this->api->set_pin( $name, $args[1] ), "Pinned $name to {$args[1]}." );
	}

	/**
	 * Refresh the Open WP Club catalog and the MU plugin catalog.
	 *
	 * @param array $args Positional arguments.
	 */
	public function refresh( $args ) {
		unset( $args );
		$repos = $this->api->refresh_csv_cache();
		$this->mu->refresh_catalog();
		\WP_CLI::success( sprintf( 'Catalog refreshed (%d plugins).', count( $repos ) ) );
	}

	/**
	 * Manage must-use plugins.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, install, update or rollback.
	 * ---
	 * options:
	 *   - list
	 *   - install
	 *   - update
	 *   - rollback
	 * ---
	 *
	 * [<file>...]
	 * : MU plugin file names. For `update`, omit to update all.
	 *
	 * [--format=<format>]
	 * : Output format for `list`.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function mu( $args, $assoc_args ) {
		$action = array_shift( $args );

		if ( 'list' === $action ) {
			$rows = array();
			foreach ( $this->mu->get_installed( true ) as $row ) {
				$rows[] = array(
					'file'       => $row['file'],
					'category'   => $row['category'],
					'version'    => $row['local_version'],
					'latest'     => $row['remote_version'],
					'update'     => $row['update'] ? 'available' : 'none',
					'autoupdate' => $row['autoupdate'] ? 'on' : 'off',
				);
			}
			\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'file', 'category', 'version', 'latest', 'update', 'autoupdate' ) );
			return;
		}

		if ( 'update' === $action && empty( $args ) ) {
			$result = $this->mu->update_all();
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			foreach ( $result['updated'] as $file => $version ) {
				\WP_CLI::success( "Updated $file to $version." );
			}
			foreach ( $result['failed'] as $file => $message ) {
				\WP_CLI::warning( "$file: $message" );
			}
			if ( empty( $result['updated'] ) && empty( $result['failed'] ) ) {
				\WP_CLI::success( 'All MU plugins are up to date.' );
			}
			$this->finish( count( $result['failed'] ) );
			return;
		}

		if ( ! in_array( $action, array( 'install', 'update', 'rollback' ), true ) || empty( $args ) ) {
			\WP_CLI::error( 'Usage: wp plugin-hub mu <list|install|update|rollback> [<file>...]' );
		}

		$failed = 0;
		foreach ( $args as $file ) {
			$result = $this->mu->$action( $file );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( "$file: " . $result->get_error_message() );
				++$failed;
				continue;
			}
			\WP_CLI::success( "$file: $action done (version $result)." );
		}
		$this->finish( $failed );
	}

	/**
	 * Export the configuration (plugins, pins, auto-updates, settings) as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to a file instead of stdout.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function export( $args, $assoc_args ) {
		unset( $args );
		$json = Config::to_json( Config::export( $this->api, $this->mu ) );

		if ( ! empty( $assoc_args['file'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI file output.
			if ( false === file_put_contents( $assoc_args['file'], $json ) ) {
				\WP_CLI::error( 'Could not write ' . $assoc_args['file'] );
			}
			\WP_CLI::success( 'Exported to ' . $assoc_args['file'] );
			return;
		}

		\WP_CLI::line( $json );
	}

	/**
	 * Import a configuration file created by `export`.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file.
	 *
	 * [--skip-settings]
	 * : Do not import the Plugin Hub settings.
	 *
	 * [--install-missing]
	 * : Install catalog plugins that are in the file but not on this site.
	 *
	 * [--dry-run]
	 * : Validate and list what would change without applying anything.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( $args, $assoc_args ) {
		if ( ! is_readable( $args[0] ) ) {
			\WP_CLI::error( 'Cannot read ' . $args[0] );
		}
		$json = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CLI file.
		$data = Config::validate( (string) $json );
		if ( is_wp_error( $data ) ) {
			\WP_CLI::error( $data->get_error_message() );
		}

		if ( ! empty( $assoc_args['dry-run'] ) ) {
			\WP_CLI::success( sprintf( 'Valid configuration: %d plugins, %d MU plugins.', count( $data['plugins'] ?? array() ), count( $data['mu'] ?? array() ) ) );
			return;
		}

		$result = Config::apply( $data, $this->api, $this->mu, array( 'skip_settings' => ! empty( $assoc_args['skip-settings'] ) ) );
		\WP_CLI::success( 'Configuration applied.' );

		$failed = 0;
		foreach ( $result['missing'] as $item ) {
			if ( empty( $assoc_args['install-missing'] ) ) {
				\WP_CLI::log( "Not installed: {$item['repo']} {$item['version']}" );
				continue;
			}
			$installed = $this->api->install_plugin( $item['repo'], $item['version'], true );
			if ( is_wp_error( $installed ) ) {
				\WP_CLI::warning( "{$item['repo']}: " . $installed->get_error_message() );
				++$failed;
				continue;
			}
			\WP_CLI::success( "Installed {$item['repo']} {$item['version']}." );
			if ( ! empty( $item['active'] ) ) {
				$this->api->activate_plugin_by_repo( $item['repo'] );
			}
		}
		foreach ( $result['mu_missing'] as $file ) {
			\WP_CLI::log( "MU plugin not installed: $file" );
		}
		$this->finish( $failed );
	}

	/**
	 * Get or set Plugin Hub settings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : get or set.
	 * ---
	 * options:
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<key>]
	 * : Setting key.
	 *
	 * [<value>]
	 * : New value. Lists (window_days) are comma-separated; booleans accept 1/0/true/false.
	 *
	 * @param array $args Positional arguments.
	 */
	public function settings( $args ) {
		$action = $args[0];
		$key    = $args[1] ?? '';
		$all    = Settings::all();

		if ( 'get' === $action ) {
			if ( '' === $key ) {
				$rows = array();
				foreach ( $all as $name => $value ) {
					$rows[] = array(
						'key'   => $name,
						'value' => is_array( $value ) ? implode( ',', $value ) : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ),
					);
				}
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
				return;
			}
			if ( ! array_key_exists( $key, $all ) ) {
				\WP_CLI::error( "Unknown setting: $key" );
			}
			$value = $all[ $key ];
			\WP_CLI::line( is_array( $value ) ? implode( ',', $value ) : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ) );
			return;
		}

		if ( '' === $key || ! array_key_exists( $key, $all ) || ! isset( $args[2] ) ) {
			\WP_CLI::error( 'Usage: wp plugin-hub settings set <key> <value>' );
		}

		$raw = $args[2];
		if ( is_array( $all[ $key ] ) ) {
			$value = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn ( $item ) => '' !== $item ) );
		} elseif ( is_bool( $all[ $key ] ) ) {
			$value = filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
		} else {
			$value = $raw;
		}

		$saved = Settings::save( array( $key => $value ) );
		$shown = $saved[ $key ];
		\WP_CLI::success( "$key = " . ( is_array( $shown ) ? implode( ',', $shown ) : ( is_bool( $shown ) ? ( $shown ? 'true' : 'false' ) : (string) $shown ) ) );
	}

	/**
	 * Run the scheduled update check now (auto-updates, notifications, pruning).
	 *
	 * @subcommand run-schedule
	 * @param array $args Positional arguments.
	 */
	public function run_schedule( $args ) {
		unset( $args );
		$this->api->do_auto_updates_and_notify();
		\WP_CLI::success( 'Scheduled check finished.' );
	}

	/**
	 * Find a catalog entry by repository name.
	 *
	 * @param string $name Repository name.
	 * @return array|null
	 */
	private function catalog_repo( $name ) {
		foreach ( $this->api->get_org_repos() as $repo ) {
			if ( 0 === strcasecmp( $repo['name'], $name ) ) {
				return $repo;
			}
		}
		return null;
	}

	/**
	 * Current catalog version of an installable plugin, or an empty string.
	 *
	 * @param string $name Repository name.
	 * @return string
	 */
	private function catalog_version( $name ) {
		$repo = $this->catalog_repo( $name );
		return ( $repo && ! empty( $repo['available'] ) ) ? $repo['version'] : '';
	}

	/**
	 * Report a true|WP_Error result.
	 *
	 * @param true|\WP_Error $result  Result.
	 * @param string         $message Success message.
	 * @param bool           $fatal   Whether failure aborts the command.
	 * @return bool Whether it succeeded.
	 */
	private function report( $result, $message, $fatal = true ) {
		if ( is_wp_error( $result ) ) {
			if ( $fatal ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::warning( $result->get_error_message() );
			return false;
		}
		\WP_CLI::success( $message );
		return true;
	}

	/**
	 * Exit with an error when any item failed.
	 *
	 * @param int $failed Failure count.
	 */
	private function finish( $failed ) {
		if ( $failed > 0 ) {
			\WP_CLI::error( sprintf( '%d item(s) failed.', $failed ) );
		}
	}
}
