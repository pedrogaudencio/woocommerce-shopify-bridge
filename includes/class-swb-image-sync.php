<?php
/**
 * Manual Shopify -> WooCommerce image sync service.
 *
 * @package Shopify_WooCommerce_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_Image_Sync Class.
 */
class SWB_Image_Sync {

	/**
	 * Source URL meta key for imported attachments.
	 *
	 * @var string
	 */
	const ATTACHMENT_SOURCE_META = '_swb_shopify_source_url';

	/**
	 * Last media hash meta key.
	 *
	 * @var string
	 */
	const LAST_MEDIA_HASH_META = 'shopify_sync_last_media_hash';

	/**
	 * Last media synced timestamp meta key.
	 *
	 * @var string
	 */
	const LAST_MEDIA_SYNCED_AT_META = 'shopify_sync_last_media_synced_at';

	/**
	 * Last local media signature meta key.
	 *
	 * @var string
	 */
	const LAST_MEDIA_SIGNATURE_META = 'shopify_sync_last_media_signature';

	/**
	 * Sync images for one mapping row (product group + mapped variations).
	 *
	 * @param object|array $mapping Mapping row.
	 * @return array
	 */
	public function sync_images_for_mapping( $mapping ) {
		$shopify_product_id = $this->get_mapping_value( $mapping, 'shopify_product_id' );
		$is_enabled         = (int) $this->get_mapping_value( $mapping, 'is_enabled', 0 );

		if ( '' === $shopify_product_id ) {
			return array(
				'success' => false,
				'changed' => false,
				'message' => __( 'Mapping is missing Shopify Product ID.', 'shopify-woo-bridge' ),
			);
		}

		if ( 1 !== $is_enabled ) {
			return array(
				'success' => false,
				'changed' => false,
				'message' => __( 'Mapping is disabled. Enable it before syncing images.', 'shopify-woo-bridge' ),
			);
		}

		$group_mappings = SWB_DB::get_mappings_by_shopify_product_id( $shopify_product_id );
		if ( empty( $group_mappings ) ) {
			return array(
				'success' => false,
				'changed' => false,
				'message' => __( 'No mappings found for this Shopify Product ID.', 'shopify-woo-bridge' ),
			);
		}

		$api             = new SWB_Shopify_API_Client();
		$shopify_product = $api->get_product_with_media( $shopify_product_id );
		if ( is_wp_error( $shopify_product ) ) {
			return array(
				'success' => false,
				'changed' => false,
				'message' => $shopify_product->get_error_message(),
			);
		}

		$full_images = $api->get_product_images( $shopify_product_id );
		if ( ! is_wp_error( $full_images ) && ! empty( $full_images ) ) {
			$shopify_product['images'] = $full_images;
		}

		$image_map = $this->build_shopify_image_map( $shopify_product );
		$parent_id = $this->resolve_parent_product_id_from_group( $group_mappings );

		if ( $parent_id <= 0 ) {
			return array(
				'success' => false,
				'changed' => false,
				'message' => __( 'Could not resolve a WooCommerce parent product from mapped SKUs.', 'shopify-woo-bridge' ),
			);
		}

		$parent_result    = $this->sync_parent_gallery( $parent_id, $shopify_product_id, $image_map );
		$variation_result = $this->sync_mapped_variations( $group_mappings, $shopify_product_id, $shopify_product, $image_map );

		$success = $parent_result['success'] && $variation_result['success'];
		$changed = $parent_result['changed'] || $variation_result['changed'];

		if ( ! $success ) {
			$message_parts = array();
			if ( ! empty( $parent_result['message'] ) ) {
				$message_parts[] = $parent_result['message'];
			}
			if ( ! empty( $variation_result['message'] ) ) {
				$message_parts[] = $variation_result['message'];
			}

			return array(
				'success' => false,
				'changed' => $changed,
				'message' => implode( ' ', array_filter( $message_parts ) ),
			);
		}

		if ( ! $changed ) {
			return array(
				'success' => true,
				'changed' => false,
				'message' => __( 'Images already in sync (no media changes detected).', 'shopify-woo-bridge' ),
			);
		}

		return array(
			'success' => true,
			'changed' => true,
			'message' => __( 'Images synced successfully.', 'shopify-woo-bridge' ),
		);
	}

