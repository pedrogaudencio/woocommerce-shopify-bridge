<?php
/**
 * WooCommerce Settings Page for Shopify Bridge.
 *
 * @package Shopify_WooCommerce_Bridge\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WC_Settings_Page', false ) ) {

	/**
	 * SWB_WC_Settings_Page Class.
	 */
	class SWB_WC_Settings_Page extends WC_Settings_Page {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id    = 'shopify_bridge';
			$this->label = __( 'Shopify Sync', 'shopify-woo-bridge' );

			parent::__construct();
		}

		/**
		 * Get sections.
		 *
		 * @return array
		 */
		public function get_sections() {
			return array(
				'' => __( 'General Sync Settings', 'shopify-woo-bridge' ),
			);
		}

		/**
		 * Output the settings.
		 */
		public function output() {
			$settings = $this->get_settings( '' );

			WC_Admin_Settings::output_fields( $settings );
		}

		/**
		 * Save settings.
		 */
		public function save() {
			$settings = $this->get_settings( '' );
			WC_Admin_Settings::save_fields( $settings );
		}

		/**
		 * Get settings array.
		 *
		 * @param string $current_section Current section name.
		 * @return array
		 */
		public function get_settings( $current_section = '' ) {
			$settings = array(
				array(
					'title' => __( 'Shopify to WooCommerce Sync', 'shopify-woo-bridge' ),
					'type'  => 'title',
					'desc'  => __( 'Configure the secure webhook connection for stock synchronization from Shopify.', 'shopify-woo-bridge' ),
					'id'    => 'swb_general_options',
				),
				array(
					'title'   => __( 'Global Kill Switch', 'shopify-woo-bridge' ),
					'desc'    => __( 'Enable to immediately stop processing incoming stock updates from Shopify. This is a default-deny safeguard.', 'shopify-woo-bridge' ),
					'id'      => 'swb_global_enable',
					'default' => 'no',
					'type'    => 'checkbox',
					'desc_tip' => true,
				),
				array(
					'title'             => __( 'Shopify Webhook Secret', 'shopify-woo-bridge' ),
					'type'              => 'password',
					'desc'              => __( 'Enter the HMAC-SHA256 secret key provided by Shopify when creating the webhook. Essential for security.', 'shopify-woo-bridge' ),
					'id'                => 'swb_webhook_secret',
					'default'           => '',
					'css'               => 'min-width: 300px;',
					'custom_attributes' => array(
						'autocomplete' => 'new-password',
					),
				),
				array(
					'title'   => __( 'Log Output', 'shopify-woo-bridge' ),
					'desc'    => __( 'Enable detailed logging to WooCommerce > Status > Logs for diagnostics and audits.', 'shopify-woo-bridge' ),
					'id'      => 'swb_enable_logging',
					'default' => 'no',
					'type'    => 'checkbox',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'swb_general_options',
				),
			);

			return apply_filters( 'swb_get_settings_' . $this->id, $settings, $current_section );
		}
	}
}
