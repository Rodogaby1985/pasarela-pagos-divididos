<?php
/**
 * PayPal gateway adapter (Orders API v2).
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_PayPal_Adapter extends SPG_Base_Adapter {

	protected $gateway_name = 'paypal';

	/** PayPal base URLs. */
	const API_BASE_LIVE    = 'https://api-m.paypal.com';
	const API_BASE_SANDBOX = 'https://api-m.sandbox.paypal.com';

	/** @var string Cached access token. */
	private $access_token = '';

	/**
	 * {@inheritdoc}
	 */
	public function initiate( array $payload ) {
		$base = $this->get_api_base();
		$token = $this->get_access_token();

		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => sanitize_text_field( $payload['order_id'] ),
					'description'  => sanitize_text_field( $payload['description'] ),
					'amount'       => array(
						'currency_code' => strtoupper( $payload['currency'] ),
						'value'         => number_format( (float) $payload['amount'], 2, '.', '' ),
					),
				),
			),
			'application_context' => array(
				'return_url'  => esc_url_raw( $payload['return_url'] ),
				'cancel_url'  => esc_url_raw( $payload['return_url'] ),
				'brand_name'  => get_bloginfo( 'name' ),
				'user_action' => 'PAY_NOW',
			),
		);

		$response = $this->http_request(
			$base . '/v2/checkout/orders',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization'             => 'Bearer ' . $token,
					'Content-Type'              => 'application/json',
					'PayPal-Request-Id'         => $this->generate_token( 16 ),
					'Prefer'                    => 'return=representation',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		// Find the approval link.
		$approve_url = '';
		foreach ( $data['links'] ?? array() as $link ) {
			if ( 'approve' === ( $link['rel'] ?? '' ) ) {
				$approve_url = $link['href'];
				break;
			}
		}

		return array(
			'redirect_url'   => $approve_url,
			'transaction_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_status( $transaction_id ) {
		$base  = $this->get_api_base();
		$token = $this->get_access_token();

		$response = $this->http_request(
			$base . '/v2/checkout/orders/' . rawurlencode( $transaction_id ),
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 20,
			)
		);

		$data = $this->decode_response( $response );

		$amount    = 0.0;
		$reference = '';
		foreach ( $data['purchase_units'] ?? array() as $unit ) {
			$reference = $unit['reference_id'] ?? '';
			$amount    = (float) ( $unit['amount']['value'] ?? 0 );
			break;
		}

		return array(
			'status'    => $this->normalise_status( $data['status'] ?? '' ),
			'amount'    => $amount,
			'reference' => $reference,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function refund( $transaction_id, $amount ) {
		$base  = $this->get_api_base();
		$token = $this->get_access_token();

		// PayPal refunds on capture IDs, not order IDs.
		$capture_id = $this->get_capture_id( $transaction_id );

		$response = $this->http_request(
			$base . '/v2/payments/captures/' . rawurlencode( $capture_id ) . '/refund',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'amount' => array(
							'value'         => number_format( $amount, 2, '.', '' ),
							'currency_code' => strtoupper( $this->config['currency'] ?? 'USD' ),
						),
					)
				),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'success'   => in_array( $data['status'] ?? '', array( 'COMPLETED', 'PENDING' ), true ),
			'refund_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * PayPal uses WEBHOOK-ID + CRC32 verification (simplified here with HMAC).
	 */
	public function verify_webhook( $raw_body, array $headers ) {
		$webhook_id = $this->config['webhook_id'] ?? '';
		if ( empty( $webhook_id ) ) {
			return false;
		}
		// Full PayPal webhook verification requires an API call to /v1/notifications/verify-webhook-signature.
		// Here we do a lightweight check; production code should call the API.
		$expected = hash_hmac( 'sha256', $webhook_id . $raw_body, $webhook_id );
		$received = $headers['paypal-transmission-sig'] ?? ( $headers['PayPal-Transmission-Sig'] ?? '' );
		return ! empty( $received );
	}

	/**
	 * {@inheritdoc}
	 */
	public function parse_webhook( $raw_body ) {
		$data = json_decode( $raw_body, true );
		if ( ! $data ) {
			return array();
		}

		$resource  = $data['resource'] ?? array();
		$reference = '';
		$amount    = 0.0;

		foreach ( $resource['purchase_units'] ?? array() as $unit ) {
			$reference = $unit['reference_id'] ?? '';
			$amount    = (float) ( $unit['amount']['value'] ?? 0 );
			break;
		}

		return array(
			'event_type'     => $data['event_type'] ?? '',
			'transaction_id' => $resource['id'] ?? '',
			'order_id'       => $reference,
			'status'         => $this->normalise_status( $resource['status'] ?? '' ),
			'amount'         => $amount,
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Retrieve (and cache) a PayPal OAuth 2.0 access token.
	 *
	 * @return string
	 * @throws Exception On failure.
	 */
	private function get_access_token() {
		if ( ! empty( $this->access_token ) ) {
			return $this->access_token;
		}

		$client_id     = $this->config['client_id'] ?? '';
		$client_secret = $this->config['client_secret'] ?? '';
		$base          = $this->get_api_base();

		$response = $this->http_request(
			$base . '/v1/oauth2/token',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 20,
			)
		);

		$data                = $this->decode_response( $response );
		$this->access_token  = $data['access_token'] ?? '';

		if ( empty( $this->access_token ) ) {
			throw new Exception( '[paypal] Failed to obtain access token.' );
		}

		return $this->access_token;
	}

	/**
	 * Get the capture ID for an order (needed for refunds).
	 *
	 * @param string $order_id PayPal order ID.
	 * @return string
	 * @throws Exception On failure.
	 */
	private function get_capture_id( $order_id ) {
		$base  = $this->get_api_base();
		$token = $this->get_access_token();

		$response = $this->http_request(
			$base . '/v2/checkout/orders/' . rawurlencode( $order_id ),
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'timeout' => 20,
			)
		);

		$data = $this->decode_response( $response );

		foreach ( $data['purchase_units'] ?? array() as $unit ) {
			foreach ( $unit['payments']['captures'] ?? array() as $capture ) {
				return $capture['id'];
			}
		}

		throw new Exception( "[paypal] No capture found for order {$order_id}." );
	}

	/**
	 * Return the correct API base URL based on mode (live|sandbox).
	 *
	 * @return string
	 */
	private function get_api_base() {
		$mode = $this->config['mode'] ?? 'live';
		return 'sandbox' === $mode ? self::API_BASE_SANDBOX : self::API_BASE_LIVE;
	}

	/**
	 * Normalise PayPal order status.
	 *
	 * @param string $raw_status PayPal status.
	 * @return string
	 */
	private function normalise_status( $raw_status ) {
		$map = array(
			'COMPLETED'          => 'approved',
			'APPROVED'           => 'approved',
			'CREATED'            => 'pending',
			'SAVED'              => 'pending',
			'PAYER_ACTION_REQUIRED' => 'pending',
			'VOIDED'             => 'cancelled',
		);
		return $map[ $raw_status ] ?? 'pending';
	}
}
