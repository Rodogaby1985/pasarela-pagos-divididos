<?php
/**
 * Abstract base gateway adapter.
 * All concrete adapters must extend this class and implement its abstract methods.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

abstract class SPG_Base_Adapter {

	use SPG_Logger;
	use SPG_Security;

	/** @var array Gateway configuration (API keys, endpoints, etc.). */
	protected $config = array();

	/** @var string Gateway identifier slug. */
	protected $gateway_name = '';

	/**
	 * Constructor.
	 *
	 * @param array $config Decrypted gateway configuration.
	 */
	public function __construct( array $config ) {
		$this->config = $config;
	}

	/**
	 * Initiate a payment and return a redirect URL + transaction ID.
	 *
	 * @param array $payload {
	 *     @type string $order_id    Unique order identifier (e.g. "123-shipping").
	 *     @type float  $amount      Amount to charge.
	 *     @type string $currency    ISO-4217 currency code.
	 *     @type string $description Human-readable description.
	 *     @type string $return_url  URL to redirect after payment.
	 *     @type array  $customer    Optional customer data (name, email).
	 * }
	 * @return array {
	 *     @type string $redirect_url  URL to redirect the customer to.
	 *     @type string $transaction_id Gateway-assigned transaction identifier.
	 * }
	 * @throws Exception On failure.
	 */
	abstract public function initiate( array $payload );

	/**
	 * Query the status of a transaction.
	 *
	 * @param string $transaction_id Gateway transaction ID.
	 * @return array {
	 *     @type string $status   Normalised status: 'approved'|'pending'|'rejected'|'cancelled'.
	 *     @type float  $amount   Amount confirmed by gateway.
	 *     @type string $reference Internal order reference.
	 * }
	 * @throws Exception On failure.
	 */
	abstract public function get_status( $transaction_id );

	/**
	 * Issue a refund for a transaction.
	 *
	 * @param string $transaction_id Gateway transaction ID.
	 * @param float  $amount         Amount to refund (partial refund if less than original).
	 * @return array {
	 *     @type bool   $success    Whether the refund was accepted.
	 *     @type string $refund_id  Gateway refund identifier.
	 * }
	 * @throws Exception On failure.
	 */
	abstract public function refund( $transaction_id, $amount );

	/**
	 * Verify the HMAC signature of an incoming webhook payload.
	 *
	 * @param string $raw_body   Raw HTTP request body.
	 * @param array  $headers    HTTP headers (key → value).
	 * @return bool
	 */
	abstract public function verify_webhook( $raw_body, array $headers );

	/**
	 * Parse a normalised event from a raw webhook payload.
	 *
	 * @param string $raw_body Raw HTTP request body.
	 * @return array {
	 *     @type string $event_type    e.g. 'payment.approved'.
	 *     @type string $transaction_id Gateway transaction ID.
	 *     @type string $order_id      Merchant order reference.
	 *     @type string $status        Normalised payment status.
	 *     @type float  $amount        Confirmed amount.
	 * }
	 */
	abstract public function parse_webhook( $raw_body );

	/**
	 * Return the gateway identifier slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->gateway_name;
	}

	/**
	 * Perform an HTTP request with retry logic.
	 *
	 * @param string $url     Target URL.
	 * @param array  $args    wp_remote_request() args.
	 * @param int    $retries Maximum number of attempts.
	 * @return array|WP_Error
	 */
	protected function http_request( $url, array $args = array(), $retries = 2 ) {
		$attempts = 0;
		$response = null;

		while ( $attempts <= $retries ) {
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				break;
			}

			$attempts++;
			if ( $attempts <= $retries ) {
				// Exponential back-off: 1s, 2s, 4s …
				sleep( (int) pow( 2, $attempts - 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_rand
			}
		}

		return $response;
	}

	/**
	 * Decode a JSON HTTP response body, throwing on error.
	 *
	 * @param array|WP_Error $response WordPress HTTP response.
	 * @return array
	 * @throws Exception On HTTP error or JSON decode failure.
	 */
	protected function decode_response( $response ) {
		if ( is_wp_error( $response ) ) {
			throw new Exception( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$error_msg = isset( $data['message'] ) ? $data['message'] : "HTTP {$code}";
			throw new Exception( "[{$this->gateway_name}] API error: {$error_msg} (HTTP {$code})" );
		}

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new Exception( "[{$this->gateway_name}] Invalid JSON response." );
		}

		return $data;
	}
}
