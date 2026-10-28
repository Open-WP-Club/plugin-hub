<?php
/**
 * Zip backups of managed plugins, created before an update.
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
 * Backups class.
 *
 * Archives live in `uploads/plugin-hub-backups/<repo>/<version>_<timestamp>.zip`
 * behind an index.php and an .htaccess deny rule. Every zip has a single
 * top-level `<repo>/` folder so it can be extracted straight into the plugins
 * directory.
 *
 * @since 1.8.0
 */
class Backups {

	/**
	 * Pattern for a backup id (`<repo>__<filename>`).
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const ID_PATTERN = '/^[A-Za-z0-9_-]+__[A-Za-z0-9._-]+\.zip$/D';

	/**
	 * Pattern for a repository (plugin directory) name.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const REPO_PATTERN = '/^[A-Za-z0-9_-]+$/D';

	/**
	 * Directory that holds the backups.
	 *
	 * @since 1.8.0
	 * @return string Normalized absolute path without trailing slash.
	 */
	public static function get_dir() {
		$uploads = wp_upload_dir( null, false );
		$base    = ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) )
			? $uploads['basedir']
			: ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : sys_get_temp_dir() );

		return rtrim( wp_normalize_path( $base ), '/' ) . '/plugin-hub-backups';
	}

	/**
	 * Directory that holds the MU plugin backups (managed by MU_Plugins).
	 *
	 * @since  1.8.0
	 * @access private
	 * @return string
	 */
	private static function get_mu_dir() {
		return dirname( self::get_dir() ) . '/plugin-hub-mu-backups';
	}

	/**
	 * Create the backup directory with web access guards.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  string $dir Directory to create.
	 * @return bool        True when the directory exists and is guarded.
	 */
	private static function ensure_dir( $dir ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$root = self::get_dir();
		if ( ! is_file( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a guard file in our own folder.
		}
		if ( ! is_file( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a guard file in our own folder.
		}

		return true;
	}

	/**
	 * Read the plugin version from the first bytes of the main plugin file.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  string $plugin_path Absolute path of the main plugin file.
	 * @return string              Filename-safe version, or "0".
	 */
	private static function read_version( $plugin_path ) {
		$head = is_file( $plugin_path ) ? file_get_contents( $plugin_path, false, null, 0, 8192 ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local filesystem read.
		if ( is_string( $head ) && preg_match( '/^[ \t\/*#@]*Version:[ \t]*(\S+)/mi', $head, $match ) ) {
			$version = trim( (string) preg_replace( '/[^A-Za-z0-9.\-]+/', '-', $match[1] ), '-.' );
			if ( '' !== $version ) {
				return $version;
			}
		}

		return '0';
	}

	/**
	 * Archive the current plugin directory before it is replaced.
	 *
	 * @since  1.8.0
	 * @param  string $repo        Repository name (also the plugin directory).
	 * @param  string $plugin_file Plugin basename (`dir/file.php`).
	 * @return string|\WP_Error|false Backup id, WP_Error on failure, or false when backups are off or unavailable.
	 */
	public static function create( $repo, $plugin_file ) {
		if ( ! Settings::get( 'backup_enabled' ) || ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		if ( ! is_string( $repo ) || ! preg_match( self::REPO_PATTERN, $repo ) || dirname( (string) $plugin_file ) !== $repo ) {
			return new \WP_Error( 'plugin_hub_backup_invalid', __( 'Invalid plugin for backup.', 'plugin-hub' ) );
		}

		$source = untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) . '/' . $repo;
		if ( ! is_dir( $source ) ) {
			return new \WP_Error( 'plugin_hub_backup_missing', __( 'The plugin directory does not exist.', 'plugin-hub' ) );
		}

		$target_dir = self::get_dir() . '/' . $repo;
		if ( ! self::ensure_dir( $target_dir ) ) {
			return new \WP_Error( 'plugin_hub_backup_dir', __( 'The backup directory could not be created.', 'plugin-hub' ) );
		}

		$version   = self::read_version( $source . '/' . basename( $plugin_file ) );
		$timestamp = time();
		do {
			$filename = $version . '_' . $timestamp . '.zip';
			$path     = $target_dir . '/' . $filename;
			++$timestamp;
		} while ( file_exists( $path ) );

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'plugin_hub_backup_zip', __( 'The backup archive could not be created.', 'plugin-hub' ) );
		}

		$zip->addEmptyDir( $repo );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isLink() ) {
				continue;
			}
			$local = $repo . '/' . ltrim( substr( wp_normalize_path( $item->getPathname() ), strlen( $source ) ), '/' );
			if ( $item->isDir() ) {
				$zip->addEmptyDir( $local );
			} elseif ( $item->isFile() ) {
				$zip->addFile( $item->getPathname(), $local );
			}
		}

		if ( ! $zip->close() || ! is_file( $path ) ) {
			wp_delete_file( $path );
			return new \WP_Error( 'plugin_hub_backup_write', __( 'The backup archive could not be written.', 'plugin-hub' ) );
		}

		self::prune();

		return $repo . '__' . $filename;
	}

	/**
	 * List every backup, newest first.
	 *
	 * @since  1.8.0
	 * @return array Rows with id, repo, version, created, size and path.
	 */
	public static function all() {
		$rows  = array();
		$files = glob( self::get_dir() . '/*/*.zip' );

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$file     = wp_normalize_path( $file );
			$filename = basename( $file );
			$repo     = basename( dirname( $file ) );
			$id       = $repo . '__' . $filename;

			if ( ! preg_match( self::REPO_PATTERN, $repo ) || ! preg_match( self::ID_PATTERN, $id ) || ! is_file( $file ) ) {
				continue;
			}

			$version = '';
			$created = 0;
			if ( preg_match( '/^(.+)_(\d+)\.zip$/D', $filename, $match ) ) {
				$version = $match[1];
				$created = (int) $match[2];
			}

			$rows[] = array(
				'id'      => $id,
				'repo'    => $repo,
				'version' => $version,
				'created' => $created ? $created : (int) filemtime( $file ),
				'size'    => (int) filesize( $file ),
				'path'    => $file,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['created'] === $b['created'] ) {
					return strcmp( $b['id'], $a['id'] );
				}
				return $b['created'] <=> $a['created'];
			}
		);

		return $rows;
	}

	/**
	 * Find one backup by id.
	 *
	 * @since  1.8.0
	 * @access private
	 * @param  string $id Backup id.
	 * @return array|null
	 */
	private static function find( $id ) {
		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}

		foreach ( self::all() as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Newest backup of a plugin.
	 *
	 * @since  1.8.0
	 * @param  string $repo Repository name.
	 * @return array|null
	 */
	public static function latest_for( $repo ) {
		foreach ( self::all() as $row ) {
			if ( $row['repo'] === $repo ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Replace the installed plugin directory with a backup.
	 *
	 * The current directory is moved aside first and put back if extraction
	 * fails, so a failed restore never leaves the plugin missing.
	 *
	 * @since  1.8.0
	 * @param  string $id Backup id.
	 * @return true|\WP_Error
	 */
	public static function restore( $id ) {
		$row = self::find( $id );
		if ( ! $row ) {
			return new \WP_Error( 'plugin_hub_backup_unknown', __( 'The backup was not found.', 'plugin-hub' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error( 'plugin_hub_backup_nozip', __( 'The PHP zip extension is required to restore backups.', 'plugin-hub' ) );
		}

		$repo = $row['repo'];
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $row['path'], \ZipArchive::CHECKCONS ) ) {
			return new \WP_Error( 'plugin_hub_backup_corrupt', __( 'The backup archive is damaged.', 'plugin-hub' ) );
		}
		for ( $i = 0, $count = $zip->count(); $i < $count; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( 0 !== strpos( $name, $repo . '/' ) || false !== strpos( $name, '..' ) || '/' === ( $name[0] ?? '' ) ) {
				$zip->close();
				return new \WP_Error( 'plugin_hub_backup_unsafe', __( 'The backup archive contains unexpected paths.', 'plugin-hub' ) );
			}
		}
		$zip->close();

		if ( ! function_exists( 'WP_Filesystem' ) || ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! $wp_filesystem ) {
			return new \WP_Error( 'plugin_hub_backup_fs', __( 'The filesystem could not be initialised.', 'plugin-hub' ) );
		}

		$plugins_dir = untrailingslashit( $wp_filesystem->wp_plugins_dir() );
		$target      = $plugins_dir . '/' . $repo;
		$old         = $plugins_dir . '/' . $repo . '.hub-old-' . time();
		$moved       = false;

		if ( $wp_filesystem->exists( $target ) ) {
			if ( ! $wp_filesystem->move( $target, $old, true ) ) {
				return new \WP_Error( 'plugin_hub_backup_move', __( 'The current plugin directory could not be moved aside.', 'plugin-hub' ) );
			}
			$moved = true;
		}

		$result = unzip_file( $row['path'], $plugins_dir );
		if ( ! is_wp_error( $result ) && ! $wp_filesystem->is_dir( $target ) ) {
			$result = new \WP_Error( 'plugin_hub_backup_extract', __( 'The backup did not contain the plugin directory.', 'plugin-hub' ) );
		}

		if ( is_wp_error( $result ) ) {
			if ( $wp_filesystem->exists( $target ) ) {
				$wp_filesystem->delete( $target, true );
			}
			if ( $moved ) {
				$wp_filesystem->move( $old, $target, true );
			}
			return $result;
		}

		if ( $moved ) {
			$wp_filesystem->delete( $old, true );
		}
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache();
		}

		return true;
	}

	/**
	 * Delete backups by id.
	 *
	 * @since  1.8.0
	 * @param  array $ids Backup ids.
	 * @return int        Number of deleted backups.
	 */
	public static function delete( $ids ) {
		$deleted = 0;

		foreach ( (array) $ids as $id ) {
			$row = self::find( $id );
			if ( ! $row ) {
				continue;
			}
			wp_delete_file( $row['path'] );
			if ( ! is_file( $row['path'] ) ) {
				++$deleted;
				$dir = dirname( $row['path'] );
				if ( array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
					rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove our own empty folder.
				}
			}
		}

		return $deleted;
	}

	/**
	 * Apply the retention settings (count per plugin and age).
	 *
	 * The newest backup of each plugin is always kept.
	 *
	 * @since  1.8.0
	 * @return int Number of deleted backups.
	 */
	public static function prune() {
		$keep    = max( 1, (int) Settings::get( 'backup_keep' ) );
		$days    = max( 0, (int) Settings::get( 'backup_days' ) );
		$cutoff  = $days > 0 ? time() - ( $days * DAY_IN_SECONDS ) : 0;
		$seen    = array();
		$expired = array();

		foreach ( self::all() as $row ) {
			$position = $seen[ $row['repo'] ] ?? 0;
			if ( $position >= $keep || ( $position > 0 && $cutoff > 0 && $row['created'] < $cutoff ) ) {
				$expired[] = $row['id'];
			}
			$seen[ $row['repo'] ] = $position + 1;
		}

		return $expired ? self::delete( $expired ) : 0;
	}

	/**
	 * Disk space used by backups.
	 *
	 * @since  1.8.0
	 * @return array Bytes for plugins, mu and total, plus the plugin backup count.
	 */
	public static function usage() {
		$plugins = 0;
		$rows    = self::all();
		foreach ( $rows as $row ) {
			$plugins += $row['size'];
		}

		$mu    = 0;
		$files = glob( self::get_mu_dir() . '/*' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( is_file( $file ) ) {
				$mu += (int) filesize( $file );
			}
		}

		return array(
			'plugins' => $plugins,
			'mu'      => $mu,
			'total'   => $plugins + $mu,
			'count'   => count( $rows ),
		);
	}

	/**
	 * Human readable size.
	 *
	 * @since  1.8.0
	 * @param  int $bytes Size in bytes.
	 * @return string
	 */
	public static function format_size( $bytes ) {
		$formatted = size_format( max( 0, (int) $bytes ), 1 );
		return false === $formatted ? '0 B' : (string) $formatted;
	}
}
