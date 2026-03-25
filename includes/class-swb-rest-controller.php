<?php
/**
 * REST API Controller for Shopify Webhooks.
 *
 * @package Shopify_WooCommerce_Bridge\REST
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_REST_Controller Class.
 */
class SWB_REST_Controller extends WP_REST_Controller {

	/**
	 * Namespace for the REST API.
	 *
	 * @var string
	 */
	protected $namespace = 'shopify-bridge/v1';

	/**
	 * Base route for the webhook endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'webhook/inventory';

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE, // POST
					'callback'            => array( $this, 'process_webhook' ),
					'permission_callback' => array( $this, 'verify_shopify_signature' ),
				),
			)
		);

		// GET /shopify-bridge/v1/stock/{inventory_item_id}
		register_rest_route(
			$this->namespace,
			'/stock/(?P<inventory_item_id>[a-zA-Z0-9]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE, // GET
					'callback'            => array( $this, 'get_inventory_stock' ),
					'permission_callback' => array( $this, 'verify_api_permission' ),
					'args'                => array(
						'inventory_item_id' => array(
							'description'       => __( 'The Shopify inventory item ID', 'shopify-woo-bridge' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return ! empty( $param );
							},
						),
					),
				),
			)
		);

		// GET /shopify-bridge/v1/stock/{inventory_item_id}/history
		register_rest_route(
			$this->namespace,
			'/stock/(?P<inventory_item_id>[a-zA-Z0-9]+)/history',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE, // GET
					'callback'            => array( $this, 'get_stock_history' ),
					'permission_callback' => array( $this, 'verify_api_permission' ),
					'args'                => array(
						'inventory_item_id' => array(
							'description'       => __( 'The Shopify inventory item ID', 'shopify-woo-bridge' ),
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => function ( $param ) {
								return ! empty( $param );
							},
						),
						'limit'             => array(
							'description'       => __( 'Number of records to retrieve', 'shopify-woo-bridge' ),
							'type'              => 'integer',
							'default'           => 50,
							'validate_callback' => function ( $param ) {
								return is_numeric( $param ) && intval( $param ) > 0;
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Verify Shopify HMAC Signature.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function verify_shopify_signature( $request ) {
		// 1. Check if the global kill switch is active.
		if ( 'yes' === get_option( 'swb_global_enable', 'no' ) ) {
			// Even if disabled, we return 200 OK so Shopify doesn't disable the webhook due to failures.
			// The actual processing will stop later, but here we just ensure the endpoint is responsive.
			// Returning a WP_Error here would send a 4xx/5xx back to Shopify.
			// Returning true allows it to proceed to process_webhook, which can handle the disabled state.
			return true;
		}

		// 2. Retrieve the stored secret.
		$secret = get_option( 'swb_webhook_secret', '' );
		if ( empty( $secret ) ) {
			SWB_Logger::error( 'Webhook rejected: Shopify webhook secret is not configured.' );
			return new WP_Error(
				'swb_missing_secret',
				__( 'Shopify webhook secret is not configured.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		// 3. Get the header signature.
		$hmac_header = $request->get_header( 'x_shopify_hmac_sha256' );
		if ( empty( $hmac_header ) ) {
			SWB_Logger::warning( 'Webhook rejected: Missing X-Shopify-Hmac-Sha256 header.' );
			return new WP_Error(
				'swb_missing_signature',
				__( 'Missing X-Shopify-Hmac-Sha256 header.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		// 4. Calculate our own signature.
		$raw_payload = $request->get_body();
		$calculated_hmac = base64_encode( hash_hmac( 'sha256', $raw_payload, $secret, true ) );

		// 5. Compare using timing-safe string comparison.
		if ( ! hash_equals( $calculated_hmac, $hmac_header ) ) {
			SWB_Logger::error( 'Webhook rejected: Invalid signature.' );
			return new WP_Error(
				'swb_invalid_signature',
				__( 'Invalid signature.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Verify API permission for GET endpoints.
	 * Requires either WordPress admin user or valid Shopify request signature.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has permission, WP_Error object otherwise.
	 */
	public function verify_api_permission( $request ) {
		if ( 'yes' === get_option( 'swb_global_enable', 'no' ) ) {
			// Keep endpoint reachable for operational checks even when sync is disabled.
			return true;
		}

		if ( $this->is_stock_api_kill_switch_enabled() ) {
			// Keep endpoint reachable for operational checks even when stock API is disabled.
			return true;
		}

		// Allow WordPress administrators.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// Fall back to Shopify signature verification.
		return $this->verify_shopify_signature( $request );
	}