	/**
	 * Build map of Shopify image IDs -> image URLs with ordered gallery list.
	 *
	 * @param array $shopify_product Shopify product payload.
	 * @return array
	 */
	private function build_shopify_image_map( $shopify_product ) {
		$images      = isset( $shopify_product['images'] ) && is_array( $shopify_product['images'] ) ? $shopify_product['images'] : array();
		$image_by_id = array();
		$ordered     = array();

		foreach ( $images as $image ) {
			$image_id = isset( $image['id'] ) ? strval( $image['id'] ) : '';
			$src      = isset( $image['src'] ) ? esc_url_raw( $image['src'] ) : '';
			if ( '' === $image_id || '' === $src ) {
				continue;
			}

			$image_by_id[ $image_id ] = $src;
			$ordered[]                = array(
				'id'  => $image_id,
				'src' => $src,
			);
		}

		$featured_src = '';
		$featured_id  = '';
		if ( isset( $shopify_product['image'] ) && is_array( $shopify_product['image'] ) ) {
			$featured_id  = isset( $shopify_product['image']['id'] ) ? strval( $shopify_product['image']['id'] ) : '';
			$featured_src = isset( $shopify_product['image']['src'] ) ? esc_url_raw( $shopify_product['image']['src'] ) : '';
		}

		if ( '' === $featured_src && '' !== $featured_id && isset( $image_by_id[ $featured_id ] ) ) {
			$featured_src = $image_by_id[ $featured_id ];
		}

		if ( '' === $featured_src && ! empty( $ordered ) ) {
			$featured_src = $ordered[0]['src'];
			$featured_id  = $ordered[0]['id'];
		}

		return array(
			'image_by_id'  => $image_by_id,
			'ordered'      => $ordered,
			'featured_id'  => $featured_id,
			'featured_src' => $featured_src,
		);
	}

	/**
	 * Sync parent featured image and gallery based on Shopify product images.
	 *
	 * @param int    $parent_id WooCommerce parent product ID.
	 * @param string $shopify_product_id Shopify product ID.
	 * @param array  $image_map Shopify image map.
	 * @return array
	 */
	private function sync_parent_gallery( $parent_id, $shopify_product_id, $image_map ) {
		$hash_payload = array(
			'shopify_product_id' => (string) $shopify_product_id,
			'featured'           => array(
				'id'  => (string) $image_map['featured_id'],
				'src' => (string) $image_map['featured_src'],
			),
			'gallery'            => $image_map['ordered'],
		);

		$new_hash  = md5( wp_json_encode( $hash_payload ) );
		$last_hash = (string) get_post_meta( $parent_id, self::LAST_MEDIA_HASH_META, true );

		if ( '' !== $last_hash && hash_equals( $last_hash, $new_hash ) ) {
			return array(
				'success' => true,
				'changed' => false,
				'message' => '',
			);
		}

		$featured_attachment_id = 0;
		if ( '' !== $image_map['featured_src'] ) {
			$featured_attachment_id = $this->import_or_get_attachment_id( $image_map['featured_src'], $parent_id );
			if ( is_wp_error( $featured_attachment_id ) ) {
				return array(
					'success' => false,
					'changed' => false,
					'message' => sprintf( __( 'Parent featured image sync failed: %s', 'shopify-woo-bridge' ), $featured_attachment_id->get_error_message() ),
				);
			}
		}

		$gallery_attachment_ids = array();
		foreach ( $image_map['ordered'] as $gallery_item ) {
			if ( empty( $gallery_item['src'] ) ) {
				continue;
			}

			$attachment_id = $this->import_or_get_attachment_id( $gallery_item['src'], $parent_id );
			if ( is_wp_error( $attachment_id ) ) {
				return array(
					'success' => false,
					'changed' => false,
					'message' => sprintf( __( 'Parent gallery sync failed: %s', 'shopify-woo-bridge' ), $attachment_id->get_error_message() ),
				);
			}

			$gallery_attachment_ids[] = absint( $attachment_id );
		}

		$gallery_attachment_ids = array_values( array_unique( array_filter( $gallery_attachment_ids ) ) );

		if ( $featured_attachment_id > 0 ) {
			set_post_thumbnail( $parent_id, $featured_attachment_id );
		}

		$gallery_without_featured = $gallery_attachment_ids;
		if ( $featured_attachment_id > 0 ) {
			$gallery_without_featured = array_values(
				array_filter(
					$gallery_attachment_ids,
					function( $id ) use ( $featured_attachment_id ) {
						return intval( $id ) !== intval( $featured_attachment_id );
					}
				)
			);
		}

		update_post_meta( $parent_id, '_product_image_gallery', implode( ',', array_map( 'absint', $gallery_without_featured ) ) );
		update_post_meta( $parent_id, self::LAST_MEDIA_HASH_META, $new_hash );
		update_post_meta( $parent_id, self::LAST_MEDIA_SYNCED_AT_META, gmdate( 'Y-m-d H:i:s' ) );

		$local_signature = $this->build_parent_local_signature( $featured_attachment_id, $gallery_without_featured );
		update_post_meta( $parent_id, self::LAST_MEDIA_SIGNATURE_META, $local_signature );

		return array(
			'success' => true,
			'changed' => true,
			'message' => '',
		);
	}

