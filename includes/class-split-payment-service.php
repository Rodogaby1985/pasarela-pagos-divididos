<?php
/**
 * Split Payment Service.
 * High-level orchestration: initiates, validates, and refunds split payments.
 * This is the main entry point called by the WooCommerce Gateway and the REST API.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Split_Payment_Service {

	use SPG_Logger;
	use SPG_Security;

	/** @var wpdb */
	private $db;

	/** @var SPG_Payment_Routing_Engine */
	private $router;

	/** @var SPG_Gateway_Adapter_Factory */
	private $factory;

	/** @var SPG_Split_Distribution_Engine */
	private $distribution;

	/**
	 * @param wpdb                          $db           WordPress DB.
	 * @param SPG_Payment_Routing_Engine    $router       Routing engine.
	 * @param SPG_Gateway_Adapter_Factory   $factory      Adapter factory.
	 * @param SPG_Split_Distribution_Engine $distribution Distribution engine.
	 */
	public function __construct( $db, $router, $factory, $distribution ) {
		$this->db           = $db;
		$this->router       = $router;
		$this->factory      = $factory;
		$this->distribution = $distribution;
	}

	/**
	 * Initiate a split payment for a WooCommerce order.
	 *
	 * Supports multi-method: each section can independently use either a
	 * traditional gateway (redirect URL) or QR Transfer (inline QR code).
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $client_id      Store/client identifier.
	 * @param array    $method_choices {
	 *     Optional method overrides.
	 *     @type string $shipping_method  Gateway slug or 'qr_transfer'.
	 *     @type string $total_method     Gateway slug or 'qr_transfer'.
	 * }
	 * @return array {
	 *     @type string $shipping_payment_url  URL to pay shipping (empty for QR).
	 *     @type string $total_payment_url     URL to pay total (empty for QR).
	 *     @type string $shipping_gateway      Gateway name for shipping.
	 *     @type string $total_gateway         Gateway name for total.
	 *     @type string $shipping_method_type  'gateway' or 'qr_transfer'.
	 *     @type string $total_method_type     'gateway' or 'qr_transfer'.
	 *     @type array  $shipping_qr_data      QR payload when shipping uses QR Transfer.
	 *     @type array  $total_qr_data         QR payload when total uses QR Transfer.
	 *     @type string $session_id            Internal tracking token.
	 * }
	 * @throws Exception On any failure.
	 */
	public function initiate( WC_Order $order, $client_id, array $method_choices = array() ) {
		$order_id        = $order->get_id();
		$shipping_amount = (float) $order->get_shipping_total();
		$order_subtotal  = (float) $order->get_subtotal();
		$currency        = $order->get_currency();
		$return_url      = $order->get_checkout_order_received_url();

		$context = array( 'currency' => $currency );

		// Resolve gateways (routing engine may be overridden by explicit method_choices).
		$shipping_gw = $this->router->resolve( $client_id, 'shipping', $shipping_amount, $context );
		$total_gw    = $this->router->resolve( $client_id, 'total',    $order_subtotal,  $context );

		// Allow explicit method overrides from the frontend selection.
		if ( ! empty( $method_choices['shipping_method'] ) ) {
			$shipping_gw['name'] = sanitize_key( $method_choices['shipping_method'] );
		}
		if ( ! empty( $method_choices['total_method'] ) ) {
			$total_gw['name'] = sanitize_key( $method_choices['total_method'] );
		}

		// Build adapter configs. QR Transfer reads aliases from wp_options directly.
		if ( 'qr_transfer' === $shipping_gw['name'] ) {
			$alias = get_option( 'spg_qr_alias_shipping', '' );
			if ( empty( $alias ) ) {
				throw new Exception( __( 'QR Transfer: Shipping alias is not configured. Go to WooCommerce → Split Payment → QR Transfer.', 'split-payment-gateway' ) );
			}
			$shipping_gw['config'] = array( 'alias' => $alias );
		} else {
			$shipping_gw['config'] = $this->get_gateway_config( $client_id, $shipping_gw['name'] );
		}

		if ( 'qr_transfer' === $total_gw['name'] ) {
			$alias = get_option( 'spg_qr_alias_subtotal', '' );
			if ( empty( $alias ) ) {
				throw new Exception( __( 'QR Transfer: Subtotal alias is not configured. Go to WooCommerce → Split Payment → QR Transfer.', 'split-payment-gateway' ) );
			}
			$total_gw['config'] = array( 'alias' => $alias );
		} else {
			$total_gw['config'] = $this->get_gateway_config( $client_id, $total_gw['name'] );
		}

		// Determine method types.
		$shipping_method_type = ( 'qr_transfer' === $shipping_gw['name'] ) ? 'qr_transfer' : 'gateway';
		$total_method_type    = ( 'qr_transfer' === $total_gw['name'] )    ? 'qr_transfer' : 'gateway';

		// Build adapters.
		$shipping_adapter = $this->factory->get_adapter( $shipping_gw['name'], $shipping_gw['config'] );
		$total_adapter    = $this->factory->get_adapter( $total_gw['name'],    $total_gw['config'] );

		// Initiate both payments.
		$shipping_result = $shipping_adapter->initiate( array(
			'order_id'    => "{$order_id}-shipping",
			'amount'      => $shipping_amount,
			'currency'    => $currency,
			'description' => sprintf(
				/* translators: %d: order ID */
				__( 'Shipping – Order #%d', 'split-payment-gateway' ),
				$order_id
			),
			'return_url'  => $return_url,
			'customer'    => array(
				'name'  => $order->get_formatted_billing_full_name(),
				'email' => $order->get_billing_email(),
			),
		) );

		$total_result = $total_adapter->initiate( array(
			'order_id'    => "{$order_id}-total",
			'amount'      => $order_subtotal,
			'currency'    => $currency,
			'description' => sprintf(
				/* translators: %d: order ID */
				__( 'Products – Order #%d', 'split-payment-gateway' ),
				$order_id
			),
			'return_url'  => $return_url,
			'customer'    => array(
				'name'  => $order->get_formatted_billing_full_name(),
				'email' => $order->get_billing_email(),
			),
		) );

		// Persist record.
		$session_id = bin2hex( random_bytes( 16 ) );

		$this->db->insert(
			$this->db->prefix . 'spg_split_payments',
			array(
				'order_id'             => $order_id,
				'client_id'            => sanitize_text_field( $client_id ),
				'shipping_gateway'     => $shipping_gw['name'],
				'shipping_method_type' => $shipping_method_type,
				'total_gateway'        => $total_gw['name'],
				'total_method_type'    => $total_method_type,
				'shipping_tx_id'       => $shipping_result['transaction_id'],
				'total_tx_id'          => $total_result['transaction_id'],
				'shipping_amount'      => $shipping_amount,
				'total_amount'         => $order_subtotal,
				'currency'             => $currency,
				'status'               => 'initiated',
				'metadata'             => wp_json_encode( array( 'session_id' => $session_id ) ),
				'created_at'           => current_time( 'mysql', true ),
				'updated_at'           => current_time( 'mysql', true ),
			)
		);

		// Store session metadata on the order.
		$order->update_meta_data( '_spg_session_id',           $session_id );
		$order->update_meta_data( '_spg_shipping_tx_id',       $shipping_result['transaction_id'] );
		$order->update_meta_data( '_spg_total_tx_id',          $total_result['transaction_id'] );
		$order->update_meta_data( '_spg_shipping_gateway',     $shipping_gw['name'] );
		$order->update_meta_data( '_spg_total_gateway',        $total_gw['name'] );
		$order->update_meta_data( '_spg_shipping_method_type', $shipping_method_type );
		$order->update_meta_data( '_spg_total_method_type',    $total_method_type );
		$order->save();

		$order->update_status(
			'pending',
			__( 'Split Payment initiated – awaiting shipping and product payments.', 'split-payment-gateway' )
		);

		$this->log_info( 'Split payment initiated.', array( 'order_id' => $order_id ) );

		return array(
			'shipping_payment_url' => $shipping_result['redirect_url'] ?? '',
			'total_payment_url'    => $total_result['redirect_url'] ?? '',
			'shipping_gateway'     => $shipping_gw['name'],
			'total_gateway'        => $total_gw['name'],
			'shipping_method_type' => $shipping_method_type,
			'total_method_type'    => $total_method_type,
			'shipping_qr_data'     => $shipping_result['qr_data'] ?? null,
			'total_qr_data'        => $total_result['qr_data'] ?? null,
			'shipping_expires_at'  => $shipping_result['expires_at'] ?? null,
			'total_expires_at'     => $total_result['expires_at'] ?? null,
			'session_id'           => $session_id,
		);
	}

	/**
	 * Validate the current payment status for an order.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array {
	 *     @type bool   $shipping_paid  Whether shipping has been paid.
	 *     @type bool   $total_paid     Whether the order total has been paid.
	 *     @type bool   $is_complete    Whether both payments are confirmed.
	 *     @type string $status         Overall status string.
	 * }
	 * @throws Exception When order or payment not found.
	 */
	public function validate( $order_id ) {
		$payment = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->db->prefix}spg_split_payments` WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		if ( ! $payment ) {
			throw new Exception( "No split payment found for order {$order_id}." );
		}

		$shipping_paid = ! empty( $payment['shipping_paid_at'] );
		$total_paid    = ! empty( $payment['total_paid_at'] );

		// If not yet both paid, poll each gateway actively.
		if ( ! $shipping_paid || ! $total_paid ) {
			list( $shipping_paid, $total_paid ) = $this->poll_gateways( $payment );
		}

		$is_complete = $shipping_paid && $total_paid;

		return array(
			'shipping_paid' => $shipping_paid,
			'total_paid'    => $total_paid,
			'is_complete'   => $is_complete,
			'status'        => $payment['status'],
		);
	}

	/**
	 * Issue refunds for an order's split payment.
	 *
	 * @param int   $order_id      WooCommerce order ID.
	 * @param float $refund_amount Total amount to refund (use 0 for full refund).
	 * @return array {
	 *     @type bool   $shipping_refunded
	 *     @type bool   $total_refunded
	 * }
	 * @throws Exception On failure.
	 */
	public function refund( $order_id, $refund_amount = 0.0 ) {
		$payment = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->db->prefix}spg_split_payments` WHERE order_id = %d LIMIT 1",
				$order_id
			),
			ARRAY_A
		);

		if ( ! $payment ) {
			throw new Exception( "No split payment found for order {$order_id}." );
		}

		// Full refund if amount is 0.
		if ( $refund_amount <= 0 ) {
			$refund_amount = $payment['shipping_amount'] + $payment['total_amount'];
		}

		$split = $this->distribution->calculate_refund_split(
			$refund_amount,
			$payment['shipping_amount'],
			$payment['total_amount']
		);

		$shipping_adapter = $this->factory->get_adapter( $payment['shipping_gateway'] );
		$total_adapter    = $this->factory->get_adapter( $payment['total_gateway'] );

		$shipping_refund = $shipping_adapter->refund( $payment['shipping_tx_id'], $split['shipping_refund'] );
		$total_refund    = $total_adapter->refund( $payment['total_tx_id'], $split['total_refund'] );

		if ( $shipping_refund['success'] && $total_refund['success'] ) {
			$this->db->update(
				$this->db->prefix . 'spg_split_payments',
				array( 'status' => 'refunded' ),
				array( 'id' => $payment['id'] )
			);
		}

		return array(
			'shipping_refunded' => $shipping_refund['success'],
			'total_refunded'    => $total_refund['success'],
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Actively poll both gateways for current payment status.
	 *
	 * @param array $payment Split payment record.
	 * @return array [bool $shipping_paid, bool $total_paid]
	 */
	private function poll_gateways( array $payment ) {
		$shipping_paid = ! empty( $payment['shipping_paid_at'] );
		$total_paid    = ! empty( $payment['total_paid_at'] );

		try {
			if ( ! $shipping_paid ) {
				$shipping_adapter = $this->factory->get_adapter( $payment['shipping_gateway'] );
				$result           = $shipping_adapter->get_status( $payment['shipping_tx_id'] );
				$shipping_paid    = 'approved' === $result['status'];
				if ( $shipping_paid ) {
					$this->db->update(
						$this->db->prefix . 'spg_split_payments',
						array( 'shipping_paid_at' => current_time( 'mysql', true ) ),
						array( 'id' => $payment['id'] )
					);
				}
			}

			if ( ! $total_paid ) {
				$total_adapter = $this->factory->get_adapter( $payment['total_gateway'] );
				$result        = $total_adapter->get_status( $payment['total_tx_id'] );
				$total_paid    = 'approved' === $result['status'];
				if ( $total_paid ) {
					$this->db->update(
						$this->db->prefix . 'spg_split_payments',
						array( 'total_paid_at' => current_time( 'mysql', true ) ),
						array( 'id' => $payment['id'] )
					);
				}
			}
		} catch ( Exception $e ) {
			$this->log_warning( 'Error polling gateway status.', array( 'error' => $e->getMessage() ) );
		}

		return array( $shipping_paid, $total_paid );
	}

	/**
	 * Load gateway configuration for a given client and gateway name.
	 *
	 * Decrypts the stored credentials from the database.
	 *
	 * @param string $client_id    Client identifier.
	 * @param string $gateway_name Gateway slug.
	 * @return array Decrypted config array (empty if not found).
	 */
	private function get_gateway_config( $client_id, $gateway_name ) {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT credentials, qr_alias FROM `{$this->db->prefix}spg_client_gateways`
				 WHERE client_id = %s AND gateway_name = %s AND is_active = 1
				 LIMIT 1",
				$client_id,
				$gateway_name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array();
		}

		$config = array();

		if ( ! empty( $row['credentials'] ) ) {
			try {
				$decrypted = $this->decrypt( $row['credentials'] );
				$config    = json_decode( $decrypted, true ) ?: array();
			} catch ( Exception $e ) {
				$this->log_warning( 'Failed to decrypt gateway credentials.', array( 'gateway' => $gateway_name ) );
			}
		}

		// For QR Transfer, inject the alias directly.
		if ( 'qr_transfer' === $gateway_name && ! empty( $row['qr_alias'] ) ) {
			$config['alias'] = $row['qr_alias'];
		}

		return $config;
	}
}
