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
	}

	/**
	 * Verify Shopify HMAC Signature.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function verify_shopify_signature( $request ) {
		// 1. Check if the global kill switch is active.
		if ( 'yes' !== get_option( 'swb_global_enable', 'no' ) ) {
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
	 * Process the incoming webhook.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response
	 */
	public function process_webhook( $request ) {
		// Check global enable again. If disabled, log and exit.
		if ( 'yes' !== get_option( 'swb_global_enable', 'no' ) ) {
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
			SWB_Logger::info( 'Webhook ignored: Mapping is disabled.', array( 'shopify_item_id' => $shopify_item_id, 'wc_product_id' => $mapping->wc_product_id ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'mapping_disabled' ) );
		}

		// 2. Retrieve WooCommerce Product
		$wc_product_id = absint( $mapping->wc_product_id );
		$product = wc_get_product( $wc_product_id );

		if ( ! $product ) {
			SWB_Logger::warning( 'Webhook ignored: Mapped WooCommerce parent product not found.', array( 'shopify_item_id' => $shopify_item_id, 'wc_product_id' => $wc_product_id ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'wc_product_not_found' ) );
		}

		$target_product = $product;
		$wc_variation_id = ! empty( $mapping->wc_variation_id ) ? absint( $mapping->wc_variation_id ) : 0;

		if ( $wc_variation_id > 0 ) {
			$variation = wc_get_product( $wc_variation_id );
			
			if ( ! $variation ) {
				SWB_Logger::warning( 'Webhook ignored: Mapped WooCommerce variation not found.', array( 'shopify_item_id' => $shopify_item_id, 'wc_variation_id' => $wc_variation_id ) );
				return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'wc_variation_not_found' ) );
			}

			// Validate that the variation belongs to the mapped parent
			if ( $variation->get_parent_id() !== $wc_product_id ) {
				SWB_Logger::error( 'Webhook rejected: WooCommerce variation does not belong to mapped parent product.', array( 'shopify_item_id' => $shopify_item_id, 'wc_product_id' => $wc_product_id, 'wc_variation_id' => $wc_variation_id ) );
				return rest_ensure_response( array( 'status' => 'error', 'reason' => 'invalid_variation_parent' ) );
			}

			$target_product = $variation;
		} else {
			// If no variation mapped, ensure the parent product is actually simple
			if ( $product->is_type( 'variable' ) ) {
				SWB_Logger::error( 'Webhook rejected: Cannot update stock for variable product without a variation ID.', array( 'shopify_item_id' => $shopify_item_id, 'wc_product_id' => $wc_product_id ) );
				return rest_ensure_response( array( 'status' => 'error', 'reason' => 'variable_product_requires_variation' ) );
			}
		}

		// 3. Update Stock
		if ( ! $target_product->managing_stock() ) {
			SWB_Logger::info( 'Webhook ignored: WooCommerce product/variation is not managing stock.', array( 'shopify_item_id' => $shopify_item_id, 'wc_target_id' => $target_product->get_id() ) );
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'product_not_managing_stock' ) );
		}

		$current_stock = $target_product->get_stock_quantity();
		
		if ( $current_stock !== $new_quantity ) {
			// wc_update_product_stock handles the update securely and fires necessary hooks.
			$result = wc_update_product_stock( $target_product, $new_quantity, 'set' );

			if ( is_wp_error( $result ) ) {
				SWB_Logger::error( 'Stock update failed.', array( 'shopify_item_id' => $shopify_item_id, 'wc_target_id' => $target_product->get_id(), 'error' => $result->get_error_message() ) );
				return rest_ensure_response( array( 'status' => 'error', 'reason' => 'stock_update_failed' ) );
			}
			
			SWB_Logger::info( 'Stock updated successfully.', array( 'shopify_item_id' => $shopify_item_id, 'wc_target_id' => $target_product->get_id(), 'old_stock' => $current_stock, 'new_stock' => $new_quantity ) );
			return rest_ensure_response( array( 'status' => 'success', 'reason' => 'stock_updated', 'new_stock' => $new_quantity ) );
		}

		SWB_Logger::info( 'Stock update skipped: Quantity is already correct.', array( 'shopify_item_id' => $shopify_item_id, 'wc_target_id' => $target_product->get_id(), 'stock' => $current_stock ) );
		return rest_ensure_response( array( 'status' => 'success', 'reason' => 'stock_unchanged', 'new_stock' => $new_quantity ) );
	}
}
