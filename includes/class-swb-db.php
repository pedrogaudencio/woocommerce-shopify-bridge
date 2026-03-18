<?php
/**
 * Database Interaction Class for Mappings.
 *
 * @package Shopify_WooCommerce_Bridge\Database
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_DB Class.
 */
class SWB_DB {

	/**
	 * Create or update the custom tables.
	 */
	public static function create_tables() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'swb_mappings';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			shopify_product_id varchar(255) NOT NULL,
			shopify_variant_id varchar(255) DEFAULT NULL,
			shopify_item_id varchar(255) NOT NULL,
			wc_sku varchar(255) NOT NULL,
			is_enabled tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY shopify_item_id (shopify_item_id),
			KEY wc_sku (wc_sku)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert or update a mapping.
	 *
	 * @param array $data Mapping data (shopify_item_id, wc_sku, is_enabled).
	 * @return int|false The number of rows inserted/updated, or false on error.
	 */
	public static function insert_mapping( $data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$defaults = array(
			'shopify_product_id' => '',
			'shopify_variant_id' => null,
			'shopify_item_id'    => '',
			'wc_sku'             => '',
			'is_enabled'         => 1,
		);

		$parsed_args = wp_parse_args( $data, $defaults );

		if ( empty( $parsed_args['shopify_product_id'] ) || empty( $parsed_args['shopify_item_id'] ) || empty( $parsed_args['wc_sku'] ) ) {
			return false;
		}

		// Check if it exists.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE shopify_item_id = %s", $parsed_args['shopify_item_id'] ) );

		if ( $existing ) {
			return self::update_mapping( $existing, $parsed_args );
		}

		$format = array( '%s', '%s', '%s', '%s', '%d' );

		return $wpdb->insert( $table_name, $parsed_args, $format );
	}

	/**
	 * Update an existing mapping.
	 *
	 * @param int   $id   Mapping ID.
	 * @param array $data Mapping data.
	 * @return int|false Number of rows affected or false on error.
	 */
	public static function update_mapping( $id, $data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		// Determine formats based on provided data keys.
		$formats = array();
		if ( isset( $data['shopify_product_id'] ) ) {
			$formats[] = '%s';
		}
		if ( array_key_exists( 'shopify_variant_id', $data ) ) {
			$formats[] = '%s';
		}
		if ( isset( $data['shopify_item_id'] ) ) {
			$formats[] = '%s';
		}
		if ( isset( $data['wc_sku'] ) ) {
			$formats[] = '%s';
		}
		if ( isset( $data['is_enabled'] ) ) {
			$formats[] = '%d';
		}

		return $wpdb->update(
			$table_name,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Delete a mapping.
	 *
	 * @param int $id Mapping ID.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public static function delete_mapping( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		return $wpdb->delete(
			$table_name,
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Get a mapping by Shopify Item ID.
	 *
	 * @param string $shopify_item_id The Shopify Item ID.
	 * @return object|null Mapping row object or null if not found.
	 */
	public static function get_mapping_by_shopify_id( $shopify_item_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE shopify_item_id = %s LIMIT 1", $shopify_item_id ) );
	}

	/**
	 * Get a mapping by WooCommerce SKU.
	 *
	 * @param string $wc_sku The WooCommerce SKU.
	 * @return object|null Mapping row object or null if not found.
	 */
	public static function get_mapping_by_wc_sku( $wc_sku ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE wc_sku = %s LIMIT 1", $wc_sku ) );
	}

	/**
	 * Toggle mapping status.
	 *
	 * @param int $id Mapping ID.
	 * @return bool True on success, false on failure.
	 */
	public static function toggle_mapping( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$current = $wpdb->get_var( $wpdb->prepare( "SELECT is_enabled FROM $table_name WHERE id = %d", $id ) );
		
		if ( null === $current ) {
			return false;
		}

		$new_status = (int) $current === 1 ? 0 : 1;

		$result = $wpdb->update(
			$table_name,
			array( 'is_enabled' => $new_status ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}
	
	/**
	 * Get all mappings (for list table).
	 *
	 * @param int    $per_page Items per page.
	 * @param int    $page_number Current page.
	 * @param string $search Search query.
	 * @return array Mappings.
	 */
	public static function get_mappings( $per_page = 20, $page_number = 1, $search = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$sql = "SELECT * FROM {$table_name}";
		
		$args = array();

		if ( ! empty( $search ) ) {
			$sql .= ' WHERE shopify_item_id LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql .= ' ORDER BY id DESC';

		$sql .= ' LIMIT %d OFFSET %d';
		$args[] = $per_page;
		$args[] = ( $page_number - 1 ) * $per_page;

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, $args );
		}

		$result = $wpdb->get_results( $sql, 'ARRAY_A' );
		return $result ? $result : array();
	}

	/**
	 * Get mapping count.
	 *
	 * @param string $search Search query.
	 * @return int Total count.
	 */
	public static function get_mappings_count( $search = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$sql = "SELECT COUNT(*) FROM {$table_name}";
		
		$args = array();

		if ( ! empty( $search ) ) {
			$sql .= ' WHERE shopify_item_id LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
			$sql = $wpdb->prepare( $sql, $args );
		}

		return (int) $wpdb->get_var( $sql );
	}
}
