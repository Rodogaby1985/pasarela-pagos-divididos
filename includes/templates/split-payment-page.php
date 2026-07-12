<?php
/**
 * Full-page Split Payment Template.
 *
 * Served at /spg-payment-page/ when the customer is redirected here after
 * WooCommerce creates the order.  This page is completely independent of
 * WooCommerce's order-pay flow so no permission-validation errors can occur.
 *
 * Expected query params:
 *   spg_order_id   - WooCommerce order ID (informational only).
 *   spg_session_id - Opaque 32-char hex token created in process_payment().
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

// Both parameters are read-only display values; actual security is enforced by
// validating the session_id against a stored transient (see get_transient below).
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$order_id   = isset( $_GET['spg_order_id'] ) ? absint( $_GET['spg_order_id'] ) : 0;
$session_id = isset( $_GET['spg_session_id'] ) ? sanitize_key( $_GET['spg_session_id'] ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

// Validate session.
$session = $session_id ? get_transient( 'spg_payment_session_' . $session_id ) : false;
if ( ! $session ) {
	wp_safe_redirect( home_url( '/checkout/' ) );
	exit;
}

// Collect available payment methods from the DB + QR options.
global $wpdb;
$available_methods = array();
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$active_gateways   = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT DISTINCT gateway_name FROM `{$wpdb->prefix}spg_client_gateways` WHERE is_active = %d",
		1
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
foreach ( $active_gateways as $gw ) {
	$labels              = array(
		'mercadopago' => 'Mercado Pago',
		'nave'        => 'Nave',
		'stripe'      => 'Stripe',
		'paypal'      => 'PayPal',
		'qr_transfer' => __( 'QR Transfer', 'split-payment-gateway' ),
	);
	$available_methods[] = array(
		'slug'  => esc_attr( $gw->gateway_name ),
		'label' => isset( $labels[ $gw->gateway_name ] ) ? $labels[ $gw->gateway_name ] : ucfirst( str_replace( '_', ' ', $gw->gateway_name ) ),
		'type'  => ( 'qr_transfer' === $gw->gateway_name ) ? 'qr' : 'gateway',
	);
}

// Include QR Transfer if configured via wp_options (not in spg_client_gateways).
if ( get_option( 'spg_qr_alias_subtotal', '' ) || get_option( 'spg_qr_alias_shipping', '' ) ) {
	$qr_already = array_filter(
		$available_methods,
		function ( $m ) {
			return 'qr_transfer' === $m['slug'];
		}
	);
	if ( empty( $qr_already ) ) {
		$available_methods[] = array(
			'slug'  => 'qr_transfer',
			'label' => __( 'QR Transfer', 'split-payment-gateway' ),
			'type'  => 'qr',
		);
	}
}

$shipping_amount = (float) $session['shipping_amount'];
$total_amount    = (float) $session['total_amount'];
$currency        = sanitize_text_field( $session['currency'] );
$currency_symbol = get_woocommerce_currency_symbol( $currency );

// Localised data passed to the JS.
$spg_page_data = array(
	'restUrl'          => rest_url( 'spg/v1/' ),
	'nonce'            => wp_create_nonce( 'wp_rest' ),
	'sessionId'        => $session_id,
	'orderId'          => $order_id,
	'shippingAmount'   => $shipping_amount,
	'totalAmount'      => $total_amount,
	'currency'         => $currency,
	'currencySymbol'   => $currency_symbol,
	'availableMethods' => $available_methods,
	'qrExpirySeconds'  => class_exists( 'SPG_QR_Transfer_Adapter' ) ? SPG_QR_Transfer_Adapter::EXPIRY_SECONDS : 900,
	'i18n'             => array(
		'pageTitle'       => __( 'Complete Your Payment', 'split-payment-gateway' ),
		'subtotalLabel'   => __( 'Subtotal', 'split-payment-gateway' ),
		'shippingLabel'   => __( 'Shipping', 'split-payment-gateway' ),
		'selectMethod'    => __( 'How would you like to pay?', 'split-payment-gateway' ),
		'startPayment'    => __( 'Start Payment', 'split-payment-gateway' ),
		'payWithGateway'  => __( 'Pay with card / wallet', 'split-payment-gateway' ),
		'qrInstruction'   => __( 'Scan with your banking app', 'split-payment-gateway' ),
		'qrAlias'         => __( 'Alias:', 'split-payment-gateway' ),
		'qrAmount'        => __( 'Amount:', 'split-payment-gateway' ),
		'qrConcept'       => __( 'Concept:', 'split-payment-gateway' ),
		'qrExpires'       => __( 'Expires in', 'split-payment-gateway' ),
		'qrExpired'       => __( 'QR expired. Click refresh to get a new one.', 'split-payment-gateway' ),
		'qrRefresh'       => __( 'Refresh QR', 'split-payment-gateway' ),
		'statusPending'   => __( 'Waiting for payment…', 'split-payment-gateway' ),
		'statusPaid'      => __( '✅ Paid', 'split-payment-gateway' ),
		'statusFailed'    => __( '❌ Failed', 'split-payment-gateway' ),
		'finalizeOrder'   => __( 'Finalize Order', 'split-payment-gateway' ),
		'processing'      => __( 'Processing…', 'split-payment-gateway' ),
		'bothPaidMessage' => __( 'Both payments confirmed! Click below to complete your order.', 'split-payment-gateway' ),
		'errorInitiate'   => __( 'Could not start payment. Please try again.', 'split-payment-gateway' ),
		'errorComplete'   => __( 'Could not finalize order. Please contact support.', 'split-payment-gateway' ),
		'copied'          => __( 'Copied!', 'split-payment-gateway' ),
		'copyUnavailable' => __( 'Select the text manually to copy.', 'split-payment-gateway' ),
	),
);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> &mdash; <?php esc_html_e( 'Complete Your Payment', 'split-payment-gateway' ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="spg-payment-body">

<div class="spg-payment-page" id="spg-payment-page">

	<header class="spg-page-header">
		<div class="spg-header-inner">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<h1 class="spg-site-name">
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
				</h1>
			<?php endif; ?>
			<div class="spg-secure-badge">🔒 <?php esc_html_e( 'Secure Payment', 'split-payment-gateway' ); ?></div>
		</div>
	</header>

	<main class="spg-page-main">

		<h2 class="spg-page-title">🔒 <?php esc_html_e( 'Complete Your Payment', 'split-payment-gateway' ); ?></h2>

		<!-- Step 1: Method selection -->
		<div class="spg-step" id="spg-step-select">

			<!-- SUBTOTAL section -->
			<div class="spg-payment-section" id="spg-section-total" data-section="total">
				<div class="spg-section-header">
					<span class="spg-section-icon">🛒</span>
					<span class="spg-section-label"><?php esc_html_e( 'Subtotal', 'split-payment-gateway' ); ?></span>
					<span class="spg-section-amount" id="spg-total-amount">
						<?php echo esc_html( $currency_symbol . number_format( $total_amount, 2 ) ); ?>
					</span>
				</div>

				<p class="spg-section-subtitle"><?php esc_html_e( 'How would you like to pay?', 'split-payment-gateway' ); ?></p>

				<div class="spg-method-options" id="spg-total-methods">
					<?php foreach ( $available_methods as $method ) : ?>
					<label class="spg-method-option" id="spg-total-option-<?php echo esc_attr( $method['slug'] ); ?>">
						<input type="radio"
							name="spg-total-method"
							value="<?php echo esc_attr( $method['slug'] ); ?>"
							class="spg-method-radio">
						<span class="spg-method-icon">
							<?php echo ( 'qr' === $method['type'] ) ? '📱' : '💳'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static emoji literals ?>
						</span>
						<span class="spg-method-info">
							<strong><?php echo esc_html( $method['label'] ); ?></strong>
							<?php if ( 'qr' === $method['type'] ) : ?>
							<small><?php esc_html_e( 'Scan with your banking app – No fees', 'split-payment-gateway' ); ?></small>
							<?php else : ?>
							<small><?php esc_html_e( 'Card or digital wallet', 'split-payment-gateway' ); ?></small>
							<?php endif; ?>
						</span>
					</label>
					<?php endforeach; ?>
				</div>
			</div>

			<hr class="spg-section-divider">

			<!-- SHIPPING section -->
			<div class="spg-payment-section" id="spg-section-shipping" data-section="shipping">
				<div class="spg-section-header">
					<span class="spg-section-icon">🚚</span>
					<span class="spg-section-label"><?php esc_html_e( 'Shipping', 'split-payment-gateway' ); ?></span>
					<span class="spg-section-amount" id="spg-shipping-amount">
						<?php echo esc_html( $currency_symbol . number_format( $shipping_amount, 2 ) ); ?>
					</span>
				</div>

				<p class="spg-section-subtitle"><?php esc_html_e( 'How would you like to pay?', 'split-payment-gateway' ); ?></p>

				<div class="spg-method-options" id="spg-shipping-methods">
					<?php foreach ( $available_methods as $method ) : ?>
					<label class="spg-method-option" id="spg-shipping-option-<?php echo esc_attr( $method['slug'] ); ?>">
						<input type="radio"
							name="spg-shipping-method"
							value="<?php echo esc_attr( $method['slug'] ); ?>"
							class="spg-method-radio">
						<span class="spg-method-icon">
							<?php echo ( 'qr' === $method['type'] ) ? '📱' : '💳'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static emoji literals ?>
						</span>
						<span class="spg-method-info">
							<strong><?php echo esc_html( $method['label'] ); ?></strong>
							<?php if ( 'qr' === $method['type'] ) : ?>
							<small><?php esc_html_e( 'Scan with your banking app – No fees', 'split-payment-gateway' ); ?></small>
							<?php else : ?>
							<small><?php esc_html_e( 'Card or digital wallet', 'split-payment-gateway' ); ?></small>
							<?php endif; ?>
						</span>
					</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="spg-start-row">
				<button class="spg-btn spg-btn-primary" id="spg-start-payment" disabled>
					<?php esc_html_e( 'Start Payment', 'split-payment-gateway' ); ?>
				</button>
			</div>
		</div><!-- /#spg-step-select -->

		<!-- Step 2: Payment processing -->
		<div class="spg-step" id="spg-step-pay" style="display:none;">

			<!-- SUBTOTAL payment block -->
			<div class="spg-pay-block" id="spg-pay-block-total">
				<div class="spg-pay-block-header">
					<span class="spg-pay-block-icon">🛒</span>
					<span class="spg-pay-block-label"><?php esc_html_e( 'Subtotal', 'split-payment-gateway' ); ?></span>
					<span class="spg-pay-block-amount" id="spg-pay-total-amount"></span>
					<span class="spg-pay-block-status" id="spg-total-status-badge">⏳</span>
				</div>

				<div class="spg-pay-block-body" id="spg-pay-total-body">
					<!-- Populated by JS: either QR image or gateway button -->
				</div>

				<div class="spg-pay-block-status-text" id="spg-total-status-text">
					<?php esc_html_e( 'Waiting for payment…', 'split-payment-gateway' ); ?>
				</div>
			</div>

			<hr class="spg-section-divider">

			<!-- SHIPPING payment block -->
			<div class="spg-pay-block" id="spg-pay-block-shipping">
				<div class="spg-pay-block-header">
					<span class="spg-pay-block-icon">🚚</span>
					<span class="spg-pay-block-label"><?php esc_html_e( 'Shipping', 'split-payment-gateway' ); ?></span>
					<span class="spg-pay-block-amount" id="spg-pay-shipping-amount"></span>
					<span class="spg-pay-block-status" id="spg-shipping-status-badge">⏳</span>
				</div>

				<div class="spg-pay-block-body" id="spg-pay-shipping-body">
					<!-- Populated by JS -->
				</div>

				<div class="spg-pay-block-status-text" id="spg-shipping-status-text">
					<?php esc_html_e( 'Waiting for payment…', 'split-payment-gateway' ); ?>
				</div>
			</div>

			<!-- Summary + finalize -->
			<div class="spg-summary-row" id="spg-both-paid-notice" style="display:none;">
				<div class="spg-success-banner">
					✅ <?php esc_html_e( 'Both payments confirmed! Click below to complete your order.', 'split-payment-gateway' ); ?>
				</div>
			</div>

			<div class="spg-finalize-row">
				<button class="spg-btn spg-btn-primary spg-btn-lg" id="spg-finalize" disabled>
					🔒 <?php esc_html_e( 'Finalize Order', 'split-payment-gateway' ); ?>
				</button>
			</div>
		</div><!-- /#spg-step-pay -->

		<!-- Error / notice -->
		<div class="spg-notice" id="spg-page-notice" style="display:none;" role="alert"></div>

	</main>

	<footer class="spg-page-footer">
		<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?></p>
	</footer>

</div><!-- /#spg-payment-page -->

<script>
window.spgPageData = <?php echo wp_json_encode( $spg_page_data, JSON_HEX_TAG ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with JSON_HEX_TAG escapes <> sequences, safe for inline script. ?>;
</script>
<?php wp_footer(); ?>
</body>
</html>
