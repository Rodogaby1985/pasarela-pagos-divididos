<?php
/**
 * QR Transfer Adapter.
 *
 * Generates a bank-scannable QR code that encodes the payment alias and amount.
 * The customer scans the QR with their banking app, completes the transfer, and
 * the gateway receives a confirmation webhook.
 *
 * Compatible with:
 *   - Argentina: CBI (Código de Barras Interoperable) – BCRA Com. "A" 6506
 *     Works with MercadoPago, MODO, BBVA, Santander, and all Argentine banks.
 *   - Chile: RUT + account / CuentaRUT (legacy JSON format)
 *   - Generic: any configurable alias string (legacy JSON format)
 *
 * For Argentina (country = 'AR'), the QR payload is a TLV-formatted CBI string
 * that is natively understood by all Argentine banking apps.  For other countries
 * the adapter falls back to the legacy custom JSON format.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.Security.EscapeOutput.ExceptionNotEscaped,Squiz.Commenting.FunctionComment.ParamCommentFullStop

/**
 * QR transfer adapter.
 */
class SPG_QR_Transfer_Adapter extends SPG_Base_Adapter {

	/**
	 * Gateway identifier.
	 *
	 * @var string
	 */
	protected $gateway_name = 'qr_transfer';

	/** QR validity window in seconds (15 minutes). */
	const EXPIRY_SECONDS = 900;

	/**
	 * Initiate a QR transfer payment.
	 *
	 * Instead of returning a redirect URL, this returns the QR payload
	 * data so the frontend can render the QR image via JavaScript.
	 *
	 * For Argentina (country = 'AR') the payload is a CBI TLV string
	 * (BCRA Com. "A" 6506) compatible with all Argentine banking apps.
	 * For other countries a custom JSON payload is used.
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
	 *     @type string $redirect_url   Empty string (no redirect for QR).
	 *     @type string $transaction_id HMAC-SHA256 hash used as transaction ID.
	 *     @type string $payment_type   Always 'qr_transfer'.
	 *     @type string $qr_data        CBI TLV string (AR) or JSON payload (other).
	 *     @type string $qr_type        'cbi' for Argentina, 'json' otherwise.
	 *     @type int    $expires_at     Unix timestamp of QR expiry.
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
		$country  = strtoupper( $this->config['country'] ?? get_option( 'spg_qr_country', 'AR' ) );

		if ( 'AR' === $country ) {
			// ── CBI format (Argentina) ─────────────────────────────────────
			$merchant_name = sanitize_text_field( get_option( 'spg_qr_merchant_name', get_bloginfo( 'name' ) ) );
			$merchant_city = sanitize_text_field( get_option( 'spg_qr_merchant_city', '' ) );
			$psp_id        = sanitize_text_field( get_option( 'spg_qr_psp_id', SPG_CBI_QR_Generator::DEFAULT_PSP_ID ) );

			if ( empty( $merchant_city ) ) {
				throw new Exception( __( 'QR Transfer: Merchant city is not configured. Set it in the QR Transfer settings before generating Argentine CBI codes.', 'split-payment-gateway' ) );
			}

			$cbi_string = SPG_CBI_QR_Generator::generate(
				$alias,
				(float) $amount,
				$merchant_name,
				$merchant_city,
				$country,
				$psp_id
			);

			// Derive a unique transaction hash from the CBI payload + order ref.
			$hash = hash_hmac( 'sha256', $cbi_string . $ref, $this->get_hash_secret() );

			$this->store_qr_record( $ref, $cbi_string, $hash, (float) $amount, $currency, $concept, $exp, $alias );

			return array(
				'redirect_url'   => '',
				'transaction_id' => $hash,
				'payment_type'   => 'qr_transfer',
				'qr_data'        => $cbi_string,
				'qr_type'        => 'cbi',
				'expires_at'     => $exp,
			);
		}

		// ── Legacy JSON format (non-AR countries) ─────────────────────────
		$raw = array(
			'v'        => '1',
			'alias'    => $alias,
			'amount'   => $amount,
			'currency' => $currency,
			'concept'  => $concept,
			'ref'      => $ref,
			'exp'      => $exp,
		);

		$hash        = $this->generate_qr_hash( $raw );
		$raw['hash'] = $hash;

		$this->store_qr_record( $ref, wp_json_encode( $raw ), $hash, (float) $amount, $currency, $concept, $exp, $alias );

		return array(
			'redirect_url'   => '',
			'transaction_id' => $hash,
			'payment_type'   => 'qr_transfer',
			'qr_data'        => $raw,
			'qr_type'        => 'json',
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
			// In production, reject requests without a configured secret.
			// Allow bypass only in local/debug environments to ease development.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->log_warning( 'QR Transfer webhook secret not configured – skipping verification (WP_DEBUG=true).' );
				return true;
			}
			$this->log_error( 'QR Transfer webhook rejected: no webhook secret configured. Set it in WooCommerce → Split Payment → QR Transfer.' );
			return false;
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
	 * @param string $order_ref  Internal order reference.
	 * @param string $qr_payload QR payload string (CBI TLV or JSON).
	 * @param string $qr_hash    HMAC-SHA256 transaction hash (used as ID).
	 * @param float  $amount     Transaction amount.
	 * @param string $currency   ISO-4217 currency code.
	 * @param string $concept    Payment description / concept.
	 * @param int    $exp        Unix timestamp of QR expiry.
	 * @param string $alias      Bank alias / CBU / CVU (for display).
	 */
	private function store_qr_record( string $order_ref, string $qr_payload, string $qr_hash, float $amount, string $currency, string $concept, int $exp, string $alias = '' ) {
		global $wpdb;

		$expires_dt = gmdate( 'Y-m-d H:i:s', $exp );

		$wpdb->insert(
			$wpdb->prefix . 'spg_qr_transfers',
			array(
				'order_ref'  => sanitize_text_field( $order_ref ),
				'alias'      => sanitize_text_field( $alias ),
				'amount'     => $amount,
				'currency'   => sanitize_text_field( $currency ),
				'concept'    => sanitize_text_field( $concept ),
				'qr_hash'    => sanitize_text_field( $qr_hash ),
				'qr_payload' => $qr_payload,
				'status'     => 'pending',
				'expires_at' => $expires_dt,
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}
}
