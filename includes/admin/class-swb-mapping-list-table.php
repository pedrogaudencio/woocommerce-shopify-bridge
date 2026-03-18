<?php
/**
 * WooCommerce Mappings Page for Shopify Bridge.
 *
 * @package Shopify_WooCommerce_Bridge\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * SWB_Mapping_List_Table Class.
 */
class SWB_Mapping_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => __( 'Mapping', 'shopify-woo-bridge' ),
				'plural'   => __( 'Mappings', 'shopify-woo-bridge' ),
				'ajax'     => false,
			)
		);
	}

	/**
	 * Retrieve mappings data from the database
	 *
	 * @param int $per_page
	 * @param int $page_number
	 *
	 * @return mixed
	 */
	public static function get_mappings( $per_page = 20, $page_number = 1 ) {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return SWB_DB::get_mappings( $per_page, $page_number, $search );
	}

	/**
	 * Returns the count of records in the database.
	 *
	 * @return null|string
	 */
	public static function record_count() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return SWB_DB::get_mappings_count( $search );
	}

	/**
	 * Render a column when no column specific method exist.
	 *
	 * @param array  $item
	 * @param string $column_name
	 *
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'shopify_item_id':
			case 'wc_product_id':
			case 'created_at':
				return esc_html( $item[ $column_name ] );
			case 'is_enabled':
				return $item[ $column_name ] ? __( 'Yes', 'shopify-woo-bridge' ) : __( 'No', 'shopify-woo-bridge' );
			default:
				return print_r( $item, true ); // Show the whole array for troubleshooting purposes
		}
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array $item
	 * @return string
	 */
	function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="bulk-delete[]" value="%s" />',
			$item['id']
		);
	}

	/**
	 * Render the shopify_item_id column with actions.
	 *
	 * @param array $item
	 * @return string
	 */
	function column_shopify_item_id( $item ) {
		$toggle_nonce = wp_create_nonce( 'swb_toggle_mapping_' . $item['id'] );
		$delete_nonce = wp_create_nonce( 'swb_delete_mapping_' . $item['id'] );

		$title = '<strong>' . esc_html( $item['shopify_item_id'] ) . '</strong>';

		$actions = array(
			'toggle' => sprintf(
				'<a href="?page=%s&action=%s&mapping=%s&_wpnonce=%s">%s</a>',
				esc_attr( $_REQUEST['page'] ),
				'toggle',
				absint( $item['id'] ),
				$toggle_nonce,
				$item['is_enabled'] ? __( 'Disable', 'shopify-woo-bridge' ) : __( 'Enable', 'shopify-woo-bridge' )
			),
			'delete' => sprintf(
				'<a href="?page=%s&action=%s&mapping=%s&_wpnonce=%s">%s</a>',
				esc_attr( $_REQUEST['page'] ),
				'delete',
				absint( $item['id'] ),
				$delete_nonce,
				__( 'Delete', 'shopify-woo-bridge' )
			),
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Render the wc_product_id column with edit link.
	 *
	 * @param array $item
	 * @return string
	 */
	function column_wc_product_id( $item ) {
		$product = wc_get_product( $item['wc_product_id'] );
		if ( $product ) {
			$title = $product->get_name();
			$edit_url = get_edit_post_link( $item['wc_product_id'] );
			return sprintf( '<a href="%s">%s (ID: %d)</a>', esc_url( $edit_url ), esc_html( $title ), absint( $item['wc_product_id'] ) );
		}
		return esc_html( $item['wc_product_id'] ) . ' - ' . __( 'Product Not Found', 'shopify-woo-bridge' );
	}

	/**
	 * Associative array of columns
	 *
	 * @return array
	 */
	function get_columns() {
		$columns = array(
			'cb'              => '<input type="checkbox" />',
			'shopify_item_id' => __( 'Shopify Item ID', 'shopify-woo-bridge' ),
			'wc_product_id'   => __( 'WooCommerce Product/Variant', 'shopify-woo-bridge' ),
			'is_enabled'      => __( 'Sync Enabled', 'shopify-woo-bridge' ),
			'created_at'      => __( 'Mapped On', 'shopify-woo-bridge' ),
		);

		return $columns;
	}

	/**
	 * Columns to make sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(); // Disable sorting for now
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = array(
			'bulk-delete' => 'Delete',
		);

		return $actions;
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {
		$this->_column_headers = $this->get_column_info();

		/** Process bulk action */
		$this->process_bulk_action();

		$per_page     = $this->get_items_per_page( 'mappings_per_page', 20 );
		$current_page = $this->get_pagenum();
		$total_items  = self::record_count();

		$this->set_pagination_args(
			array(
				'total_items' => $total_items, // WE have to calculate the total number of items
				'per_page'    => $per_page, // WE have to determine how many items to show on a page
				'total_pages' => ceil( $total_items / $per_page ), // WE have to calculate the total number of pages
			)
		);

		$this->items = self::get_mappings( $per_page, $current_page );
	}

	/**
	 * Process bulk action.
	 */
	public function process_bulk_action() {

		if ( 'delete' === $this->current_action() ) {
			// Handle single delete.
			if ( isset( $_GET['mapping'] ) ) {
				$mapping_id = absint( $_GET['mapping'] );
				
				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'swb_delete_mapping_' . $mapping_id ) ) {
					die( 'Go get a life script kiddies' );
				}

				SWB_DB::delete_mapping( $mapping_id );
				
				// redirect to avoid resubmission
				wp_safe_redirect( admin_url( 'admin.php?page=shopify-bridge-mappings' ) );
				exit;
			}
		}

		if ( 'toggle' === $this->current_action() ) {
			// Handle single toggle.
			if ( isset( $_GET['mapping'] ) ) {
				$mapping_id = absint( $_GET['mapping'] );
				
				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'swb_toggle_mapping_' . $mapping_id ) ) {
					die( 'Go get a life script kiddies' );
				}

				SWB_DB::toggle_mapping( $mapping_id );

				wp_safe_redirect( admin_url( 'admin.php?page=shopify-bridge-mappings' ) );
				exit;
			}
		}

		if ( ( isset( $_POST['action'] ) && $_POST['action'] == 'bulk-delete' )
		     || ( isset( $_POST['action2'] ) && $_POST['action2'] == 'bulk-delete' )
		) {
			
			if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'bulk-' . $this->_args['plural'] ) ) {
				die( 'Go get a life script kiddies' );
			}
			
			$delete_ids = esc_sql( $_POST['bulk-delete'] );

			foreach ( $delete_ids as $id ) {
				SWB_DB::delete_mapping( absint( $id ) );
			}

			wp_safe_redirect( admin_url( 'admin.php?page=shopify-bridge-mappings' ) );
			exit;
		}
	}
}
