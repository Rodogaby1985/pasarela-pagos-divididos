<?php
/**
 * PHPUnit bootstrap for Split Payment Gateway tests.
 * Loads traits and WordPress stubs so the unit tests can run
 * without a real WordPress installation.
 *
 * @package SplitPaymentGateway
 */

$plugin_dir = dirname( __DIR__ );

// ── WordPress global stubs ─────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_dir . '/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $str ): string {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string { return $url; }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ): string {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}

if ( ! function_exists( 'sprintf' ) ) {
	// Already a built-in – listed for documentation purposes only.
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $id ) { return null; }
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string { return 'https://example.com/wp-json/' . ltrim( $path, '/' ); }
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}
	}
}

// ── Plugin traits ──────────────────────────────────────────────────────────
require_once $plugin_dir . '/includes/traits/trait-logger.php';
require_once $plugin_dir . '/includes/traits/trait-security.php';
require_once $plugin_dir . '/includes/adapters/class-spg-base-adapter.php';
require_once $plugin_dir . '/includes/class-gateway-adapter-factory-interface.php';
