<?php
/**
 * Admin CSV export actions for Shopify data.
 *
 * @package Shopify_WooCommerce_Bridge\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_Admin_Export Class.
 */
class SWB_Admin_Export {

	/**
	 * User-scoped transient key prefix for admin notices.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT_PREFIX = 'swb_admin_notice_';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_swb_export_shopify_csv', array( $this, 'handle_export' ) );
		add_action( 'admin_post_swb_test_shopify_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Handle explicit test connection request.
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to test Shopify connection.', 'shopify-woo-bridge' ) );
		}

		check_admin_referer( 'swb_test_shopify_connection', 'swb_test_connection_nonce' );

		$api        = new SWB_Shopify_API_Client();
		$connection = $api->test_connection();

		if ( empty( $connection['success'] ) ) {
			$message = isset( $connection['message'] ) ? $connection['message'] : __( 'Connection failed.', 'shopify-woo-bridge' );
			SWB_Logger::error( 'Manual Shopify connection test failed.', array( 'message' => $message ) );
			$this->redirect_with_notice( 'error', $message );
		}

		$this->redirect_with_notice(
			'success',
			isset( $connection['message'] ) ? $connection['message'] : __( 'Connection successful.', 'shopify-woo-bridge' )
		);
	}

	/**
	 * Handle export request.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this export.', 'shopify-woo-bridge' ) );
		}

		check_admin_referer( 'swb_export_shopify_csv', 'swb_export_nonce' );

		$api = new SWB_Shopify_API_Client();

		$connection = $api->test_connection();
		if ( empty( $connection['success'] ) ) {
			$message = isset( $connection['message'] ) ? $connection['message'] : __( 'Unable to connect to Shopify.', 'shopify-woo-bridge' );
			SWB_Logger::error( 'CSV export failed during Shopify connection test.', array( 'message' => $message ) );
			$this->redirect_with_notice( 'error', $message );
		}

		$products = $api->get_products();
		if ( is_wp_error( $products ) ) {
			SWB_Logger::error( 'CSV export failed while fetching products.', array( 'error' => $products->get_error_message() ) );
			$this->redirect_with_notice( 'error', $products->get_error_message() );
		}

		$inventory_item_ids = $this->extract_inventory_item_ids( $products );
		$levels_by_item     = $api->get_inventory_levels_for_item_ids( $inventory_item_ids );
		if ( is_wp_error( $levels_by_item ) ) {
			SWB_Logger::error( 'CSV export failed while fetching inventory levels.', array( 'error' => $levels_by_item->get_error_message() ) );
			$this->redirect_with_notice( 'error', $levels_by_item->get_error_message() );
		}

		$this->store_next_notice(
			'success',
			sprintf(
				/* translators: 1: product count. 2: inventory item count. */
				__( 'Export completed: %1$d products and %2$d inventory item groups were retrieved.', 'shopify-woo-bridge' ),
				count( $products ),
				count( $levels_by_item )
			)
		);

		$this->stream_csv( $products, $levels_by_item );
		exit;
	}

	/**
	 * Collect inventory item IDs from product variants.
	 *
	 * @param array $products Shopify products.
	 * @return array
	 */
	private function extract_inventory_item_ids( $products ) {
		$ids = array();

		foreach ( $products as $product ) {
			$variants = ! empty( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : array();
			foreach ( $variants as $variant ) {
				if ( ! empty( $variant['inventory_item_id'] ) ) {
					$ids[] = strval( $variant['inventory_item_id'] );
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Stream CSV to the browser.
	 *
	 * @param array $products Shopify products.
	 * @param array $levels_by_item Inventory levels indexed by inventory item ID.
	 */
	private function stream_csv( $products, $levels_by_item ) {
		$filename = 'shopify-products-inventory-' . gmdate( 'Ymd-His' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			wp_die( esc_html__( 'Unable to generate CSV output.', 'shopify-woo-bridge' ) );
		}

		fputcsv(
			$output,
			$this->sanitize_csv_row(
				array(
					'product_id',
					'product_title',
					'product_handle',
					'product_status',
					'vendor',
					'product_type',
					'product_updated_at',
					'variant_id',
					'variant_title',
					'sku',
					'barcode',
					'inventory_item_id',
					'variant_inventory_quantity',
					'location_id',
					'inventory_level_available',
				)
			)
		);

		foreach ( $products as $product ) {
			$variants = ! empty( $product['variants'] ) && is_array( $product['variants'] ) ? $product['variants'] : array();

			if ( empty( $variants ) ) {
				fputcsv(
					$output,
					$this->sanitize_csv_row(
						array(
							isset( $product['id'] ) ? strval( $product['id'] ) : '',
							isset( $product['title'] ) ? $product['title'] : '',
							isset( $product['handle'] ) ? $product['handle'] : '',
							isset( $product['status'] ) ? $product['status'] : '',
							isset( $product['vendor'] ) ? $product['vendor'] : '',
							isset( $product['product_type'] ) ? $product['product_type'] : '',
							isset( $product['updated_at'] ) ? $product['updated_at'] : '',
							'',
							'',
							'',
							'',
							'',
							'',
							'',
							'',
						)
					)
				);
				continue;
			}

			foreach ( $variants as $variant ) {
				$inventory_item_id = isset( $variant['inventory_item_id'] ) ? strval( $variant['inventory_item_id'] ) : '';
				$levels            = isset( $levels_by_item[ $inventory_item_id ] ) ? $levels_by_item[ $inventory_item_id ] : array();

				if ( empty( $levels ) ) {
					fputcsv(
						$output,
						$this->sanitize_csv_row(
							array(
								isset( $product['id'] ) ? strval( $product['id'] ) : '',
								isset( $product['title'] ) ? $product['title'] : '',
								isset( $product['handle'] ) ? $product['handle'] : '',
								isset( $product['status'] ) ? $product['status'] : '',
								isset( $product['vendor'] ) ? $product['vendor'] : '',
								isset( $product['product_type'] ) ? $product['product_type'] : '',
								isset( $product['updated_at'] ) ? $product['updated_at'] : '',
								isset( $variant['id'] ) ? strval( $variant['id'] ) : '',
								isset( $variant['title'] ) ? $variant['title'] : '',
								isset( $variant['sku'] ) ? $variant['sku'] : '',
								isset( $variant['barcode'] ) ? $variant['barcode'] : '',
								$inventory_item_id,
								isset( $variant['inventory_quantity'] ) ? intval( $variant['inventory_quantity'] ) : '',
								'',
								'',
							)
						)
					);
					continue;
				}

				foreach ( $levels as $level ) {
					fputcsv(
						$output,
						$this->sanitize_csv_row(
							array(
								isset( $product['id'] ) ? strval( $product['id'] ) : '',
								isset( $product['title'] ) ? $product['title'] : '',
								isset( $product['handle'] ) ? $product['handle'] : '',
								isset( $product['status'] ) ? $product['status'] : '',
								isset( $product['vendor'] ) ? $product['vendor'] : '',
								isset( $product['product_type'] ) ? $product['product_type'] : '',
								isset( $product['updated_at'] ) ? $product['updated_at'] : '',
								isset( $variant['id'] ) ? strval( $variant['id'] ) : '',
								isset( $variant['title'] ) ? $variant['title'] : '',
								isset( $variant['sku'] ) ? $variant['sku'] : '',
								isset( $variant['barcode'] ) ? $variant['barcode'] : '',
								$inventory_item_id,
								isset( $variant['inventory_quantity'] ) ? intval( $variant['inventory_quantity'] ) : '',
								isset( $level['location_id'] ) ? $level['location_id'] : '',
								isset( $level['available'] ) ? $level['available'] : '',
							)
						)
					);
				}
			}
		}

		fclose( $output );

		SWB_Logger::info(
			'Shopify CSV export completed.',
			array(
				'products'             => count( $products ),
				'inventory_item_count' => count( $levels_by_item ),
			)
		);
	}

	/**
	 * Render admin notices for this plugin's actions.
	 */
	public function render_admin_notices() {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$notices = array();
		$has_query_notice = isset( $_GET['swb_notice'], $_GET['swb_notice_type'], $_GET['swb_notice_message'] ) && '1' === $_GET['swb_notice'];

		if ( $has_query_notice ) {
			$notices[] = array(
				'type'    => sanitize_key( wp_unslash( $_GET['swb_notice_type'] ) ),
				'message' => sanitize_text_field( wp_unslash( $_GET['swb_notice_message'] ) ),
			);
		}

		$stored_notice = $this->consume_next_notice();
		$is_settings_screen = isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'integration' === $_GET['tab'];
		if ( $is_settings_screen && ! empty( $stored_notice ) ) {
			$notices[] = $stored_notice;
		}

		if ( empty( $notices ) ) {
			return;
		}

		foreach ( $notices as $notice ) {
			$type = isset( $notice['type'] ) && 'success' === $notice['type'] ? 'success' : 'error';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
		}
	}

	/**
	 * Redirect to settings with a query-string notice.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 */
	private function redirect_with_notice( $type, $message ) {
		$url = $this->get_settings_return_url();
		$url = add_query_arg(
			array(
				'swb_notice'         => '1',
				'swb_notice_type'    => 'success' === $type ? 'success' : 'error',
				'swb_notice_message' => sanitize_text_field( $message ),
			),
			$url
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Store a notice for the user's next admin page load.
	 *
	 * @param string $type Notice type.
	 * @param string $message Notice message.
	 */
	private function store_next_notice( $type, $message ) {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . $user_id,
			array(
				'type'    => 'success' === $type ? 'success' : 'error',
				'message' => sanitize_text_field( $message ),
			),
			30 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Retrieve and clear any pending user notice.
	 *
	 * @return array
	 */
	private function consume_next_notice() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		$key    = self::NOTICE_TRANSIENT_PREFIX . $user_id;
		$notice = get_transient( $key );
		delete_transient( $key );

		return is_array( $notice ) ? $notice : array();
	}

	/**
	 * Resolve where action handlers should redirect on completion.
	 *
	 * @return string
	 */
	private function get_settings_return_url() {
		return admin_url( 'admin.php?page=wc-settings&tab=integration&section=shopify_bridge' );
	}

	/**
	 * Sanitize a row before writing CSV.
	 *
	 * @param array $row Raw row values.
	 * @return array
	 */
	private function sanitize_csv_row( $row ) {
		return array_map( array( $this, 'sanitize_csv_cell' ), $row );
	}

	/**
	 * Prevent formula injection in spreadsheet tools.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private function sanitize_csv_cell( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}

		$first_char = substr( $value, 0, 1 );
		if ( in_array( $first_char, array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}
}

