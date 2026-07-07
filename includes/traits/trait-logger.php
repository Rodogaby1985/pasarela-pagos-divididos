<?php
/**
 * Logger trait.
 * Provides structured logging to a custom WordPress log file and to the
 * WooCommerce logger when available.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

trait SPG_Logger {

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 */
	protected function log_info( $message, array $context = array() ) {
		$this->write_log( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 */
	protected function log_warning( $message, array $context = array() ) {
		$this->write_log( 'warning', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 */
	protected function log_error( $message, array $context = array() ) {
		$this->write_log( 'error', $message, $context );
	}

	/**
	 * Log a debug message (only written in WP_DEBUG mode).
	 *
	 * @param string $message Message to log.
	 * @param array  $context Optional context data.
	 */
	protected function log_debug( $message, array $context = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->write_log( 'debug', $message, $context );
		}
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Additional data.
	 */
	private function write_log( $level, $message, array $context = array() ) {
		$source = 'split-payment-gateway';

		// Use WooCommerce logger when available.
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->$level(
				$this->format_message( $message, $context ),
				array( 'source' => $source )
			);
			return;
		}

		// Fallback: write to PHP error log.
		$formatted = sprintf(
			'[SPG][%s][%s] %s %s',
			strtoupper( $level ),
			gmdate( 'Y-m-d H:i:s' ),
			$message,
			empty( $context ) ? '' : wp_json_encode( $context )
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $formatted );
	}

	/**
	 * Format a message with its context.
	 *
	 * @param string $message Message.
	 * @param array  $context Context data.
	 * @return string
	 */
	private function format_message( $message, array $context ) {
		if ( empty( $context ) ) {
			return $message;
		}
		return $message . ' | Context: ' . wp_json_encode( $context );
	}
}
