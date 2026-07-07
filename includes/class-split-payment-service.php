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
	 * @param WC_Order $order     WooCommerce order.
	 * @param string   $client_id Store/client identifier.
	 * @return array {
	 *     @type string $shipping_payment_url  URL to pay for shipping.
	 *     @type string $total_payment_url     URL to pay for products.
	 *     @type string $shipping_gateway      Gateway name for shipping.
	 *     @type string $total_gateway         Gateway name for total.
	 *     @type string $session_id            Internal tracking token.
	 * }
	 * @throws Exception On any failure.
	 */
	public function initiate( WC_Order $order, $client_id ) {
		$order_id         = $order->get_id();
		$shipping_amount  = (float) $order->get_shipping_total();
		$order_subtotal   = (float) $order->get_subtotal(); // Products only, excluding shipping.
		$currency         = $order->get_currency();
		$return_url       = $order->get_checkout_order_received_url();

		$context = array( 'currency' => $currency );

		// Resolve gateways.
		$shipping_gw = $this->router->resolve( $client_id, 'shipping', $shipping_amount, $context );
		$total_gw    = $this->router->resolve( $client_id, 'total',    $order_subtotal,  $context );

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
				'order_id'         => $order_id,
				'client_id'        => sanitize_text_field( $client_id ),
				'shipping_gateway' => $shipping_gw['name'],
				'total_gateway'    => $total_gw['name'],
				'shipping_tx_id'   => $shipping_result['transaction_id'],
				'total_tx_id'      => $total_result['transaction_id'],
				'shipping_amount'  => $shipping_amount,
				'total_amount'     => $order_subtotal,
				'currency'         => $currency,
				'status'           => 'initiated',
				'metadata'         => wp_json_encode( array( 'session_id' => $session_id ) ),
				'created_at'       => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			)
		);

		// Store session_id on the order meta for later cross-reference.
		$order->update_meta_data( '_spg_session_id',      $session_id );
		$order->update_meta_data( '_spg_shipping_tx_id',  $shipping_result['transaction_id'] );
		$order->update_meta_data( '_spg_total_tx_id',     $total_result['transaction_id'] );
		$order->update_meta_data( '_spg_shipping_gateway', $shipping_gw['name'] );
		$order->update_meta_data( '_spg_total_gateway',    $total_gw['name'] );
		$order->save();

		$order->update_status(
			'pending',
			__( 'Split Payment initiated – awaiting shipping and product payments.', 'split-payment-gateway' )
		);

		$this->log_info( 'Split payment initiated.', array( 'order_id' => $order_id ) );

		return array(
			'shipping_payment_url' => $shipping_result['redirect_url'],
			'total_payment_url'    => $total_result['redirect_url'],
			'shipping_gateway'     => $shipping_gw['name'],
			'total_gateway'        => $total_gw['name'],
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
}
