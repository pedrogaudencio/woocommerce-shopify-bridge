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
			return new WP_Error(
				'swb_missing_secret',
				__( 'Shopify webhook secret is not configured.', 'shopify-woo-bridge' ),
				array( 'status' => 401 )
			);
		}

		// 3. Get the header signature.
		$hmac_header = $request->get_header( 'x_shopify_hmac_sha256' );
		if ( empty( $hmac_header ) ) {
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
			// Log here (later task)
			return rest_ensure_response( array( 'status' => 'ignored', 'reason' => 'global_sync_disabled' ) );
		}

		// We will implement mapping logic and stock updates here in subsequent tasks.

		return rest_ensure_response( array( 'status' => 'success', 'message' => 'Webhook verified.' ) );
	}
}
