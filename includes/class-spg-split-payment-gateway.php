<?php
/**
 * WooCommerce Split Payment Gateway.
 * Registered with WooCommerce as a custom payment method.
 * Overrides the standard checkout flow to show the split-payment modal.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable Generic.Commenting.DocComment.MissingShort

/**
 * WooCommerce split payment gateway.
 */
class SPG_Split_Payment_Gateway extends WC_Payment_Gateway {

	use SPG_Logger;

	/** @var SPG_Split_Payment_Service */
	private $service;

	/**
	 * Constructor – set gateway properties and initialise settings.
	 */
	public function __construct() {
		$this->id                 = 'split_payment_gateway';
		$this->icon               = apply_filters( 'spg_gateway_icon', SPG_PLUGIN_URL . 'assets/images/gateway-icon.png' );
		$this->has_fields         = true;
		$this->method_title       = __( 'Split Payment Gateway', 'split-payment-gateway' );
		$this->method_description = __( 'Pay shipping and products via independent payment processors.', 'split-payment-gateway' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		// Load settings fields.
		$this->init_form_fields();
		$this->init_settings();

		// Assign settings to properties.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->client_id   = $this->get_option( 'client_id', get_option( 'blogname' ) );

		// Save settings hook.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Define admin settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'split-payment-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Split Payment Gateway', 'split-payment-gateway' ),
				'default' => 'yes',
			),
			'title'       => array(
				'title'       => __( 'Title', 'split-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Title shown to customers during checkout.', 'split-payment-gateway' ),
				'default'     => __( 'Split Payment', 'split-payment-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'split-payment-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Description shown to customers during checkout.', 'split-payment-gateway' ),
				'default'     => __( 'Pay your shipping and order total separately using the payment method of your choice.', 'split-payment-gateway' ),
			),
			'client_id'   => array(
				'title'       => __( 'Client ID', 'split-payment-gateway' ),
				'type'        => 'text',
				'description' => __( 'Unique identifier for this store (used for gateway routing).', 'split-payment-gateway' ),
				'default'     => sanitize_key( get_option( 'blogname', 'default' ) ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Output additional checkout fields (shows the split-payment modal placeholder).
	 */
	public function payment_fields() {
		echo '<div id="spg-payment-fields">';
		if ( $this->description ) {
			echo '<p>' . wp_kses_post( $this->description ) . '</p>';
		}
		echo '<div id="spg-modal-trigger-area"></div>';
		echo '</div>';
	}

	/**
	 * Process the payment for an order.
	 *
	 * Instead of immediately initiating the split payment (which caused double-initiation
	 * issues and required redirecting to WooCommerce's order-pay page), this method creates
	 * a payment session and redirects to our own full-page payment UI. This bypasses
	 * WooCommerce's order-pay permission validation entirely.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array WooCommerce result array.
	 */
	public function process_payment( $order_id ) {
		$order     = wc_get_order( $order_id );
		$client_id = $this->get_option( 'client_id', sanitize_key( get_option( 'blogname', 'default' ) ) );

		if ( ! $order ) {
			wc_add_notice(
				__( 'Payment could not be initiated. Please try again.', 'split-payment-gateway' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		// Create a lightweight session token. The actual payment initiation (adapter calls,
		// DB records) happens only once the customer selects their payment methods on the
		// dedicated payment page. This prevents double-initiation.
		$session_id = bin2hex( random_bytes( 16 ) );
		set_transient(
			'spg_payment_session_' . $session_id,
			array(
				'order_id'        => $order_id,
				'client_id'       => $client_id,
				'shipping_amount' => $order->get_shipping_total(),
				'total_amount'    => $order->get_subtotal(),
				'currency'        => $order->get_currency(),
			),
			30 * MINUTE_IN_SECONDS
		);

		// Reduce order stock and empty the cart early (same as WooCommerce expects).
		wc_reduce_stock_levels( $order_id );
		WC()->cart->empty_cart();

		// Redirect to our full-page payment UI, bypassing WooCommerce's order-pay
		// validation which causes "this order is not valid" errors.
		$redirect_url = add_query_arg(
			array(
				'spg_order_id'   => $order_id,
				'spg_session_id' => $session_id,
			),
			home_url( '/spg-payment-page/' )
		);

		return array(
			'result'   => 'success',
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Process a refund request from WooCommerce.
	 *
	 * @param int        $order_id WooCommerce order ID.
	 * @param float|null $amount   Amount to refund (null for full refund).
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		try {
			$service = $this->get_service();
			$result  = $service->refund( $order_id, (float) $amount );

			if ( $result['shipping_refunded'] && $result['total_refunded'] ) {
				return true;
			}

			return new WP_Error(
				'spg_refund_partial',
				__( 'Partial refund: not all gateways confirmed the refund.', 'split-payment-gateway' )
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'spg_refund_error', $e->getMessage() );
		}
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Lazily build and return the SPG_Split_Payment_Service.
	 *
	 * @return SPG_Split_Payment_Service
	 */
	private function get_service() {
		if ( $this->service ) {
			return $this->service;
		}

		global $wpdb;

		$factory      = SPG_Gateway_Adapter_Factory::instance();
		$router       = new SPG_Payment_Routing_Engine( $wpdb, $factory );
		$distribution = new SPG_Split_Distribution_Engine();

		$this->service = new SPG_Split_Payment_Service( $wpdb, $router, $factory, $distribution );

		return $this->service;
	}
}
