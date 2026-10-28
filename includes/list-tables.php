<?php
/**
 * WP_List_Table implementations for every Plugin Hub table.
 *
 * Loaded lazily from the admin page because WP_List_Table is not available
 * on every request.
 *
 * @package    PluginHub
 * @subpackage PluginHub/includes
 * @since      1.8.0
 */

namespace PluginHub;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- The list table classes are loaded together on demand.

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Shared behaviour: request reading, sorting and pagination.
 *
 * @since 1.8.0
 */
abstract class Hub_List_Table extends \WP_List_Table {

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	protected $per_page = 25;

	/**
	 * Read a sanitized string from the query string (read-only view state).
	 *
	 * @param string $key     Query argument.
	 * @param string $fallback Default value.
	 * @return string
	 */
	protected function query_arg( $key, $fallback = '' ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
			return $fallback;
		}
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view state.
	}

	/**
	 * Sort key value of an item.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column key.
	 * @return int|string
	 */
	abstract protected function sort_value( $item, $orderby );

	/**
	 * Sort items by the requested sortable column.
	 *
	 * @param array  $items           Rows.
	 * @param string $default_orderby Default column key.
	 * @param string $default_order   asc|desc.
	 * @return array
	 */
	protected function sort_items( array $items, $default_orderby, $default_order = 'asc' ) {
		$allowed = array();
		foreach ( $this->get_sortable_columns() as $definition ) {
			$allowed[] = is_array( $definition ) ? $definition[0] : $definition;
		}

		$orderby = sanitize_key( $this->query_arg( 'orderby', $default_orderby ) );
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = $default_orderby;
		}
		$order = 'desc' === strtolower( $this->query_arg( 'order', $default_order ) ) ? 'desc' : 'asc';

		usort(
			$items,
			function ( $a, $b ) use ( $orderby, $order ) {
				$va = $this->sort_value( $a, $orderby );
				$vb = $this->sort_value( $b, $orderby );
				if ( is_int( $va ) && is_int( $vb ) ) {
					$cmp = $va <=> $vb;
				} else {
					$cmp = strnatcasecmp( (string) $va, (string) $vb );
				}
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);

		return $items;
	}

	/**
	 * Register pagination and return the current page slice.
	 *
	 * @param array $items All rows.
	 * @return array
	 */
	protected function paginate( array $items ) {
		$total = count( $items );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $this->per_page,
				'total_pages' => max( 1, (int) ceil( $total / $this->per_page ) ),
			)
		);
		$paged = $this->get_pagenum();

		return array_slice( $items, ( $paged - 1 ) * $this->per_page, $this->per_page );
	}

	/**
	 * Set the column header state without relying on screen options.
	 */
	protected function init_column_headers() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_default_primary_column_name() );
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Build a "views" link.
	 *
	 * @param string $url     Target URL.
	 * @param string $label   Label.
	 * @param int    $count   Count.
	 * @param bool   $current Whether it is the active view.
	 * @return string
	 */
	protected function view_link( $url, $label, $count, $current ) {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Standard table classes.
	 *
	 * @return string[]
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'fixed', 'striped', 'plugin-hub-table' );
	}

	/**
	 * Select-all/row checkbox markup.
	 *
	 * @param string $value Value.
	 * @param string $label Screen reader label.
	 * @return string
	 */
	protected function checkbox( $value, $label ) {
		return sprintf(
			'<input type="checkbox" name="checked[]" id="cb-select-%1$s" value="%2$s"><label for="cb-select-%1$s"><span class="screen-reader-text">%3$s</span></label>',
			esc_attr( md5( $value ) ),
			esc_attr( $value ),
			esc_html( $label )
		);
	}
}

/**
 * Catalog plugins table.
 *
 * @since 1.8.0
 */
class Plugins_List_Table extends Hub_List_Table {

	/**
	 * API instance.
	 *
	 * @var API
	 */
	private $api;

	/**
	 * Active view.
	 *
	 * @var string
	 */
	private $filter;

	/**
	 * Every visible row before filtering.
	 *
	 * @var array
	 */
	private $all_items = array();

	/**
	 * Constructor.
	 *
	 * @param API    $api    API instance.
	 * @param string $filter Active view.
	 */
	public function __construct( API $api, $filter = 'all' ) {
		parent::__construct(
			array(
				'singular' => 'plugin',
				'plural'   => 'plugins',
				'ajax'     => false,
			)
		);
		$this->api    = $api;
		$this->filter = $filter;
	}

	/**
	 * Table CSS classes.
	 *
	 * @return string[]
	 */
	protected function get_table_classes() {
		return array( 'widefat', 'plugins', 'plugin-hub-table' );
	}

