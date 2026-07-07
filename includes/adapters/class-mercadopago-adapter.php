<?php
/**
 * MercadoPago gateway adapter.
 *
 * Supports:
 *  - Sandbox and production environments.
 *  - Checkout Preferences API (redirect flow).
 *  - Payment status queries.
 *  - Refunds.
 *  - HMAC-SHA256 webhook signature verification.
 *  - Auto-creation and listing of webhooks via the MercadoPago Webhooks API.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_MercadoPago_Adapter extends SPG_Base_Adapter {

	protected $gateway_name = 'mercadopago';

	/** @var string MercadoPago Preferences API endpoint. */
	const API_PREFERENCES = 'https://api.mercadopago.com/checkout/preferences';

	/** @var string MercadoPago Payments API endpoint. */
	const API_PAYMENTS = 'https://api.mercadopago.com/v1/payments';

	/** @var string MercadoPago Refunds API endpoint. */
	const API_REFUNDS = 'https://api.mercadopago.com/v1/payments/%s/refunds';

	/** @var string MercadoPago Webhooks API endpoint. */
	const API_WEBHOOKS = 'https://api.mercadopago.com/v1/webhooks';

	/**
	 * {@inheritdoc}
	 */
	public function initiate( array $payload ) {
		$access_token = $this->config['access_token'] ?? '';
		$is_sandbox   = $this->is_sandbox();

		$preference = array(
			'items'              => array(
				array(
					'title'       => sanitize_text_field( $payload['description'] ),
					'quantity'    => 1,
					'unit_price'  => (float) $payload['amount'],
					'currency_id' => strtoupper( $payload['currency'] ),
				),
			),
			'external_reference' => sanitize_text_field( $payload['order_id'] ),
			'back_urls'          => array(
				'success' => esc_url_raw( $payload['return_url'] ),
				'failure' => esc_url_raw( $payload['return_url'] ),
				'pending' => esc_url_raw( $payload['return_url'] ),
			),
			'auto_return'      => 'approved',
			'notification_url' => rest_url( 'spg/v1/webhooks/mercadopago' ),
		);

		// In sandbox mode use the sandbox init_point.
		if ( $is_sandbox ) {
			$preference['sandbox_mode'] = true;
		}

		$response = $this->http_request(
			self::API_PREFERENCES,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $preference ),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		// In sandbox, prefer sandbox_init_point if available.
		$redirect_url = $is_sandbox
			? ( $data['sandbox_init_point'] ?? $data['init_point'] ?? '' )
			: ( $data['init_point'] ?? '' );

		return array(
			'redirect_url'   => $redirect_url,
			'transaction_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_status( $transaction_id ) {
		$access_token = $this->config['access_token'] ?? '';

		$response = $this->http_request(
			self::API_PAYMENTS . '/' . rawurlencode( $transaction_id ),
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 20,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'status'    => $this->normalise_status( $data['status'] ?? '' ),
			'amount'    => (float) ( $data['transaction_amount'] ?? 0 ),
			'reference' => $data['external_reference'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function refund( $transaction_id, $amount ) {
		$access_token = $this->config['access_token'] ?? '';

		$url = sprintf( self::API_REFUNDS, rawurlencode( $transaction_id ) );

		$response = $this->http_request(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'amount' => (float) $amount ) ),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'success'   => isset( $data['id'] ),
			'refund_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_webhook( $raw_body, array $headers ) {
		$secret    = $this->config['webhook_secret'] ?? '';
		$signature = $headers['x-signature'] ?? ( $headers['X-Signature'] ?? '' );
		return $this->verify_webhook_signature( $raw_body, $signature, $secret );
	}

	/**
	 * {@inheritdoc}
	 */
	public function parse_webhook( $raw_body ) {
		$data = json_decode( $raw_body, true );
		if ( ! $data ) {
			return array();
		}

		// MercadoPago sends a notification pointing us to fetch the real data.
		$tx_id = $data['data']['id'] ?? '';

		return array(
			'event_type'     => $data['type'] ?? '',
			'transaction_id' => (string) $tx_id,
			'order_id'       => $data['external_reference'] ?? '',
			'status'         => $this->normalise_status( $data['status'] ?? 'pending' ),
			'amount'         => (float) ( $data['transaction_amount'] ?? 0 ),
		);
	}

	// ── Webhook management ──────────────────────────────────────────────────────

	/**
	 * Create a webhook in MercadoPago for this site's notification URL.
	 *
	 * Checks whether a webhook for the same URL already exists before creating
	 * a new one to avoid duplicates.
	 *
	 * @return array {
	 *     @type bool   $success    Whether the operation succeeded.
	 *     @type string $webhook_id Webhook ID (new or existing).
	 *     @type string $message    Human-readable status message.
	 * }
	 */
	public function create_webhook() {
		$access_token   = $this->config['access_token'] ?? '';
		$notification_url = rest_url( 'spg/v1/webhooks/mercadopago' );

		if ( empty( $access_token ) ) {
			return array(
				'success'    => false,
				'webhook_id' => '',
				'message'    => __( 'Access Token is not configured.', 'split-payment-gateway' ),
			);
		}

		// Check for an existing webhook with the same URL first.
		$existing = $this->find_existing_webhook( $access_token, $notification_url );
		if ( $existing ) {
			return array(
				'success'    => true,
				'webhook_id' => (string) $existing['id'],
				'message'    => __( 'Webhook already exists and is active.', 'split-payment-gateway' ),
			);
		}

		// Create new webhook.
		$body = array(
			'url'    => $notification_url,
			'events' => array(
				array( 'name' => 'payment' ),
			),
		);

		$response = $this->http_request(
			self::API_WEBHOOKS,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		try {
			$data = $this->decode_response( $response );
			$webhook_id = (string) ( $data['id'] ?? '' );

			// Persist the webhook ID so it can be referenced later.
			update_option( 'spg_mercadopago_webhook_id', $webhook_id );

			return array(
				'success'    => true,
				'webhook_id' => $webhook_id,
				'message'    => __( 'Webhook created successfully.', 'split-payment-gateway' ),
			);
		} catch ( Exception $e ) {
			return array(
				'success'    => false,
				'webhook_id' => '',
				'message'    => $e->getMessage(),
			);
		}
	}

	/**
	 * List all webhooks registered in MercadoPago.
	 *
	 * @return array List of webhook objects, or empty array on failure.
	 */
	public function list_webhooks() {
		$access_token = $this->config['access_token'] ?? '';

		if ( empty( $access_token ) ) {
			return array();
		}

		$response = $this->http_request(
			self::API_WEBHOOKS,
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 20,
			)
		);

		try {
			$data = $this->decode_response( $response );
			return $data['data'] ?? ( is_array( $data ) ? $data : array() );
		} catch ( Exception $e ) {
			$this->log_error( 'Failed to list MercadoPago webhooks.', array( 'error' => $e->getMessage() ) );
			return array();
		}
	}

	/**
	 * Verify that the configured webhook URL is registered and active in MercadoPago.
	 *
	 * @return array {
	 *     @type bool   $active     Whether the webhook is active.
	 *     @type string $webhook_id Webhook ID if found.
	 *     @type string $message    Human-readable status message.
	 * }
	 */
	public function verify_webhook_registration() {
		$access_token     = $this->config['access_token'] ?? '';
		$notification_url = rest_url( 'spg/v1/webhooks/mercadopago' );

		if ( empty( $access_token ) ) {
			return array(
				'active'     => false,
				'webhook_id' => '',
				'message'    => __( 'Access Token is not configured.', 'split-payment-gateway' ),
			);
		}

		$existing = $this->find_existing_webhook( $access_token, $notification_url );

		if ( $existing ) {
			return array(
				'active'     => true,
				'webhook_id' => (string) $existing['id'],
				'message'    => __( 'Webhook is active.', 'split-payment-gateway' ),
			);
		}

		return array(
			'active'     => false,
			'webhook_id' => '',
			'message'    => __( 'No active webhook found for this site. Click "Create Webhook" to register one.', 'split-payment-gateway' ),
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Return true when sandbox mode is enabled.
	 *
	 * @return bool
	 */
	private function is_sandbox() {
		return ! empty( $this->config['sandbox'] ) && 'yes' === $this->config['sandbox'];
	}

	/**
	 * Search the registered webhooks list for one whose URL matches the given URL.
	 *
	 * @param string $access_token MercadoPago access token.
	 * @param string $url          URL to look for.
	 * @return array|null First matching webhook record, or null if not found.
	 */
	private function find_existing_webhook( $access_token, $url ) {
		$response = $this->http_request(
			self::API_WEBHOOKS,
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 20,
			)
		);

		try {
			$data  = $this->decode_response( $response );
			$items = $data['data'] ?? ( is_array( $data ) ? $data : array() );

			foreach ( $items as $webhook ) {
				if ( isset( $webhook['url'] ) && rtrim( $webhook['url'], '/' ) === rtrim( $url, '/' ) ) {
					return $webhook;
				}
			}
		} catch ( Exception $e ) {
			// Ignore – treat as not found.
		}

		return null;
	}

	/**
	 * Normalise MercadoPago status to plugin vocabulary.
	 *
	 * @param string $raw_status MercadoPago status string.
	 * @return string
	 */
	private function normalise_status( $raw_status ) {
		$map = array(
			'approved'      => 'approved',
			'authorized'    => 'approved',
			'pending'       => 'pending',
			'in_process'    => 'pending',
			'in_mediation'  => 'pending',
			'rejected'      => 'rejected',
			'cancelled'     => 'cancelled',
			'refunded'      => 'refunded',
			'charged_back'  => 'refunded',
		);
		return $map[ $raw_status ] ?? 'pending';
	}
}
