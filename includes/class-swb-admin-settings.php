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
	 * SWB_Admin_Settings Class.
	 */
	class SWB_Admin_Settings extends WC_Settings_Page {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id    = 'shopify_bridge';
			$this->label = __( 'Shopify Sync', 'shopify-woo-bridge' );

			add_action( 'woocommerce_admin_field_swb_export_action', array( $this, 'render_export_action_field' ) );
			add_action( 'woocommerce_admin_field_swb_test_connection_action', array( $this, 'render_test_connection_action_field' ) );

			parent::__construct();
		}

		/**
		 * Get sections.
		 *
		 * @return array
		 */
		public function get_sections() {
			return array(
				''            => __( 'General', 'shopify-woo-bridge' ),
				'credentials' => __( 'Credentials', 'shopify-woo-bridge' ),
				'export'      => __( 'Export', 'shopify-woo-bridge' ),
			);
		}

		/**
		 * Output the settings.
		 */
		public function output() {
			$current_section = $this->get_active_section();
			$settings        = $this->get_settings( $current_section );

			WC_Admin_Settings::output_fields( $settings );
		}

		/**
		 * Output settings fields for an explicit section.
		 *
		 * @param string $section Settings section.
		 */
		public function output_for_section( $section ) {
			global $current_section;
			$current_section = (string) $section;

			$this->output();
		}

		/**
		 * Save settings.
		 */
		public function save() {
			$current_section = $this->get_active_section();

			if ( 'credentials' === $current_section ) {
				$this->save_credentials_section();
				return;
			}

			$settings = $this->get_settings( $current_section );
			WC_Admin_Settings::save_fields( $settings );
		}

		/**
		 * Save settings for an explicit section.
		 *
		 * @param string $section Settings section.
		 */
		public function save_for_section( $section ) {
			global $current_section;
			$current_section = (string) $section;

			$this->save();
		}

		/**
		 * Resolve active settings section in a way compatible with WC settings internals.
		 *
		 * @return string
		 */
		private function get_active_section() {
			global $current_section;
			return is_string( $current_section ) ? $current_section : '';
		}

		/**
		 * Save credentials section.
		 */
		private function save_credentials_section() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			$raw_domain = isset( $_POST['swb_shopify_store_domain'] ) ? wp_unslash( $_POST['swb_shopify_store_domain'] ) : '';
			$raw_id     = isset( $_POST['swb_shopify_client_id'] ) ? wp_unslash( $_POST['swb_shopify_client_id'] ) : '';
			$raw_secret = isset( $_POST['swb_shopify_client_secret'] ) ? wp_unslash( $_POST['swb_shopify_client_secret'] ) : '';

			$domain = $this->sanitize_store_domain( $raw_domain );
			if ( '' === $domain && '' !== trim( (string) $raw_domain ) ) {
				WC_Admin_Settings::add_error( __( 'Store domain must be a valid *.myshopify.com domain.', 'shopify-woo-bridge' ) );
				return;
			}

			$client_id = sanitize_text_field( $raw_id );
			$secret    = trim( (string) $raw_secret );

			$old_domain = trim( (string) get_option( 'swb_shopify_store_domain', '' ) );
			$old_id     = trim( (string) get_option( 'swb_shopify_client_id', '' ) );

			update_option( 'swb_shopify_store_domain', $domain );
			update_option( 'swb_shopify_client_id', $client_id );

			$secret_updated = false;
			// Keep the existing client secret if an empty value was submitted.
			if ( '' !== $secret ) {
				update_option( 'swb_shopify_client_secret', $secret );
				$secret_updated = true;
			}

			if ( $old_domain !== $domain || $old_id !== $client_id || $secret_updated ) {
				delete_option( 'swb_shopify_access_token' );
				delete_option( 'swb_shopify_access_token_created_at' );
			}

			WC_Admin_Settings::add_message( __( 'Credentials saved. Use "Test connection" to validate access.', 'shopify-woo-bridge' ) );
		}

		/**
		 * Render the export action field.
		 *
		 * @param array $field Field definition.
		 */
		public function render_export_action_field( $field ) {
			$action_url = add_query_arg(
				array(
					'action' => 'swb_export_shopify_csv',
				),
				admin_url( 'admin-post.php' )
			);
			$action_url = wp_nonce_url( $action_url, 'swb_export_shopify_csv', 'swb_export_nonce' );
			?>
			<tr>
				<th scope="row" class="titledesc">
					<?php echo esc_html( isset( $field['title'] ) ? $field['title'] : __( 'Export', 'shopify-woo-bridge' ) ); ?>
				</th>
				<td class="forminp">
					<a href="<?php echo esc_url( $action_url ); ?>" class="button button-primary"><?php esc_html_e( 'Retrieve products and inventory, then export CSV', 'shopify-woo-bridge' ); ?></a>
					<?php if ( ! empty( $field['desc'] ) ) : ?>
						<p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render the test connection action field.
		 *
		 * @param array $field Field definition.
		 */
		public function render_test_connection_action_field( $field ) {
			$action_url = add_query_arg(
				array(
					'action' => 'swb_test_shopify_connection',
				),
				admin_url( 'admin-post.php' )
			);
			$action_url = wp_nonce_url( $action_url, 'swb_test_shopify_connection', 'swb_test_connection_nonce' );
			?>
			<tr>
				<th scope="row" class="titledesc">
					<?php echo esc_html( isset( $field['title'] ) ? $field['title'] : __( 'Test connection', 'shopify-woo-bridge' ) ); ?>
				</th>
				<td class="forminp">
					<a href="<?php echo esc_url( $action_url ); ?>" class="button"><?php esc_html_e( 'Test Shopify connection', 'shopify-woo-bridge' ); ?></a>
					<?php if ( ! empty( $field['desc'] ) ) : ?>
						<p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Normalize store domain to a strict myshopify domain.
		 *
		 * @param string $domain Raw domain value.
		 * @return string
		 */
		private function sanitize_store_domain( $domain ) {
			$domain = trim( strtolower( (string) $domain ) );
			$domain = preg_replace( '#^https?://#', '', $domain );
			$domain = preg_replace( '#/.*$#', '', $domain );

			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain ) ) {
				return '';
			}

			return $domain;
		}

		/**
		 * Get settings array.
		 *
		 * @param string $current_section Current section name.
		 * @return array
		 */
		public function get_settings( $current_section = '' ) {
			if ( 'credentials' === $current_section ) {
				$masked_secret_note = '';
				if ( '' !== trim( (string) get_option( 'swb_shopify_client_secret', '' ) ) ) {
					$masked_secret_note = __( 'A client secret is already stored. Leave blank to keep the existing value.', 'shopify-woo-bridge' );
				}

				$settings = array(
					array(
						'title' => __( 'Shopify App Credentials', 'shopify-woo-bridge' ),
						'type'  => 'title',
						'desc'  => __( 'Credentials used to generate a temporary Admin API token via Shopify client credentials flow. This plugin only performs GET requests.', 'shopify-woo-bridge' ),
						'id'    => 'swb_credentials_options',
					),
					array(
						'title'             => __( 'Store domain', 'shopify-woo-bridge' ),
						'type'              => 'text',
						'id'                => 'swb_shopify_store_domain',
						'default'           => '',
						'css'               => 'min-width: 300px;',
						'desc'              => __( 'Example: your-store.myshopify.com', 'shopify-woo-bridge' ),
						'custom_attributes' => array(
							'autocomplete' => 'off',
						),
					),
					array(
						'title'             => __( 'Client ID', 'shopify-woo-bridge' ),
						'type'              => 'text',
						'id'                => 'swb_shopify_client_id',
						'default'           => '',
						'css'               => 'min-width: 300px;',
						'desc'              => __( 'From Shopify Dev Dashboard app credentials.', 'shopify-woo-bridge' ),
						'custom_attributes' => array(
							'autocomplete' => 'off',
						),
					),
					array(
						'title'             => __( 'Client secret', 'shopify-woo-bridge' ),
						'type'              => 'password',
						'id'                => 'swb_shopify_client_secret',
						'default'           => '',
						'css'               => 'min-width: 300px;',
						'desc'              => __( 'From Shopify Dev Dashboard app credentials. Used to generate an Admin API token.', 'shopify-woo-bridge' ) . ( $masked_secret_note ? ' ' . $masked_secret_note : '' ),
						'custom_attributes' => array(
							'autocomplete' => 'new-password',
						),
					),
					array(
						'title' => __( 'Connection check', 'shopify-woo-bridge' ),
						'type'  => 'swb_test_connection_action',
						'id'    => 'swb_test_connection_action',
						'desc'  => __( 'Runs a read-only GET request to Shopify to verify the saved credentials.', 'shopify-woo-bridge' ),
					),
					array(
						'type' => 'sectionend',
						'id'   => 'swb_credentials_options',
					),
				);

				return apply_filters( 'swb_get_settings_' . $this->id, $settings, $current_section );
			}

			if ( 'export' === $current_section ) {
				$settings = array(
					array(
						'title' => __( 'Shopify Product and Inventory Export', 'shopify-woo-bridge' ),
						'type'  => 'title',
						'desc'  => __( 'Retrieves products and inventory from Shopify through read-only endpoints and downloads a CSV file.', 'shopify-woo-bridge' ),
						'id'    => 'swb_export_options',
					),
					array(
						'title' => __( 'Export action', 'shopify-woo-bridge' ),
						'type'  => 'swb_export_action',
						'id'    => 'swb_export_action',
						'desc'  => __( 'No data is written back to Shopify. This operation only fetches data via GET.', 'shopify-woo-bridge' ),
					),
					array(
						'type' => 'sectionend',
						'id'   => 'swb_export_options',
					),
				);

				return apply_filters( 'swb_get_settings_' . $this->id, $settings, $current_section );
			}

			$settings = array(
				array(
					'title' => __( 'Shopify to WooCommerce Sync', 'shopify-woo-bridge' ),
					'type'  => 'title',
					'desc'  => __( 'Configure secure, one-way webhook stock synchronization from Shopify to WooCommerce.', 'shopify-woo-bridge' ),
					'id'    => 'swb_general_options',
				),
				array(
					'title'    => __( 'Global Kill Switch', 'shopify-woo-bridge' ),
					'desc'     => __( 'Enable to immediately stop processing incoming stock updates from Shopify. This is a default-deny safeguard.', 'shopify-woo-bridge' ),
					'id'       => 'swb_global_enable',
					'default'  => 'no',
					'type'     => 'checkbox',
					'desc_tip' => true,
				),
				array(
					'title'             => __( 'Shopify Webhook Secret', 'shopify-woo-bridge' ),
					'type'              => 'password',
					'desc'              => __( 'Enter the HMAC-SHA256 secret key provided by Shopify when creating the webhook.', 'shopify-woo-bridge' ),
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
