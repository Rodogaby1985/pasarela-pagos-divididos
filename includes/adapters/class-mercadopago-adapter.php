<?php
/**
 * MercadoPago gateway adapter.
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

	/**
	 * {@inheritdoc}
	 */
	public function initiate( array $payload ) {
		$access_token = $this->config['access_token'] ?? '';

		$preference = array(
			'items'              => array(
				array(
					'title'      => sanitize_text_field( $payload['description'] ),
					'quantity'   => 1,
					'unit_price' => (float) $payload['amount'],
					'currency_id' => strtoupper( $payload['currency'] ),
				),
			),
			'external_reference' => sanitize_text_field( $payload['order_id'] ),
			'back_urls'          => array(
				'success' => esc_url_raw( $payload['return_url'] ),
				'failure' => esc_url_raw( $payload['return_url'] ),
				'pending' => esc_url_raw( $payload['return_url'] ),
			),
			'auto_return'        => 'approved',
			'notification_url'   => rest_url( 'spg/v1/webhooks/mercadopago' ),
		);

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

		return array(
			'redirect_url'   => $data['init_point'] ?? '',
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
