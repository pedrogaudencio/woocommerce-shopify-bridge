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
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_swb_export_shopify_csv', array( $this, 'handle_export' ) );
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
			SWB_Logger::error( 'CSV export failed during Shopify connection test.', array( 'message' => isset( $connection['message'] ) ? $connection['message'] : '' ) );
			wp_die( esc_html( isset( $connection['message'] ) ? $connection['message'] : __( 'Unable to connect to Shopify.', 'shopify-woo-bridge' ) ) );
		}

		$products = $api->get_products();
		if ( is_wp_error( $products ) ) {
			SWB_Logger::error( 'CSV export failed while fetching products.', array( 'error' => $products->get_error_message() ) );
			wp_die( esc_html( $products->get_error_message() ) );
		}

		$inventory_item_ids = $this->extract_inventory_item_ids( $products );
		$levels_by_item     = $api->get_inventory_levels_for_item_ids( $inventory_item_ids );
		if ( is_wp_error( $levels_by_item ) ) {
			SWB_Logger::error( 'CSV export failed while fetching inventory levels.', array( 'error' => $levels_by_item->get_error_message() ) );
			wp_die( esc_html( $levels_by_item->get_error_message() ) );
		}

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

