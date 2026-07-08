<?php
/**
 * Stripe gateway adapter.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.VariableComment.Missing

/**
 * Stripe payment gateway adapter.
 */
class SPG_Stripe_Adapter extends SPG_Base_Adapter {

	/**
	 * Gateway slug.
	 *
	 * @var string
	 */
	protected $gateway_name = 'stripe';

	const API_SESSIONS        = 'https://api.stripe.com/v1/checkout/sessions';
	const API_PAYMENT_INTENTS = 'https://api.stripe.com/v1/payment_intents';
	const API_REFUNDS         = 'https://api.stripe.com/v1/refunds';

	/**
	 * {@inheritdoc}
	 *
	 * Creates a Stripe Checkout Session and returns the hosted URL.
	 */
	public function initiate( array $payload ) {
		$secret_key = $this->config['secret_key'] ?? '';

		$body = array(
			'payment_method_types[]'                 => 'card',
			'mode'                                   => 'payment',
			'line_items[0][price_data][currency]'    => strtolower( $payload['currency'] ),
			'line_items[0][price_data][product_data][name]' => sanitize_text_field( $payload['description'] ),
			'line_items[0][price_data][unit_amount]' => (int) round( (float) $payload['amount'] * 100 ),
			'line_items[0][quantity]'                => 1,
			'client_reference_id'                    => sanitize_text_field( $payload['order_id'] ),
			'success_url'                            => esc_url_raw( $payload['return_url'] ) . '?session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'                             => esc_url_raw( $payload['return_url'] ),
		);

		$response = $this->http_request(
			self::API_SESSIONS,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'redirect_url'   => $data['url'] ?? '',
			'transaction_id' => $data['payment_intent'] ?? $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_status( $transaction_id ) {
		$secret_key = $this->config['secret_key'] ?? '';

		$response = $this->http_request(
			self::API_PAYMENT_INTENTS . '/' . rawurlencode( $transaction_id ),
			array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'timeout' => 20,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'status'    => $this->normalise_status( $data['status'] ?? '' ),
			'amount'    => isset( $data['amount_received'] ) ? (float) $data['amount_received'] / 100 : 0.0,
			'reference' => $data['metadata']['order_id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function refund( $transaction_id, $amount ) {
		$secret_key = $this->config['secret_key'] ?? '';

		$response = $this->http_request(
			self::API_REFUNDS,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'payment_intent' => $transaction_id,
					'amount'         => (int) round( $amount * 100 ),
				),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'success'   => ( $data['status'] ?? '' ) !== 'failed',
			'refund_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Uses Stripe-Signature header with timestamp+payload HMAC.
	 */
	public function verify_webhook( $raw_body, array $headers ) {
		$secret     = $this->config['webhook_secret'] ?? '';
		$sig_header = $headers['stripe-signature'] ?? ( $headers['Stripe-Signature'] ?? '' );

		if ( empty( $sig_header ) || empty( $secret ) ) {
			return false;
		}

		// Parse t= and v1= components from the header.
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $item ) {
			list( $k, $v )       = array_pad( explode( '=', $item, 2 ), 2, '' );
			$parts[ trim( $k ) ] = trim( $v );
		}

		$timestamp = $parts['t'] ?? 0;
		$v1        = $parts['v1'] ?? '';

		if ( ! $timestamp ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $raw_body;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		return hash_equals( $expected, $v1 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function parse_webhook( $raw_body ) {
		$data = json_decode( $raw_body, true );
		if ( ! $data ) {
			return array();
		}

		$object = $data['data']['object'] ?? array();

		return array(
			'event_type'     => $data['type'] ?? '',
			'transaction_id' => $object['id'] ?? '',
			'order_id'       => $object['client_reference_id'] ?? ( $object['metadata']['order_id'] ?? '' ),
			'status'         => $this->normalise_status( $object['status'] ?? '' ),
			'amount'         => isset( $object['amount_received'] ) ? (float) $object['amount_received'] / 100 : 0.0,
		);
	}

	/**
	 * Normalise Stripe status to plugin vocabulary.
	 *
	 * @param string $raw_status Stripe status.
	 * @return string
	 */
	private function normalise_status( $raw_status ) {
		$map = array(
			'succeeded'               => 'approved',
			'complete'                => 'approved',
			'processing'              => 'pending',
			'requires_payment_method' => 'pending',
			'requires_confirmation'   => 'pending',
			'requires_action'         => 'pending',
			'requires_capture'        => 'pending',
			'canceled'                => 'cancelled',
		);
		return $map[ $raw_status ] ?? 'pending';
	}
}
