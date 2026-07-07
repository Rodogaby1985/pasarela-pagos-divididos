<?php
/**
 * Webhook Orchestrator.
 * Handles incoming webhook notifications from all supported gateways,
 * validates signatures, updates payment records and fires WooCommerce hooks.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Webhook_Orchestrator {

	use SPG_Logger;

	/** @var wpdb */
	private $db;

	/** @var SPG_Gateway_Adapter_Factory_Interface */
	private $factory;

	/**
	 * @param wpdb                                  $db      WordPress DB.
	 * @param SPG_Gateway_Adapter_Factory_Interface $factory Adapter factory.
	 */
	public function __construct( $db, SPG_Gateway_Adapter_Factory_Interface $factory ) {
		$this->db      = $db;
		$this->factory = $factory;
	}

	/**
	 * Process an incoming webhook for a specific gateway.
	 *
	 * @param string $gateway_name Gateway slug.
	 * @param string $raw_body     Raw HTTP request body.
	 * @param array  $headers      HTTP request headers.
	 * @return array {
	 *     @type bool   $success  Whether the webhook was processed successfully.
	 *     @type string $message  Status message.
	 * }
	 */
	public function process( $gateway_name, $raw_body, array $headers ) {
		$log_id = $this->log_webhook( $gateway_name, $raw_body, $headers );

		try {
			// Retrieve a temporary adapter without config just for signature verification.
			// The adapter's config will be loaded after we know the order / client.
			$adapter = $this->factory->get_adapter( $gateway_name );

			// Validate signature.
			if ( ! $adapter->verify_webhook( $raw_body, $headers ) ) {
				$this->update_webhook_log( $log_id, false, 'Invalid webhook signature.' );
				$this->log_warning( 'Invalid webhook signature.', array( 'gateway' => $gateway_name ) );
				return array( 'success' => false, 'message' => 'Invalid signature.' );
			}

			// Parse event.
			$event = $adapter->parse_webhook( $raw_body );

			if ( empty( $event['transaction_id'] ) ) {
				$this->update_webhook_log( $log_id, false, 'Missing transaction_id in webhook.' );
				return array( 'success' => false, 'message' => 'Missing transaction_id.' );
			}

			// Update payment record.
			$this->update_payment_status( $gateway_name, $event, $log_id );

			$this->update_webhook_log( $log_id, true );
			return array( 'success' => true, 'message' => 'Webhook processed.' );

		} catch ( Exception $e ) {
			$this->log_error( 'Webhook processing error.', array(
				'gateway' => $gateway_name,
				'error'   => $e->getMessage(),
			) );
			$this->update_webhook_log( $log_id, false, $e->getMessage() );
			return array( 'success' => false, 'message' => $e->getMessage() );
		}
	}

	/**
	 * Update the split payment record based on a webhook event.
	 *
	 * Handles both traditional gateway events (shipping_tx_id / total_tx_id)
	 * and QR Transfer events (qr_hash stored in spg_qr_transfers).
	 *
	 * @param string $gateway_name Gateway slug.
	 * @param array  $event        Parsed webhook event.
	 * @param int    $log_id       Webhook log ID for FK reference.
	 */
	private function update_payment_status( $gateway_name, array $event, $log_id ) {
		$tx_id  = $event['transaction_id'];
		$status = $event['status'];

		// For QR Transfers, confirm the QR record first.
		if ( 'qr_transfer' === $gateway_name && 'approved' === $status ) {
			$qr_row = $this->db->get_row(
				$this->db->prepare(
					"SELECT * FROM `{$this->db->prefix}spg_qr_transfers`
					 WHERE qr_hash = %s AND status = 'pending'
					 LIMIT 1",
					$tx_id
				),
				ARRAY_A
			);

			if ( $qr_row ) {
				$this->db->update(
					$this->db->prefix . 'spg_qr_transfers',
					array(
						'status'       => 'confirmed',
						'confirmed_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $qr_row['id'] )
				);
			}
		}

		// Find the split payment that owns this transaction.
		$payment = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->db->prefix}spg_split_payments`
				 WHERE shipping_tx_id = %s OR total_tx_id = %s
				 LIMIT 1",
				$tx_id,
				$tx_id
			),
			ARRAY_A
		);

		if ( ! $payment ) {
			$this->log_warning( 'No split payment found for transaction.', array( 'tx_id' => $tx_id ) );
			return;
		}

		$is_shipping = ( $payment['shipping_tx_id'] === $tx_id );
		$now         = current_time( 'mysql', true );

		if ( 'approved' === $status ) {
			if ( $is_shipping ) {
				$this->db->update(
					$this->db->prefix . 'spg_split_payments',
					array( 'shipping_paid_at' => $now ),
					array( 'id' => $payment['id'] )
				);
			} else {
				$this->db->update(
					$this->db->prefix . 'spg_split_payments',
					array( 'total_paid_at' => $now ),
					array( 'id' => $payment['id'] )
				);
			}
		}

		// Re-fetch to check if both are now paid.
		$refreshed = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->db->prefix}spg_split_payments` WHERE id = %d",
				$payment['id']
			),
			ARRAY_A
		);

		$both_paid = ! empty( $refreshed['shipping_paid_at'] ) && ! empty( $refreshed['total_paid_at'] );

		if ( $both_paid ) {
			$this->db->update(
				$this->db->prefix . 'spg_split_payments',
				array( 'status' => 'completed' ),
				array( 'id' => $payment['id'] )
			);

			// Fire WooCommerce order completion.
			$order_id = (int) $payment['order_id'];
			$order    = wc_get_order( $order_id );
			if ( $order ) {
				$order->payment_complete();
				$order->add_order_note(
					__( 'Split Payment: both shipping and total payments confirmed.', 'split-payment-gateway' )
				);
			}

			/**
			 * Fires when a split payment is fully completed.
			 *
			 * @since 1.0.0
			 * @param int   $order_id   WooCommerce order ID.
			 * @param array $payment    Split payment record.
			 */
			do_action( 'spg_split_payment_completed', $order_id, $refreshed );

		} elseif ( 'rejected' === $status || 'cancelled' === $status ) {
			$this->db->update(
				$this->db->prefix . 'spg_split_payments',
				array( 'status' => 'partial_failed' ),
				array( 'id' => $payment['id'] )
			);

			$order_id = (int) $payment['order_id'];
			$order    = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_status(
					'failed',
					/* translators: %1$s: gateway name, %2$s: payment type */
					sprintf(
						__( 'Split Payment: %1$s payment %2$s.', 'split-payment-gateway' ),
						$gateway_name,
						$status
					)
				);
			}
		}

		// Update reconciliation table.
		$this->db->insert(
			$this->db->prefix . 'spg_transaction_reconciliation',
			array(
				'split_payment_id' => $payment['id'],
				'order_id'         => $payment['order_id'],
				'tx_type'          => $is_shipping ? 'shipping' : 'total',
				'gateway'          => $gateway_name,
				'tx_id'            => $tx_id,
				'amount'           => $is_shipping ? $payment['shipping_amount'] : $payment['total_amount'],
				'currency'         => $payment['currency'],
				'gateway_status'   => $event['status'],
				'raw_response'     => wp_json_encode( $event ),
				'created_at'       => $now,
			)
		);

		// Update the webhook log with the order ID for cross-reference.
		$this->db->update(
			$this->db->prefix . 'spg_webhook_logs',
			array( 'order_id' => $payment['order_id'], 'tx_id' => $tx_id ),
			array( 'id' => $log_id )
		);
	}

	/**
	 * Insert a webhook log entry.
	 *
	 * @param string $gateway  Gateway slug.
	 * @param string $raw_body Raw request body.
	 * @param array  $headers  Request headers.
	 * @return int Inserted row ID.
	 */
	private function log_webhook( $gateway, $raw_body, array $headers ) {
		$this->db->insert(
			$this->db->prefix . 'spg_webhook_logs',
			array(
				'gateway'    => sanitize_key( $gateway ),
				'payload'    => $raw_body,
				'headers'    => wp_json_encode( $headers ),
				'processed'  => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $this->db->insert_id;
	}

	/**
	 * Mark a webhook log entry as processed (or failed).
	 *
	 * @param int         $log_id    Log row ID.
	 * @param bool        $success   Whether processing succeeded.
	 * @param string|null $error_msg Error message on failure.
	 */
	private function update_webhook_log( $log_id, $success, $error_msg = null ) {
		$this->db->update(
			$this->db->prefix . 'spg_webhook_logs',
			array(
				'processed'    => $success ? 1 : 0,
				'processed_at' => current_time( 'mysql', true ),
				'error'        => $error_msg,
			),
			array( 'id' => $log_id )
		);
	}
}
