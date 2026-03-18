<?php
/**
 * Logger Utility Class.
 *
 * @package Shopify_WooCommerce_Bridge\Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SWB_Logger Class.
 */
class SWB_Logger {

	/**
	 * Log source identifier.
	 *
	 * @var string
	 */
	const SOURCE = 'shopify-woo-bridge';

	/**
	 * Check if logging is enabled in settings.
	 *
	 * @return bool
	 */
	public static function is_logging_enabled() {
		return 'yes' === get_option( 'swb_enable_logging', 'no' );
	}

	/**
	 * Add an info log entry.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * Add an error log entry.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}

	/**
	 * Add a warning log entry.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function warning( $message, $context = array() ) {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Write to the WooCommerce logger.
	 *
	 * @param string $level   Log level (e.g., 'info', 'error', 'warning').
	 * @param string $message Log message.
	 * @param array  $context Context data.
	 */
	private static function log( $level, $message, $context = array() ) {
		if ( ! self::is_logging_enabled() ) {
			return;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$context['source'] = self::SOURCE;
			
			// Append context to message if present for easier reading in simple text log viewers.
			if ( ! empty( $context ) ) {
				$message .= ' | Context: ' . wp_json_encode( $context );
			}

			$logger->log( $level, $message, $context );
		}
	}
}
