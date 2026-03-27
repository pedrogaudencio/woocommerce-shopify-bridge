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
	 * Maximum allowed age/skew for signed requests in seconds.
	 *
	 * @var int
	 */
	const SIGNATURE_MAX_AGE_SECONDS = 600;

	/**
	 * Webhook replay cache TTL in seconds.
	 *
	 * @var int
	 */
	const WEBHOOK_REPLAY_TTL_SECONDS = 900;

	/**
	 * Generic rate limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW_SECONDS = 60;

	/**
	 * Maximum webhook requests per rate-limit window per source.
	 *
	 * @var int
	 */
	const WEBHOOK_RATE_LIMIT_MAX_REQUESTS = 120;

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
		$rate_limit = $this->enforce_rate_limit( $request, 'webhook', self::WEBHOOK_RATE_LIMIT_MAX_REQUESTS, self::RATE_LIMIT_WINDOW_SECONDS );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		// Always enforce signature verification at the permission layer.
		// Disabled-state behavior is handled by the endpoint callbacks.
		// 1. Retrieve the stored secret.
		$secret = get_option( 'swb_webhook_secret', '' );
		if ( empty( $secret ) ) {
			SWB_Logger::error( 'Webhook rejected: Shopify webhook secret is not configured.' );
			return new WP_Error(
				'swb_missing_secret',
				__( 'Shopify webhook secret is not configured.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		// 2. Get the header signature.
		$hmac_header = $request->get_header( 'x_shopify_hmac_sha256' );
		if ( empty( $hmac_header ) ) {
			SWB_Logger::warning( 'Webhook rejected: Missing X-Shopify-Hmac-Sha256 header.' );
			return new WP_Error(
				'swb_missing_signature',
				__( 'Missing X-Shopify-Hmac-Sha256 header.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		// 3. Calculate our own signature.
		$raw_payload = $request->get_body();
		$calculated_hmac = base64_encode( hash_hmac( 'sha256', $raw_payload, $secret, true ) );

		// 4. Compare using timing-safe string comparison.
		if ( ! hash_equals( $calculated_hmac, $hmac_header ) ) {
			SWB_Logger::error( 'Webhook rejected: Invalid signature.' );
			return new WP_Error(
				'swb_invalid_signature',
				__( 'Invalid signature.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		$freshness = $this->verify_shopify_request_freshness( $request );
		if ( is_wp_error( $freshness ) ) {
			return $freshness;
		}

		$replay_guard = $this->verify_shopify_replay_protection( $request, $calculated_hmac );
		if ( is_wp_error( $replay_guard ) ) {
			return $replay_guard;
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

		// Allow WordPress administrators.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// External GET access requires a signed request bound to method/path/query and timestamp.
		return $this->verify_signed_read_request( $request );
	}

	/**
	 * Verify signed GET request for stock read endpoints.
	 *
	 * Required headers:
	 * - X-SWB-Timestamp: unix timestamp
	 * - X-SWB-Signature: hex HMAC-SHA256 over canonical payload
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function verify_signed_read_request( $request ) {
		$rate_limit = $this->enforce_rate_limit( $request, 'read', self::WEBHOOK_RATE_LIMIT_MAX_REQUESTS, self::RATE_LIMIT_WINDOW_SECONDS );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$secret = get_option( 'swb_webhook_secret', '' );
		if ( '' === trim( (string) $secret ) ) {
			return new WP_Error( 'swb_missing_secret', __( 'Webhook secret is not configured.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		$timestamp_raw = $request->get_header( 'x_swb_timestamp' );
		$signature     = strtolower( trim( (string) $request->get_header( 'x_swb_signature' ) ) );

		if ( '' === $timestamp_raw || '' === $signature ) {
			return new WP_Error( 'swb_missing_read_signature', __( 'Missing X-SWB-Timestamp or X-SWB-Signature header.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		if ( ! ctype_digit( (string) $timestamp_raw ) ) {
			return new WP_Error( 'swb_invalid_read_timestamp', __( 'Invalid X-SWB-Timestamp header.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		$timestamp = intval( $timestamp_raw );
		$now       = time();
		if ( abs( $now - $timestamp ) > self::SIGNATURE_MAX_AGE_SECONDS ) {
			return new WP_Error( 'swb_stale_read_signature', __( 'Signed request is expired or outside the allowed time window.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		$canonical = $this->build_signed_read_canonical_payload( $request, $timestamp );
		$expected  = hash_hmac( 'sha256', $canonical, (string) $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error( 'swb_invalid_read_signature', __( 'Invalid read request signature.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		$replay_key = 'swb_read_sig_' . md5( $signature . '|' . $timestamp );
		if ( get_transient( $replay_key ) ) {
			return new WP_Error( 'swb_read_replay_detected', __( 'Replay detected for signed read request.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		set_transient( $replay_key, 1, self::SIGNATURE_MAX_AGE_SECONDS );

		return true;
	}

	/**
	 * Build canonical payload for signed read requests.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param int             $timestamp Timestamp.
	 * @return string
	 */
	private function build_signed_read_canonical_payload( $request, $timestamp ) {
		$method = strtoupper( (string) $request->get_method() );
		$route  = '/' . ltrim( (string) $request->get_route(), '/' );

		$query_params = (array) $request->get_query_params();
		unset( $query_params['rest_route'] );
		ksort( $query_params );
		$query = http_build_query( $query_params, '', '&', PHP_QUERY_RFC3986 );

		return implode(
			"\n",
			array(
				$method,
				$route,
				$query,
				(string) intval( $timestamp ),
			)
		);
	}

	/**
	 * Verify Shopify request freshness by timestamp header.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	private function verify_shopify_request_freshness( $request ) {
		$triggered_at = trim( (string) $request->get_header( 'x_shopify_triggered_at' ) );
		if ( '' === $triggered_at ) {
			return new WP_Error( 'swb_missing_triggered_at', __( 'Missing X-Shopify-Triggered-At header.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		$timestamp = strtotime( $triggered_at );
		if ( false === $timestamp ) {
			return new WP_Error( 'swb_invalid_triggered_at', __( 'Invalid X-Shopify-Triggered-At header.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		if ( abs( time() - intval( $timestamp ) ) > self::SIGNATURE_MAX_AGE_SECONDS ) {
			return new WP_Error( 'swb_stale_webhook', __( 'Webhook request is outside the allowed freshness window.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Verify webhook replay protection using Shopify webhook ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $calculated_hmac Calculated HMAC.
	 * @return true|WP_Error
	 */
	private function verify_shopify_replay_protection( $request, $calculated_hmac ) {
		$webhook_id = trim( (string) $request->get_header( 'x_shopify_webhook_id' ) );
		if ( '' === $webhook_id ) {
			return new WP_Error( 'swb_missing_webhook_id', __( 'Missing X-Shopify-Webhook-Id header.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		$replay_key = 'swb_webhook_seen_' . md5( $webhook_id . '|' . $calculated_hmac );
		if ( get_transient( $replay_key ) ) {
			return new WP_Error( 'swb_webhook_replay', __( 'Replay detected for Shopify webhook request.', 'shopify-woo-bridge' ), array( 'status' => 401 ) );
		}

		set_transient( $replay_key, 1, self::WEBHOOK_REPLAY_TTL_SECONDS );

		return true;
	}

	/**
	 * Apply per-source endpoint rate limiting.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $scope Scope key.
	 * @param int             $max_requests Max requests in window.
	 * @param int             $window_seconds Window in seconds.
	 * @return true|WP_Error
	 */
	private function enforce_rate_limit( $request, $scope, $max_requests, $window_seconds ) {
		$ip = $this->get_request_ip( $request );
		if ( '' === $ip ) {
			$ip = 'unknown';
		}

		$key     = 'swb_rl_' . md5( $scope . '|' . $ip );
		$current = intval( get_transient( $key ) );

		if ( $current >= intval( $max_requests ) ) {
			return new WP_Error( 'swb_rate_limited', __( 'Rate limit exceeded. Please retry later.', 'shopify-woo-bridge' ), array( 'status' => 429 ) );
		}

		set_transient( $key, $current + 1, max( 1, intval( $window_seconds ) ) );

		return true;
	}

	/**
	 * Resolve caller IP address from request headers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function get_request_ip( $request ) {
		$forwarded_for = trim( (string) $request->get_header( 'x_forwarded_for' ) );
		if ( '' !== $forwarded_for ) {
			$parts = array_map( 'trim', explode( ',', $forwarded_for ) );
			if ( ! empty( $parts[0] ) ) {
				return sanitize_text_field( $parts[0] );
			}
		}

		$real_ip = trim( (string) $request->get_header( 'x_real_ip' ) );
		if ( '' !== $real_ip ) {
			return sanitize_text_field( $real_ip );
		}

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return '';
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
