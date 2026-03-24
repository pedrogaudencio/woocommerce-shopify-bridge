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
	 * Settings page adapter.
	 *
	 * @var SWB_Admin_Settings|null
	 */
	private $settings_page = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'handle_add_mapping' ) );
		add_action( 'admin_init', array( $this, 'handle_fetch_all_shopify_ids' ) );
		add_action( 'admin_init', array( $this, 'handle_bulk_sync_images' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy_settings_url' ) );
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
		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Shopify Bridge', 'shopify-woo-bridge' ); ?></h1>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper" style="margin-bottom: 16px;">
				<?php foreach ( $this->get_tabs() as $tab_key => $tab_label ) : ?>
					<?php $tab_url = add_query_arg( array( 'page' => 'shopify-bridge-mappings', 'tab' => $tab_key ), admin_url( 'admin.php' ) ); ?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $active_tab === $tab_key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php $this->render_action_notice(); ?>

			<?php if ( 'mappings' === $active_tab ) : ?>
				<?php $this->render_mappings_tab(); ?>
			<?php else : ?>
				<?php $this->render_settings_tab( $active_tab ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render mappings table tab.
	 */
	private function render_mappings_tab() {
		require_once SWB_PLUGIN_DIR . 'includes/admin/class-swb-mapping-list-table.php';

		$mapping_table = new SWB_Mapping_List_Table();
		$mapping_table->prepare_items();
		$page_slug = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : 'shopify-bridge-mappings';
		?>
		<div style="margin-bottom: 12px;">
			<form method="post" action="" style="display: inline-block;">
				<?php wp_nonce_field( 'swb_fetch_all_shopify_ids_action', 'swb_fetch_all_shopify_ids_nonce' ); ?>
				<input type="hidden" name="page" value="shopify-bridge-mappings" />
				<input type="hidden" name="tab" value="mappings" />
				<input type="hidden" name="swb_fetch_all_shopify_ids" value="1" />
				<?php submit_button( __( 'Fetch all Shopify IDs from products', 'shopify-woo-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="" style="display: inline-block; margin-left: 8px;">
				<?php wp_nonce_field( 'swb_bulk_sync_images_action', 'swb_bulk_sync_images_nonce' ); ?>
				<input type="hidden" name="page" value="shopify-bridge-mappings" />
				<input type="hidden" name="tab" value="mappings" />
				<input type="hidden" name="swb_bulk_sync_images" value="1" />
				<?php submit_button( __( 'Sync Images for all eligible mappings', 'shopify-woo-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<div class="meta-box-sortables ui-sortable">
						<form method="post">
							<?php
							echo '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '" />';
							echo '<input type="hidden" name="tab" value="mappings" />';
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
									<input type="hidden" name="page" value="shopify-bridge-mappings" />
									<input type="hidden" name="tab" value="mappings" />
									<p>
										<label for="shopify_product_id"><strong><?php esc_html_e( 'Shopify Product ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
										<input type="text" name="shopify_product_id" id="shopify_product_id" class="widefat" />
										<span class="description"><?php esc_html_e( 'The ID of the parent product in Shopify. If empty, we try product meta: shopify_sync_product_id.', 'shopify-woo-bridge' ); ?></span>
									</p>
									<p>
										<label for="shopify_variant_id"><strong><?php esc_html_e( 'Shopify Variant ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
										<input type="text" name="shopify_variant_id" id="shopify_variant_id" class="widefat" />
										<span class="description"><?php esc_html_e( 'The ID of the variant in Shopify (leave blank for simple products). If empty, we try product meta: shopify_sync_variant_id.', 'shopify-woo-bridge' ); ?></span>
									</p>
									<p>
										<label for="shopify_item_id"><strong><?php esc_html_e( 'Shopify Inventory Item ID', 'shopify-woo-bridge' ); ?></strong></label><br/>
										<input type="text" name="shopify_item_id" id="shopify_item_id" class="widefat" />
										<span class="description"><?php esc_html_e( 'The ID of the inventory item in Shopify (used by webhooks). If empty, we try product meta: shopify_sync_inventory_item_id.', 'shopify-woo-bridge' ); ?></span>
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
		<?php
	}

	/**
	 * Render settings tab content.
	 *
	 * @param string $tab Active tab.
	 */
	private function render_settings_tab( $tab ) {
		$settings_page = $this->get_settings_page();
		if ( ! $settings_page ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Settings UI is unavailable because WooCommerce settings classes are not loaded.', 'shopify-woo-bridge' ) . '</p></div>';
			return;
		}

		$section = $this->tab_to_section( $tab );
		if ( class_exists( 'WC_Admin_Settings' ) && method_exists( 'WC_Admin_Settings', 'show_messages' ) ) {
			WC_Admin_Settings::show_messages();
		}

		echo '<form method="post" action="">';
		echo '<input type="hidden" name="page" value="shopify-bridge-mappings" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<input type="hidden" name="swb_settings_tab" value="' . esc_attr( $tab ) . '" />';
		echo '<input type="hidden" name="swb_save_settings" value="1" />';
		wp_nonce_field( 'swb_save_settings_' . $tab, 'swb_save_settings_nonce' );

		$settings_page->output_for_section( $section );
		submit_button( __( 'Save changes', 'shopify-woo-bridge' ) );
		echo '</form>';
	}

	/**
	 * Get tab definitions.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'mappings'    => __( 'Mappings', 'shopify-woo-bridge' ),
			'general'     => __( 'General', 'shopify-woo-bridge' ),
			'credentials' => __( 'Credentials', 'shopify-woo-bridge' ),
			'export'      => __( 'Export', 'shopify-woo-bridge' ),
		);
	}

	/**
	 * Get active tab key.
	 *
	 * @return string
	 */
	private function get_active_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'mappings';
		if ( ! array_key_exists( $tab, $this->get_tabs() ) ) {
			return 'mappings';
		}

		return $tab;
	}

	/**
	 * Handle settings save on custom tabbed page.
	 */
	public function handle_settings_save() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! isset( $_POST['swb_save_settings'], $_POST['page'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_POST['page'] ) );
		if ( 'shopify-bridge-mappings' !== $page ) {
			return;
		}

		$tab = isset( $_POST['swb_settings_tab'] ) ? sanitize_key( wp_unslash( $_POST['swb_settings_tab'] ) ) : 'general';
		if ( ! isset( $_POST['swb_save_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swb_save_settings_nonce'] ) ), 'swb_save_settings_' . $tab ) ) {
			return;
		}

		$settings_page = $this->get_settings_page();
		if ( ! $settings_page ) {
			return;
		}

		$settings_page->save_for_section( $this->tab_to_section( $tab ) );
	}

	/**
	 * Redirect legacy wc-settings URL to tabbed bridge page.
	 */
	public function maybe_redirect_legacy_settings_url() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! isset( $_GET['page'], $_GET['tab'] ) ) {
			return;
		}

		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ) );
		if ( 'wc-settings' !== $page || 'shopify_bridge' !== $tab ) {
			return;
		}

		$section    = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		$target_tab = in_array( $section, array( 'credentials', 'export' ), true ) ? $section : 'general';

		wp_safe_redirect( add_query_arg( array( 'page' => 'shopify-bridge-mappings', 'tab' => $target_tab ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Resolve settings adapter instance.
	 *
	 * @return SWB_Admin_Settings|null
	 */
	private function get_settings_page() {
		if ( null !== $this->settings_page ) {
			return $this->settings_page;
		}

		if ( ! class_exists( 'SWB_Admin_Settings' ) ) {
			return null;
		}

		$this->settings_page = new SWB_Admin_Settings();
		return $this->settings_page;
	}

	/**
	 * Map UI tab to settings section.
	 *
	 * @param string $tab Tab key.
	 * @return string
	 */
	private function tab_to_section( $tab ) {
		if ( 'credentials' === $tab ) {
			return 'credentials';
		}

		if ( 'export' === $tab ) {
			return 'export';
		}

		return '';
	}

	/**
	 * Handle bulk fetch of Shopify IDs from product meta and create/update mappings.
	 */
	public function handle_fetch_all_shopify_ids() {
		if ( ! isset( $_POST['swb_fetch_all_shopify_ids'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized.' );
		}

		if ( ! isset( $_POST['swb_fetch_all_shopify_ids_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swb_fetch_all_shopify_ids_nonce'] ) ), 'swb_fetch_all_shopify_ids_action' ) ) {
			wp_die( 'Security check failed.' );
		}

		global $wpdb;
		$product_ids = $wpdb->get_col(
			"
			SELECT posts.ID
			FROM {$wpdb->posts} as posts
			INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
			WHERE
			posts.post_type IN ( 'product', 'product_variation' )
			AND posts.post_status != 'trash'
			AND lookup.sku IS NOT NULL
			AND lookup.sku != ''
			"
		);

		$total_checked   = 0;
		$imported_count  = 0;
		$skipped_count   = 0;
		$failed_count    = 0;

		foreach ( $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( $product_id <= 0 ) {
				continue;
			}

			$total_checked++;

			$wc_sku             = trim( (string) get_post_meta( $product_id, '_sku', true ) );
			$shopify_product_id = trim( (string) get_post_meta( $product_id, 'shopify_sync_product_id', true ) );
			$shopify_variant_id = trim( (string) get_post_meta( $product_id, 'shopify_sync_variant_id', true ) );
			$shopify_item_id    = trim( (string) get_post_meta( $product_id, 'shopify_sync_inventory_item_id', true ) );

			if ( '' === $wc_sku || '' === $shopify_product_id || '' === $shopify_item_id ) {
				$skipped_count++;
				continue;
			}

			$result = SWB_DB::insert_mapping(
				array(
					'shopify_product_id' => $shopify_product_id,
					'shopify_variant_id' => '' === $shopify_variant_id ? null : $shopify_variant_id,
					'shopify_item_id'    => $shopify_item_id,
					'wc_sku'             => $wc_sku,
					'is_enabled'         => 1,
				)
			);

			if ( false === $result ) {
				$failed_count++;
			} else {
				$imported_count++;
			}
		}

		$notice_type = $failed_count > 0 ? 'error' : 'success';
		$message = sprintf(
			/* translators: 1: checked count, 2: imported count, 3: skipped count, 4: failed count. */
			__( 'Fetch complete. Checked: %1$d, Imported/Updated: %2$d, Skipped (missing meta): %3$d, Failed: %4$d.', 'shopify-woo-bridge' ),
			$total_checked,
			$imported_count,
			$skipped_count,
			$failed_count
		);

		$redirect_url = add_query_arg(
			array(
				'page'        => 'shopify-bridge-mappings',
				'tab'         => 'mappings',
				'swb_notice'  => '1',
				'swb_type'    => $notice_type,
				'swb_message' => $message,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle bulk manual image sync for all eligible mapping groups.
	 */
	public function handle_bulk_sync_images() {
		if ( ! isset( $_POST['swb_bulk_sync_images'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized.' );
		}

		if ( ! isset( $_POST['swb_bulk_sync_images_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swb_bulk_sync_images_nonce'] ) ), 'swb_bulk_sync_images_action' ) ) {
			wp_die( 'Security check failed.' );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$rows = $wpdb->get_results(
			"
			SELECT *
			FROM {$table_name}
			WHERE is_enabled = 1
			ORDER BY shopify_product_id ASC, id ASC
			"
		);

		$processed_groups = 0;
		$changed_groups   = 0;
		$unchanged_groups = 0;
		$failed_groups    = 0;
		$skipped_groups   = 0;
		$seen_products    = array();

		$sync_service = new SWB_Image_Sync();

		foreach ( $rows as $row ) {
			$shopify_product_id = isset( $row->shopify_product_id ) ? trim( (string) $row->shopify_product_id ) : '';

			if ( '' === $shopify_product_id ) {
				$this->set_mapping_media_sync_status( absint( $row->id ), 'error', __( 'Missing Shopify Product ID.', 'shopify-woo-bridge' ) );
				$skipped_groups++;
				continue;
			}

			if ( isset( $seen_products[ $shopify_product_id ] ) ) {
				continue;
			}

			$seen_products[ $shopify_product_id ] = true;
			$processed_groups++;

			$result = $sync_service->sync_images_for_mapping( $row );
			$group_rows = SWB_DB::get_mappings_by_shopify_product_id( $shopify_product_id );

			if ( ! empty( $result['success'] ) ) {
				if ( ! empty( $result['changed'] ) ) {
					$changed_groups++;
					$status = 'changed';
				} else {
					$unchanged_groups++;
					$status = 'unchanged';
				}

				foreach ( $group_rows as $group_row ) {
					$this->set_mapping_media_sync_status( absint( $group_row->id ), $status, '' );
				}
				continue;
			}

			$failed_groups++;
			$error_message = ! empty( $result['message'] ) ? $result['message'] : __( 'Image sync failed.', 'shopify-woo-bridge' );
			foreach ( $group_rows as $group_row ) {
				$this->set_mapping_media_sync_status( absint( $group_row->id ), 'error', $error_message );
			}
		}

		$notice_type = $failed_groups > 0 ? 'error' : 'success';
		$message     = sprintf(
			/* translators: 1: processed groups, 2: changed groups, 3: unchanged groups, 4: failed groups, 5: skipped groups. */
			__( 'Bulk image sync complete. Processed groups: %1$d, Changed: %2$d, Unchanged: %3$d, Failed: %4$d, Skipped: %5$d.', 'shopify-woo-bridge' ),
			$processed_groups,
			$changed_groups,
			$unchanged_groups,
			$failed_groups,
			$skipped_groups
		);

		$redirect_url = add_query_arg(
			array(
				'page'        => 'shopify-bridge-mappings',
				'tab'         => 'mappings',
				'swb_notice'  => '1',
				'swb_type'    => $notice_type,
				'swb_message' => $message,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render action notice passed via query string.
	 */
	private function render_action_notice() {
		if ( ! isset( $_GET['swb_notice'], $_GET['swb_type'], $_GET['swb_message'] ) ) {
			return;
		}

		if ( '1' !== sanitize_text_field( wp_unslash( $_GET['swb_notice'] ) ) ) {
			return;
		}

		$type    = sanitize_key( wp_unslash( $_GET['swb_type'] ) );
		$message = sanitize_text_field( wp_unslash( $_GET['swb_message'] ) );

		$notice_class = 'success' === $type ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Persist media sync status and error for a specific mapping row.
	 *
	 * @param int    $mapping_id Mapping ID.
	 * @param string $result changed|unchanged|error.
	 * @param string $error_message Error message.
	 */
	private function set_mapping_media_sync_status( $mapping_id, $result, $error_message ) {
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

		if ( empty( $wc_sku ) ) {
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
			add_action(
				'admin_notices',
				function() {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Multiple WooCommerce products found with this SKU. Please ensure SKUs are unique.', 'shopify-woo-bridge' ) . '</p></div>';
				}
			);
			return;
		}

		$product = wc_get_product( $product_ids[0] );
		if ( $product && $product->is_type( 'variable' ) ) {
			add_action(
				'admin_notices',
				function() {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Target SKU belongs to a variable product parent. Please provide a variation SKU.', 'shopify-woo-bridge' ) . '</p></div>';
				}
			);
			return;
		}

		$product_id = absint( $product_ids[0] );

		$meta_shopify_item_id    = trim( (string) get_post_meta( $product_id, 'shopify_sync_inventory_item_id', true ) );
		$meta_shopify_product_id = trim( (string) get_post_meta( $product_id, 'shopify_sync_product_id', true ) );
		$meta_shopify_variant_id = trim( (string) get_post_meta( $product_id, 'shopify_sync_variant_id', true ) );

		$resolved_shopify_item_id    = '' !== $meta_shopify_item_id ? $meta_shopify_item_id : $shopify_item_id;
		$resolved_shopify_product_id = '' !== $meta_shopify_product_id ? $meta_shopify_product_id : $shopify_product_id;
		$resolved_shopify_variant_id = '' !== $meta_shopify_variant_id ? $meta_shopify_variant_id : $shopify_variant_id;

		if ( empty( $resolved_shopify_product_id ) || empty( $resolved_shopify_item_id ) ) {
			add_action( 'admin_notices', array( $this, 'notice_error_missing_shopify_ids' ) );
			return;
		}

		$result = SWB_DB::insert_mapping(
			array(
				'shopify_product_id' => $resolved_shopify_product_id,
				'shopify_variant_id' => empty( $resolved_shopify_variant_id ) ? null : $resolved_shopify_variant_id,
				'shopify_item_id'    => $resolved_shopify_item_id,
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
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Please provide a WooCommerce SKU.', 'shopify-woo-bridge' ) . '</p></div>';
	}

	public function notice_error_missing_shopify_ids() {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Shopify Product ID and Inventory Item ID are required. Provide them manually or store meta keys on the product: shopify_sync_product_id and shopify_sync_inventory_item_id.', 'shopify-woo-bridge' ) . '</p></div>';
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
