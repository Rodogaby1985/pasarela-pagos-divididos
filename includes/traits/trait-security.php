<?php
/**
 * Security trait.
 * Provides signature verification, input sanitisation and credential
 * encryption helpers used across the plugin.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

trait SPG_Security {

	/**
	 * Verify an HMAC-SHA256 webhook signature.
	 *
	 * @param string $payload   Raw request body.
	 * @param string $signature Signature provided by the gateway (hex-encoded).
	 * @param string $secret    Shared secret for the gateway.
	 * @return bool
	 */
	protected function verify_webhook_signature( $payload, $signature, $secret ) {
		if ( empty( $signature ) || empty( $secret ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $payload, $secret );
		return hash_equals( $expected, ltrim( $signature, 'sha256=' ) );
	}

	/**
	 * Encrypt a string using AES-256-CBC.
	 * Requires the OpenSSL PHP extension. An exception is thrown when it is
	 * unavailable so callers are not given a false sense of security.
	 *
	 * @param string $plaintext String to encrypt.
	 * @return string Encrypted string (IV prepended, base64-encoded).
	 * @throws RuntimeException When OpenSSL is not available.
	 */
	protected function encrypt( $plaintext ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new RuntimeException(
				'OpenSSL PHP extension is required for credential encryption. ' .
				'Please enable ext-openssl on your server.'
			);
		}

		$key       = $this->get_encryption_key();
		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );

		return base64_encode( $iv . $encrypted ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a string encrypted by ::encrypt().
	 *
	 * @param string $ciphertext Base64-encoded ciphertext (IV prepended).
	 * @return string|false Decrypted string or false on failure.
	 * @throws RuntimeException When OpenSSL is not available.
	 */
	protected function decrypt( $ciphertext ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			throw new RuntimeException(
				'OpenSSL PHP extension is required for credential decryption. ' .
				'Please enable ext-openssl on your server.'
			);
		}

		$key       = $this->get_encryption_key();
		$raw       = base64_decode( $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv        = substr( $raw, 0, 16 );
		$encrypted = substr( $raw, 16 );

		return openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );
	}

	/**
	 * Retrieve the 32-byte encryption key.
	 * Priority: SPG_ENCRYPTION_KEY constant → WordPress AUTH_KEY salted/hashed.
	 *
	 * @return string 32-byte key.
	 */
	private function get_encryption_key() {
		if ( defined( 'SPG_ENCRYPTION_KEY' ) ) {
			return substr( hash( 'sha256', SPG_ENCRYPTION_KEY, true ), 0, 32 );
		}
		// Derive from WordPress secret keys.
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'spg-fallback-key';
		return substr( hash( 'sha256', $salt . 'spg', true ), 0, 32 );
	}

	/**
	 * Sanitise a gateway name: lowercase alphanumeric + hyphens only.
	 *
	 * @param string $name Raw gateway name.
	 * @return string
	 */
	protected function sanitize_gateway_name( $name ) {
		return preg_replace( '/[^a-z0-9\-]/', '', strtolower( $name ) );
	}

	/**
	 * Generate a cryptographically random token.
	 *
	 * @param int $length Byte length before hex-encoding (default 32 → 64-char hex).
	 * @return string Hex string.
	 */
	protected function generate_token( $length = 32 ) {
		return bin2hex( random_bytes( $length ) );
	}

	/**
	 * Validate a WordPress nonce.
	 *
	 * @param string $nonce  Nonce to verify.
	 * @param string $action Nonce action.
	 * @return bool
	 */
	protected function verify_nonce( $nonce, $action ) {
		return (bool) wp_verify_nonce( $nonce, $action );
	}
}
