<?php
/**
 * Split Distribution Engine.
 * Calculates how payment amounts should be distributed across gateways
 * based on configured split rules (percentages, fixed amounts, etc.).
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
	 * @param float  $shipping_total  Shipping amount from the order.
	 * @param float  $order_total     Order subtotal (products only, excluding shipping).
	 * @param array  $split_rule      Active split rule row (from DB).
	 * @param string $currency        ISO-4217 currency code.
	 * @return array {
	 *     @type float  $shipping_amount Amount to charge via the shipping gateway.
	 *     @type float  $total_amount    Amount to charge via the total/products gateway.
	 *     @type string $currency        Currency code.
	 *     @type array  $breakdown       Detailed breakdown for auditing.
	 * }
	 */
	public function calculate( $shipping_total, $order_total, array $split_rule = array(), $currency = 'USD' ) {
		$shipping_pct = isset( $split_rule['shipping_percentage'] )
			? (float) $split_rule['shipping_percentage']
			: 100.0;

		$total_pct = isset( $split_rule['total_percentage'] )
			? (float) $split_rule['total_percentage']
			: 100.0;

		// Clamp percentages to 0–100.
		$shipping_pct = max( 0.0, min( 100.0, $shipping_pct ) );
		$total_pct    = max( 0.0, min( 100.0, $total_pct ) );

		$shipping_charge = round( $shipping_total * ( $shipping_pct / 100 ), 2 );
		$total_charge    = round( $order_total * ( $total_pct / 100 ), 2 );

		$result = array(
			'shipping_amount' => $shipping_charge,
			'total_amount'    => $total_charge,
			'currency'        => strtoupper( $currency ),
			'breakdown'       => array(
				'shipping_original'   => round( $shipping_total, 2 ),
				'shipping_percentage' => $shipping_pct,
				'shipping_charged'    => $shipping_charge,
				'total_original'      => round( $order_total, 2 ),
				'total_percentage'    => $total_pct,
				'total_charged'       => $total_charge,
			),
		);

		$this->log_debug( 'Split distribution calculated.', $result );

		return $result;
	}

	/**
	 * Validate that a set of split rules is internally consistent.
	 * (Currently checks that percentages are within valid ranges.)
	 *
	 * @param array $rules Array of rule rows from the DB.
	 * @return true|WP_Error
	 */
	public function validate_rules( array $rules ) {
		foreach ( $rules as $rule ) {
			$shipping_pct = (float) ( $rule['shipping_percentage'] ?? 100 );
			$total_pct    = (float) ( $rule['total_percentage'] ?? 100 );

			if ( $shipping_pct < 0 || $shipping_pct > 100 ) {
				return new WP_Error(
					'invalid_shipping_percentage',
					sprintf(
						/* translators: %s: rule name */
						__( 'Shipping percentage must be between 0 and 100 for rule "%s".', 'split-payment-gateway' ),
						$rule['rule_name'] ?? ''
					)
				);
			}

			if ( $total_pct < 0 || $total_pct > 100 ) {
				return new WP_Error(
					'invalid_total_percentage',
					sprintf(
						/* translators: %s: rule name */
						__( 'Total percentage must be between 0 and 100 for rule "%s".', 'split-payment-gateway' ),
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
