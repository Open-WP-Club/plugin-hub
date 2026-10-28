<?php
/**
 * Minimal WP_List_Table and the output helpers the list tables call.
 *
 * Mirrors the parts of core the Plugin Hub tables rely on: column dispatch,
 * row actions, pagination arguments and the checkbox/primary column handling.
 *
 * @package PluginHub
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Squiz.Commenting

namespace {
	if ( ! class_exists( 'WP_List_Table' ) ) {
		class WP_List_Table {
			public $items = array();
			protected $_args;
			protected $_pagination_args = array();
			protected $_column_headers;

			public function __construct( $args = array() ) {
				$this->_args = $args;
			}

			public function get_columns() {
				return array();
			}

			protected function get_sortable_columns() {
				return array();
			}

			protected function get_bulk_actions() {
				return array();
			}

			protected function get_views() {
				return array();
			}

			protected function get_default_primary_column_name() {
				return 'name';
			}

			protected function set_pagination_args( $args ) {
				$this->_pagination_args = $args;
			}

			public function get_pagination_arg( $key ) {
				return $this->_pagination_args[ $key ] ?? 0;
			}

			public function get_pagenum() {
				return isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}

			protected function row_actions( $actions, $always_visible = false ) {
				$out = array();
				foreach ( $actions as $class => $link ) {
					$out[] = "<span class='$class'>$link</span>";
				}
				return '<div class="row-actions">' . implode( ' | ', $out ) . '</div>';
			}

			protected function single_row_columns( $item ) {
				$primary = $this->get_default_primary_column_name();
				foreach ( array_keys( $this->get_columns() ) as $column ) {
					if ( 'cb' === $column ) {
						echo '<th class="check-column">' . $this->column_cb( $item ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput
						continue;
					}
					echo '<td class="column-' . $column . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
					echo method_exists( $this, 'column_' . $column ) ? $this->{'column_' . $column}( $item ) : $this->column_default( $item, $column ); // phpcs:ignore WordPress.Security.EscapeOutput
					echo $this->handle_row_actions( $item, $column, $primary ); // phpcs:ignore WordPress.Security.EscapeOutput
					echo '</td>';
				}
			}

			public function single_row( $item ) {
				echo '<tr>';
				$this->single_row_columns( $item );
				echo '</tr>';
			}

			public function display_rows() {
				foreach ( $this->items as $item ) {
					$this->single_row( $item );
				}
			}

			public function views() {
				echo '<ul class="subsubsub"><li>' . implode( '</li><li>', $this->get_views() ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}

			public function search_box( $text, $input_id ) {
				echo '<p class="search-box"><input type="search" id="' . $input_id . '-input" name="s"><button>' . $text . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}

			public function display() {
				echo '<table class="wp-list-table ' . implode( ' ', $this->get_table_classes() ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
				$this->extra_tablenav( 'top' );
				echo '<tbody>';
				if ( empty( $this->items ) ) {
					echo '<tr class="no-items"><td>';
					$this->no_items();
					echo '</td></tr>';
				}
				$this->display_rows();
				echo '</tbody></table>';
			}

			protected function get_table_classes() {
				return array( 'widefat' );
			}

			public function no_items() {}

			public function views_html() {
				return implode( ' | ', $this->get_views() );
			}

			public function tablenav_html( $which = 'top' ) {
				ob_start();
				$this->extra_tablenav( $which );
				return (string) ob_get_clean();
			}

			protected function extra_tablenav( $which ) {}

			protected function handle_row_actions( $item, $column_name, $primary ) {
				return '';
			}

			protected function column_default( $item, $column_name ) {
				return '';
			}
		}
	}
}

namespace PluginHub {
	if ( ! function_exists( __NAMESPACE__ . '\\esc_url' ) ) {
		function esc_url( $url ) {
			return htmlspecialchars( (string) $url, ENT_QUOTES );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\esc_attr' ) ) {
		function esc_attr( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\esc_html__' ) ) {
		function esc_html__( $text, $domain = 'default' ) {
			return htmlspecialchars( $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\esc_html_e' ) ) {
		function esc_html_e( $text, $domain = 'default' ) {
			echo htmlspecialchars( $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\number_format_i18n' ) ) {
		function number_format_i18n( $number, $decimals = 0 ) {
			return number_format( (float) $number, $decimals );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\checked' ) ) {
		function checked( $checked, $current = true, $display = true ) {
			$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
			if ( $display ) {
				echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			return $out;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\selected' ) ) {
		function selected( $selected, $current = true, $display = true ) {
			$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
			if ( $display ) {
				echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			return $out;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\submit_button' ) ) {
		function submit_button( $text = '', $type = 'primary', $name = 'submit', $wrap = true ) {
			echo '<button name="' . $name . '">' . $text . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_plugin_active_for_network' ) ) {
		function is_plugin_active_for_network( $file ) {
			return in_array( $file, $GLOBALS['hub_test_network_active'] ?? array(), true );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_date' ) ) {
		function wp_date( $format, $timestamp = null ) {
			return date( $format, $timestamp ?? ( $GLOBALS['hub_test_now'] ?? time() ) );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\_n' ) ) {
		function _n( $single, $plural, $number, $domain = 'default' ) {
			return 1 === $number ? $single : $plural;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\current_user_can' ) ) {
		function current_user_can( $capability ) {
			return ! isset( $GLOBALS['hub_test_caps'] ) || in_array( $capability, $GLOBALS['hub_test_caps'], true );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\is_network_admin' ) ) {
		function is_network_admin() {
			return $GLOBALS['hub_test_network_admin'] ?? false;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\admin_url' ) ) {
		function admin_url( $path = '' ) {
			return 'https://example.test/wp-admin/' . $path;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\network_admin_url' ) ) {
		function network_admin_url( $path = '' ) {
			return 'https://example.test/wp-admin/network/' . $path;
		}
	}
	if ( ! function_exists( __NAMESPACE__ . '\\add_query_arg' ) ) {
		function add_query_arg( $args, $value = '', $url = '' ) {
			if ( is_array( $args ) ) {
				$url = $value;
			} else {
				$args = array( $args => $value );
			}
			return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
		}
	}
}
