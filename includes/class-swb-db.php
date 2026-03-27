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

		// Stock history table for tracking updates.
		$stock_history_table = $wpdb->prefix . 'swb_stock_history';
		$stock_sql           = "CREATE TABLE $stock_history_table (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			shopify_item_id varchar(255) NOT NULL,
			wc_sku varchar(255) NOT NULL,
			wc_product_id bigint(20) DEFAULT NULL,
			old_stock int(11) DEFAULT NULL,
			new_stock int(11) NOT NULL,
			source varchar(50) NOT NULL DEFAULT 'webhook',
			status varchar(50) NOT NULL DEFAULT 'success',
			error_message longtext,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY shopify_item_id (shopify_item_id),
			KEY wc_sku (wc_sku),
			KEY wc_product_id (wc_product_id),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $stock_sql );
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
	 * Get a mapping by ID.
	 *
	 * @param int $id The mapping ID.
	 * @return object|null Mapping row object or null if not found.
	 */
	public static function get_mapping( $id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d LIMIT 1", $id ) );
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
	 * Get mappings by Shopify Product ID.
	 *
	 * @param string $shopify_product_id Shopify Product ID.
	 * @return array
	 */
	public static function get_mappings_by_shopify_product_id( $shopify_product_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table_name WHERE shopify_product_id = %s ORDER BY id ASC", $shopify_product_id )
		);
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
	public static function get_mappings( $per_page = 20, $page_number = 1, $search = '', $product_type = 'all' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$sql         = "SELECT * FROM {$table_name} AS m";
		$args        = array();
		$where_parts = array();

		if ( ! empty( $search ) ) {
			$where_parts[] = 'm.shopify_item_id LIKE %s';
			$args[]        = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$product_type_where = self::get_product_type_where_clause( $product_type, 'm.wc_sku' );
		if ( ! empty( $product_type_where ) ) {
			$where_parts[] = $product_type_where;
			if ( in_array( $product_type, array( 'variable', 'grouped', 'external' ), true ) ) {
				$args[] = $product_type;
			}
		}

		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		$sql .= ' ORDER BY m.id DESC';

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
	public static function get_mappings_count( $search = '', $product_type = 'all' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_mappings';

		$sql         = "SELECT COUNT(*) FROM {$table_name} AS m";
		$args        = array();
		$where_parts = array();

		if ( ! empty( $search ) ) {
			$where_parts[] = 'm.shopify_item_id LIKE %s';
			$args[]        = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$product_type_where = self::get_product_type_where_clause( $product_type, 'm.wc_sku' );
		if ( ! empty( $product_type_where ) ) {
			$where_parts[] = $product_type_where;
			if ( in_array( $product_type, array( 'variable', 'grouped', 'external' ), true ) ) {
				$args[] = $product_type;
			}
		}

		if ( ! empty( $where_parts ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where_parts );
		}

		if ( ! empty( $args ) ) {
			$sql = $wpdb->prepare( $sql, $args );
		}

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Build WHERE clause for WooCommerce product type filtering by mapped SKU.
	 *
	 * @param string $product_type Product type filter.
	 * @param string $sku_reference SQL reference to SKU column.
	 * @return string
	 */
	private static function get_product_type_where_clause( $product_type, $sku_reference ) {
		global $wpdb;

		$allowed = array( 'all', 'simple', 'variable', 'variation', 'grouped', 'external' );
		if ( ! in_array( $product_type, $allowed, true ) || 'all' === $product_type ) {
			return '';
		}

		if ( 'variation' === $product_type ) {
			return "EXISTS (
				SELECT 1
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS l ON p.ID = l.product_id
				WHERE p.post_type = 'product_variation'
				AND p.post_status != 'trash'
				AND l.sku = {$sku_reference}
			)";
		}

		if ( 'simple' === $product_type ) {
			return "EXISTS (
				SELECT 1
				FROM {$wpdb->posts} AS p
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS l ON p.ID = l.product_id
				LEFT JOIN {$wpdb->term_relationships} AS tr ON tr.object_id = p.ID
				LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
				LEFT JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id
				WHERE p.post_type = 'product'
				AND p.post_status != 'trash'
				AND l.sku = {$sku_reference}
				AND (t.slug = 'simple' OR t.slug IS NULL)
			)";
		}

		return "EXISTS (
			SELECT 1
			FROM {$wpdb->posts} AS p
			INNER JOIN {$wpdb->wc_product_meta_lookup} AS l ON p.ID = l.product_id
			INNER JOIN {$wpdb->term_relationships} AS tr ON tr.object_id = p.ID
			INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id
			WHERE p.post_type = 'product'
			AND p.post_status != 'trash'
			AND l.sku = {$sku_reference}
			AND t.slug = %s
		)";
	}

	/**
	 * Log a stock update to history.
	 *
	 * @param array $data Stock history data (shopify_item_id, wc_sku, wc_product_id, old_stock, new_stock, source, status, error_message).
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public static function log_stock_update( $data ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_stock_history';

		$defaults = array(
			'shopify_item_id' => '',
			'wc_sku'          => '',
			'wc_product_id'   => null,
			'old_stock'       => null,
			'new_stock'       => 0,
			'source'          => 'webhook',
			'status'          => 'success',
			'error_message'   => null,
		);

		$parsed_args = wp_parse_args( $data, $defaults );

		if ( empty( $parsed_args['shopify_item_id'] ) || empty( $parsed_args['wc_sku'] ) ) {
			return false;
		}

		$format = array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' );

		return $wpdb->insert( $table_name, $parsed_args, $format );
	}

	/**
	 * Get stock history for a specific inventory item.
	 *
	 * @param string $shopify_item_id The Shopify inventory item ID.
	 * @param int    $limit Number of records to retrieve.
	 * @return array Stock history records.
	 */
	public static function get_stock_history( $shopify_item_id, $limit = 50 ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_stock_history';
		$limit      = max( 1, min( 200, absint( $limit ) ) );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE shopify_item_id = %s ORDER BY created_at DESC LIMIT %d",
				$shopify_item_id,
				$limit
			),
			'ARRAY_A'
		);

		return $results ? $results : array();
	}

	/**
	 * Get last successful stock sync timestamps for Shopify inventory item IDs.
	 *
	 * @param array $shopify_item_ids Shopify inventory item IDs.
	 * @return array<string,string> Timestamps keyed by Shopify item ID.
	 */
	public static function get_last_successful_stock_sync_by_item_ids( $shopify_item_ids ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_stock_history';

		$shopify_item_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', (array) $shopify_item_ids )
				)
			)
		);

		if ( empty( $shopify_item_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $shopify_item_ids ), '%s' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
				SELECT shopify_item_id, MAX(created_at) AS last_synced_at
				FROM {$table_name}
				WHERE status = 'success'
				AND shopify_item_id IN ({$placeholders})
				GROUP BY shopify_item_id
				",
				$shopify_item_ids
			),
			'ARRAY_A'
		);

		if ( empty( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			$item_id = isset( $row['shopify_item_id'] ) ? (string) $row['shopify_item_id'] : '';
			if ( '' === $item_id ) {
				continue;
			}

			$results[ $item_id ] = isset( $row['last_synced_at'] ) ? (string) $row['last_synced_at'] : '';
		}

		return $results;
	}

	/**
	 * Get current stock for a mapped product.
	 *
	 * @param string $shopify_item_id The Shopify inventory item ID.
	 * @return int|null Current stock quantity or null if not found.
	 */
	public static function get_current_stock( $shopify_item_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'swb_stock_history';

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT new_stock FROM $table_name WHERE shopify_item_id = %s ORDER BY created_at DESC LIMIT 1",
				$shopify_item_id
			)
		);

		return $result !== null ? intval( $result ) : null;
	}
}