	/**
	 * Sync variation image for eligible mapped variations.
	 *
	 * @param array  $group_mappings Mappings in same Shopify product group.
	 * @param string $shopify_product_id Shopify product ID.
	 * @param array  $shopify_product Shopify product payload.
	 * @param array  $image_map Shopify image map.
	 * @return array
	 */
	private function sync_mapped_variations( $group_mappings, $shopify_product_id, $shopify_product, $image_map ) {
		$variants      = isset( $shopify_product['variants'] ) && is_array( $shopify_product['variants'] ) ? $shopify_product['variants'] : array();
		$variant_by_id = array();
		$changed_any   = false;
		$messages      = array();

		foreach ( $variants as $variant ) {
			$variant_id = isset( $variant['id'] ) ? strval( $variant['id'] ) : '';
			if ( '' !== $variant_id ) {
				$variant_by_id[ $variant_id ] = $variant;
			}
		}

		foreach ( $group_mappings as $mapping ) {
			if ( 1 !== intval( $this->get_mapping_value( $mapping, 'is_enabled', 0 ) ) ) {
				continue;
			}

			$shopify_variant_id = $this->get_mapping_value( $mapping, 'shopify_variant_id' );
			$wc_sku             = $this->get_mapping_value( $mapping, 'wc_sku' );

			if ( '' === $shopify_variant_id || '' === $wc_sku ) {
				continue;
			}

			$wc_product_id = $this->find_wc_product_id_by_sku( $wc_sku );
			if ( is_wp_error( $wc_product_id ) ) {
				$messages[] = sprintf( __( 'Variation %1$s skipped: %2$s', 'shopify-woo-bridge' ), $shopify_variant_id, $wc_product_id->get_error_message() );
				continue;
			}

			$wc_product = wc_get_product( $wc_product_id );
			if ( ! $wc_product || ! $wc_product->is_type( 'variation' ) ) {
				continue;
			}

			$shopify_variant = isset( $variant_by_id[ $shopify_variant_id ] ) ? $variant_by_id[ $shopify_variant_id ] : null;
			if ( ! $shopify_variant || ! is_array( $shopify_variant ) ) {
				$messages[] = sprintf( __( 'Variation %s not found in Shopify product payload.', 'shopify-woo-bridge' ), $shopify_variant_id );
				continue;
			}

			$variant_image_id  = isset( $shopify_variant['image_id'] ) ? strval( $shopify_variant['image_id'] ) : '';
			$variant_image_src = ( '' !== $variant_image_id && isset( $image_map['image_by_id'][ $variant_image_id ] ) ) ? $image_map['image_by_id'][ $variant_image_id ] : '';

			$variant_hash_payload = array(
				'shopify_product_id' => (string) $shopify_product_id,
				'shopify_variant_id' => (string) $shopify_variant_id,
				'image_id'           => (string) $variant_image_id,
				'image_src'          => (string) $variant_image_src,
			);

			$new_hash  = md5( wp_json_encode( $variant_hash_payload ) );
			$last_hash = (string) get_post_meta( $wc_product_id, self::LAST_MEDIA_HASH_META, true );
			if ( '' !== $last_hash && hash_equals( $last_hash, $new_hash ) ) {
				continue;
			}

			if ( '' !== $variant_image_src ) {
				$attachment_id = $this->import_or_get_attachment_id( $variant_image_src, $wc_product_id );
				if ( is_wp_error( $attachment_id ) ) {
					$messages[] = sprintf( __( 'Variation %1$s image sync failed: %2$s', 'shopify-woo-bridge' ), $shopify_variant_id, $attachment_id->get_error_message() );
					continue;
				}

				$wc_product->set_image_id( absint( $attachment_id ) );
				$wc_product->save();
			}

			$current_variation_image_id = absint( $wc_product->get_image_id() );

			update_post_meta( $wc_product_id, self::LAST_MEDIA_HASH_META, $new_hash );
			update_post_meta( $wc_product_id, self::LAST_MEDIA_SYNCED_AT_META, gmdate( 'Y-m-d H:i:s' ) );
			update_post_meta( $wc_product_id, self::LAST_MEDIA_SIGNATURE_META, $this->build_variation_local_signature( $current_variation_image_id ) );
			$changed_any = true;
		}

		if ( ! empty( $messages ) ) {
			return array(
				'success' => false,
				'changed' => $changed_any,
				'message' => implode( ' ', $messages ),
			);
		}

		return array(
			'success' => true,
			'changed' => $changed_any,
			'message' => '',
		);
	}

