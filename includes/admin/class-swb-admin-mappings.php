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
											<label for="shopify_item_id"><strong><?php esc_html_e( 'Shopify Item ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
											<input type="text" name="shopify_item_id" id="shopify_item_id" class="widefat" required />
											<span class="description"><?php esc_html_e( 'The ID of the inventory item in Shopify.', 'shopify-woo-bridge' ); ?></span>
										</p>
										<p>
											<label for="wc_product_id"><strong><?php esc_html_e( 'WooCommerce Product/Variant ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
											<input type="number" name="wc_product_id" id="wc_product_id" class="widefat" required />
											<span class="description"><?php esc_html_e( 'The ID of the corresponding product or variation in WooCommerce.', 'shopify-woo-bridge' ); ?></span>
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

		$shopify_item_id = isset( $_POST['shopify_item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['shopify_item_id'] ) ) : '';
		$wc_product_id   = isset( $_POST['wc_product_id'] ) ? absint( wp_unslash( $_POST['wc_product_id'] ) ) : 0;
		$is_enabled      = isset( $_POST['is_enabled'] ) ? 1 : 0;

		if ( empty( $shopify_item_id ) || empty( $wc_product_id ) ) {
			add_action( 'admin_notices', array( $this, 'notice_error_missing_fields' ) );
			return;
		}

		// Verify product exists.
		$product = wc_get_product( $wc_product_id );
		if ( ! $product ) {
			add_action( 'admin_notices', array( $this, 'notice_error_invalid_product' ) );
			return;
		}

		$result = SWB_DB::insert_mapping(
			array(
				'shopify_item_id' => $shopify_item_id,
				'wc_product_id'   => $wc_product_id,
				'is_enabled'      => $is_enabled,
			)
		);

		if ( $result ) {
			add_action( 'admin_notices', array( $this, 'notice_success' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'notice_error_db' ) );
		}
	}

	public function notice_error_missing_fields() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please provide both Shopify ID and WooCommerce ID.', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_error_invalid_product() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid WooCommerce Product ID.', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_success() {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Mapping added/updated successfully.', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_error_db() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Failed to save mapping. It might already exist.', 'shopify-woo-bridge' ) . '</p></div>';
	}
}