	/**
	 * Build the unfiltered rows.
	 *
	 * @return array
	 */
	private function build_items() {
		$show_beta  = (bool) get_option( 'plugin_hub_show_beta', false );
		$autoupdate = get_option( 'plugin_hub_autoupdate_plugins', array() );
		$autoupdate = is_array( $autoupdate ) ? $autoupdate : array();
		$items      = array();

		foreach ( $this->api->get_org_repos() as $repo ) {
			$name      = $repo['name'];
			$installed = $this->api->is_plugin_installed( $name );
			$available = ! empty( $repo['available'] );
			$is_beta   = $available && version_compare( $repo['version'], '1.0.0', '<' );

			if ( ! $show_beta && $is_beta && ! $installed ) {
				continue;
			}

			$file           = $installed ? $this->api->get_plugin_file( $name ) : '';
			$version        = $installed ? $this->api->get_installed_plugin_version( $name ) : '';
			$network_active = $installed && is_multisite() && $file && is_plugin_active_for_network( $file );
			$dependents     = array();
			$missing        = array();

			if ( $installed ) {
				foreach ( $this->api->get_dependents( $name ) as $dependent ) {
					if ( ! empty( $dependent['active'] ) ) {
						$dependents[] = $dependent['name'];
					}
				}
				$missing = $this->api->get_missing_requirements( $name );
			}

			$items[] = array(
				'repo'             => $repo,
				'name'             => $name,
				'display_name'     => $repo['display_name'],
				'installed'        => $installed,
				'available'        => $available,
				'active'           => $installed && $this->api->is_plugin_active( $name ),
				'network_active'   => $network_active,
				'version'          => $version,
				'update_available' => $installed && $this->api->is_update_available( $repo, $version ),
				'update_allowed'   => $installed && $this->api->is_update_allowed( $repo, $version ),
				'beta'             => $is_beta,
				'autoupdate'       => in_array( $name, $autoupdate, true ),
				'pin'              => $installed ? $this->api->get_pin( $name ) : '',
				'dependents'       => $dependents,
				'missing'          => $missing,
			);
		}

		return $items;
	}

	/**
	 * Whether an item belongs to a view.
	 *
	 * @param array  $item Row.
	 * @param string $view View key.
	 * @return bool
	 */
	private function in_view( $item, $view ) {
		switch ( $view ) {
			case 'active':
				return $item['active'];
			case 'inactive':
				return $item['installed'] && ! $item['active'];
			case 'installed':
				return $item['installed'];
			case 'available':
				return ! $item['installed'];
			case 'update':
				return $item['update_allowed'];
			case 'beta':
				return $item['beta'];
			case 'pinned':
				return '' !== $item['pin'];
		}
		return true;
	}

	/**
	 * Prepare rows for display.
	 */
	public function prepare_items() {
		$this->init_column_headers();
		$this->all_items = $this->build_items();

		$search = strtolower( trim( $this->query_arg( 's' ) ) );
		$items  = array();
		foreach ( $this->all_items as $item ) {
			if ( ! $this->in_view( $item, $this->filter ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $item['display_name'], $search ) && false === stripos( $item['repo']['description'], $search ) && false === stripos( $item['name'], $search ) ) {
				continue;
			}
			$items[] = $item;
		}

		$this->items = $this->paginate( $this->sort_items( $items, 'name' ) );
	}

	/**
	 * Sort values.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return int|string
	 */
	protected function sort_value( $item, $orderby ) {
		switch ( $orderby ) {
			case 'status':
				if ( $item['active'] ) {
					return 0;
				}
				return $item['installed'] ? 1 : 2;
			case 'version':
				return $item['installed'] ? $item['version'] : $item['repo']['version'];
		}
		return $item['display_name'];
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'name'        => __( 'Plugin', 'plugin-hub' ),
			'status'      => __( 'Status', 'plugin-hub' ),
			'version'     => __( 'Version', 'plugin-hub' ),
			'autoupdate'  => __( 'Auto-update', 'plugin-hub' ),
			'description' => __( 'Description', 'plugin-hub' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'    => array( 'name', false ),
			'status'  => array( 'status', false ),
			'version' => array( 'version', false ),
		);
	}

