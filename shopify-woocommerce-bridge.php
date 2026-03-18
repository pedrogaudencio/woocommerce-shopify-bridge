<?php
/**
 * Plugin Name: Shopify WooCommerce Bridge
 * Plugin URI:  https://github.com/pedrogaudencio/woocommerce-shopify-sync
 * Description: Secure, one-way stock synchronization from Shopify to WooCommerce for explicitly mapped products. Phase 1 limits scope to absolute stock updates via webhooks.
 * Version:     1.0.0
 * Author:      Pedro Gaudencio
 * Author URI:  https://github.com/pedrogaudencio
 * License:     GPL-2.0+
 * Text Domain: shopify-woo-bridge
 * Domain Path: /languages
 *
 * @package Shopify_WooCommerce_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Main Shopify_WooCommerce_Bridge Class.
 */
class Shopify_WooCommerce_Bridge {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Single instance of the class.
	 *
	 * @var Shopify_WooCommerce_Bridge|null
	 */
	protected static $instance = null;

	/**
	 * Main Shopify_WooCommerce_Bridge Instance.
	 *
	 * Ensures only one instance of Shopify_WooCommerce_Bridge is loaded or can be loaded.
	 *
	 * @return Shopify_WooCommerce_Bridge - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Define plugin constants.
	 */
	private function define_constants() {
		define( 'SWB_PLUGIN_FILE', __FILE__ );
		define( 'SWB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		define( 'SWB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		define( 'SWB_VERSION', self::VERSION );
	}

	/**
	 * Include required core files.
	 */
	private function includes() {
		// Database class.
		require_once SWB_PLUGIN_DIR . 'includes/class-swb-db.php';

		// Logger class.
		require_once SWB_PLUGIN_DIR . 'includes/class-swb-logger.php';

		// Admin classes.
		if ( is_admin() ) {
			require_once SWB_PLUGIN_DIR . 'includes/admin/class-swb-admin-mappings.php';
		}

		// REST API.
		require_once SWB_PLUGIN_DIR . 'includes/class-swb-rest-controller.php';
	}

	/**
	 * Hook into actions and filters.
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ) );

		register_activation_hook( __FILE__, array( 'Shopify_WooCommerce_Bridge', 'activate' ) );
		register_deactivation_hook( __FILE__, array( 'Shopify_WooCommerce_Bridge', 'deactivate' ) );

		// Hook into WC settings.
		if ( is_admin() ) {
			add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
		}

		// Register REST API.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_rest_routes() {
		$controller = new SWB_REST_Controller();
		$controller->register_routes();
	}

	/**
	 * Add settings page.
	 *
	 * @param array $settings WC settings pages.
	 * @return array
	 */
	public function add_settings_page( $settings ) {
		if ( class_exists( 'SWB_Admin_Settings' ) ) {
			$settings[] = new SWB_Admin_Settings();
		}
		return $settings;
	}

	/**
	 * Executed when plugins are loaded.
	 */
	public function on_plugins_loaded() {
		// Load text domain for translations.
		load_plugin_textdomain( 'shopify-woo-bridge', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return; // Don't proceed if WC is missing.
		}

		if ( is_admin() ) {
			// Ensure WooCommerce's base settings class is available before loading our settings page class.
			if ( ! class_exists( 'WC_Settings_Page' ) && defined( 'WC_ABSPATH' ) ) {
				$wc_settings_page_file = WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
				if ( file_exists( $wc_settings_page_file ) ) {
					require_once $wc_settings_page_file;
				}
			}

			if ( ! class_exists( 'WC_Settings_Page' ) ) {
				SWB_Logger::warning( 'Shopify WooCommerce Bridge settings page not loaded: WC_Settings_Page class is unavailable.' );
				add_action( 'admin_notices', array( $this, 'woocommerce_settings_class_missing_notice' ) );
				return;
			}

			require_once SWB_PLUGIN_DIR . 'includes/class-swb-admin-settings.php';

			if ( class_exists( 'SWB_Admin_Mappings' ) ) {
				new SWB_Admin_Mappings();
			}
		}
	}

	/**
	 * Admin notice if WooCommerce is missing.
	 */
	public function woocommerce_missing_notice() {
		?>
		<div class="error">
			<p><?php esc_html_e( 'Shopify WooCommerce Bridge requires WooCommerce to be installed and active.', 'shopify-woo-bridge' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Admin notice if WooCommerce settings base class is unavailable.
	 */
	public function woocommerce_settings_class_missing_notice() {
		?>
		<div class="error">
			<p><?php esc_html_e( 'Shopify WooCommerce Bridge could not load its settings page because WooCommerce settings classes are unavailable.', 'shopify-woo-bridge' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Plugin activation hook.
	 */
	public static function activate() {
		// e.g., Set default options if they don't exist.
		if ( false === get_option( 'swb_global_enable' ) ) {
			add_option( 'swb_global_enable', 'no' ); // Default deny/disabled
		}

		// Create database tables.
		require_once SWB_PLUGIN_DIR . 'includes/class-swb-db.php';
		SWB_DB::create_tables();
	}

	/**
	 * Plugin deactivation hook.
	 */
	public static function deactivate() {
		// Cleanup if necessary on deactivation (usually leave data intact).
	}
}

/**
 * Returns the main instance of Shopify_WooCommerce_Bridge to prevent the need to use globals.
 *
 * @return Shopify_WooCommerce_Bridge
 */
function SWB() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return Shopify_WooCommerce_Bridge::instance();
}

// Global for backwards compatibility.
$GLOBALS['shopify_woo_bridge'] = SWB();
