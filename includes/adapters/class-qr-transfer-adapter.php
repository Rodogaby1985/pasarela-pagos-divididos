<?php
/**
 * QR Transfer Adapter.
 *
 * Generates a bank-scannable QR code that encodes the payment alias and amount.
 * The customer scans the QR with their banking app, completes the transfer, and
 * the gateway receives a confirmation webhook.
 *
 * Compatible with:
 *   - Argentina: CBU / CVU / Alias (Mercado Pago, MODO, Interoperable QR / CBI)
 *   - Chile: RUT + account / CuentaRUT
 *   - Generic: any configurable alias string
 *
 * QR data format (encoded as JSON → QR image):
 * {
 *   "v":       "1",          // schema version
 *   "alias":   "tienda.mp",  // bank alias or CBU/CVU
 *   "amount":  "100.00",     // decimal string
 *   "currency":"ARS",        // ISO-4217
 *   "concept": "Orden #123", // human-readable description
 *   "ref":     "123-total",  // internal order reference
 *   "exp":     1700000000,   // Unix timestamp expiry (15 min)
 *   "hash":    "sha256..."   // integrity hash
 * }
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_QR_Transfer_Adapter extends SPG_Base_Adapter {

	/** @var string Gateway identifier. */
	protected $gateway_name = 'qr_transfer';

	/** QR validity window in seconds (15 minutes). */
	const EXPIRY_SECONDS = 900;

	/**
	 * Initiate a QR transfer payment.
	 *
	 * Instead of returning a redirect URL, this returns the QR payload
	 * data so the frontend can render the QR image via JavaScript.
	 *
	 * @param array $payload {
	 *     @type string $order_id    Internal order reference (e.g. "123-shipping").
	 *     @type float  $amount      Amount to request.
	 *     @type string $currency    ISO-4217 currency code.
	 *     @type string $description Payment description.
	 *     @type string $return_url  Unused for QR (kept for interface compatibility).
	 *     @type array  $customer    Optional customer data.
	 * }
	 * @return array {
	 *     @type string $redirect_url  Empty string (no redirect for QR).
	 *     @type string $transaction_id SHA-256 hash used as transaction ID.
	 *     @type string $payment_type  Always 'qr_transfer'.
	 *     @type array  $qr_data       Structured QR payload to render client-side.
	 *     @type int    $expires_at    Unix timestamp of QR expiry.
	 * }
	 * @throws Exception When alias is not configured.
	 */
	public function initiate( array $payload ) {
		$alias    = $this->get_alias();
		$amount   = number_format( (float) $payload['amount'], 2, '.', '' );
		$currency = strtoupper( sanitize_text_field( $payload['currency'] ?? 'ARS' ) );
		$ref      = sanitize_text_field( $payload['order_id'] ?? '' );
		$concept  = sanitize_text_field( $payload['description'] ?? "Payment {$ref}" );
		$exp      = time() + self::EXPIRY_SECONDS;

		// Build the raw QR data before hashing.
		$raw = array(
			'v'        => '1',
			'alias'    => $alias,
			'amount'   => $amount,
			'currency' => $currency,
			'concept'  => $concept,
			'ref'      => $ref,
			'exp'      => $exp,
		);

		// Create integrity hash so the webhook receiver can verify the QR has not been tampered.
		$hash = $this->generate_qr_hash( $raw );
		$raw['hash'] = $hash;

		// Store the QR transfer record.
		$this->store_qr_record( $ref, $raw );

		// The "transaction_id" for a QR transfer is the hash itself.
		return array(
			'redirect_url'   => '',
			'transaction_id' => $hash,
			'payment_type'   => 'qr_transfer',
			'qr_data'        => $raw,
			'expires_at'     => $exp,
		);
	}

	/**
	 * Query the status of a QR transfer by its hash (transaction_id).
	 *
	 * @param string $transaction_id SHA-256 hash of the QR payload.
	 * @return array {
	 *     @type string $status    'approved'|'pending'|'expired'|'cancelled'.
	 *     @type float  $amount    Requested amount.
	 *     @type string $reference Internal order reference.
	 * }
	 */
	public function get_status( $transaction_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}spg_qr_transfers`
				 WHERE qr_hash = %s
				 LIMIT 1",
				sanitize_text_field( $transaction_id )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array(
				'status'    => 'pending',
				'amount'    => 0.0,
				'reference' => '',
			);
		}

		$status = $row['status'];

		// Auto-expire if the QR has passed its expiry time and is still pending.
		if ( 'pending' === $status && ! empty( $row['expires_at'] ) ) {
			if ( strtotime( $row['expires_at'] ) < time() ) {
				$status = 'expired';
				$wpdb->update(
					$wpdb->prefix . 'spg_qr_transfers',
					array( 'status' => 'expired' ),
					array( 'id' => $row['id'] )
				);
			}
		}

		// Map internal statuses to normalised adapter statuses.
		$status_map = array(
			'pending'   => 'pending',
			'confirmed' => 'approved',
			'expired'   => 'cancelled',
			'cancelled' => 'cancelled',
		);

		return array(
			'status'    => $status_map[ $status ] ?? 'pending',
			'amount'    => (float) ( $row['amount'] ?? 0 ),
			'reference' => $row['order_ref'] ?? '',
		);
	}

	/**
	 * Issue a refund for a QR transfer.
	 *
	 * Bank transfers typically require a manual reversal. This method marks
	 * the record as refunded for bookkeeping but does NOT initiate a real reversal.
	 *
	 * @param string $transaction_id QR hash / transaction ID.
	 * @param float  $amount         Amount to refund.
	 * @return array {
	 *     @type bool   $success   Always true (manual process).
	 *     @type string $refund_id Same as transaction_id.
	 *     @type string $note      Explanation that this is a manual process.
	 * }
	 */
	public function refund( $transaction_id, $amount ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'spg_qr_transfers',
			array( 'status' => 'refunded' ),
			array( 'qr_hash' => sanitize_text_field( $transaction_id ) )
		);

		return array(
			'success'   => true,
			'refund_id' => $transaction_id,
			'note'      => __( 'QR Transfer refunds must be processed manually through your banking app or platform.', 'split-payment-gateway' ),
		);
	}

	/**
	 * Verify the HMAC-SHA256 signature of an incoming QR transfer webhook.
	 *
	 * The webhook sender must include an X-SPG-Signature header with the value:
	 *   sha256=HMAC_SHA256(raw_body, webhook_secret)
	 *
	 * @param string $raw_body Raw HTTP request body.
	 * @param array  $headers  HTTP headers.
	 * @return bool
	 */
	public function verify_webhook( $raw_body, array $headers ) {
		$secret = $this->config['webhook_secret'] ?? get_option( 'spg_qr_webhook_secret', '' );

		if ( empty( $secret ) ) {
			// If no secret is configured, skip signature check (dev mode).
			$this->log_warning( 'QR Transfer webhook secret not configured – skipping signature verification.' );
			return true;
		}

		$provided_sig = $headers['x-spg-signature'] ?? $headers['X-SPG-Signature'] ?? '';

		if ( empty( $provided_sig ) ) {
			return false;
		}

		$expected_sig = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );

		return hash_equals( $expected_sig, $provided_sig );
	}

	/**
	 * Parse a QR transfer confirmation webhook payload.
	 *
	 * Expected JSON body:
	 * {
	 *   "event":          "qr.payment.confirmed",
	 *   "transaction_id": "<qr_hash>",
	 *   "order_ref":      "123-total",
	 *   "amount":         "100.00",
	 *   "status":         "confirmed"
	 * }
	 *
	 * @param string $raw_body Raw HTTP request body.
	 * @return array Normalised event array.
	 */
	public function parse_webhook( $raw_body ) {
		$data = json_decode( $raw_body, true );

		if ( empty( $data ) || json_last_error() !== JSON_ERROR_NONE ) {
			return array();
		}

		$raw_tx_id = $data['transaction_id'] ?? '';
		$raw_ref   = $data['order_ref'] ?? '';
		$raw_amt   = $data['amount'] ?? '0';
		$raw_evt   = $data['event'] ?? 'qr.payment.confirmed';

		// Map the incoming status to a normalised value.
		$incoming_status = strtolower( sanitize_text_field( $data['status'] ?? 'confirmed' ) );
		$status_map      = array(
			'confirmed' => 'approved',
			'approved'  => 'approved',
			'rejected'  => 'rejected',
			'cancelled' => 'cancelled',
			'expired'   => 'cancelled',
		);

		$normalised_status = $status_map[ $incoming_status ] ?? 'pending';

		return array(
			'event_type'     => sanitize_text_field( $raw_evt ),
			'transaction_id' => sanitize_text_field( $raw_tx_id ),
			'order_id'       => sanitize_text_field( $raw_ref ),
			'status'         => $normalised_status,
			'amount'         => (float) $raw_amt,
		);
	}

	// ── Public helpers ─────────────────────────────────────────────────────────

	/**
	 * Regenerate or return existing QR data for an order section.
	 *
	 * Used by the REST API endpoint to provide fresh QR data without re-initiating the payment.
	 *
	 * @param string $qr_hash Transaction ID / QR hash.
	 * @return array|null QR record or null if not found.
	 */
	public function get_qr_record( $qr_hash ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}spg_qr_transfers`
				 WHERE qr_hash = %s
				 LIMIT 1",
				sanitize_text_field( $qr_hash )
			),
			ARRAY_A
		);
	}

	/**
	 * Manually confirm a QR transfer payment (admin action or webhook handler).
	 *
	 * @param string $qr_hash Transaction ID / QR hash.
	 * @return bool Whether the record was updated.
	 */
	public function confirm_payment( $qr_hash ) {
		global $wpdb;

		$rows = $wpdb->update(
			$wpdb->prefix . 'spg_qr_transfers',
			array(
				'status'       => 'confirmed',
				'confirmed_at' => current_time( 'mysql', true ),
			),
			array(
				'qr_hash' => sanitize_text_field( $qr_hash ),
				'status'  => 'pending',
			)
		);

		return $rows > 0;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Return the configured bank alias, throwing if not set.
	 *
	 * @return string
	 * @throws Exception When alias is missing from config.
	 */
	private function get_alias() {
		$alias = $this->config['alias'] ?? '';

		if ( empty( $alias ) ) {
			throw new Exception( __( 'QR Transfer: bank alias is not configured.', 'split-payment-gateway' ) );
		}

		return sanitize_text_field( $alias );
	}

	/**
	 * Compute the integrity hash for a QR payload.
	 *
	 * Hash input: canonical JSON (sorted keys) + plugin secret key.
	 *
	 * @param array $raw QR payload (without the hash field).
	 * @return string Hex SHA-256 string.
	 */
	private function generate_qr_hash( array $raw ) {
		ksort( $raw );
		$canonical = wp_json_encode( $raw );
		$secret    = $this->get_hash_secret();

		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Get (or auto-generate) the HMAC secret used for QR hash generation.
	 *
	 * @return string
	 */
	private function get_hash_secret() {
		$secret = get_option( 'spg_qr_hash_secret', '' );

		if ( empty( $secret ) ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( 'spg_qr_hash_secret', $secret );
		}

		return $secret;
	}

	/**
	 * Persist a new QR transfer record to the database.
	 *
	 * @param string $order_ref Internal order reference.
	 * @param array  $qr_data   Full QR payload (including hash).
	 */
	private function store_qr_record( $order_ref, array $qr_data ) {
		global $wpdb;

		$expires_dt = gmdate( 'Y-m-d H:i:s', $qr_data['exp'] );

		$wpdb->insert(
			$wpdb->prefix . 'spg_qr_transfers',
			array(
				'order_ref'   => sanitize_text_field( $order_ref ),
				'alias'       => sanitize_text_field( $qr_data['alias'] ),
				'amount'      => (float) $qr_data['amount'],
				'currency'    => sanitize_text_field( $qr_data['currency'] ),
				'concept'     => sanitize_text_field( $qr_data['concept'] ),
				'qr_hash'     => sanitize_text_field( $qr_data['hash'] ),
				'qr_payload'  => wp_json_encode( $qr_data ),
				'status'      => 'pending',
				'expires_at'  => $expires_dt,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}
}