	/**
	 * Views.
	 *
	 * @return array
	 */
	protected function get_views() {
		$labels = array(
			'all'       => __( 'All', 'plugin-hub' ),
			'active'    => __( 'Active', 'plugin-hub' ),
			'inactive'  => __( 'Inactive', 'plugin-hub' ),
			'installed' => __( 'Installed', 'plugin-hub' ),
			'available' => __( 'Not installed', 'plugin-hub' ),
			'update'    => __( 'Updates', 'plugin-hub' ),
			'beta'      => __( 'Beta', 'plugin-hub' ),
			'pinned'    => __( 'Pinned', 'plugin-hub' ),
		);

		if ( empty( $this->all_items ) ) {
			$this->all_items = $this->build_items();
		}

		$search = $this->query_arg( 's' );
		$views  = array();
		foreach ( $labels as $key => $label ) {
			$count = 0;
			foreach ( $this->all_items as $item ) {
				if ( $this->in_view( $item, $key ) ) {
					++$count;
				}
			}
			$args          = array( 'filter' => $key );
			$args          = '' !== $search ? array_merge( $args, array( 's' => $search ) ) : $args;
			$views[ $key ] = $this->view_link( page_url( $args ), $label, $count, $key === $this->filter );
		}

		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'install'            => __( 'Install', 'plugin-hub' ),
			'activate'           => __( 'Activate', 'plugin-hub' ),
			'deactivate'         => __( 'Deactivate', 'plugin-hub' ),
			'update'             => __( 'Update', 'plugin-hub' ),
			'delete'             => __( 'Delete', 'plugin-hub' ),
			'enable_autoupdate'  => __( 'Enable auto-updates', 'plugin-hub' ),
			'disable_autoupdate' => __( 'Disable auto-updates', 'plugin-hub' ),
		);

		if ( is_multisite() && current_user_can( 'manage_network_plugins' ) ) {
			$actions['network_activate']   = __( 'Network activate', 'plugin-hub' );
			$actions['network_deactivate'] = __( 'Network deactivate', 'plugin-hub' );
		}

