<?php
/**
 * Admin Dashboard page – transaction overview, reconciliation status, webhook logs.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Admin_Dashboard {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	/**
	 * Register the dashboard sub-page under WooCommerce.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Split Payment Dashboard', 'split-payment-gateway' ),
			__( 'Split Dashboard', 'split-payment-gateway' ),
			'manage_woocommerce',
			'spg-dashboard',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the dashboard page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'split-payment-gateway' ) );
		}

		global $wpdb;

		$payments      = $this->get_recent_payments( $wpdb );
		$webhook_logs  = $this->get_recent_webhook_logs( $wpdb );
		$stats         = $this->get_stats( $wpdb );

		include SPG_PLUGIN_DIR . 'admin/templates/dashboard-page.php';
	}

	// ── Data helpers ───────────────────────────────────────────────────────────

	/**
	 * Get the 50 most recent split payment records.
	 *
	 * @param wpdb $wpdb WordPress DB object.
	 * @return array
	 */
	private function get_recent_payments( $wpdb ) {
		return $wpdb->get_results(
			"SELECT sp.*, o.post_status AS order_status
			 FROM `{$wpdb->prefix}spg_split_payments` sp
			 LEFT JOIN `{$wpdb->prefix}posts` o ON sp.order_id = o.ID
			 ORDER BY sp.created_at DESC
			 LIMIT 50",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get the 50 most recent webhook log entries.
	 *
	 * @param wpdb $wpdb WordPress DB object.
	 * @return array
	 */
	private function get_recent_webhook_logs( $wpdb ) {
		return $wpdb->get_results(
			"SELECT * FROM `{$wpdb->prefix}spg_webhook_logs`
			 ORDER BY created_at DESC
			 LIMIT 50",
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get aggregate statistics for the dashboard summary cards.
	 *
	 * @param wpdb $wpdb WordPress DB object.
	 * @return array
	 */
	private function get_stats( $wpdb ) {
		$total_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}spg_split_payments`"
		);

		$completed_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}spg_split_payments` WHERE status = 'completed'"
		);

		$failed_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}spg_split_payments`
			 WHERE status IN ('failed', 'partial_failed')"
		);

		$total_revenue = (float) $wpdb->get_var(
			"SELECT SUM(shipping_amount + total_amount)
			 FROM `{$wpdb->prefix}spg_split_payments`
			 WHERE status = 'completed'"
		);

		$pending_webhooks = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}spg_webhook_logs` WHERE processed = 0"
		);

		return array(
			'total_payments'   => $total_count,
			'completed'        => $completed_count,
			'failed'           => $failed_count,
			'total_revenue'    => $total_revenue,
			'pending_webhooks' => $pending_webhooks,
		);
	}
}