	/**
	 * Resolve parent WooCommerce product ID from mapping group.
	 *
	 * @param array $group_mappings Mappings.
	 * @return int
	 */
	private function resolve_parent_product_id_from_group( $group_mappings ) {
		foreach ( $group_mappings as $mapping ) {
			$wc_sku = $this->get_mapping_value( $mapping, 'wc_sku' );
			if ( '' === $wc_sku ) {
				continue;
			}

			$product_id = $this->find_wc_product_id_by_sku( $wc_sku );
			if ( is_wp_error( $product_id ) ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variation' ) ) {
				return absint( $product->get_parent_id() );
			}

			return absint( $product->get_id() );
		}

		return 0;
	}

	/**
	 * Find WooCommerce product ID by unique SKU.
	 *
	 * @param string $wc_sku WooCommerce SKU.
	 * @return int|WP_Error
	 */
	private function find_wc_product_id_by_sku( $wc_sku ) {
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
			return new WP_Error( 'swb_wc_sku_not_found', __( 'WooCommerce SKU not found.', 'shopify-woo-bridge' ) );
		}

		if ( count( $product_ids ) > 1 ) {
			return new WP_Error( 'swb_duplicate_wc_sku', __( 'Multiple WooCommerce products share this SKU.', 'shopify-woo-bridge' ) );
		}

		return absint( $product_ids[0] );
	}

	/**
	 * Import Shopify image URL or reuse existing attachment.
	 *
	 * @param string $url Image URL.
	 * @param int    $parent_post_id Parent post ID.
	 * @return int|WP_Error
	 */
	private function import_or_get_attachment_id( $url, $parent_post_id ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return new WP_Error( 'swb_empty_image_url', __( 'Image URL is empty.', 'shopify-woo-bridge' ) );
		}

		$existing = $this->find_attachment_by_source_url( $url );
		if ( $existing > 0 ) {
			return $existing;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$attachment_id = media_sideload_image( $url, $parent_post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return new WP_Error( 'swb_attachment_import_failed', __( 'Unable to import image attachment.', 'shopify-woo-bridge' ) );
		}

		update_post_meta( $attachment_id, self::ATTACHMENT_SOURCE_META, $url );

		return $attachment_id;
	}

	/**
	 * Find existing attachment by stored Shopify source URL.
	 *
	 * @param string $url Source URL.
	 * @return int
	 */
	private function find_attachment_by_source_url( $url ) {
		global $wpdb;

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"
				SELECT post_id
				FROM {$wpdb->postmeta}
				WHERE meta_key = %s AND meta_value = %s
				LIMIT 1
				",
				self::ATTACHMENT_SOURCE_META,
				$url
			)
		);

		return absint( $attachment_id );
	}

	/**
	 * Build local signature for parent media assignment.
	 *
	 * @param int   $featured_attachment_id Featured attachment ID.
	 * @param array $gallery_attachment_ids Gallery attachment IDs.
	 * @return string
	 */
	private function build_parent_local_signature( $featured_attachment_id, $gallery_attachment_ids ) {
		$payload = array(
			'featured' => absint( $featured_attachment_id ),
			'gallery'  => array_map( 'absint', array_values( (array) $gallery_attachment_ids ) ),
		);

		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * Build local signature for variation image assignment.
	 *
	 * @param int $image_id Attachment ID.
	 * @return string
	 */
	private function build_variation_local_signature( $image_id ) {
		$payload = array(
			'image_id' => absint( $image_id ),
		);

		return md5( wp_json_encode( $payload ) );
	}

	/**
	 * Read mapping field for array or object row format.
	 *
	 * @param array|object $mapping Mapping row.
	 * @param string       $key Field name.
	 * @param mixed        $default Default value.
	 * @return mixed
	 */
	private function get_mapping_value( $mapping, $key, $default = '' ) {
		if ( is_array( $mapping ) && array_key_exists( $key, $mapping ) ) {
			return $mapping[ $key ];
		}

		if ( is_object( $mapping ) && isset( $mapping->{$key} ) ) {
			return $mapping->{$key};
		}

		return $default;
	}
}

