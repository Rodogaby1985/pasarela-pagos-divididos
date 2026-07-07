<?php
/**
 * Nave gateway adapter.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Nave_Adapter extends SPG_Base_Adapter {

	protected $gateway_name = 'nave';

	/** @var string Nave API base URL (production). */
	const API_BASE = 'https://api.nave.com/v1';

	/**
	 * {@inheritdoc}
	 */
	public function initiate( array $payload ) {
		$api_key = $this->config['api_key'] ?? '';

		$body = array(
			'amount'      => (int) round( (float) $payload['amount'] * 100 ), // centavos
			'currency'    => strtoupper( $payload['currency'] ),
			'order_id'    => sanitize_text_field( $payload['order_id'] ),
			'description' => sanitize_text_field( $payload['description'] ),
			'success_url' => esc_url_raw( $payload['return_url'] ),
			'webhook_url' => rest_url( 'spg/v1/webhooks/nave' ),
		);

		$response = $this->http_request(
			self::API_BASE . '/charges',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'redirect_url'   => $data['checkout_url'] ?? '',
			'transaction_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_status( $transaction_id ) {
		$api_key = $this->config['api_key'] ?? '';

		$response = $this->http_request(
			self::API_BASE . '/charges/' . rawurlencode( $transaction_id ),
			array(
				'method'  => 'GET',
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
				'timeout' => 20,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'status'    => $this->normalise_status( $data['status'] ?? '' ),
			'amount'    => isset( $data['amount'] ) ? (float) $data['amount'] / 100 : 0.0,
			'reference' => $data['order_id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function refund( $transaction_id, $amount ) {
		$api_key = $this->config['api_key'] ?? '';

		$response = $this->http_request(
			self::API_BASE . '/charges/' . rawurlencode( $transaction_id ) . '/refund',
			array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'amount' => (int) round( $amount * 100 ) ) ),
				'timeout' => 30,
			)
		);

		$data = $this->decode_response( $response );

		return array(
			'success'   => ! empty( $data['id'] ),
			'refund_id' => $data['id'] ?? '',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify_webhook( $raw_body, array $headers ) {
		$secret    = $this->config['webhook_secret'] ?? '';
		$signature = $headers['x-nave-signature'] ?? ( $headers['X-Nave-Signature'] ?? '' );
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

		return array(
			'event_type'     => $data['event'] ?? '',
			'transaction_id' => $data['id'] ?? '',
			'order_id'       => $data['order_id'] ?? '',
			'status'         => $this->normalise_status( $data['status'] ?? '' ),
			'amount'         => isset( $data['amount'] ) ? (float) $data['amount'] / 100 : 0.0,
		);
	}

	/**
	 * Normalise Nave status to plugin vocabulary.
	 *
	 * @param string $raw_status Nave status.
	 * @return string
	 */
	private function normalise_status( $raw_status ) {
		$map = array(
			'paid'      => 'approved',
			'approved'  => 'approved',
			'pending'   => 'pending',
			'processing'=> 'pending',
			'failed'    => 'rejected',
			'cancelled' => 'cancelled',
			'refunded'  => 'refunded',
		);
		return $map[ $raw_status ] ?? 'pending';
	}
}