		return $actions;
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No plugins match the current view. Refresh the catalog or adjust the filter.', 'plugin-hub' );
	}

	/**
	 * Row markup with the WordPress active/inactive/update classes.
	 *
	 * @param array $item Row.
	 */
	public function single_row( $item ) {
		$classes = array( $item['active'] ? 'active' : 'inactive' );
		if ( $item['update_allowed'] ) {
			$classes[] = 'update';
		}
		echo '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '" data-repo="' . esc_attr( $item['name'] ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %s: Plugin name. */
		return $this->checkbox( $item['name'], sprintf( __( 'Select %s', 'plugin-hub' ), $item['display_name'] ) );
	}

	/**
	 * Name column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		return '<strong>' . esc_html( $item['display_name'] ) . '</strong>';
	}

	/**
	 * Row actions.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}

		$name       = $item['name'];
		$repo       = esc_attr( $name );
		$dependents = esc_attr( implode( ', ', $item['dependents'] ) );
		$actions    = array();

		if ( ! $item['installed'] ) {
			if ( $item['available'] ) {
				$actions['install'] = '<a href="#" class="install-now" data-repo="' . $repo . '" data-version="' . esc_attr( $item['repo']['version'] ) . '">' . esc_html__( 'Install now', 'plugin-hub' ) . '</a>';
			} else {
				$actions['unavailable'] = '<span class="unavailable">' . esc_html__( 'No release available', 'plugin-hub' ) . '</span>';
			}
		} else {
			if ( $item['active'] && $item['network_active'] ) {
				if ( current_user_can( 'manage_network_plugins' ) ) {
					$actions['deactivate'] = '<a href="#" class="deactivate-now" data-network="1" data-repo="' . $repo . '" data-dependents="' . $dependents . '">' . esc_html__( 'Network deactivate', 'plugin-hub' ) . '</a>';
				}
			} elseif ( $item['active'] ) {
				$actions['deactivate'] = '<a href="#" class="deactivate-now" data-repo="' . $repo . '" data-dependents="' . $dependents . '">' . esc_html__( 'Deactivate', 'plugin-hub' ) . '</a>';
			} else {
				$actions['activate'] = '<a href="#" class="activate-now" data-repo="' . $repo . '">' . esc_html__( 'Activate', 'plugin-hub' ) . '</a>';
				if ( is_multisite() && current_user_can( 'manage_network_plugins' ) ) {
					$actions['network_activate'] = '<a href="#" class="activate-now network-activate-now" data-network="1" data-repo="' . $repo . '">' . esc_html__( 'Network activate', 'plugin-hub' ) . '</a>';
				}
				$actions['delete'] = '<a href="#" class="delete-now" data-repo="' . $repo . '" data-dependents="' . $dependents . '">' . esc_html__( 'Delete', 'plugin-hub' ) . '</a>';
			}

			if ( $item['update_allowed'] ) {
				$actions['update'] = '<a href="#" class="update-now" data-repo="' . $repo . '" data-version="' . esc_attr( $item['repo']['version'] ) . '" data-current-version="' . esc_attr( $item['version'] ) . '">' . esc_html__( 'Update now', 'plugin-hub' ) . '</a>';
			}
			if ( $item['update_available'] ) {
				$actions['view-changelog'] = '<a href="#" class="open-changelog" data-repo="' . $repo . '" data-current-version="' . esc_attr( $item['version'] ) . '" data-new-version="' . esc_attr( $item['repo']['version'] ) . '">' . esc_html__( "What's new?", 'plugin-hub' ) . '</a>';
			}

			$actions['pin'] = '<a href="#" class="pin-toggle" data-repo="' . $repo . '" data-version="' . esc_attr( $item['version'] ) . '" data-pin="' . esc_attr( $item['pin'] ) . '">' . ( '' !== $item['pin'] ? esc_html__( 'Change pin', 'plugin-hub' ) : esc_html__( 'Pin version', 'plugin-hub' ) ) . '</a>';
		}

		$actions['view'] = '<a href="' . esc_url( $item['repo']['repo_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on GitHub', 'plugin-hub' ) . '</a>';

		if ( $item['installed'] ) {
			$actions['rollback'] = '<a href="#" class="rollback-toggle" data-repo="' . $repo . '">' . esc_html__( 'Versions', 'plugin-hub' ) . '</a><span class="rollback-list" style="display:none;"></span>';
		}

		return $this->row_actions( $actions, true );
	}

	/**
	 * Status column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		if ( ! $item['installed'] ) {
			$out = esc_html__( 'Not installed', 'plugin-hub' );
		} elseif ( $item['network_active'] ) {
			$out = esc_html__( 'Network active', 'plugin-hub' );
		} elseif ( $item['active'] ) {
			$out = esc_html__( 'Active', 'plugin-hub' );
		} else {
			$out = esc_html__( 'Inactive', 'plugin-hub' );
		}
		if ( $item['beta'] ) {
			$out .= ' <span class="plugin-hub-badge is-beta">' . esc_html__( 'Beta', 'plugin-hub' ) . '</span>';
		}
		return $out;
	}

	/**
	 * Version column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_version( $item ) {
		if ( ! $item['installed'] ) {
			if ( ! $item['available'] ) {
				return esc_html__( 'No published release', 'plugin-hub' );
			}
			/* translators: %s: Plugin version number */
			return esc_html( sprintf( __( 'Latest version is %s', 'plugin-hub' ), $item['repo']['version'] ) );
		}

		/* translators: %s: Plugin version number */
		$out = esc_html( sprintf( __( 'Version %s', 'plugin-hub' ), $item['version'] ) );

		if ( $item['update_allowed'] ) {
			/* translators: %s: New version number */
			$out .= '<div class="update-message notice inline notice-warning notice-alt"><p>' . esc_html( sprintf( __( 'Update available (%s)', 'plugin-hub' ), $item['repo']['version'] ) ) . '</p></div>';
		} elseif ( $item['update_available'] ) {
			/* translators: %s: New version number */
			$out .= '<br><span class="description">' . esc_html( sprintf( __( 'Update to %s is blocked by the pin or a failed earlier attempt.', 'plugin-hub' ), $item['repo']['version'] ) ) . '</span>';
		}

		if ( '' !== $item['pin'] ) {
			/* translators: %s: Pinned version or constraint. */
			$out .= '<br><span class="plugin-hub-badge is-pin">' . esc_html( sprintf( __( 'Pinned: %s', 'plugin-hub' ), $item['pin'] ) ) . '</span>';
		}

		return $out;
	}

	/**
	 * Auto-update column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_autoupdate( $item ) {
		if ( ! $item['installed'] ) {
			return '&mdash;';
		}
		return '<label><input type="checkbox" class="autoupdate-toggle" data-repo="' . esc_attr( $item['name'] ) . '"' . checked( $item['autoupdate'], true, false ) . '> <span class="screen-reader-text">' . esc_html__( 'Auto-update', 'plugin-hub' ) . '</span></label>';
	}

	/**
	 * Description column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_description( $item ) {
		$out = '<div class="plugin-description"><p>' . esc_html( $item['repo']['description'] ) . '</p></div>';

		if ( ! empty( $item['missing'] ) ) {
			/* translators: %s: Comma-separated plugin slugs. */
			$out .= '<p class="plugin-hub-warning">' . esc_html( sprintf( __( 'Requires plugins that are not installed: %s', 'plugin-hub' ), implode( ', ', $item['missing'] ) ) ) . '</p>';
		}
		if ( ! empty( $item['dependents'] ) ) {
			/* translators: %s: Comma-separated plugin names. */
			$out .= '<p class="description">' . esc_html( sprintf( __( 'Required by active plugins: %s', 'plugin-hub' ), implode( ', ', $item['dependents'] ) ) ) . '</p>';
		}

		return $out;
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}
}

