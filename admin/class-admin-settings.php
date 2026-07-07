<?php
/**
 * Admin Settings page for Split Payment Gateway.
 * Adds a sub-menu under WooCommerce for managing client gateways and split rules.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Admin_Settings {

	use SPG_Logger;
	use SPG_Security;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spg_save_gateway', array( $this, 'ajax_save_gateway' ) );
		add_action( 'wp_ajax_spg_delete_gateway', array( $this, 'ajax_delete_gateway' ) );
		add_action( 'wp_ajax_spg_save_rule', array( $this, 'ajax_save_rule' ) );
		add_action( 'wp_ajax_spg_delete_rule', array( $this, 'ajax_delete_rule' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function add_menu_pages() {
		add_submenu_page(
			'woocommerce',
			__( 'Split Payment Settings', 'split-payment-gateway' ),
			__( 'Split Payment', 'split-payment-gateway' ),
			'manage_woocommerce',
			'spg-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin JS/CSS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'spg-' ) ) {
			return;
		}

		wp_enqueue_style(
			'spg-admin-css',
			SPG_PLUGIN_URL . 'admin/assets/css/admin-settings.css',
			array(),
			SPG_VERSION
		);

		wp_enqueue_script(
			'spg-admin-js',
			SPG_PLUGIN_URL . 'admin/assets/js/admin-settings.js',
			array( 'jquery' ),
			SPG_VERSION,
			true
		);

		wp_localize_script(
			'spg-admin-js',
			'spgAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'spg_admin_nonce' ),
				'gateways'  => $this->get_registered_gateway_list(),
				'i18n'      => array(
					'confirmDelete' => __( 'Are you sure you want to delete this item?', 'split-payment-gateway' ),
					'saved'         => __( 'Saved successfully.', 'split-payment-gateway' ),
					'error'         => __( 'An error occurred. Please try again.', 'split-payment-gateway' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'split-payment-gateway' ) );
		}

		$client_id = sanitize_key( get_option( 'spg_default_client_id', sanitize_key( get_option( 'blogname', 'default' ) ) ) );
		$gateways  = $this->get_client_gateways( $client_id );
		$rules     = $this->get_client_rules( $client_id );

		include SPG_PLUGIN_DIR . 'admin/templates/settings-page.php';
	}

	// ── AJAX handlers ──────────────────────────────────────────────────────────

	/**
	 * Save or update a gateway configuration.
	 */
	public function ajax_save_gateway() {
		$this->verify_ajax_nonce();

		global $wpdb;

		$client_id    = sanitize_key( $_POST['client_id'] ?? '' );
		$gateway_name = sanitize_key( $_POST['gateway_name'] ?? '' );
		$display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
		$credentials  = $_POST['credentials'] ?? array(); // Array of key => value.
		$id           = absint( $_POST['id'] ?? 0 );

		if ( ! $client_id || ! $gateway_name ) {
			wp_send_json_error( array( 'message' => __( 'Missing required fields.', 'split-payment-gateway' ) ) );
		}

		// Sanitize credential values and encrypt.
		$clean_credentials = array();
		foreach ( $credentials as $k => $v ) {
			$clean_credentials[ sanitize_key( $k ) ] = sanitize_text_field( $v );
		}

		$encrypted = $this->encrypt( wp_json_encode( $clean_credentials ) );

		$data = array(
			'client_id'           => $client_id,
			'gateway_name'        => $gateway_name,
			'display_name'        => $display_name,
			'credentials'         => $encrypted,
			'is_default_shipping' => ! empty( $_POST['is_default_shipping'] ) ? 1 : 0,
			'is_default_total'    => ! empty( $_POST['is_default_total'] )    ? 1 : 0,
			'is_active'           => 1,
			'fiscal_entity_name'  => sanitize_text_field( $_POST['fiscal_entity_name'] ?? '' ),
			'fiscal_tax_id'       => sanitize_text_field( $_POST['fiscal_tax_id'] ?? '' ),
			'fiscal_address'      => sanitize_textarea_field( $_POST['fiscal_address'] ?? '' ),
			'updated_at'          => current_time( 'mysql', true ),
		);

		if ( $id ) {
			$wpdb->update( $wpdb->prefix . 'spg_client_gateways', $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $wpdb->prefix . 'spg_client_gateways', $data );
			$id = $wpdb->insert_id;
		}

		wp_send_json_success( array( 'id' => $id, 'message' => __( 'Gateway saved.', 'split-payment-gateway' ) ) );
	}

	/**
	 * Delete a gateway.
	 */
	public function ajax_delete_gateway() {
		$this->verify_ajax_nonce();

		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );

		if ( $id ) {
			$wpdb->update(
				$wpdb->prefix . 'spg_client_gateways',
				array( 'is_active' => 0 ),
				array( 'id' => $id )
			);
		}

		wp_send_json_success( array( 'message' => __( 'Gateway removed.', 'split-payment-gateway' ) ) );
	}

	/**
	 * Save or update a split rule.
	 */
	public function ajax_save_rule() {
		$this->verify_ajax_nonce();

		global $wpdb;

		$id        = absint( $_POST['id'] ?? 0 );
		$client_id = sanitize_key( $_POST['client_id'] ?? '' );

		$data = array(
			'client_id'           => $client_id,
			'rule_name'           => sanitize_text_field( $_POST['rule_name'] ?? '' ),
			'shipping_gateway'    => sanitize_key( $_POST['shipping_gateway'] ?? '' ),
			'total_gateway'       => sanitize_key( $_POST['total_gateway'] ?? '' ),
			'shipping_percentage' => min( 100, max( 0, (float) ( $_POST['shipping_percentage'] ?? 100 ) ) ),
			'total_percentage'    => min( 100, max( 0, (float) ( $_POST['total_percentage'] ?? 100 ) ) ),
			'priority'            => absint( $_POST['priority'] ?? 10 ),
			'is_active'           => ! empty( $_POST['is_active'] ) ? 1 : 0,
			'conditions'          => wp_json_encode( array() ),
			'updated_at'          => current_time( 'mysql', true ),
		);

		if ( $id ) {
			$wpdb->update( $wpdb->prefix . 'spg_client_split_rules', $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $wpdb->prefix . 'spg_client_split_rules', $data );
			$id = $wpdb->insert_id;
		}

		wp_send_json_success( array( 'id' => $id, 'message' => __( 'Rule saved.', 'split-payment-gateway' ) ) );
	}

	/**
	 * Delete a split rule.
	 */
	public function ajax_delete_rule() {
		$this->verify_ajax_nonce();

		global $wpdb;
		$id = absint( $_POST['id'] ?? 0 );

		if ( $id ) {
			$wpdb->update(
				$wpdb->prefix . 'spg_client_split_rules',
				array( 'is_active' => 0 ),
				array( 'id' => $id )
			);
		}

		wp_send_json_success( array( 'message' => __( 'Rule removed.', 'split-payment-gateway' ) ) );
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Verify the AJAX nonce and capability.
	 */
	private function verify_ajax_nonce() {
		if ( ! check_ajax_referer( 'spg_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'split-payment-gateway' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'split-payment-gateway' ) ), 403 );
		}
	}

	/**
	 * Get all active gateways for a client.
	 *
	 * @param string $client_id Client ID.
	 * @return array
	 */
	private function get_client_gateways( $client_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, gateway_name, display_name, is_default_shipping, is_default_total,
				        fiscal_entity_name, fiscal_tax_id, is_active
				 FROM `{$wpdb->prefix}spg_client_gateways`
				 WHERE client_id = %s
				 ORDER BY id ASC",
				$client_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get all active split rules for a client.
	 *
	 * @param string $client_id Client ID.
	 * @return array
	 */
	private function get_client_rules( $client_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}spg_client_split_rules`
				 WHERE client_id = %s
				 ORDER BY priority ASC",
				$client_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Return the list of registered gateway slugs for the admin UI.
	 *
	 * @return string[]
	 */
	private function get_registered_gateway_list() {
		try {
			return SPG_Gateway_Adapter_Factory::instance()->get_registered_gateways();
		} catch ( Exception $e ) {
			return array( 'mercadopago', 'nave', 'stripe', 'paypal' );
		}
	}
}
