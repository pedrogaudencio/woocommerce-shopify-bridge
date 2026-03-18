<?php
/**
 * Admin Mappings Page Handler.
 *
 * @package Shopify_WooCommerce_Bridge\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_Admin_Mappings Class.
 */
class SWB_Admin_Mappings {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'handle_add_mapping' ) );
	}

	/**
	 * Add menu page.
	 */
	public function add_plugin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Shopify Mappings', 'shopify-woo-bridge' ),
			__( 'Shopify Mappings', 'shopify-woo-bridge' ),
			'manage_woocommerce',
			'shopify-bridge-mappings',
			array( $this, 'create_admin_page' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public function create_admin_page() {
		require_once SWB_PLUGIN_DIR . 'includes/admin/class-swb-mapping-list-table.php';

		$mapping_table = new SWB_Mapping_List_Table();
		$mapping_table->prepare_items();
		$page_slug = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : 'shopify-bridge-mappings';

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Shopify Mappings', 'shopify-woo-bridge' ); ?></h1>
			<hr class="wp-header-end">

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<div class="meta-box-sortables ui-sortable">
							<form method="post">
								<?php
								echo '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '" />';
								$mapping_table->search_box( __( 'Search Shopify Item ID', 'shopify-woo-bridge' ), 'swb-mappings' );
								$mapping_table->display();
								?>
							</form>
						</div>
					</div>
					<div id="postbox-container-1" class="postbox-container">
						<div class="meta-box-sortables">
							<div class="postbox">
								<div class="postbox-header"><h2 class="hndle"><span><?php esc_html_e( 'Add New Mapping', 'shopify-woo-bridge' ); ?></span></h2></div>
								<div class="inside">
									<form method="post" action="">
										<?php wp_nonce_field( 'swb_add_mapping_nonce', 'swb_add_mapping_nonce_field' ); ?>
										<p>
											<label for="shopify_product_id"><strong><?php esc_html_e( 'Shopify Product ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
											<input type="text" name="shopify_product_id" id="shopify_product_id" class="widefat" required />
											<span class="description"><?php esc_html_e( 'The ID of the parent product in Shopify.', 'shopify-woo-bridge' ); ?></span>
										</p>
										<p>
											<label for="shopify_variant_id"><strong><?php esc_html_e( 'Shopify Variant ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
											<input type="text" name="shopify_variant_id" id="shopify_variant_id" class="widefat" />
											<span class="description"><?php esc_html_e( 'The ID of the variant in Shopify (leave blank for simple products).', 'shopify-woo-bridge' ); ?></span>
										</p>
										<p>
											<label for="shopify_item_id"><strong><?php esc_html_e( 'Shopify Inventory Item ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
											<input type="text" name="shopify_item_id" id="shopify_item_id" class="widefat" required />
											<span class="description"><?php esc_html_e( 'The ID of the inventory item in Shopify (used by webhooks).', 'shopify-woo-bridge' ); ?></span>
										</p>
										<p>
											<label for="wc_sku"><strong><?php esc_html_e( 'WooCommerce SKU', 'shopify-woo-bridge' ); ?></strong></label><br/>
											<input type="text" name="wc_sku" id="wc_sku" class="widefat" required />
											<span class="description"><?php esc_html_e( 'The SKU of the product or variation in WooCommerce.', 'shopify-woo-bridge' ); ?></span>
										</p>
										<p>
											<label for="is_enabled">
												<input type="checkbox" name="is_enabled" id="is_enabled" value="1" checked="checked" />
												<?php esc_html_e( 'Enable Sync', 'shopify-woo-bridge' ); ?>
											</label>
										</p>
										<?php submit_button( __( 'Add Mapping', 'shopify-woo-bridge' ), 'primary', 'swb_add_mapping', false ); ?>
									</form>
								</div>
							</div>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * Handle form submission for adding a mapping.
	 */
	public function handle_add_mapping() {
		if ( ! isset( $_POST['swb_add_mapping'] ) ) {
			return;
		}

		if ( ! isset( $_POST['swb_add_mapping_nonce_field'] ) || ! wp_verify_nonce( $_POST['swb_add_mapping_nonce_field'], 'swb_add_mapping_nonce' ) ) {
			wp_die( 'Security check failed.' );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized.' );
		}

		$shopify_product_id = isset( $_POST['shopify_product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_product_id'] ) ) : '';
		$shopify_variant_id = isset( $_POST['shopify_variant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_variant_id'] ) ) : '';
		$shopify_item_id    = isset( $_POST['shopify_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_item_id'] ) ) : '';
		$wc_sku             = isset( $_POST['wc_sku'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_sku'] ) ) : '';
		$is_enabled         = isset( $_POST['is_enabled'] ) ? 1 : 0;

		if ( empty( $shopify_product_id ) || empty( $shopify_item_id ) || empty( $wc_sku ) ) {
			add_action( 'admin_notices', array( $this, 'notice_error_missing_fields' ) );
			return;
		}

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
			add_action( 'admin_notices', array( $this, 'notice_error_invalid_product' ) );
			return;
		}

		if ( count( $product_ids ) > 1 ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Multiple WooCommerce products found with this SKU. Please ensure SKUs are unique.', 'shopify-woo-bridge' ) . '</p></div>';
			} );
			return;
		}

		$product = wc_get_product( $product_ids[0] );
		if ( $product && $product->is_type( 'variable' ) ) {
			add_action( 'admin_notices', function() {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Target SKU belongs to a variable product parent. Please provide a variation SKU.', 'shopify-woo-bridge' ) . '</p></div>';
			} );
			return;
		}

		$result = SWB_DB::insert_mapping(
			array(
				'shopify_product_id' => $shopify_product_id,
				'shopify_variant_id' => empty( $shopify_variant_id ) ? null : $shopify_variant_id,
				'shopify_item_id'    => $shopify_item_id,
				'wc_sku'             => $wc_sku,
				'is_enabled'         => $is_enabled,
			)
		);

		if ( $result ) {
			add_action( 'admin_notices', array( $this, 'notice_success' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'notice_error_db' ) );
		}
	}

	public function notice_error_missing_fields() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please provide both Shopify ID and WooCommerce SKU.', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_error_invalid_product() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid WooCommerce SKU (product not found).', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_success() {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mapping added/updated successfully.', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_error_db() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to save mapping. It might already exist.', 'shopify-woo-bridge' ) . '</p></div>';
	}
}