/**
 * Installed MU plugins table.
 *
 * @since 1.8.0
 */
class MU_Installed_List_Table extends Hub_List_Table {

	/**
	 * MU view data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Active view.
	 *
	 * @var string
	 */
	private $view;

	/**
	 * Constructor.
	 *
	 * @param array  $data MU view data.
	 * @param string $view all|update.
	 */
	public function __construct( array $data, $view = 'all' ) {
		parent::__construct(
			array(
				'singular' => 'mu-plugin',
				'plural'   => 'mu-plugins',
				'ajax'     => false,
			)
		);
		$this->data = $data;
		$this->view = $view;
	}

	/**
	 * Prepare rows.
	 */
	public function prepare_items() {
		$this->init_column_headers();
		$category = $this->query_arg( 'mu_category' );
		$search   = strtolower( trim( $this->query_arg( 's' ) ) );
		$items    = array();

		foreach ( $this->data['installed'] as $row ) {
			if ( 'update' === $this->view && empty( $row['update'] ) ) {
				continue;
			}
			if ( '' !== $category && $row['category'] !== $category ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $row['name'] . ' ' . $row['file'], $search ) ) {
				continue;
			}
			$items[] = $row;
		}

		$this->items = $this->paginate( $this->sort_items( $items, 'name' ) );
	}

	/**
	 * Sort values.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return int|string
	 */
	protected function sort_value( $item, $orderby ) {
		switch ( $orderby ) {
			case 'category':
				return $item['category'];
			case 'version':
				return $item['local_version'];
		}
		return $item['name'];
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'name'        => __( 'MU plugin', 'plugin-hub' ),
			'category'    => __( 'Category', 'plugin-hub' ),
			'version'     => __( 'Version', 'plugin-hub' ),
			'new_version' => __( 'New version', 'plugin-hub' ),
			'autoupdate'  => __( 'Auto-update', 'plugin-hub' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'category' => array( 'category', false ),
			'version'  => array( 'version', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'update'             => __( 'Update', 'plugin-hub' ),
			'rollback'           => __( 'Roll back', 'plugin-hub' ),
			'enable_autoupdate'  => __( 'Enable auto-updates', 'plugin-hub' ),
			'disable_autoupdate' => __( 'Disable auto-updates', 'plugin-hub' ),
		);
	}

	/**
	 * Category filter above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$categories = array_values( array_unique( array_filter( array_column( $this->data['installed'], 'category' ) ) ) );
		sort( $categories );
		$current = $this->query_arg( 'mu_category' );
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="plugin-hub-mu-category">' . esc_html__( 'Filter by category', 'plugin-hub' ) . '</label>';
		echo '<select name="mu_category" id="plugin-hub-mu-category"><option value="">' . esc_html__( 'All categories', 'plugin-hub' ) . '</option>';
		foreach ( $categories as $category ) {
			echo '<option value="' . esc_attr( $category ) . '"' . selected( $current, $category, false ) . '>' . esc_html( $category ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'plugin-hub' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No catalog MU plugins are installed.', 'plugin-hub' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %s: MU plugin file. */
		return $this->checkbox( $item['file'], sprintf( __( 'Select %s', 'plugin-hub' ), $item['file'] ) );
	}

	/**
	 * Name column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		return '<strong>' . esc_html( $item['name'] ) . '</strong><br><code>' . esc_html( $item['file'] ) . '</code>';
	}

	/**
	 * Row actions.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		$actions = array();
		$file    = esc_attr( $item['file'] );
		if ( ! empty( $item['update'] ) && $this->data['file_mods'] ) {
			$actions['update'] = '<a href="#" class="mu-update-now" data-file="' . $file . '">' . esc_html__( 'Update', 'plugin-hub' ) . '</a>';
		}
		if ( ! empty( $item['has_backup'] ) && $this->data['file_mods'] ) {
			$actions['rollback'] = '<a href="#" class="mu-rollback-now" data-file="' . $file . '">' . esc_html__( 'Rollback', 'plugin-hub' ) . '</a>';
		}
		if ( empty( $actions ) ) {
			$actions['current'] = '<span class="description">' . esc_html__( 'Up to date', 'plugin-hub' ) . '</span>';
		}
		return $this->row_actions( $actions, true );
	}

	/**
	 * Category column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_category( $item ) {
		return esc_html( '' !== $item['category'] ? $item['category'] : __( 'shared', 'plugin-hub' ) );
	}

	/**
	 * Version column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_version( $item ) {
		return esc_html( $item['local_version'] );
	}

	/**
	 * New version column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_new_version( $item ) {
		return ! empty( $item['update'] ) ? esc_html( $item['remote_version'] ) : '&mdash;';
	}

	/**
	 * Auto-update column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_autoupdate( $item ) {
		return '<label><input type="checkbox" class="mu-autoupdate-toggle" data-file="' . esc_attr( $item['file'] ) . '"' . checked( ! empty( $item['autoupdate'] ), true, false ) . '> <span class="screen-reader-text">' . esc_html__( 'Auto-update', 'plugin-hub' ) . '</span></label>';
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}
}

/**
 * Available (not installed) MU plugins table.
 *
 * @since 1.8.0
 */