	/**
	 * Process the incoming webhook.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function process_webhook( $request ) {
		// Check global enable again. If disabled, log and exit.
		if ( 'yes' === get_option( 'swb_global_enable', 'no' ) ) {
			SWB_Logger::info( 'Webhook ignored: Global sync is disabled via kill switch.' );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'global_sync_disabled' ) );
		}

		$payload = $request->get_json_params();

		// Ensure we have the necessary data from Shopify payload
		// Shopify inventory_levels/update webhook sends 'inventory_item_id' and 'available'
		if ( empty( $payload['inventory_item_id'] ) || ! isset( $payload['available'] ) ) {
			SWB_Logger::warning( 'Webhook ignored: Invalid payload missing inventory_item_id or available quantity.' );
			return new WP_Error(
				'swb_invalid_payload',
				__( 'Invalid payload. Missing inventory_item_id or available quantity.', 'shopify-woo-bridge' ),
				array( 'status' => 400 )
			);
		}

		$shopify_item_id = strval( $payload['inventory_item_id'] );
		$new_quantity    = intval( $payload['available'] );

		// 1. Lookup Mapping (Default Deny)
		$mapping = SWB_DB::get_mapping_by_shopify_id( $shopify_item_id );

		if ( ! $mapping ) {
			SWB_Logger::info( 'Webhook ignored: Item is not mapped.', array( 'shopify_item_id' => $shopify_item_id ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'unmapped_item' ) );
		}

		if ( ! $mapping->is_enabled ) {
			SWB_Logger::info( 'Webhook ignored: Mapping is disabled.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $mapping->wc_sku ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'mapping_disabled' ) );
		}

		// 2. Retrieve WooCommerce Product by SKU
		$wc_sku = $mapping->wc_sku;
		
		// Check for duplicate SKUs in WooCommerce to prevent ambiguous updates
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
			SWB_Logger::warning( 'Webhook ignored: Mapped WooCommerce SKU not found.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'wc_sku_not_found' ) );
		}

		if ( count( $product_ids ) > 1 ) {
			SWB_Logger::error( 'Webhook rejected: Multiple products found with the same SKU. Cannot safely update stock.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku, 'matching_ids' => $product_ids ) );
			return rest_ensure_response( array( 'status' => 'error', 'reason' => 'duplicate_wc_sku' ) );
		}

		$product_id = $product_ids[0];
		$target_product = wc_get_product( $product_id );

		if ( ! $target_product ) {
			SWB_Logger::warning( 'Webhook ignored: Mapped WooCommerce product could not be loaded.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'wc_product_not_loaded' ) );
		}

		// Prevent updating parent variable products
		if ( $target_product->is_type( 'variable' ) ) {
			SWB_Logger::error( 'Webhook rejected: Target SKU belongs to a variable product parent. A specific variation SKU is required.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku ) );
			return rest_ensure_response( array( 'status' => 'error', 'reason' => 'variable_product_requires_variation' ) );
		}

		// 3. Update Stock
		if ( ! $target_product->managing_stock() ) {
			SWB_Logger::info( 'Webhook ignored: WooCommerce product/variation is not managing stock.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku, 'wc_target_id' => $target_product->get_id() ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'product_not_managing_stock' ) );
		}

		$current_stock = $target_product->get_stock_quantity();
		
		if ( $current_stock !== $new_quantity ) {
			// wc_update_product_stock handles the update securely and fires necessary hooks.
			$result = wc_update_product_stock( $target_product, $new_quantity, 'set' );

			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				SWB_Logger::error( 'Stock update failed.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku, 'wc_target_id' => $target_product->get_id(), 'error' => $error_message ) );
				
				// Log failed update to history.
				SWB_DB::log_stock_update(
					array(
						'shopify_item_id' => $shopify_item_id,
						'wc_sku'          => $wc_sku,
						'wc_product_id'   => $target_product->get_id(),
						'old_stock'       => $current_stock,
						'new_stock'       => $new_quantity,
						'source'          => 'webhook',
						'status'          => 'failed',
						'error_message'   => $error_message,
					)
				);
				
				return rest_ensure_response( array( 'status' => 'error', 'reason' => 'stock_update_failed' ) );
			}
			
			// Log successful update to history.
			SWB_DB::log_stock_update(
				array(
					'shopify_item_id' => $shopify_item_id,
					'wc_sku'          => $wc_sku,
					'wc_product_id'   => $target_product->get_id(),
					'old_stock'       => $current_stock,
					'new_stock'       => $new_quantity,
					'source'          => 'webhook',
					'status'          => 'success',
				)
			);
			
			SWB_Logger::info( 'Stock updated successfully.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku, 'wc_target_id' => $target_product->get_id(), 'old_stock' => $current_stock, 'new_stock' => $new_quantity ) );
			return rest_ensure_response( array( 'status' => 'success', 'reason' => 'stock_updated', 'new_stock' => $new_quantity ) );
		}

		SWB_Logger::info( 'Stock update skipped: Quantity is already correct.', array( 'shopify_item_id' => $shopify_item_id, 'wc_sku' => $wc_sku, 'wc_target_id' => $target_product->get_id(), 'stock' => $current_stock ) );
		return rest_ensure_response( array( 'status' => 'success', 'reason' => 'stock_unchanged', 'new_stock' => $new_quantity ) );
	}

	/**
	 * Get inventory stock for a specific Shopify inventory item ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_inventory_stock( $request ) {
		$inventory_item_id = sanitize_text_field( $request['inventory_item_id'] );

		if ( $this->is_stock_api_kill_switch_enabled() ) {
			SWB_Logger::info( 'Stock query ignored: Stock REST API is disabled via kill switch.' );
			return $this->stock_api_disabled_response();
		}

		// Check if global sync is enabled.
		if ( 'yes' === get_option( 'swb_global_enable', 'no' ) ) {
			SWB_Logger::info( 'Stock query ignored: Global sync is disabled.' );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'global_sync_disabled',
					'message' => __( 'Global sync is currently disabled.', 'shopify-woo-bridge' ),
				)
			)->set_status( 503 );
		}

		// Check if the item is mapped.
		$mapping = SWB_DB::get_mapping_by_shopify_id( $inventory_item_id );
		if ( ! $mapping ) {
			SWB_Logger::info( 'Stock query: Item is not mapped.', array( 'shopify_item_id' => $inventory_item_id ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'unmapped_item',
					'message' => __( 'This inventory item is not mapped in the system.', 'shopify-woo-bridge' ),
				)
			)->set_status( 404 );
		}

		if ( ! $mapping->is_enabled ) {
			SWB_Logger::info( 'Stock query: Mapping is disabled.', array( 'shopify_item_id' => $inventory_item_id ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'mapping_disabled',
					'message' => __( 'This mapping is currently disabled.', 'shopify-woo-bridge' ),
				)
			)->set_status( 403 );
		}

		// Fetch stock from Shopify API.
		$client = new SWB_Shopify_API_Client();
		$response = $client->get_inventory_level_for_item( $inventory_item_id );

		if ( is_wp_error( $response ) ) {
			$status_code = 'swb_item_not_found' === $response->get_error_code() ? 404 : 500;
			SWB_Logger::error( 'Failed to fetch inventory from Shopify.', array( 'shopify_item_id' => $inventory_item_id, 'error' => $response->get_error_message() ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'shopify_api_error',
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				)
			)->set_status( $status_code );
		}

		// Get WooCommerce product information.
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
				$mapping->wc_sku
			)
		);

		$wc_product = null;
		if ( empty( $product_ids ) ) {
			SWB_Logger::warning( 'Stock query failed: Mapped WooCommerce SKU not found.', array( 'shopify_item_id' => $inventory_item_id, 'wc_sku' => $mapping->wc_sku ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'wc_sku_not_found',
					'message' => __( 'Mapped WooCommerce SKU was not found.', 'shopify-woo-bridge' ),
				)
			)->set_status( 404 );
		}

		if ( count( $product_ids ) > 1 ) {
			SWB_Logger::error( 'Stock query rejected: Multiple WooCommerce products found for mapped SKU.', array( 'shopify_item_id' => $inventory_item_id, 'wc_sku' => $mapping->wc_sku, 'matching_ids' => $product_ids ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'duplicate_wc_sku',
					'message' => __( 'Multiple WooCommerce products share this mapped SKU.', 'shopify-woo-bridge' ),
				)
			)->set_status( 409 );
		}

		$wc_product = wc_get_product( $product_ids[0] );
		if ( ! $wc_product ) {
			SWB_Logger::warning( 'Stock query failed: Mapped WooCommerce product could not be loaded.', array( 'shopify_item_id' => $inventory_item_id, 'wc_sku' => $mapping->wc_sku, 'wc_product_id' => $product_ids[0] ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'wc_product_not_loaded',
					'message' => __( 'Mapped WooCommerce product could not be loaded.', 'shopify-woo-bridge' ),
				)
			)->set_status( 500 );
		}

		$response_data = array(
			'success'          => true,
			'inventory_item_id' => $inventory_item_id,
			'wc_sku'           => $mapping->wc_sku,
			'shopify'          => $response,
			'woocommerce'      => array(
				'sku'   => $mapping->wc_sku,
				'stock' => $wc_product ? $wc_product->get_stock_quantity() : null,
			),
			'mapping'          => array(
				'id'              => $mapping->id,
				'enabled'         => (bool) $mapping->is_enabled,
				'shopify_item_id' => $mapping->shopify_item_id,
				'wc_sku'          => $mapping->wc_sku,
			),
		);

		SWB_Logger::info( 'Stock queried successfully.', array( 'shopify_item_id' => $inventory_item_id ) );

		return rest_ensure_response( $response_data )->set_status( 200 );
	}

	/**
	 * Get stock history for a specific Shopify inventory item ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function get_stock_history( $request ) {
		$inventory_item_id = sanitize_text_field( $request['inventory_item_id'] );
		$limit             = intval( $request->get_param( 'limit' ) );
		if ( $limit <= 0 ) {
			$limit = 50;
		}
		$limit = min( $limit, 200 );

		if ( $this->is_stock_api_kill_switch_enabled() ) {
			SWB_Logger::info( 'Stock history query ignored: Stock REST API is disabled via kill switch.' );
			return $this->stock_api_disabled_response();
		}

		// Check if global sync is enabled.
		if ( 'yes' === get_option( 'swb_global_enable', 'no' ) ) {
			SWB_Logger::info( 'Stock history query ignored: Global sync is disabled.' );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'global_sync_disabled',
					'message' => __( 'Global sync is currently disabled.', 'shopify-woo-bridge' ),
				)
			)->set_status( 503 );
		}

		// Check if the item is mapped.
		$mapping = SWB_DB::get_mapping_by_shopify_id( $inventory_item_id );
		if ( ! $mapping ) {
			SWB_Logger::info( 'Stock history query: Item is not mapped.', array( 'shopify_item_id' => $inventory_item_id ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'unmapped_item',
					'message' => __( 'This inventory item is not mapped in the system.', 'shopify-woo-bridge' ),
				)
			)->set_status( 404 );
		}

		if ( ! $mapping->is_enabled ) {
			SWB_Logger::info( 'Stock history query: Mapping is disabled.', array( 'shopify_item_id' => $inventory_item_id ) );
			return rest_ensure_response(
				array(
					'success' => false,
					'error'   => 'mapping_disabled',
					'message' => __( 'This mapping is currently disabled.', 'shopify-woo-bridge' ),
				)
			)->set_status( 403 );
		}

		// Get stock history from database.
		$history = SWB_DB::get_stock_history( $inventory_item_id, $limit );

		$response_data = array(
			'success'          => true,
			'inventory_item_id' => $inventory_item_id,
			'wc_sku'           => $mapping->wc_sku,
			'limit'            => $limit,
			'count'            => count( $history ),
			'history'          => $history,
			'mapping'          => array(
				'id'      => $mapping->id,
				'enabled' => (bool) $mapping->is_enabled,
			),
		);

		SWB_Logger::info( 'Stock history queried successfully.', array( 'shopify_item_id' => $inventory_item_id, 'records' => count( $history ) ) );

		return rest_ensure_response( $response_data )->set_status( 200 );
	}

	/**
	 * Check if stock REST API kill switch is enabled.
	 *
	 * @return bool
	 */
	private function is_stock_api_kill_switch_enabled() {
		return 'yes' === get_option( 'swb_stock_api_kill_switch', 'no' );
	}

	/**
	 * Standard response when stock REST API is disabled.
	 *
	 * @return WP_REST_Response
	 */
	private function stock_api_disabled_response() {
		return rest_ensure_response(
			array(
				'success' => false,
				'error'   => 'stock_api_disabled',
				'message' => __( 'Stock REST API is currently disabled.', 'shopify-woo-bridge' ),
			)
		)->set_status( 503 );
	}
}
