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
	 * Read a mapping field regardless of row data shape.
	 *
	 * @param array|object $item Mapping row.
	 * @param string       $key  Field name.
	 * @param mixed        $default Default value.
	 * @return mixed
	 */
	private function get_item_value( $item, $key, $default = '' ) {
		if ( is_array( $item ) && array_key_exists( $key, $item ) ) {
			return $item[ $key ];
		}

		if ( is_object( $item ) && isset( $item->{$key} ) ) {
			return $item->{$key};
		}

		return $default;
	}

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
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_type = self::get_product_type_filter();
		return SWB_DB::get_mappings( $per_page, $page_number, $search, $product_type );
	}

	/**
	 * Returns the count of records in the database.
	 *
	 * @return null|string
	 */
	public static function record_count() {
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product_type = self::get_product_type_filter();
		return SWB_DB::get_mappings_count( $search, $product_type );
	}

	/**
	 * Read product type filter from request.
	 *
	 * @return string
	 */
	private static function get_product_type_filter() {
		$allowed = array( 'all', 'simple', 'variable', 'variation', 'grouped', 'external' );
		$value   = isset( $_REQUEST['swb_product_type'] ) ? sanitize_key( $_REQUEST['swb_product_type'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $value, $allowed, true ) ) {
			return 'all';
		}

		return $value;
	}

	/**
	 * Build query args that preserve current list-table state.
	 *
	 * @return array
	 */
	private static function get_preserved_state_query_args() {
		$args = array();

		$product_type = self::get_product_type_filter();
		if ( 'all' !== $product_type ) {
			$args['swb_product_type'] = $product_type;
		}

		if ( isset( $_REQUEST['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$search = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $search ) {
				$args['s'] = $search;
			}
		}

		return $args;
	}

	/**
	 * Render extra controls above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$current_filter = self::get_product_type_filter();
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="swb-product-type-filter"><?php esc_html_e( 'Filter by product type', 'shopify-woo-bridge' ); ?></label>
			<select name="swb_product_type" id="swb-product-type-filter">
				<option value="all" <?php selected( $current_filter, 'all' ); ?>><?php esc_html_e( 'All product types', 'shopify-woo-bridge' ); ?></option>
				<option value="simple" <?php selected( $current_filter, 'simple' ); ?>><?php esc_html_e( 'Simple', 'shopify-woo-bridge' ); ?></option>
				<option value="variable" <?php selected( $current_filter, 'variable' ); ?>><?php esc_html_e( 'Variable', 'shopify-woo-bridge' ); ?></option>
				<option value="variation" <?php selected( $current_filter, 'variation' ); ?>><?php esc_html_e( 'Variation', 'shopify-woo-bridge' ); ?></option>
				<option value="grouped" <?php selected( $current_filter, 'grouped' ); ?>><?php esc_html_e( 'Grouped', 'shopify-woo-bridge' ); ?></option>
				<option value="external" <?php selected( $current_filter, 'external' ); ?>><?php esc_html_e( 'External/Affiliate', 'shopify-woo-bridge' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'shopify-woo-bridge' ), 'button', 'filter_action', false ); ?>
		</div>
		<?php
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
			case 'wc_sku':
			case 'created_at':
				return esc_html( $this->get_item_value( $item, $column_name ) );
			case 'media_sync_status':
				return $this->column_media_sync_status( $item );
			case 'is_enabled':
				return (int) $this->get_item_value( $item, $column_name, 0 ) ? __( 'Yes', 'shopify-woo-bridge' ) : __( 'No', 'shopify-woo-bridge' );
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
			absint( $this->get_item_value( $item, 'id', 0 ) )
		);
	}

	/**
	 * Render the shopify_item_id column with actions.
	 *
	 * @param array $item
	 * @return string
	 */
	function column_shopify_item_id( $item ) {
		$id = absint( $this->get_item_value( $item, 'id', 0 ) );
		$toggle_nonce = wp_create_nonce( 'swb_toggle_mapping_' . $id );
		$delete_nonce = wp_create_nonce( 'swb_delete_mapping_' . $id );
		$sync_nonce = wp_create_nonce( 'swb_sync_mapping_' . $id );
		$sync_images_nonce = wp_create_nonce( 'swb_sync_images_mapping_' . $id );

		$title = '<strong>' . esc_html( $this->get_item_value( $item, 'shopify_item_id' ) ) . '</strong>';
		$page  = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : 'shopify-bridge-mappings';
		$is_enabled = (int) $this->get_item_value( $item, 'is_enabled', 0 );

		$state_args = self::get_preserved_state_query_args();
		$actions = array(
			'sync' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array_merge(
							array(
								'page'     => $page,
								'tab'      => 'mappings',
								'action'   => 'sync',
								'mapping'  => $id,
								'_wpnonce' => $sync_nonce,
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				),
				__( 'Sync', 'shopify-woo-bridge' )
			),
			'sync_images' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array_merge(
							array(
								'page'     => $page,
								'tab'      => 'mappings',
								'action'   => 'sync_images',
								'mapping'  => $id,
								'_wpnonce' => $sync_images_nonce,
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				),
				__( 'Sync Images', 'shopify-woo-bridge' )
			),
			'toggle' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array_merge(
							array(
								'page'     => $page,
								'tab'      => 'mappings',
								'action'   => 'toggle',
								'mapping'  => $id,
								'_wpnonce' => $toggle_nonce,
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				),
				$is_enabled ? __( 'Disable', 'shopify-woo-bridge' ) : __( 'Enable', 'shopify-woo-bridge' )
			),
			'delete' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array_merge(
							array(
								'page'     => $page,
								'tab'      => 'mappings',
								'action'   => 'delete',
								'mapping'  => $id,
								'_wpnonce' => $delete_nonce,
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				),
				__( 'Delete', 'shopify-woo-bridge' )
			),
		);

		if ( ! $is_enabled || '' === (string) $this->get_item_value( $item, 'shopify_product_id', '' ) ) {
			unset( $actions['sync_images'] );
		}

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Render image sync status column.
	 *
	 * @param array|object $item Mapping row.
	 * @return string
	 */
	public function column_media_sync_status( $item ) {
		$wc_sku = (string) $this->get_item_value( $item, 'wc_sku', '' );
		if ( '' === $wc_sku ) {
			return esc_html__( 'Not available', 'shopify-woo-bridge' );
		}

		$product_id = $this->find_wc_product_id_by_sku_for_status( $wc_sku );
		if ( $product_id <= 0 ) {
			return esc_html__( 'Not synced', 'shopify-woo-bridge' );
		}

		$last_synced = (string) get_post_meta( $product_id, 'shopify_sync_last_media_synced_at', true );
		$last_hash   = (string) get_post_meta( $product_id, 'shopify_sync_last_media_hash', true );

		$mapping_id  = absint( $this->get_item_value( $item, 'id', 0 ) );
		$error       = (string) get_option( 'swb_mapping_media_sync_error_' . $mapping_id, '' );
		$last_result = (string) get_option( 'swb_mapping_media_sync_result_' . $mapping_id, '' );

		if ( '' === $last_synced ) {
			$status = esc_html__( 'Not synced', 'shopify-woo-bridge' );
		} else {
			$status = esc_html__( 'Synced', 'shopify-woo-bridge' ) . ': ' . esc_html( $last_synced );
		}

		if ( '' !== $last_result ) {
			$status .= '<br/><small>' . esc_html( ucfirst( $last_result ) ) . '</small>';
		}

		if ( '' !== $last_hash ) {
			$status .= '<br/><small>' . esc_html__( 'Hash stored', 'shopify-woo-bridge' ) . '</small>';
		}

		if ( '' !== $error ) {
			$status .= '<br/><small style="color:#b32d2e;">' . esc_html( $error ) . '</small>';
		}

		return $status;
	}

	/**
	 * Resolve product ID by SKU for status rendering.
	 *
	 * @param string $wc_sku SKU.
	 * @return int
	 */
	private function find_wc_product_id_by_sku_for_status( $wc_sku ) {
		global $wpdb;

		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT posts.ID
				FROM {$wpdb->posts} as posts
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
				WHERE
				posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.sku = %s
				LIMIT 1
				",
				$wc_sku
			)
		);

		if ( empty( $product_ids ) ) {
			return 0;
		}

		return absint( $product_ids[0] );
	}

	/**
	 * Render the wc_sku column with edit link.
	 *
	 * @param array $item
	 * @return string
	 */
	function column_wc_sku( $item ) {
		$wc_sku_raw = (string) $this->get_item_value( $item, 'wc_sku' );
		$wc_sku = esc_html( $wc_sku_raw );

		global $wpdb;
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT posts.ID
				FROM {$wpdb->posts} as posts
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
				WHERE
				posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.sku = %s
				",
				$wc_sku_raw
			)
		);

		if ( empty( $product_ids ) ) {
			return $wc_sku . ' - ' . __( 'Product Not Found', 'shopify-woo-bridge' );
		}

		if ( count( $product_ids ) > 1 ) {
			return '<span style="color:red;">' . $wc_sku . ' - ' . __( 'Error: Duplicate SKUs Found', 'shopify-woo-bridge' ) . '</span>';
		}

		$product_id = $product_ids[0];
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$title = $product->get_name();
			$edit_url = get_edit_post_link( $product_id );
			$type_label = $product->is_type('variation') ? __( 'Variation', 'shopify-woo-bridge' ) : __( 'Product', 'shopify-woo-bridge' );
			return sprintf( '<strong>%s</strong><br><a href="%s">%s (ID: %d - %s)</a>', $wc_sku, esc_url( $edit_url ), esc_html( $title ), $product_id, $type_label );
		}

		return $wc_sku . ' - ' . __( 'Product Not Found', 'shopify-woo-bridge' );
	}

	/**
	 * Render the shopify product/variant ID column.
	 *
	 * @param array $item
	 * @return string
	 */
	function column_shopify_product_id( $item ) {
		$product_id = esc_html( $this->get_item_value( $item, 'shopify_product_id' ) );
		$variant_raw = $this->get_item_value( $item, 'shopify_variant_id' );
		$variant_id = ! empty( $variant_raw ) ? esc_html( $variant_raw ) : '';

		if ( $variant_id ) {
			return sprintf( '%s<br><small>Variant: %s</small>', $product_id, $variant_id );
		}
		return $product_id;
	}

	/**
	 * Associative array of columns
	 *
	 * @return array
	 */
	function get_columns() {
		$columns = array(
			'cb'                 => '<input type="checkbox" />',
			'shopify_item_id'    => __( 'Shopify Inventory ID', 'shopify-woo-bridge' ),
			'shopify_product_id' => __( 'Shopify Product/Variant ID', 'shopify-woo-bridge' ),
			'wc_sku'             => __( 'WooCommerce SKU', 'shopify-woo-bridge' ),
			'media_sync_status'  => __( 'Media Sync Status', 'shopify-woo-bridge' ),
			'is_enabled'         => __( 'Sync Enabled', 'shopify-woo-bridge' ),
			'created_at'         => __( 'Mapped On', 'shopify-woo-bridge' ),
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
		$columns  = $this->get_columns();
		$hidden   = array();
		$sortable = $this->get_sortable_columns();
		$primary  = 'shopify_item_id';
		// Force visible columns to avoid blank rows caused by stale per-user hidden-column settings.
		$this->_column_headers = array( $columns, $hidden, $sortable, $primary );

		/** Process bulk action */
		$this->process_bulk_action();

		$per_page     = max( 1, (int) $this->get_items_per_page( 'mappings_per_page', 20 ) );
		$total_items  = (int) self::record_count();
		$total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
		$current_page = min( $this->get_pagenum(), $total_pages );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items, // WE have to calculate the total number of items
				'per_page'    => $per_page, // WE have to determine how many items to show on a page
				'total_pages' => $total_pages, // WE have to calculate the total number of pages
			)
		);

		$this->items = self::get_mappings( $per_page, $current_page );
		if ( ! is_array( $this->items ) ) {
			$this->items = array();
		}
	}

	/**
	 * Sync inventory for a specific mapping by fetching from Shopify and updating WooCommerce.
	 *
	 * @param object|array $mapping The mapping data.
	 * @return bool True if sync was successful, false otherwise.
	 */
	private function sync_inventory_for_mapping( $mapping ) {
		$shopify_item_id = $this->get_item_value( $mapping, 'shopify_item_id' );
		$wc_sku          = $this->get_item_value( $mapping, 'wc_sku' );

		if ( empty( $shopify_item_id ) || empty( $wc_sku ) ) {
			SWB_Logger::warning( 'Manual sync skipped: Missing shopify_item_id or wc_sku.' );
			return false;
		}

		// Fetch inventory levels from Shopify
		$api            = new SWB_Shopify_API_Client();
		$levels_by_item = $api->get_inventory_levels_for_item_ids( array( $shopify_item_id ) );

		if ( is_wp_error( $levels_by_item ) ) {
			SWB_Logger::error( 'Manual sync failed: Could not fetch inventory levels from Shopify.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'error'           => $levels_by_item->get_error_message(),
			) );
			return false;
		}

		if ( empty( $levels_by_item[ $shopify_item_id ] ) ) {
			SWB_Logger::warning( 'Manual sync skipped: No inventory levels found for item.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
			) );
			return false;
		}

		// Use the first available location's inventory (or sum them)
		$total_available = 0;
		foreach ( $levels_by_item[ $shopify_item_id ] as $level ) {
			$total_available += isset( $level['available'] ) ? intval( $level['available'] ) : 0;
		}

		// Find WooCommerce product by SKU
		global $wpdb;
		$product_ids = $wpdb->get_col(
			$wpdb->prepare(
				"
				SELECT posts.ID
				FROM {$wpdb->posts} as posts
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
				WHERE
				posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.sku = %s
				",
				$wc_sku
			)
		);

		if ( empty( $product_ids ) ) {
			SWB_Logger::warning( 'Manual sync failed: WooCommerce product with SKU not found.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
			) );
			return false;
		}

		if ( count( $product_ids ) > 1 ) {
			SWB_Logger::error( 'Manual sync failed: Multiple WooCommerce products found with same SKU.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'matching_ids'    => $product_ids,
			) );
			return false;
		}

		$product_id = $product_ids[0];
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			SWB_Logger::warning( 'Manual sync failed: Could not load WooCommerce product.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'wc_product_id'   => $product_id,
			) );
			return false;
		}

		// Prevent updating parent variable products
		if ( $product->is_type( 'variable' ) ) {
			SWB_Logger::error( 'Manual sync failed: Target SKU belongs to a variable product parent. A specific variation SKU is required.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'wc_product_id'   => $product_id,
			) );
			return false;
		}

		// Check if product manages stock
		if ( ! $product->managing_stock() ) {
			SWB_Logger::info( 'Manual sync skipped: WooCommerce product is not managing stock.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'wc_product_id'   => $product_id,
			) );
			return false;
		}

		// Update stock
		$current_stock = $product->get_stock_quantity();

		if ( $current_stock !== $total_available ) {
			$result = wc_update_product_stock( $product, $total_available, 'set' );

			if ( is_wp_error( $result ) ) {
				SWB_Logger::error( 'Manual stock update failed.', array(
					'shopify_item_id' => $shopify_item_id,
					'wc_sku'          => $wc_sku,
					'wc_product_id'   => $product_id,
					'error'           => $result->get_error_message(),
				) );
				return false;
			}

			SWB_Logger::info( 'Manual stock update successful.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'wc_product_id'   => $product_id,
				'old_stock'       => $current_stock,
				'new_stock'       => $total_available,
			) );
		} else {
			SWB_Logger::info( 'Manual sync: Stock already up to date.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'wc_product_id'   => $product_id,
				'stock'           => $current_stock,
			) );
		}

		return true;
	}

	/**
	 * Process bulk action.
	 */
	public function process_bulk_action() {
		$state_args = self::get_preserved_state_query_args();

		if ( 'sync_images' === $this->current_action() ) {
			if ( isset( $_GET['mapping'] ) ) {
				$mapping_id = absint( $_GET['mapping'] );

				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'swb_sync_images_mapping_' . $mapping_id ) ) {
					die( 'Go get a life script kiddies' );
				}

				$mapping = SWB_DB::get_mapping( $mapping_id );
				if ( ! $mapping ) {
					$this->set_media_sync_status( $mapping_id, 'error', __( 'Mapping not found.', 'shopify-woo-bridge' ) );
					wp_safe_redirect(
						add_query_arg(
							array_merge(
								array(
								'page'        => 'shopify-bridge-mappings',
								'tab'         => 'mappings',
								'swb_notice'  => '1',
								'swb_type'    => 'error',
								'swb_message' => __( 'Mapping not found.', 'shopify-woo-bridge' ),
								),
								$state_args
							),
							admin_url( 'admin.php' )
						)
					);
					exit;
				}

				$sync_service = new SWB_Image_Sync();
				$result       = $sync_service->sync_images_for_mapping( $mapping );

				if ( ! empty( $result['success'] ) ) {
					$this->set_media_sync_status( $mapping_id, ! empty( $result['changed'] ) ? 'changed' : 'unchanged', '' );
					$type    = 'success';
					$message = ! empty( $result['message'] ) ? $result['message'] : __( 'Image sync completed.', 'shopify-woo-bridge' );
				} else {
					$message = ! empty( $result['message'] ) ? $result['message'] : __( 'Image sync failed.', 'shopify-woo-bridge' );
					$this->set_media_sync_status( $mapping_id, 'error', $message );
					$type = 'error';
				}

				wp_safe_redirect(
					add_query_arg(
						array_merge(
							array(
							'page'        => 'shopify-bridge-mappings',
							'tab'         => 'mappings',
							'swb_notice'  => '1',
							'swb_type'    => $type,
							'swb_message' => $message,
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		if ( 'sync' === $this->current_action() ) {
			// Handle sync action.
			if ( isset( $_GET['mapping'] ) ) {
				$mapping_id = absint( $_GET['mapping'] );

				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'swb_sync_mapping_' . $mapping_id ) ) {
					die( 'Go get a life script kiddies' );
				}

				$mapping = SWB_DB::get_mapping( $mapping_id );
				if ( $mapping ) {
					$this->sync_inventory_for_mapping( $mapping );
				}

				wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'shopify-bridge-mappings', 'tab' => 'mappings' ), $state_args ), admin_url( 'admin.php' ) ) );
				exit;
			}
		}

		if ( 'delete' === $this->current_action() ) {
			// Handle single delete.
			if ( isset( $_GET['mapping'] ) ) {
				$mapping_id = absint( $_GET['mapping'] );

				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'swb_delete_mapping_' . $mapping_id ) ) {
					die( 'Go get a life script kiddies' );
				}

				SWB_DB::delete_mapping( $mapping_id );

				// redirect to avoid resubmission
				wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'shopify-bridge-mappings', 'tab' => 'mappings' ), $state_args ), admin_url( 'admin.php' ) ) );
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

				wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'shopify-bridge-mappings', 'tab' => 'mappings' ), $state_args ), admin_url( 'admin.php' ) ) );
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

			wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'shopify-bridge-mappings', 'tab' => 'mappings' ), $state_args ), admin_url( 'admin.php' ) ) );
			exit;
		}
	}

	/**
	 * Persist last media sync result/error for mapping row status display.
	 *
	 * @param int    $mapping_id Mapping ID.
	 * @param string $result changed|unchanged|error.
	 * @param string $error_message Error message.
	 */
	private function set_media_sync_status( $mapping_id, $result, $error_message ) {
		$mapping_id = absint( $mapping_id );
		if ( $mapping_id <= 0 ) {
			return;
		}

		update_option( 'swb_mapping_media_sync_result_' . $mapping_id, sanitize_key( $result ) );

		if ( '' === trim( (string) $error_message ) ) {
			delete_option( 'swb_mapping_media_sync_error_' . $mapping_id );
			return;
		}

		update_option( 'swb_mapping_media_sync_error_' . $mapping_id, sanitize_text_field( $error_message ) );
	}
}