class MU_Available_List_Table extends Hub_List_Table {

	/**
	 * MU view data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array $data MU view data.
	 */
	public function __construct( array $data ) {
		parent::__construct(
			array(
				'singular' => 'mu-plugin',
				'plural'   => 'mu-plugins-available',
				'ajax'     => false,
			)
		);
		$this->data = $data;
	}

	/**
	 * Prepare rows.
	 */
	public function prepare_items() {
		$this->init_column_headers();
		$category = $this->query_arg( 'mu_category' );
		$search   = strtolower( trim( $this->query_arg( 's' ) ) );
		$items    = array();

		foreach ( $this->data['available'] as $row ) {
			if ( '' !== $category && $row['category'] !== $category ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $row['name'] . ' ' . $row['file'] . ' ' . $row['description'], $search ) ) {
				continue;
			}
			$items[] = $row;
		}

		$this->items = $this->paginate( $this->sort_items( $items, 'name' ) );
	}

	/**
	 * Sort values.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return int|string
	 */
	protected function sort_value( $item, $orderby ) {
		switch ( $orderby ) {
			case 'category':
				return $item['category'];
			case 'version':
				return $item['version'];
		}
		return $item['name'];
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'name'        => __( 'MU plugin', 'plugin-hub' ),
			'category'    => __( 'Category', 'plugin-hub' ),
			'version'     => __( 'Version', 'plugin-hub' ),
			'description' => __( 'Description', 'plugin-hub' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'category' => array( 'category', false ),
			'version'  => array( 'version', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return $this->data['file_mods'] ? array( 'install' => __( 'Install', 'plugin-hub' ) ) : array();
	}

	/**
	 * Category filter above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$categories = array_values( array_unique( array_filter( array_column( $this->data['available'], 'category' ) ) ) );
		sort( $categories );
		$current = $this->query_arg( 'mu_category' );
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="plugin-hub-mu-category">' . esc_html__( 'Filter by category', 'plugin-hub' ) . '</label>';
		echo '<select name="mu_category" id="plugin-hub-mu-category"><option value="">' . esc_html__( 'All categories', 'plugin-hub' ) . '</option>';
		foreach ( $categories as $category ) {
			echo '<option value="' . esc_attr( $category ) . '"' . selected( $current, $category, false ) . '>' . esc_html( $category ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'plugin-hub' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No MU plugins match your filters, or every catalog MU plugin is already installed.', 'plugin-hub' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %s: MU plugin file. */
		return $this->checkbox( $item['file'], sprintf( __( 'Select %s', 'plugin-hub' ), $item['file'] ) );
	}

	/**
	 * Name column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		$out = '<strong>' . esc_html( $item['name'] ) . '</strong>';
		if ( $item['name'] !== $item['file'] ) {
			$out .= '<br><code>' . esc_html( $item['file'] ) . '</code>';
		}
		return $out;
	}

	/**
	 * Row actions.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary || ! $this->data['file_mods'] ) {
			return '';
		}
		return $this->row_actions(
			array(
				'install' => '<a href="#" class="mu-install-now" data-file="' . esc_attr( $item['file'] ) . '">' . esc_html__( 'Install', 'plugin-hub' ) . '</a>',
			),
			true
		);
	}

	/**
	 * Category column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_category( $item ) {
		return esc_html( $item['category'] );
	}

	/**
	 * Version column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_version( $item ) {
		$out          = esc_html( $item['version'] );
		$requirements = array();
		if ( '' !== $item['wp'] ) {
			/* translators: %s: WordPress version. */
			$requirements[] = sprintf( __( 'WP %s', 'plugin-hub' ), $item['wp'] );
		}
		if ( '' !== $item['php'] ) {
			/* translators: %s: PHP version. */
			$requirements[] = sprintf( __( 'PHP %s', 'plugin-hub' ), $item['php'] );
		}
		if ( $requirements ) {
			$out .= '<br><span class="description">' . esc_html( implode( ' · ', $requirements ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Description column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_description( $item ) {
		return esc_html( $item['description'] );
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}
}

/**
 * Activity log table.
 *
 * @since 1.8.0
 */
class Activity_List_Table extends Hub_List_Table {

	/**
	 * API instance.
	 *
	 * @var API
	 */
	private $api;

	/**
	 * Action labels.
	 *
	 * @return array
	 */
	public static function action_labels() {
		return array(
			'install'        => __( 'Install', 'plugin-hub' ),
			'update'         => __( 'Update', 'plugin-hub' ),
			'rollback'       => __( 'Rollback', 'plugin-hub' ),
			'auto_rollback'  => __( 'Auto-Rollback', 'plugin-hub' ),
			'activate'       => __( 'Activate', 'plugin-hub' ),
			'deactivate'     => __( 'Deactivate', 'plugin-hub' ),
			'delete'         => __( 'Delete', 'plugin-hub' ),
			'auto_update'    => __( 'Auto-Update', 'plugin-hub' ),
			'pin'            => __( 'Pin', 'plugin-hub' ),
			'backup_restore' => __( 'Backup restore', 'plugin-hub' ),
			'import'         => __( 'Import', 'plugin-hub' ),
			'mu_install'     => __( 'MU Install', 'plugin-hub' ),
			'mu_update'      => __( 'MU Update', 'plugin-hub' ),
			'mu_rollback'    => __( 'MU Rollback', 'plugin-hub' ),
			'mu_auto_update' => __( 'MU Auto-Update', 'plugin-hub' ),
		);
	}

	/**
	 * Badge CSS class for an action.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	private static function action_class( $action ) {
		$map = array(
			'install'        => 'activity-install',
			'mu_install'     => 'activity-install',
			'update'         => 'activity-update',
			'mu_update'      => 'activity-update',
			'activate'       => 'activity-activate',
			'rollback'       => 'activity-rollback',
			'auto_rollback'  => 'activity-rollback',
			'mu_rollback'    => 'activity-rollback',
			'backup_restore' => 'activity-rollback',
			'deactivate'     => 'activity-deactivate',
			'delete'         => 'activity-delete',
			'auto_update'    => 'activity-auto-update',
			'mu_auto_update' => 'activity-auto-update',
		);
		return $map[ $action ] ?? '';
	}

	/**
	 * Constructor.
	 *
	 * @param API $api API instance.
	 */
	public function __construct( API $api ) {
		parent::__construct(
			array(
				'singular' => 'entry',
				'plural'   => 'entries',
				'ajax'     => false,
			)
		);
		$this->api = $api;
	}

	/**
	 * Filters read from the request.
	 *
	 * @return array
	 */
	public function get_filters() {
		return array(
			'action' => sanitize_key( $this->query_arg( 'log_action' ) ),
			'user'   => $this->query_arg( 'log_user' ),
			'search' => $this->query_arg( 's' ),
			'from'   => $this->query_arg( 'log_from' ),
			'to'     => $this->query_arg( 'log_to' ),
		);
	}

	/**
	 * Prepare rows.
	 */
	public function prepare_items() {
		$this->init_column_headers();
		$this->items = $this->paginate( $this->sort_items( $this->api->get_activity_log( $this->get_filters() ), 'time', 'desc' ) );
	}

	/**
	 * Sort values.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return int|string
	 */
	protected function sort_value( $item, $orderby ) {
		switch ( $orderby ) {
			case 'action':
				return (string) $item['action'];
			case 'plugin':
				return (string) $item['plugin'];
			case 'user':
				return (string) $item['user'];
		}
		return (int) $item['time'];
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'time';
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox">',
			'time'    => __( 'Date / Time', 'plugin-hub' ),
			'action'  => __( 'Action', 'plugin-hub' ),
			'plugin'  => __( 'Plugin', 'plugin-hub' ),
			'version' => __( 'Version', 'plugin-hub' ),
			'user'    => __( 'User', 'plugin-hub' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'time'   => array( 'time', true ),
			'action' => array( 'action', false ),
			'plugin' => array( 'plugin', false ),
			'user'   => array( 'user', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete entries', 'plugin-hub' ) );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = $this->get_filters();
		$users   = array();
		foreach ( $this->api->get_activity_log() as $entry ) {
			$users[ $entry['user'] ] = true;
		}
		$users = array_keys( $users );
		sort( $users );

		echo '<div class="alignleft actions plugin-hub-log-filters">';
		echo '<label class="screen-reader-text" for="plugin-hub-log-action">' . esc_html__( 'Filter by action', 'plugin-hub' ) . '</label>';
		echo '<select name="log_action" id="plugin-hub-log-action"><option value="">' . esc_html__( 'All actions', 'plugin-hub' ) . '</option>';
		foreach ( self::action_labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $filters['action'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="plugin-hub-log-user">' . esc_html__( 'Filter by user', 'plugin-hub' ) . '</label>';
		echo '<select name="log_user" id="plugin-hub-log-user"><option value="">' . esc_html__( 'All users', 'plugin-hub' ) . '</option>';
		foreach ( $users as $user ) {
			echo '<option value="' . esc_attr( $user ) . '"' . selected( $filters['user'], $user, false ) . '>' . esc_html( $user ) . '</option>';
		}
		echo '</select>';
		echo '<label class="screen-reader-text" for="plugin-hub-log-from">' . esc_html__( 'From date', 'plugin-hub' ) . '</label>';
		echo '<input type="date" name="log_from" id="plugin-hub-log-from" value="' . esc_attr( $filters['from'] ) . '">';
		echo '<label class="screen-reader-text" for="plugin-hub-log-to">' . esc_html__( 'To date', 'plugin-hub' ) . '</label>';
		echo '<input type="date" name="log_to" id="plugin-hub-log-to" value="' . esc_attr( $filters['to'] ) . '">';
		submit_button( __( 'Filter', 'plugin-hub' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No activity recorded yet.', 'plugin-hub' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return $this->checkbox( (string) $item['id'], __( 'Select entry', 'plugin-hub' ) );
	}

	/**
	 * Time column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_time( $item ) {
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['time'] ) );
	}

	/**
	 * Row actions.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		return $this->row_actions(
			array(
				'delete' => '<a href="#" class="delete-activity-now" data-id="' . esc_attr( $item['id'] ) . '">' . esc_html__( 'Delete', 'plugin-hub' ) . '</a>',
			),
			true
		);
	}

	/**
	 * Action column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_action( $item ) {
		$labels = self::action_labels();
		$label  = $labels[ $item['action'] ] ?? $item['action'];
		return '<span class="activity-badge ' . esc_attr( self::action_class( $item['action'] ) ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return esc_html( isset( $item[ $column_name ] ) ? (string) $item[ $column_name ] : '' );
	}
}

/**
 * Local plugin backups table.
 *
 * @since 1.8.0
 */
class Backups_List_Table extends Hub_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'backup',
				'plural'   => 'backups',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Prepare rows.
	 */
	public function prepare_items() {
		$this->init_column_headers();
		$search = strtolower( trim( $this->query_arg( 's' ) ) );
		$items  = array();

		foreach ( Backups::all() as $row ) {
			if ( '' !== $search && false === stripos( $row['repo'], $search ) ) {
				continue;
			}
			$items[] = $row;
		}

		$this->items = $this->paginate( $this->sort_items( $items, 'created', 'desc' ) );
	}

	/**
	 * Sort values.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return int|string
	 */
	protected function sort_value( $item, $orderby ) {
		switch ( $orderby ) {
			case 'name':
				return (string) $item['repo'];
			case 'size':
				return (int) $item['size'];
		}
		return (int) $item['created'];
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox">',
			'name'    => __( 'Plugin', 'plugin-hub' ),
			'version' => __( 'Version', 'plugin-hub' ),
			'created' => __( 'Created', 'plugin-hub' ),
			'size'    => __( 'Size', 'plugin-hub' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'    => array( 'name', false ),
			'created' => array( 'created', true ),
			'size'    => array( 'size', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete backups', 'plugin-hub' ) );
	}

	/**
	 * Message when empty.
	 */
	public function no_items() {
		esc_html_e( 'No backups yet. A backup is created before each plugin update when backups are enabled in Settings.', 'plugin-hub' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return $this->checkbox( $item['id'], __( 'Select backup', 'plugin-hub' ) );
	}

	/**
	 * Name column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		return '<strong>' . esc_html( $item['repo'] ) . '</strong>';
	}

	/**
	 * Row actions.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @param string $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $column_name !== $primary ) {
			return '';
		}
		$id = esc_attr( $item['id'] );
		return $this->row_actions(
			array(
				'restore' => '<a href="#" class="restore-backup-now" data-id="' . $id . '" data-version="' . esc_attr( $item['version'] ) . '">' . esc_html__( 'Restore', 'plugin-hub' ) . '</a>',
				'delete'  => '<a href="#" class="delete-backup-now" data-id="' . $id . '">' . esc_html__( 'Delete', 'plugin-hub' ) . '</a>',
			),
			true
		);
	}

	/**
	 * Version column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_version( $item ) {
		return esc_html( $item['version'] );
	}

	/**
	 * Created column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_created( $item ) {
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['created'] ) );
	}

	/**
	 * Size column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_size( $item ) {
		return esc_html( Backups::format_size( (int) $item['size'] ) );
	}

	/**
	 * Default column.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}
}
