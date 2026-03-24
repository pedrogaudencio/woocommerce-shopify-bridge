<?php
/**
 * Shopify Admin API read-only client.
 *
 * @package Shopify_WooCommerce_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_Shopify_API_Client Class.
 */
class SWB_Shopify_API_Client {

	/**
	 * Shopify stable API version.
	 *
	 * @var string
	 */
	const API_VERSION = '2025-10';

	/**
	 * Token lifetime in seconds for Dev Dashboard client credentials flow.
	 *
	 * @var int
	 */
	const ACCESS_TOKEN_TTL = 86400;

	/**
	 * Validate credentials by performing a read-only shop query.
	 *
	 * @return array
	 */
	public function test_connection() {
		$response = $this->request(
			'shop.json',
			array(
				'fields' => 'name,myshopify_domain,plan_name',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$body = $response['body'];
		if ( empty( $body['shop']['myshopify_domain'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Connected response was invalid. Shopify shop details were not returned.', 'shopify-woo-bridge' ),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: Shopify domain. */
				__( 'Connection successful. Connected to %s.', 'shopify-woo-bridge' ),
				$body['shop']['myshopify_domain']
			),
		);
	}

	/**
	 * Fetch products with cursor pagination.
	 *
	 * @return array|WP_Error
	 */
	public function get_products() {
		$products = array();
		$next_url = null;

		do {
			if ( empty( $next_url ) ) {
				$response = $this->request(
					'products.json',
					array(
						'limit'  => 250,
						'status' => 'active',
						'fields' => 'id,title,handle,status,vendor,product_type,updated_at,variants',
					)
				);
			} else {
				$response = $this->request_by_url( $next_url );
			}

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( ! empty( $response['body']['products'] ) && is_array( $response['body']['products'] ) ) {
				$products = array_merge( $products, $response['body']['products'] );
			}

			$next_url = $this->extract_next_link( $response['headers'] );
		} while ( ! empty( $next_url ) );

		return $products;
	}

	/**
	 * Fetch one Shopify product with media fields needed for image syncing.
	 *
	 * @param string $shopify_product_id Shopify product ID.
	 * @return array|WP_Error
	 */
	public function get_product_with_media( $shopify_product_id ) {
		$shopify_product_id = trim( (string) $shopify_product_id );
		if ( '' === $shopify_product_id ) {
			return new WP_Error( 'swb_invalid_product_id', __( 'Invalid Shopify product ID.', 'shopify-woo-bridge' ) );
		}

		$response = $this->request(
			'products/' . rawurlencode( $shopify_product_id ) . '.json',
			array(
				'fields' => 'id,image,images,variants',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['body']['product'] ) || ! is_array( $response['body']['product'] ) ) {
			return new WP_Error( 'swb_product_not_found', __( 'Shopify product was not found.', 'shopify-woo-bridge' ) );
		}

		return $response['body']['product'];
	}

	/**
	 * Fetch inventory levels by inventory item IDs.
	 *
	 * @param array $inventory_item_ids Inventory item IDs.
	 * @return array|WP_Error
	 */
	public function get_inventory_levels_for_item_ids( $inventory_item_ids ) {
		$inventory_item_ids = array_filter( array_map( 'strval', $inventory_item_ids ) );
		if ( empty( $inventory_item_ids ) ) {
			return array();
		}

		$levels_by_item = array();
		$chunks         = array_chunk( array_values( array_unique( $inventory_item_ids ) ), 50 );

		foreach ( $chunks as $chunk ) {
			$next_url = null;
			do {
				if ( empty( $next_url ) ) {
					$response = $this->request(
						'inventory_levels.json',
						array(
							'limit'              => 250,
							'inventory_item_ids' => implode( ',', $chunk ),
						)
					);
				} else {
					$response = $this->request_by_url( $next_url );
				}

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$levels = ! empty( $response['body']['inventory_levels'] ) && is_array( $response['body']['inventory_levels'] )
					? $response['body']['inventory_levels']
					: array();

				foreach ( $levels as $level ) {
					$item_id = isset( $level['inventory_item_id'] ) ? strval( $level['inventory_item_id'] ) : '';
					if ( '' === $item_id ) {
						continue;
					}

					if ( ! isset( $levels_by_item[ $item_id ] ) ) {
						$levels_by_item[ $item_id ] = array();
					}

					$levels_by_item[ $item_id ][] = array(
						'location_id' => isset( $level['location_id'] ) ? strval( $level['location_id'] ) : '',
						'available'   => isset( $level['available'] ) ? intval( $level['available'] ) : null,
					);
				}

				$next_url = $this->extract_next_link( $response['headers'] );
			} while ( ! empty( $next_url ) );
		}

		return $levels_by_item;
	}

	/**
	 * Fetch inventory level for a single inventory item ID.
	 *
	 * @param string $inventory_item_id The Shopify inventory item ID.
	 * @return array|WP_Error Array with 'inventory_item_id', 'locations' (array of location stock), or WP_Error.
	 */
	public function get_inventory_level_for_item( $inventory_item_id ) {
		$inventory_item_id = strval( $inventory_item_id );
		if ( empty( $inventory_item_id ) ) {
			return new WP_Error( 'swb_invalid_item_id', __( 'Invalid or empty inventory item ID.', 'shopify-woo-bridge' ) );
		}

		$response = $this->request(
			'inventory_levels.json',
			array(
				'inventory_item_ids' => $inventory_item_id,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$levels = ! empty( $response['body']['inventory_levels'] ) && is_array( $response['body']['inventory_levels'] )
			? $response['body']['inventory_levels']
			: array();

		if ( empty( $levels ) ) {
			return new WP_Error( 'swb_item_not_found', __( 'Inventory item not found in Shopify.', 'shopify-woo-bridge' ) );
		}

		$locations = array();
		foreach ( $levels as $level ) {
			$locations[] = array(
				'location_id' => isset( $level['location_id'] ) ? strval( $level['location_id'] ) : '',
				'available'   => isset( $level['available'] ) ? intval( $level['available'] ) : null,
			);
		}

		return array(
			'inventory_item_id' => $inventory_item_id,
			'locations'         => $locations,
		);
	}

	/**
	 * Request helper for endpoint + query.
	 *
	 * @param string $endpoint Endpoint relative to /admin/api/{version}/.
	 * @param array  $query Query args.
	 * @return array|WP_Error
	 */
	public function request( $endpoint, $query = array() ) {
		$store_domain = $this->get_store_domain();
		if ( empty( $store_domain ) ) {
			return new WP_Error( 'swb_store_missing', __( 'Shopify store domain is not configured.', 'shopify-woo-bridge' ) );
		}

		$path = 'admin/api/' . self::API_VERSION . '/' . ltrim( $endpoint, '/' );
		$url  = 'https://' . $store_domain . '/' . $path;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $this->request_by_url( $url );
	}

	/**
	 * Execute read-only GET request to an already built URL.
	 *
	 * @param string $url Absolute Shopify URL.
	 * @return array|WP_Error
	 */
	public function request_by_url( $url ) {
		$auth = $this->get_auth_values();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return $this->perform_request( $url, $auth );
	}

	/**
	 * Parse Link header and return next cursor URL when present.
	 *
	 * @param array $headers Response headers.
	 * @return string|null
	 */
	private function extract_next_link( $headers ) {
		if ( empty( $headers['link'] ) ) {
			return null;
		}

		$link_header = $headers['link'];
		if ( is_array( $link_header ) ) {
			$link_header = implode( ',', $link_header );
		}

		if ( preg_match( '/<([^>]+)>;\s*rel="next"/i', $link_header, $matches ) ) {
			return $matches[1];
		}

		return null;
	}

	/**
	 * Execute GET request with lightweight retry for rate limiting.
	 *
	 * @param string $url Full URL.
	 * @param array  $auth Auth payload.
	 * @return array|WP_Error
	 */
	private function perform_request( $url, $auth ) {
		$args = array(
			'method'  => 'GET',
			'timeout' => 30,
			'headers' => $this->build_headers( $auth ),
		);

		$attempts = 0;
		do {
			$attempts++;
			$raw_response = wp_remote_request( $url, $args );

			if ( is_wp_error( $raw_response ) ) {
				return $raw_response;
			}

			$code    = wp_remote_retrieve_response_code( $raw_response );
			$body    = json_decode( wp_remote_retrieve_body( $raw_response ), true );
			$headers = wp_remote_retrieve_headers( $raw_response );

			if ( 429 !== intval( $code ) ) {
				if ( intval( $code ) < 200 || intval( $code ) >= 300 ) {
					return new WP_Error( 'swb_shopify_request_failed', $this->build_error_message( $code, $body ) );
				}

				return array(
					'code'    => intval( $code ),
					'body'    => is_array( $body ) ? $body : array(),
					'headers' => $headers,
				);
			}

			$retry_after = isset( $headers['retry-after'] ) ? max( 1, intval( $headers['retry-after'] ) ) : $attempts;
			sleep( $retry_after );
		} while ( $attempts < 3 );

		return new WP_Error( 'swb_shopify_rate_limited', __( 'Shopify API rate limit reached. Please try again shortly.', 'shopify-woo-bridge' ) );
	}

	/**
	 * Build auth headers.
	 *
	 * @param array $auth Auth payload.
	 * @return array
	 */
	private function build_headers( $auth ) {
		$headers = array(
			'Accept'                 => 'application/json',
			'User-Agent'             => 'Shopify-WooCommerce-Bridge/' . SWB_VERSION . '; ' . home_url( '/' ),
			'X-Shopify-Access-Token' => $auth['access_token'],
		);

		return $headers;
	}

	/**
	 * Build readable API failure message.
	 *
	 * @param int   $code HTTP status code.
	 * @param array $body Decoded JSON response body.
	 * @return string
	 */
	private function build_error_message( $code, $body ) {
		$message = '';

		if ( ! empty( $body['errors'] ) ) {
			if ( is_string( $body['errors'] ) ) {
				$message = $body['errors'];
			} else {
				$message = wp_json_encode( $body['errors'] );
			}
		} elseif ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
			$message = $body['error'];
		}

		if ( empty( $message ) ) {
			/* translators: %d: HTTP status code from Shopify API. */
			return sprintf( __( 'Shopify API request failed with HTTP %d.', 'shopify-woo-bridge' ), intval( $code ) );
		}

		/* translators: 1: HTTP status code. 2: Shopify API message. */
		return sprintf( __( 'Shopify API request failed (HTTP %1$d): %2$s', 'shopify-woo-bridge' ), intval( $code ), sanitize_text_field( $message ) );
	}

	/**
	 * Validate and read configured credentials.
	 *
	 * @return array|WP_Error
	 */
	private function get_auth_values() {
		$access_token = $this->get_or_refresh_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		return array(
			'access_token' => $access_token,
		);
	}

	/**
	 * Return a valid cached token or generate a new one if expired/missing.
	 *
	 * @return string|WP_Error
	 */
	private function get_or_refresh_access_token() {
		$cached_token      = trim( (string) get_option( 'swb_shopify_access_token', '' ) );
		$created_timestamp = intval( get_option( 'swb_shopify_access_token_created_at', 0 ) );
		$now               = time();

		if ( '' !== $cached_token && $created_timestamp > 0 && ( $now - $created_timestamp ) < self::ACCESS_TOKEN_TTL ) {
			return $cached_token;
		}

		$generated = $this->generate_access_token();
		if ( is_wp_error( $generated ) ) {
			return $generated;
		}

		update_option( 'swb_shopify_access_token', $generated );
		update_option( 'swb_shopify_access_token_created_at', $now );

		return $generated;
	}

	/**
	 * Generate an Admin API token using client credentials.
	 *
	 * @return string|WP_Error
	 */
	private function generate_access_token() {
		$store_domain = $this->get_store_domain();
		if ( empty( $store_domain ) ) {
			return new WP_Error( 'swb_store_missing', __( 'Shopify store domain is not configured.', 'shopify-woo-bridge' ) );
		}

		$client_id     = trim( (string) get_option( 'swb_shopify_client_id', '' ) );
		$client_secret = trim( (string) get_option( 'swb_shopify_client_secret', '' ) );

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'swb_client_credentials_missing', __( 'Shopify client ID or client secret is not configured.', 'shopify-woo-bridge' ) );
		}

		$url = 'https://' . $store_domain . '/admin/oauth/access_token';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'grant_type'    => 'client_credentials',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( intval( $code ) < 200 || intval( $code ) >= 300 ) {
			return new WP_Error( 'swb_token_request_failed', $this->build_error_message( $code, is_array( $body ) ? $body : array() ) );
		}

		if ( empty( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
			return new WP_Error( 'swb_token_missing', __( 'Shopify token response did not include an access token.', 'shopify-woo-bridge' ) );
		}

		return trim( $body['access_token'] );
	}

	/**
	 * Read configured store domain.
	 *
	 * @return string
	 */
	private function get_store_domain() {
		$domain = trim( strtolower( (string) get_option( 'swb_shopify_store_domain', '' ) ) );
		return preg_replace( '#^https?://#', '', $domain );
	}
}

