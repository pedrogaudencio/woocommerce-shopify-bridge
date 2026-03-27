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
	 * Option key prefix for selected bulk image sync jobs.
	 *
	 * @var string
	 */
	const SELECTED_BULK_IMAGE_SYNC_JOB_OPTION_PREFIX = 'swb_selected_bulk_image_sync_job_';

	/**
	 * Default number of selected mappings to process per request.
	 *
	 * @var int
	 */
	const SELECTED_BULK_IMAGE_SYNC_BATCH_SIZE = 12;

	/**
	 * Last successful stock sync timestamps keyed by Shopify item ID.
	 *
	 * @var array<string,string>
	 */
	private $last_stock_sync_by_item_id = array();

	/**
	 * Last successful stock change payload keyed by Shopify item ID.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $last_stock_change_by_item_id = array();

	/**
	 * Cached media sync sort data keyed by mapping ID.
	 *
	 * @var array<int,array<string,int>>
	 */
	private $media_sync_sort_data_by_mapping_id = array();

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
	 * Get existing variation gallery value used by woo-product-gallery-slider.
	 *
	 * @param array|object $item Mapping row.
	 * @return string
	 */
	private function get_existing_variation_wavi_value( $item ) {
		$wc_sku = trim( (string) $this->get_item_value( $item, 'wc_sku', '' ) );
		if ( '' === $wc_sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return '';
		}

		$product_id = absint( wc_get_product_id_by_sku( $wc_sku ) );
		if ( $product_id <= 0 ) {
			return '';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return '';
		}

		return trim( (string) get_post_meta( $product_id, 'wavi_value', true ) );
	}

	/**
	 * Build onclick confirmation attribute when existing variation images will be overridden.
	 *
	 * @param array|object $item Mapping row.
	 * @return string
	 */
	private function get_sync_images_confirm_attr( $item ) {
		$existing_wavi_value = $this->get_existing_variation_wavi_value( $item );
		if ( '' === $existing_wavi_value ) {
			return '';
		}

		$preview = $existing_wavi_value;
		if ( strlen( $preview ) > 60 ) {
			$preview = substr( $preview, 0, 57 ) . '...';
		}

		$message = sprintf(
			/* translators: %s existing variation gallery attachment IDs. */
			__( 'This variation already has additional images (%s). Syncing images will override them. Continue?', 'shopify-woo-bridge' ),
			$preview
		);

		return ' onclick="return window.confirm(\'' . esc_js( $message ) . '\');"';
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
	 * Read mapping state filter from request.
	 *
	 * @return string
	 */
	private static function get_mapping_state_filter() {
		$allowed = array( 'all', 'enabled', 'invalidated', 'disabled', 'not-synced', 'synced', 'stock-synced', 'stock-not-synced' );
		$value   = isset( $_REQUEST['swb_mapping_state'] ) ? sanitize_key( $_REQUEST['swb_mapping_state'] ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $value, $allowed, true ) ) {
			return 'all';
		}

		return $value;
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
	 * Read sortable orderby value from request.
	 *
	 * @return string
	 */
	private static function get_orderby_filter() {
		$allowed = array( 'last_stock_synced', 'media_sync_status', 'created_at' );
		$value   = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $value, $allowed, true ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Read sortable order direction from request.
	 *
	 * @return string
	 */
	private static function get_order_direction_filter() {
		$value = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( $_REQUEST['order'] ) ) : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $value, array( 'asc', 'desc' ), true ) ) {
			return 'asc';
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

		$mapping_state = self::get_mapping_state_filter();
		if ( 'all' !== $mapping_state ) {
			$args['swb_mapping_state'] = $mapping_state;
		}

		$orderby = self::get_orderby_filter();
		if ( '' !== $orderby ) {
			$args['orderby'] = $orderby;
			$args['order']   = self::get_order_direction_filter();
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

		$current_filter       = self::get_product_type_filter();
		$current_state_filter = self::get_mapping_state_filter();
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
			<label class="screen-reader-text" for="swb-mapping-state-filter"><?php esc_html_e( 'Filter by mapping status', 'shopify-woo-bridge' ); ?></label>
			<select name="swb_mapping_state" id="swb-mapping-state-filter">
				<option value="all" <?php selected( $current_state_filter, 'all' ); ?>><?php esc_html_e( 'All statuses', 'shopify-woo-bridge' ); ?></option>
				<option value="enabled" <?php selected( $current_state_filter, 'enabled' ); ?>><?php esc_html_e( 'Enabled', 'shopify-woo-bridge' ); ?></option>
				<option value="synced" <?php selected( $current_state_filter, 'synced' ); ?>><?php esc_html_e( 'Media synced', 'shopify-woo-bridge' ); ?></option>
				<option value="stock-synced" <?php selected( $current_state_filter, 'stock-synced' ); ?>><?php esc_html_e( 'Stock synced', 'shopify-woo-bridge' ); ?></option>
				<option value="invalidated" <?php selected( $current_state_filter, 'invalidated' ); ?>><?php esc_html_e( 'Invalidated', 'shopify-woo-bridge' ); ?></option>
				<option value="disabled" <?php selected( $current_state_filter, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'shopify-woo-bridge' ); ?></option>
				<option value="not-synced" <?php selected( $current_state_filter, 'not-synced' ); ?>><?php esc_html_e( 'Media not synced', 'shopify-woo-bridge' ); ?></option>
				<option value="stock-not-synced" <?php selected( $current_state_filter, 'stock-not-synced' ); ?>><?php esc_html_e( 'Stock not synced', 'shopify-woo-bridge' ); ?></option>
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
			case 'last_stock_synced':
				return $this->column_last_stock_synced( $item );
			case 'last_stock_change':
				return $this->column_last_stock_change( $item );
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
		$reset_media_nonce = wp_create_nonce( 'swb_reset_media_status_' . $id );

		$title = '<strong>' . esc_html( $this->get_item_value( $item, 'shopify_item_id' ) ) . '</strong>';
		$page  = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : 'shopify-bridge-mappings';
		$is_enabled = (int) $this->get_item_value( $item, 'is_enabled', 0 );

		$state_args = self::get_preserved_state_query_args();
		$sync_images_confirm_attr = $this->get_sync_images_confirm_attr( $item );
		$actions = array(
			'sync' => sprintf(
				'<a href="%s" class="swb-long-action-link" data-swb-long-action="1">%s</a>',
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
				__( 'Sync stock', 'shopify-woo-bridge' )
			),
			'sync_images' => sprintf(
				'<a href="%s" class="swb-long-action-link" data-swb-long-action="1" %s>%s</a>',
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
				$sync_images_confirm_attr,
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
			'reset_media_status' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array_merge(
							array(
								'page'     => $page,
								'tab'      => 'mappings',
								'action'   => 'reset_media_status',
								'mapping'  => $id,
								'_wpnonce' => $reset_media_nonce,
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				),
				__( 'Reset Media Status', 'shopify-woo-bridge' )
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
			return esc_html__( 'Media not synced', 'shopify-woo-bridge' );
		}

		$product      = wc_get_product( $product_id );
		$last_synced  = (string) get_post_meta( $product_id, 'shopify_sync_last_media_synced_at', true );
		$last_hash    = (string) get_post_meta( $product_id, 'shopify_sync_last_media_hash', true );
		$last_sig     = (string) get_post_meta( $product_id, 'shopify_sync_last_media_signature', true );
		$current_sig  = $this->build_current_local_media_signature( $product );

		$mapping_id  = absint( $this->get_item_value( $item, 'id', 0 ) );
		$error       = (string) get_option( 'swb_mapping_media_sync_error_' . $mapping_id, '' );
		$last_result = (string) get_option( 'swb_mapping_media_sync_result_' . $mapping_id, '' );

		if ( 'invalidated' === $last_result ) {
			$status = '<strong style="color:#b32d2e;">' . esc_html__( 'Invalidated', 'shopify-woo-bridge' ) . '</strong>';
			if ( '' !== $error ) {
				$status .= '<br/><small style="color:#b32d2e;">' . esc_html( $error ) . '</small>';
			} else {
				$status .= '<br/><small>' . esc_html__( 'Status was reset manually.', 'shopify-woo-bridge' ) . '</small>';
			}

			return $status;
		}

		if ( '' === $last_synced ) {
			$status = esc_html__( 'Media not synced', 'shopify-woo-bridge' );
		} else {
			$status = esc_html__( 'Media synced', 'shopify-woo-bridge' ) . ': ' . esc_html( $last_synced );
		}

		if ( '' !== $last_synced && '' !== $last_sig && '' !== $current_sig && ! hash_equals( $last_sig, $current_sig ) ) {
			return '<strong style="color:#b32d2e;">' . esc_html__( 'Invalidated', 'shopify-woo-bridge' ) . '</strong><br/><small>' . esc_html__( 'Media changed since the last sync.', 'shopify-woo-bridge' ) . '</small>';
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
	 * Build local media signature from current WooCommerce assignment.
	 *
	 * @param WC_Product|false $product Product object.
	 * @return string
	 */
	private function build_current_local_media_signature( $product ) {
		if ( ! $product ) {
			return '';
		}

		if ( $product->is_type( 'variation' ) ) {
			$variation_gallery_raw = (string) get_post_meta( $product->get_id(), '_product_image_gallery', true );
			$variation_gallery_ids = array_values(
				array_filter(
					array_map( 'absint', explode( ',', $variation_gallery_raw ) )
				)
			);

			$payload = array(
				'image_id' => absint( $product->get_image_id() ),
				'gallery'  => $variation_gallery_ids,
			);

			return md5( wp_json_encode( $payload ) );
		}

		$gallery_ids = array_map( 'absint', array_values( (array) $product->get_gallery_image_ids() ) );
		$payload     = array(
			'featured' => absint( $product->get_image_id() ),
			'gallery'  => $gallery_ids,
		);

		return md5( wp_json_encode( $payload ) );
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
			$title        = $product->get_name();
			$edit_post_id = absint( $product_id );
			if ( $product->is_type( 'variation' ) ) {
				$parent_id = absint( $product->get_parent_id() );
				if ( $parent_id > 0 ) {
					$edit_post_id = $parent_id;
				}
			}
			$edit_url = add_query_arg(
				array(
					'post'   => $edit_post_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
			$type_label = $product->is_type( 'variation' ) ? __( 'Variable Product', 'shopify-woo-bridge' ) : __( 'Product', 'shopify-woo-bridge' );
			return sprintf( '<strong>%s</strong><br><a href="%s">%s (ID: %d - %s)</a>', $wc_sku, esc_url( $edit_url ), esc_html( $title ), $edit_post_id, $type_label );
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
			'last_stock_synced'  => __( 'Last Stock Synced', 'shopify-woo-bridge' ),
			'last_stock_change'  => __( 'Last Stock Change', 'shopify-woo-bridge' ),
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
		return array(
			'last_stock_synced' => array( 'last_stock_synced', false ),
			'media_sync_status' => array( 'media_sync_status', false ),
			'created_at'        => array( 'created_at', true ),
		);
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = array(
			'bulk-sync-stock'         => __( 'Sync stock', 'shopify-woo-bridge' ),
			'bulk-sync-images'        => __( 'Sync images', 'shopify-woo-bridge' ),
			'bulk-reset-media-status' => __( 'Reset media status', 'shopify-woo-bridge' ),
			'bulk-enable'             => __( 'Enable', 'shopify-woo-bridge' ),
			'bulk-disable'            => __( 'Disable', 'shopify-woo-bridge' ),
			'bulk-delete'             => __( 'Delete', 'shopify-woo-bridge' ),
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

		$per_page      = max( 1, (int) $this->get_items_per_page( 'mappings_per_page', 20 ) );
		$mapping_state = self::get_mapping_state_filter();
		$orderby       = self::get_orderby_filter();
		$order         = self::get_order_direction_filter();
		$has_sort      = '' !== $orderby;

		if ( 'all' === $mapping_state && ! $has_sort ) {
			$total_items  = (int) self::record_count();
			$total_pages  = max( 1, (int) ceil( $total_items / $per_page ) );
			$current_page = min( $this->get_pagenum(), $total_pages );
			$this->items  = self::get_mappings( $per_page, $current_page );
		} else {
			$base_total    = (int) self::record_count();
			$all_items     = $base_total > 0 ? self::get_mappings( $base_total, 1 ) : array();
			$filtered      = 'all' === $mapping_state ? $all_items : $this->filter_items_by_mapping_state( $all_items, $mapping_state );

			if ( $has_sort ) {
				$this->sort_items_for_list_table( $filtered, $orderby, $order );
			}

			$total_items   = count( $filtered );
			$total_pages   = max( 1, (int) ceil( $total_items / $per_page ) );
			$current_page  = min( $this->get_pagenum(), $total_pages );
			$offset        = max( 0, ( $current_page - 1 ) * $per_page );
			$this->items   = array_slice( $filtered, $offset, $per_page );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items, // WE have to calculate the total number of items
				'per_page'    => $per_page, // WE have to determine how many items to show on a page
				'total_pages' => $total_pages, // WE have to calculate the total number of pages
			)
		);

		if ( ! is_array( $this->items ) ) {
			$this->items = array();
		}

		$this->prime_last_stock_sync_cache( $this->items );
	}

	/**
	 * Sort list table items by supported orderby values.
	 *
	 * @param array  $items Items to sort.
	 * @param string $orderby Supported orderby key.
	 * @param string $order asc|desc.
	 * @return void
	 */
	private function sort_items_for_list_table( &$items, $orderby, $order ) {
		if ( ! is_array( $items ) || count( $items ) < 2 ) {
			return;
		}

		$direction = 'desc' === $order ? -1 : 1;

		if ( 'last_stock_synced' === $orderby ) {
			$this->prime_last_stock_sync_cache( $items );
		}

		if ( 'media_sync_status' === $orderby ) {
			$this->media_sync_sort_data_by_mapping_id = array();
		}

		usort(
			$items,
			function ( $a, $b ) use ( $orderby, $direction ) {
				$comparison = 0;

				if ( 'created_at' === $orderby ) {
					$comparison = strcmp(
						(string) $this->get_item_value( $a, 'created_at', '' ),
						(string) $this->get_item_value( $b, 'created_at', '' )
					);
				} elseif ( 'last_stock_synced' === $orderby ) {
					$comparison = $this->compare_last_stock_synced_sort_values( $a, $b );
				} elseif ( 'media_sync_status' === $orderby ) {
					$comparison = $this->compare_media_sync_status_sort_values( $a, $b );
				}

				if ( 0 === $comparison ) {
					$comparison = absint( $this->get_item_value( $a, 'id', 0 ) ) <=> absint( $this->get_item_value( $b, 'id', 0 ) );
				}

				return $comparison * $direction;
			}
		);
	}

	/**
	 * Compare items by last successful stock sync timestamp.
	 *
	 * @param array|object $a Item A.
	 * @param array|object $b Item B.
	 * @return int
	 */
	private function compare_last_stock_synced_sort_values( $a, $b ) {
		$a_timestamp = $this->get_last_stock_synced_timestamp_for_sort( $a );
		$b_timestamp = $this->get_last_stock_synced_timestamp_for_sort( $b );

		return $a_timestamp <=> $b_timestamp;
	}

	/**
	 * Get UNIX timestamp used for Last Stock Synced sorting.
	 *
	 * @param array|object $item Mapping row.
	 * @return int
	 */
	private function get_last_stock_synced_timestamp_for_sort( $item ) {
		$shopify_item_id = trim( (string) $this->get_item_value( $item, 'shopify_item_id', '' ) );
		if ( '' === $shopify_item_id || ! isset( $this->last_stock_sync_by_item_id[ $shopify_item_id ] ) ) {
			return 0;
		}

		$last_synced = (string) $this->last_stock_sync_by_item_id[ $shopify_item_id ];
		if ( '' === $last_synced ) {
			return 0;
		}

		$timestamp = (int) mysql2date( 'U', $last_synced, true );

		return $timestamp > 0 ? $timestamp : 0;
	}

	/**
	 * Compare items by media sync status rank and timestamp.
	 *
	 * @param array|object $a Item A.
	 * @param array|object $b Item B.
	 * @return int
	 */
	private function compare_media_sync_status_sort_values( $a, $b ) {
		$a_data = $this->get_media_sync_status_sort_data( $a );
		$b_data = $this->get_media_sync_status_sort_data( $b );

		if ( $a_data['rank'] !== $b_data['rank'] ) {
			return $a_data['rank'] <=> $b_data['rank'];
		}

		return $a_data['timestamp'] <=> $b_data['timestamp'];
	}

	/**
	 * Build sortable media status metadata for a mapping row.
	 *
	 * Rank order (asc): invalidated, not synced, synced, not available.
	 *
	 * @param array|object $item Mapping row.
	 * @return array<string,int>
	 */
	private function get_media_sync_status_sort_data( $item ) {
		$mapping_id = absint( $this->get_item_value( $item, 'id', 0 ) );
		if ( $mapping_id > 0 && isset( $this->media_sync_sort_data_by_mapping_id[ $mapping_id ] ) ) {
			return $this->media_sync_sort_data_by_mapping_id[ $mapping_id ];
		}

		$data = array(
			'rank'      => 3,
			'timestamp' => 0,
		);

		$wc_sku = (string) $this->get_item_value( $item, 'wc_sku', '' );
		if ( '' === $wc_sku ) {
			if ( $mapping_id > 0 ) {
				$this->media_sync_sort_data_by_mapping_id[ $mapping_id ] = $data;
			}

			return $data;
		}

		$last_result = (string) get_option( 'swb_mapping_media_sync_result_' . $mapping_id, '' );
		if ( 'invalidated' === $last_result ) {
			$data['rank'] = 0;
			if ( $mapping_id > 0 ) {
				$this->media_sync_sort_data_by_mapping_id[ $mapping_id ] = $data;
			}

			return $data;
		}

		$product_id = $this->find_wc_product_id_by_sku_for_status( $wc_sku );
		if ( $product_id <= 0 ) {
			$data['rank'] = 1;
			if ( $mapping_id > 0 ) {
				$this->media_sync_sort_data_by_mapping_id[ $mapping_id ] = $data;
			}

			return $data;
		}

		$product     = wc_get_product( $product_id );
		$last_synced = (string) get_post_meta( $product_id, 'shopify_sync_last_media_synced_at', true );
		$last_sig    = (string) get_post_meta( $product_id, 'shopify_sync_last_media_signature', true );
		$current_sig = $this->build_current_local_media_signature( $product );

		if ( '' !== $last_synced && '' !== $last_sig && '' !== $current_sig && ! hash_equals( $last_sig, $current_sig ) ) {
			$data['rank'] = 0;
			$data['timestamp'] = (int) mysql2date( 'U', $last_synced, true );
		} elseif ( '' === $last_synced ) {
			$data['rank'] = 1;
		} else {
			$data['rank'] = 2;
			$data['timestamp'] = (int) mysql2date( 'U', $last_synced, true );
		}

		if ( $mapping_id > 0 ) {
			$this->media_sync_sort_data_by_mapping_id[ $mapping_id ] = $data;
		}

		return $data;
	}

	/**
	 * Prime stock sync timestamp cache for rows shown on the current page.
	 *
	 * @param array $items Mapping rows.
	 * @return void
	 */
	private function prime_last_stock_sync_cache( $items ) {
		$this->last_stock_sync_by_item_id = array();
		$this->last_stock_change_by_item_id = array();
		$shopify_item_ids                 = array();

		foreach ( (array) $items as $item ) {
			$shopify_item_id = trim( (string) $this->get_item_value( $item, 'shopify_item_id', '' ) );
			if ( '' !== $shopify_item_id ) {
				$shopify_item_ids[] = $shopify_item_id;
			}
		}

		if ( empty( $shopify_item_ids ) ) {
			return;
		}

		$this->last_stock_sync_by_item_id = SWB_DB::get_last_successful_stock_sync_by_item_ids( $shopify_item_ids );
		$this->last_stock_change_by_item_id = SWB_DB::get_last_successful_stock_change_by_item_ids( $shopify_item_ids );
	}

	/**
	 * Render last successful stock sync timestamp for a mapping row.
	 *
	 * @param array|object $item Mapping row.
	 * @return string
	 */
	public function column_last_stock_synced( $item ) {
		$shopify_item_id = trim( (string) $this->get_item_value( $item, 'shopify_item_id', '' ) );
		if ( '' === $shopify_item_id ) {
			return esc_html__( 'Not available', 'shopify-woo-bridge' );
		}

		if ( isset( $this->last_stock_sync_by_item_id[ $shopify_item_id ] ) && '' !== $this->last_stock_sync_by_item_id[ $shopify_item_id ] ) {
			return esc_html( $this->format_stock_sync_datetime_for_display( $this->last_stock_sync_by_item_id[ $shopify_item_id ] ) );
		}

		return esc_html__( 'Never synced', 'shopify-woo-bridge' );
	}

	/**
	 * Render last successful stock change values for a mapping row.
	 *
	 * @param array|object $item Mapping row.
	 * @return string
	 */
	public function column_last_stock_change( $item ) {
		$shopify_item_id = trim( (string) $this->get_item_value( $item, 'shopify_item_id', '' ) );
		if ( '' === $shopify_item_id ) {
			return esc_html__( 'Not available', 'shopify-woo-bridge' );
		}

		if ( ! isset( $this->last_stock_change_by_item_id[ $shopify_item_id ] ) ) {
			return esc_html__( 'No history', 'shopify-woo-bridge' );
		}

		$change    = (array) $this->last_stock_change_by_item_id[ $shopify_item_id ];
		$old_stock = array_key_exists( 'old_stock', $change ) && null !== $change['old_stock'] ? (string) intval( $change['old_stock'] ) : '-';
		$new_stock = array_key_exists( 'new_stock', $change ) && null !== $change['new_stock'] ? (string) intval( $change['new_stock'] ) : '-';

		return esc_html( $old_stock . ' -> ' . $new_stock );
	}

	/**
	 * Format stock sync datetime for admin display using site settings.
	 *
	 * @param string $mysql_datetime Datetime value from DB (Y-m-d H:i:s).
	 * @return string
	 */
	private function format_stock_sync_datetime_for_display( $mysql_datetime ) {
		$mysql_datetime = trim( (string) $mysql_datetime );
		if ( '' === $mysql_datetime ) {
			return '';
		}

		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		if ( '' === $format ) {
			$format = 'Y-m-d H:i:s';
		}

		// created_at is stored by MySQL, so convert from GMT timestamp into site timezone for display.
		$timestamp = mysql2date( 'U', $mysql_datetime, true );
		if ( ! $timestamp ) {
			return $mysql_datetime;
		}

		return wp_date( $format, (int) $timestamp, wp_timezone() );
	}

	/**
	 * Filter rows by selected mapping state.
	 *
	 * @param array  $items Mapping rows.
	 * @param string $mapping_state Mapping state filter.
	 * @return array
	 */
	private function filter_items_by_mapping_state( $items, $mapping_state ) {
		if ( 'all' === $mapping_state ) {
			return is_array( $items ) ? $items : array();
		}

		$last_stock_sync_by_item_id = array();
		if ( in_array( $mapping_state, array( 'stock-synced', 'stock-not-synced' ), true ) ) {
			$shopify_item_ids = array();
			foreach ( (array) $items as $item ) {
				$shopify_item_id = trim( (string) $this->get_item_value( $item, 'shopify_item_id', '' ) );
				if ( '' !== $shopify_item_id ) {
					$shopify_item_ids[] = $shopify_item_id;
				}
			}

			if ( ! empty( $shopify_item_ids ) ) {
				$last_stock_sync_by_item_id = SWB_DB::get_last_successful_stock_sync_by_item_ids( $shopify_item_ids );
			}
		}

		$filtered = array();
		foreach ( (array) $items as $item ) {
			$is_enabled = (int) $this->get_item_value( $item, 'is_enabled', 0 );
			$shopify_item_id = trim( (string) $this->get_item_value( $item, 'shopify_item_id', '' ) );

			if ( 'enabled' === $mapping_state && 1 === $is_enabled ) {
				$filtered[] = $item;
				continue;
			}

			if ( 'disabled' === $mapping_state && 1 !== $is_enabled ) {
				$filtered[] = $item;
				continue;
			}

			if ( 'invalidated' === $mapping_state && $this->is_mapping_invalidated_for_filter( $item ) ) {
				$filtered[] = $item;
				continue;
			}

			if ( 'synced' === $mapping_state && $this->is_mapping_synced_for_filter( $item ) ) {
				$filtered[] = $item;
				continue;
			}

			if ( 'not-synced' === $mapping_state && $this->is_mapping_not_synced_for_filter( $item ) ) {
				$filtered[] = $item;
				continue;
			}

			if ( 'stock-synced' === $mapping_state && '' !== $shopify_item_id && isset( $last_stock_sync_by_item_id[ $shopify_item_id ] ) && '' !== (string) $last_stock_sync_by_item_id[ $shopify_item_id ] ) {
				$filtered[] = $item;
				continue;
			}

			if ( 'stock-not-synced' === $mapping_state ) {
				if ( '' === $shopify_item_id || ! isset( $last_stock_sync_by_item_id[ $shopify_item_id ] ) || '' === (string) $last_stock_sync_by_item_id[ $shopify_item_id ] ) {
					$filtered[] = $item;
					continue;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Determine whether a mapping should be treated as invalidated for filtering.
	 *
	 * @param array|object $item Mapping row.
	 * @return bool
	 */
	private function is_mapping_invalidated_for_filter( $item ) {
		$mapping_id  = absint( $this->get_item_value( $item, 'id', 0 ) );
		$last_result = (string) get_option( 'swb_mapping_media_sync_result_' . $mapping_id, '' );
		if ( 'invalidated' === $last_result ) {
			return true;
		}

		$wc_sku = (string) $this->get_item_value( $item, 'wc_sku', '' );
		if ( '' === $wc_sku ) {
			return false;
		}

		$product_id = $this->find_wc_product_id_by_sku_for_status( $wc_sku );
		if ( $product_id <= 0 ) {
			return false;
		}

		$product     = wc_get_product( $product_id );
		$last_synced = (string) get_post_meta( $product_id, 'shopify_sync_last_media_synced_at', true );
		$last_sig    = (string) get_post_meta( $product_id, 'shopify_sync_last_media_signature', true );
		$current_sig = $this->build_current_local_media_signature( $product );

		return '' !== $last_synced && '' !== $last_sig && '' !== $current_sig && ! hash_equals( $last_sig, $current_sig );
	}

	/**
	 * Determine whether a mapping should be treated as not synced for filtering.
	 *
	 * @param array|object $item Mapping row.
	 * @return bool
	 */
	private function is_mapping_not_synced_for_filter( $item ) {
		$wc_sku = (string) $this->get_item_value( $item, 'wc_sku', '' );
		if ( '' === $wc_sku ) {
			return false;
		}

		$product_id = $this->find_wc_product_id_by_sku_for_status( $wc_sku );
		if ( $product_id <= 0 ) {
			return true; // Product not found means media hasn't been synced yet
		}

		$last_synced = (string) get_post_meta( $product_id, 'shopify_sync_last_media_synced_at', true );
		return '' === $last_synced; // Not synced if no sync timestamp exists
	}

	/**
	 * Determine whether a mapping should be treated as synced for filtering.
	 *
	 * @param array|object $item Mapping row.
	 * @return bool
	 */
	private function is_mapping_synced_for_filter( $item ) {
		if ( $this->is_mapping_invalidated_for_filter( $item ) ) {
			return false;
		}

		$wc_sku = (string) $this->get_item_value( $item, 'wc_sku', '' );
		if ( '' === $wc_sku ) {
			return false;
		}

		$product_id = $this->find_wc_product_id_by_sku_for_status( $wc_sku );
		if ( $product_id <= 0 ) {
			return false;
		}

		$last_synced = (string) get_post_meta( $product_id, 'shopify_sync_last_media_synced_at', true );
		return '' !== $last_synced;
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
				SWB_DB::log_stock_update(
					array(
						'shopify_item_id' => $shopify_item_id,
						'wc_sku'          => $wc_sku,
						'wc_product_id'   => $product_id,
						'old_stock'       => $current_stock,
						'new_stock'       => $total_available,
						'source'          => 'manual',
						'status'          => 'failed',
						'error_message'   => $result->get_error_message(),
					)
				);

				SWB_Logger::error( 'Manual stock update failed.', array(
					'shopify_item_id' => $shopify_item_id,
					'wc_sku'          => $wc_sku,
					'wc_product_id'   => $product_id,
					'error'           => $result->get_error_message(),
				) );
				return false;
			}

			SWB_DB::log_stock_update(
				array(
					'shopify_item_id' => $shopify_item_id,
					'wc_sku'          => $wc_sku,
					'wc_product_id'   => $product_id,
					'old_stock'       => $current_stock,
					'new_stock'       => $total_available,
					'source'          => 'manual',
					'status'          => 'success',
				)
			);

			SWB_Logger::info( 'Manual stock update successful.', array(
				'shopify_item_id' => $shopify_item_id,
				'wc_sku'          => $wc_sku,
				'wc_product_id'   => $product_id,
				'old_stock'       => $current_stock,
				'new_stock'       => $total_available,
			) );
		} else {
			SWB_DB::log_stock_update(
				array(
					'shopify_item_id' => $shopify_item_id,
					'wc_sku'          => $wc_sku,
					'wc_product_id'   => $product_id,
					'old_stock'       => $current_stock,
					'new_stock'       => $current_stock,
					'source'          => 'manual',
					'status'          => 'success',
				)
			);

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
		$bulk_action = $this->current_action();

		if ( isset( $_GET['swb_bulk_sync_images_selected_continue'], $_GET['swb_bulk_sync_images_selected_job'] ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Unauthorized.' );
			}

			$job_token = preg_replace( '/[^a-zA-Z0-9]/', '', (string) wp_unslash( $_GET['swb_bulk_sync_images_selected_job'] ) );
			if ( '' === $job_token ) {
				wp_safe_redirect(
					add_query_arg(
						array_merge(
							array(
								'page'        => 'shopify-bridge-mappings',
								'tab'         => 'mappings',
								'swb_notice'  => '1',
								'swb_type'    => 'error',
								'swb_message' => __( 'Selected bulk image sync session is invalid.', 'shopify-woo-bridge' ),
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			if ( ! isset( $_GET['swb_job_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['swb_job_nonce'] ) ), 'swb_selected_bulk_sync_images_job_' . $job_token ) ) {
				wp_die( 'Security check failed.' );
			}

			$this->process_selected_bulk_image_sync_job( $job_token );
			exit;
		}

		if ( in_array( $bulk_action, array( 'bulk-sync-stock', 'bulk-sync-images', 'bulk-reset-media-status', 'bulk-enable', 'bulk-disable' ), true ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Unauthorized.' );
			}

			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'bulk-' . $this->_args['plural'] ) ) {
				die( 'Go get a life script kiddies' );
			}

			$mapping_ids = isset( $_POST['bulk-delete'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bulk-delete'] ) ) : array();
			$mapping_ids = array_values( array_filter( $mapping_ids ) );

			if ( empty( $mapping_ids ) ) {
				wp_safe_redirect(
					add_query_arg(
						array_merge(
							array(
								'page'        => 'shopify-bridge-mappings',
								'tab'         => 'mappings',
								'swb_notice'  => '1',
								'swb_type'    => 'error',
								'swb_message' => __( 'No mappings selected.', 'shopify-woo-bridge' ),
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			if ( 'bulk-sync-images' === $bulk_action ) {
				$this->start_selected_bulk_image_sync_job( $mapping_ids, $state_args );
				exit;
			}

			$failed      = 0;
			$processed   = 0;
			$skipped     = 0;
			$updated     = 0;
			$unchanged   = 0;
			$enabled     = 0;
			$disabled    = 0;
			$sync_images = null;

			foreach ( $mapping_ids as $mapping_id ) {
				$mapping = SWB_DB::get_mapping( $mapping_id );
				if ( ! $mapping ) {
					$failed++;
					continue;
				}

				if ( 'bulk-reset-media-status' === $bulk_action ) {
					$this->reset_media_sync_status_for_mapping( $mapping );
					$processed++;
					continue;
				}

				if ( 'bulk-enable' === $bulk_action ) {
					$result = SWB_DB::update_mapping( $mapping_id, array( 'is_enabled' => 1 ) );
					if ( false === $result ) {
						$failed++;
						continue;
					}

					$enabled++;
					continue;
				}

				if ( 'bulk-disable' === $bulk_action ) {
					$result = SWB_DB::update_mapping( $mapping_id, array( 'is_enabled' => 0 ) );
					if ( false === $result ) {
						$failed++;
						continue;
					}

					$disabled++;
					continue;
				}

				$is_enabled = (int) $this->get_item_value( $mapping, 'is_enabled', 0 );
				if ( 1 !== $is_enabled ) {
					$skipped++;
					continue;
				}

				if ( 'bulk-sync-stock' === $bulk_action ) {
					$processed++;
					if ( $this->sync_inventory_for_mapping( $mapping ) ) {
						$updated++;
					} else {
						$failed++;
					}
					continue;
				}

				$shopify_product_id = (string) $this->get_item_value( $mapping, 'shopify_product_id', '' );
				if ( '' === $shopify_product_id ) {
					$skipped++;
					continue;
				}

				$processed++;
				$result = $sync_images->sync_images_for_mapping( $mapping );

				if ( ! empty( $result['success'] ) ) {
					$this->set_media_sync_status( $mapping_id, ! empty( $result['changed'] ) ? 'changed' : 'unchanged', '' );
					if ( ! empty( $result['changed'] ) ) {
						$updated++;
					} else {
						$unchanged++;
					}
					continue;
				}

				$this->set_media_sync_status(
					$mapping_id,
					'error',
					! empty( $result['message'] ) ? $result['message'] : __( 'Image sync failed.', 'shopify-woo-bridge' )
				);
				$failed++;
			}

			$notice_type = $failed > 0 ? 'error' : 'success';
			$message     = '';

			if ( 'bulk-sync-stock' === $bulk_action ) {
				$message = sprintf(
					/* translators: 1: processed mappings, 2: synced mappings, 3: skipped mappings, 4: failed mappings. */
					__( 'Bulk stock sync complete. Processed: %1$d, Synced: %2$d, Skipped: %3$d, Failed: %4$d.', 'shopify-woo-bridge' ),
					$processed,
					$updated,
					$skipped,
					$failed
				);
			} elseif ( 'bulk-sync-images' === $bulk_action ) {
				$message = sprintf(
					/* translators: 1: processed mappings, 2: changed mappings, 3: unchanged mappings, 4: skipped mappings, 5: failed mappings. */
					__( 'Bulk image sync complete. Processed: %1$d, Changed: %2$d, Unchanged: %3$d, Skipped: %4$d, Failed: %5$d.', 'shopify-woo-bridge' ),
					$processed,
					$updated,
					$unchanged,
					$skipped,
					$failed
				);
			} elseif ( 'bulk-reset-media-status' === $bulk_action ) {
				$message = sprintf(
					/* translators: 1: processed mappings, 2: failed mappings. */
					__( 'Bulk reset complete. Reset: %1$d, Failed: %2$d.', 'shopify-woo-bridge' ),
					$processed,
					$failed
				);
			} elseif ( 'bulk-enable' === $bulk_action ) {
				$message = sprintf(
					/* translators: 1: enabled mappings, 2: failed mappings. */
					__( 'Bulk enable complete. Enabled: %1$d, Failed: %2$d.', 'shopify-woo-bridge' ),
					$enabled,
					$failed
				);
			} else {
				$message = sprintf(
					/* translators: 1: disabled mappings, 2: failed mappings. */
					__( 'Bulk disable complete. Disabled: %1$d, Failed: %2$d.', 'shopify-woo-bridge' ),
					$disabled,
					$failed
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array_merge(
						array(
							'page'        => 'shopify-bridge-mappings',
							'tab'         => 'mappings',
							'swb_notice'  => '1',
							'swb_type'    => $notice_type,
							'swb_message' => $message,
						),
						$state_args
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'reset_media_status' === $this->current_action() ) {
			if ( isset( $_GET['mapping'] ) ) {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Unauthorized.' );
				}

				$mapping_id = absint( $_GET['mapping'] );

				if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'swb_reset_media_status_' . $mapping_id ) ) {
					die( 'Go get a life script kiddies' );
				}

				$mapping = SWB_DB::get_mapping( $mapping_id );
				if ( ! $mapping ) {
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

				$this->reset_media_sync_status_for_mapping( $mapping );

				wp_safe_redirect(
					add_query_arg(
						array_merge(
							array(
								'page'        => 'shopify-bridge-mappings',
								'tab'         => 'mappings',
								'swb_notice'  => '1',
								'swb_type'    => 'success',
								'swb_message' => __( 'Media sync status reset and invalidated.', 'shopify-woo-bridge' ),
							),
							$state_args
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		if ( 'sync_images' === $this->current_action() ) {
			if ( isset( $_GET['mapping'] ) ) {
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Unauthorized.' );
				}

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
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Unauthorized.' );
				}

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
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Unauthorized.' );
				}

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
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					wp_die( 'Unauthorized.' );
				}

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
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( 'Unauthorized.' );
			}

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
	 * Start resumable selected-row bulk image sync.
	 *
	 * @param array $mapping_ids Selected mapping IDs.
	 * @param array $state_args Preserved table state args.
	 * @return void
	 */
	private function start_selected_bulk_image_sync_job( $mapping_ids, $state_args ) {
		$mapping_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $mapping_ids ) ) ) );
		$job_token   = wp_generate_password( 20, false, false );
		$option_key  = $this->get_selected_bulk_image_sync_job_option_key( $job_token );

		$state = array(
			'mapping_ids' => $mapping_ids,
			'offset'      => 0,
			'processed'   => 0,
			'updated'     => 0,
			'unchanged'   => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'state_args'  => is_array( $state_args ) ? $state_args : array(),
		);

		update_option( $option_key, $state, false );
		$this->redirect_selected_bulk_image_sync_job( $job_token, $state['state_args'] );
	}

	/**
	 * Process one batch of selected-row bulk image sync.
	 *
	 * @param string $job_token Job token.
	 * @return void
	 */
	private function process_selected_bulk_image_sync_job( $job_token ) {
		$option_key = $this->get_selected_bulk_image_sync_job_option_key( $job_token );
		$state      = get_option( $option_key, null );

		if ( ! is_array( $state ) || empty( $state['mapping_ids'] ) || ! is_array( $state['mapping_ids'] ) ) {
			delete_option( $option_key );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'shopify-bridge-mappings',
						'tab'         => 'mappings',
						'swb_notice'  => '1',
						'swb_type'    => 'error',
						'swb_message' => __( 'Selected bulk image sync session expired. Please run it again.', 'shopify-woo-bridge' ),
					),
					admin_url( 'admin.php' )
				)
			);
			return;
		}

		$mapping_ids = array_values( array_filter( array_map( 'absint', $state['mapping_ids'] ) ) );
		$offset      = isset( $state['offset'] ) ? absint( $state['offset'] ) : 0;
		$batch_size  = $this->get_selected_bulk_image_sync_batch_size();
		$max_index   = min( count( $mapping_ids ), $offset + $batch_size );
		$sync_images = new SWB_Image_Sync();

		for ( $index = $offset; $index < $max_index; $index++ ) {
			$mapping_id = $mapping_ids[ $index ];
			$mapping    = SWB_DB::get_mapping( $mapping_id );
			if ( ! $mapping ) {
				$state['failed'] = isset( $state['failed'] ) ? intval( $state['failed'] ) + 1 : 1;
				continue;
			}

			$is_enabled = (int) $this->get_item_value( $mapping, 'is_enabled', 0 );
			if ( 1 !== $is_enabled ) {
				$state['skipped'] = isset( $state['skipped'] ) ? intval( $state['skipped'] ) + 1 : 1;
				continue;
			}

			$shopify_product_id = (string) $this->get_item_value( $mapping, 'shopify_product_id', '' );
			if ( '' === $shopify_product_id ) {
				$state['skipped'] = isset( $state['skipped'] ) ? intval( $state['skipped'] ) + 1 : 1;
				continue;
			}

			$state['processed'] = isset( $state['processed'] ) ? intval( $state['processed'] ) + 1 : 1;
			$result             = $sync_images->sync_images_for_mapping( $mapping );

			if ( ! empty( $result['success'] ) ) {
				$this->set_media_sync_status( $mapping_id, ! empty( $result['changed'] ) ? 'changed' : 'unchanged', '' );
				if ( ! empty( $result['changed'] ) ) {
					$state['updated'] = isset( $state['updated'] ) ? intval( $state['updated'] ) + 1 : 1;
				} else {
					$state['unchanged'] = isset( $state['unchanged'] ) ? intval( $state['unchanged'] ) + 1 : 1;
				}
				continue;
			}

			$this->set_media_sync_status(
				$mapping_id,
				'error',
				! empty( $result['message'] ) ? $result['message'] : __( 'Image sync failed.', 'shopify-woo-bridge' )
			);
			$state['failed'] = isset( $state['failed'] ) ? intval( $state['failed'] ) + 1 : 1;
		}

		$state['offset'] = $max_index;
		$state_args      = isset( $state['state_args'] ) && is_array( $state['state_args'] ) ? $state['state_args'] : array();

		if ( $max_index < count( $mapping_ids ) ) {
			update_option( $option_key, $state, false );
			$this->redirect_selected_bulk_image_sync_job( $job_token, $state_args );
			return;
		}

		delete_option( $option_key );

		$failed      = isset( $state['failed'] ) ? intval( $state['failed'] ) : 0;
		$processed   = isset( $state['processed'] ) ? intval( $state['processed'] ) : 0;
		$updated     = isset( $state['updated'] ) ? intval( $state['updated'] ) : 0;
		$unchanged   = isset( $state['unchanged'] ) ? intval( $state['unchanged'] ) : 0;
		$skipped     = isset( $state['skipped'] ) ? intval( $state['skipped'] ) : 0;
		$notice_type = $failed > 0 ? 'error' : 'success';

		$message = sprintf(
			/* translators: 1: processed mappings, 2: changed mappings, 3: unchanged mappings, 4: skipped mappings, 5: failed mappings. */
			__( 'Bulk image sync complete. Processed: %1$d, Changed: %2$d, Unchanged: %3$d, Skipped: %4$d, Failed: %5$d.', 'shopify-woo-bridge' ),
			$processed,
			$updated,
			$unchanged,
			$skipped,
			$failed
		);

		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'        => 'shopify-bridge-mappings',
						'tab'         => 'mappings',
						'swb_notice'  => '1',
						'swb_type'    => $notice_type,
						'swb_message' => $message,
					),
					$state_args
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Build option key for selected bulk image sync job state.
	 *
	 * @param string $job_token Job token.
	 * @return string
	 */
	private function get_selected_bulk_image_sync_job_option_key( $job_token ) {
		return self::SELECTED_BULK_IMAGE_SYNC_JOB_OPTION_PREFIX . $job_token;
	}

	/**
	 * Redirect to continue selected bulk image sync processing.
	 *
	 * @param string $job_token Job token.
	 * @param array  $state_args State query args to preserve.
	 * @return void
	 */
	private function redirect_selected_bulk_image_sync_job( $job_token, $state_args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'                                 => 'shopify-bridge-mappings',
						'tab'                                  => 'mappings',
						'swb_bulk_sync_images_selected_continue' => '1',
						'swb_bulk_sync_images_selected_job'      => $job_token,
						'swb_job_nonce'                        => wp_create_nonce( 'swb_selected_bulk_sync_images_job_' . $job_token ),
					),
					is_array( $state_args ) ? $state_args : array()
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Resolve selected bulk image sync batch size.
	 *
	 * @return int
	 */
	private function get_selected_bulk_image_sync_batch_size() {
		$batch_size = apply_filters( 'swb_selected_bulk_image_sync_batch_size', self::SELECTED_BULK_IMAGE_SYNC_BATCH_SIZE );
		$batch_size = intval( $batch_size );

		return max( 1, min( 100, $batch_size ) );
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

	/**
	 * Reset and invalidate media sync status for one mapping row.
	 *
	 * @param object|array $mapping Mapping row.
	 */
	private function reset_media_sync_status_for_mapping( $mapping ) {
		$mapping_id = absint( $this->get_item_value( $mapping, 'id', 0 ) );
		if ( $mapping_id <= 0 ) {
			return;
		}

		$wc_sku     = (string) $this->get_item_value( $mapping, 'wc_sku', '' );
		$product_id = $this->find_wc_product_id_by_sku_for_status( $wc_sku );

		if ( $product_id > 0 ) {
			delete_post_meta( $product_id, 'shopify_sync_last_media_hash' );
			delete_post_meta( $product_id, 'shopify_sync_last_media_synced_at' );
			delete_post_meta( $product_id, 'shopify_sync_last_media_signature' );
		}

		$this->set_media_sync_status( $mapping_id, 'invalidated', '' );
	}
}
