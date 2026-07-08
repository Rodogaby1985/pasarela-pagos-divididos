<?php
/**
 * Gateway Credentials Validator.
 *
 * Validates gateway API credentials in real-time and checks the webhook status.
 * Currently supports: MercadoPago.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment

/**
 * Validates gateway credentials against provider APIs.
 */
class SPG_Gateway_Credentials_Validator {

	use SPG_Logger;

	// ── MercadoPago ─────────────────────────────────────────────────────────────

	/**
	 * Validate MercadoPago credentials by calling the /users/me endpoint.
	 *
	 * @param string $access_token MercadoPago Access Token.
	 * @param string $user_id      Expected User ID (optional – pass empty string to skip check).
	 * @return array {
	 *     @type bool   $valid        Whether the credentials are valid.
	 *     @type string $user_id      User ID returned by the API.
	 *     @type string $environment  'sandbox' or 'production'.
	 *     @type string $country_code ISO-3166-1 alpha-2 country code (e.g. 'AR').
	 *     @type string $message      Human-readable result message.
	 * }
	 */
	public function validate_mercadopago( $access_token, $user_id = '' ) {
		if ( empty( $access_token ) ) {
			return array(
				'valid'        => false,
				'user_id'      => '',
				'environment'  => '',
				'country_code' => '',
				'message'      => __( 'Access Token is required.', 'split-payment-gateway' ),
			);
		}

		$response = wp_remote_get(
			'https://api.mercadopago.com/users/me',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . sanitize_text_field( $access_token ) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'        => false,
				'user_id'      => '',
				'environment'  => '',
				'country_code' => '',
				'message'      => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$msg = $body['message'] ?? sprintf( __( 'API returned HTTP %d.', 'split-payment-gateway' ), $code );
			return array(
				'valid'        => false,
				'user_id'      => '',
				'environment'  => '',
				'country_code' => '',
				'message'      => $msg,
			);
		}

		$api_user_id     = (string) ( $body['id'] ?? '' );
		$api_country     = strtoupper( $body['site_id'] ?? '' );
		$api_environment = ( false !== strpos( $access_token, 'TEST-' ) ) ? 'sandbox' : 'production';

		// Validate that the provided User ID matches (if supplied).
		if ( ! empty( $user_id ) && $api_user_id !== (string) $user_id ) {
			return array(
				'valid'        => false,
				'user_id'      => $api_user_id,
				'environment'  => $api_environment,
				'country_code' => $api_country,
				'message'      => sprintf(
					/* translators: 1: expected user ID, 2: actual user ID */
					__( 'User ID mismatch: expected %1$s, got %2$s.', 'split-payment-gateway' ),
					esc_html( $user_id ),
					esc_html( $api_user_id )
				),
			);
		}

		return array(
			'valid'        => true,
			'user_id'      => $api_user_id,
			'environment'  => $api_environment,
			'country_code' => $api_country,
			'message'      => sprintf(
				/* translators: 1: user ID, 2: environment */
				__( 'Credentials valid. User ID: %1$s (%2$s).', 'split-payment-gateway' ),
				esc_html( $api_user_id ),
				esc_html( $api_environment )
			),
		);
	}

	/**
	 * Verify that a MercadoPago webhook is registered and active for this site.
	 *
	 * @param string $access_token MercadoPago Access Token.
	 * @return array {
	 *     @type bool   $active     Whether the webhook is registered.
	 *     @type string $webhook_id Webhook ID if found.
	 *     @type string $message    Human-readable status message.
	 * }
	 */
	public function verify_mercadopago_webhook( $access_token ) {
		try {
			$adapter = new SPG_MercadoPago_Adapter( array( 'access_token' => $access_token ) );
			return $adapter->verify_webhook_registration();
		} catch ( Exception $e ) {
			return array(
				'active'     => false,
				'webhook_id' => '',
				'message'    => $e->getMessage(),
			);
		}
	}

	/**
	 * Create a MercadoPago webhook for this site.
	 *
	 * @param string $access_token MercadoPago Access Token.
	 * @return array {
	 *     @type bool   $success    Whether the operation succeeded.
	 *     @type string $webhook_id Webhook ID (new or existing).
	 *     @type string $message    Human-readable status message.
	 * }
	 */
	public function create_mercadopago_webhook( $access_token ) {
		try {
			$adapter = new SPG_MercadoPago_Adapter( array( 'access_token' => $access_token ) );
			return $adapter->create_webhook();
		} catch ( Exception $e ) {
			return array(
				'success'    => false,
				'webhook_id' => '',
				'message'    => $e->getMessage(),
			);
		}
	}
}
