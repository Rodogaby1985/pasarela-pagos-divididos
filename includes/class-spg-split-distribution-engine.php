<?php
/**
 * Split Distribution Engine.
 * Calculates how payment amounts should be distributed across gateways.
 * Each split rule assigns the full shipping amount to the shipping gateway
 * and the full subtotal amount to the total gateway (100% each).
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * Split distribution calculation engine.
 */
class SPG_Split_Distribution_Engine {

	use SPG_Logger;

	/**
	 * Calculate the split distribution for an order.
	 *
	 * The shipping gateway receives the full shipping amount and the total
	 * gateway receives the full order subtotal — no partial percentages.
	 *
	 * @param float  $shipping_total  Shipping amount from the order.
	 * @param float  $order_total     Order subtotal (products only, excluding shipping).
	 * @param array  $split_rule      Active split rule row (from DB). Not used for amounts; kept for API compatibility.
	 * @param string $currency        ISO-4217 currency code.
	 * @return array {
	 *     @type float  $shipping_amount Amount to charge via the shipping gateway.
	 *     @type float  $total_amount    Amount to charge via the total/products gateway.
	 *     @type string $currency        Currency code.
	 *     @type array  $breakdown       Detailed breakdown for auditing.
	 * }
	 */
	public function calculate( $shipping_total, $order_total, array $split_rule = array(), $currency = 'USD' ) {
		$shipping_charge = round( (float) $shipping_total, 2 );
		$total_charge    = round( (float) $order_total, 2 );

		$result = array(
			'shipping_amount' => $shipping_charge,
			'total_amount'    => $total_charge,
			'currency'        => strtoupper( $currency ),
			'breakdown'       => array(
				'shipping_original' => $shipping_charge,
				'shipping_charged'  => $shipping_charge,
				'total_original'    => $total_charge,
				'total_charged'     => $total_charge,
			),
		);

		$this->log_debug( 'Split distribution calculated.', $result );

		return $result;
	}

	/**
	 * Validate that a set of split rules is internally consistent.
	 *
	 * @param array $rules Array of rule rows from the DB.
	 * @return true|WP_Error
	 */
	public function validate_rules( array $rules ) {
		foreach ( $rules as $rule ) {
			if ( empty( $rule['shipping_gateway'] ) || empty( $rule['total_gateway'] ) ) {
				return new WP_Error(
					'missing_gateway',
					sprintf(
						/* translators: %s: rule name */
						__( 'Both Shipping and Subtotal payment methods must be set for rule "%s".', 'split-payment-gateway' ),
						$rule['rule_name'] ?? ''
					)
				);
			}
		}

		return true;
	}

	/**
	 * Calculate refund amounts respecting the original split.
	 *
	 * @param float $refund_amount     Total amount to refund.
	 * @param float $shipping_charged  Original shipping charge.
	 * @param float $total_charged     Original total charge.
	 * @return array {
	 *     @type float $shipping_refund Amount to refund from the shipping gateway.
	 *     @type float $total_refund    Amount to refund from the total gateway.
	 * }
	 */
	public function calculate_refund_split( $refund_amount, $shipping_charged, $total_charged ) {
		$grand_total = $shipping_charged + $total_charged;

		if ( $grand_total <= 0 ) {
			return array(
				'shipping_refund' => 0.0,
				'total_refund'    => 0.0,
			);
		}

		$shipping_ratio = $shipping_charged / $grand_total;

		$shipping_refund = round( $refund_amount * $shipping_ratio, 2 );
		$total_refund    = round( $refund_amount - $shipping_refund, 2 );

		return array(
			'shipping_refund' => $shipping_refund,
			'total_refund'    => $total_refund,
		);
	}
}
