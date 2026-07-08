<?php
/**
 * Admin Settings page for Split Payment Gateway.
 * Adds a sub-menu under WooCommerce for managing client gateways and split rules.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.WP.Capabilities.Unknown

/**
 * Admin settings controller.
 */
class SPG_Admin_Settings {

	use SPG_Logger;
	use SPG_Security;

	/**
	 * Register admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_spg_save_gateway', array( $this, 'ajax_save_gateway' ) );
		add_action( 'wp_ajax_spg_delete_gateway', array( $this, 'ajax_delete_gateway' ) );
		add_action( 'wp_ajax_spg_save_rule', array( $this, 'ajax_save_rule' ) );
		add_action( 'wp_ajax_spg_delete_rule', array( $this, 'ajax_delete_rule' ) );
		add_action( 'wp_ajax_spg_save_qr_settings', array( $this, 'ajax_save_qr_settings' ) );
		add_action( 'wp_ajax_spg_save_mp_settings', array( $this, 'ajax_save_mp_settings' ) );
		add_action( 'wp_ajax_spg_verify_mp_credentials', array( $this, 'ajax_verify_mp_credentials' ) );
		add_action( 'wp_ajax_spg_create_mp_webhook', array( $this, 'ajax_create_mp_webhook' ) );
		add_action( 'wp_ajax_spg_save_qr_gateways_settings', array( $this, 'ajax_save_qr_gateways_settings' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function add_menu_pages() {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		add_submenu_page(
			'woocommerce',
			__( 'Split Payment Settings', 'split-payment-gateway' ),
			__( 'Split Payment', 'split-payment-gateway' ),
			'manage_woocommerce',
			'spg-settings',
			array( $this, 'render_settings_page' )
		);

		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		add_submenu_page(
			'woocommerce',
			__( 'Split Payment – Gateways', 'split-payment-gateway' ),
			__( 'SPG Gateways', 'split-payment-gateway' ),
			'manage_woocommerce',
			'spg-gateways',
			array( $this, 'render_gateways_page' )
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
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'spg_admin_nonce' ),
				'gateways' => $this->get_registered_gateway_list(),
				'i18n'     => array(
					'confirmDelete'  => __( 'Are you sure you want to delete this item?', 'split-payment-gateway' ),
					'saved'          => __( 'Saved successfully.', 'split-payment-gateway' ),
					'error'          => __( 'An error occurred. Please try again.', 'split-payment-gateway' ),
					'verifying'      => __( 'Verifying...', 'split-payment-gateway' ),
					'creating'       => __( 'Creating webhook...', 'split-payment-gateway' ),
					'webhookCreated' => __( 'Webhook created successfully.', 'split-payment-gateway' ),
					'webhookExists'  => __( 'Webhook already exists and is active.', 'split-payment-gateway' ),
					'credentialsOk'  => __( 'Credentials valid.', 'split-payment-gateway' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'split-payment-gateway' ) );
		}

		$client_id   = sanitize_key( get_option( 'spg_default_client_id', sanitize_key( get_option( 'blogname', 'default' ) ) ) );
		$gateways    = $this->get_client_gateways( $client_id );
		$rules       = $this->get_client_rules( $client_id );
		$qr_settings = $this->get_qr_settings();

		include SPG_PLUGIN_DIR . 'admin/templates/settings-page.php';
	}

	/**
	 * Render the dedicated Gateways configuration page.
	 */
	public function render_gateways_page() {
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Access denied.', 'split-payment-gateway' ) );
		}

		$mp_settings = $this->get_mp_settings();
		$qr_settings = $this->get_qr_gateways_settings();

		include SPG_PLUGIN_DIR . 'admin/templates/settings-page-gateways.php';
	}

	// ── AJAX handlers ──────────────────────────────────────────────────────────

	/**
	 * Save or update a gateway configuration.
	 */
	public function ajax_save_gateway() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();

		global $wpdb;
		$post_data = wp_unslash( $_POST );

		$client_id    = sanitize_key( $post_data['client_id'] ?? '' );
		$gateway_name = sanitize_key( $post_data['gateway_name'] ?? '' );
		$display_name = sanitize_text_field( $post_data['display_name'] ?? '' );
		$credentials  = $post_data['credentials'] ?? array(); // Array of key => value.
		$id           = absint( $post_data['id'] ?? 0 );

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
			'is_default_shipping' => ! empty( $post_data['is_default_shipping'] ) ? 1 : 0,
			'is_default_total'    => ! empty( $post_data['is_default_total'] ) ? 1 : 0,
			'is_active'           => 1,
			'fiscal_entity_name'  => sanitize_text_field( $post_data['fiscal_entity_name'] ?? '' ),
			'fiscal_tax_id'       => sanitize_text_field( $post_data['fiscal_tax_id'] ?? '' ),
			'fiscal_address'      => sanitize_textarea_field( $post_data['fiscal_address'] ?? '' ),
			'updated_at'          => current_time( 'mysql', true ),
		);

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->prefix . 'spg_client_gateways', $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $wpdb->prefix . 'spg_client_gateways', $data );
			$id = $wpdb->insert_id;
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Gateway saved.', 'split-payment-gateway' ),
			)
		);
	}

	/**
	 * Delete a gateway.
	 */
	public function ajax_delete_gateway() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();

		global $wpdb;
		$post_data = wp_unslash( $_POST );
		$id        = absint( $post_data['id'] ?? 0 );

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();

		global $wpdb;
		$post_data = wp_unslash( $_POST );

		$id        = absint( $post_data['id'] ?? 0 );
		$client_id = sanitize_key( $post_data['client_id'] ?? '' );

		$data = array(
			'client_id'           => $client_id,
			'rule_name'           => sanitize_text_field( $post_data['rule_name'] ?? '' ),
			'shipping_gateway'    => sanitize_key( $post_data['shipping_gateway'] ?? '' ),
			'total_gateway'       => sanitize_key( $post_data['total_gateway'] ?? '' ),
			'shipping_percentage' => min( 100, max( 0, (float) sanitize_text_field( $post_data['shipping_percentage'] ?? '100' ) ) ),
			'total_percentage'    => min( 100, max( 0, (float) sanitize_text_field( $post_data['total_percentage'] ?? '100' ) ) ),
			'priority'            => absint( $post_data['priority'] ?? 10 ),
			'is_active'           => ! empty( $post_data['is_active'] ) ? 1 : 0,
			'conditions'          => wp_json_encode( array() ),
			'updated_at'          => current_time( 'mysql', true ),
		);

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $wpdb->prefix . 'spg_client_split_rules', $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $wpdb->prefix . 'spg_client_split_rules', $data );
			$id = $wpdb->insert_id;
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Rule saved.', 'split-payment-gateway' ),
			)
		);
	}

	/**
	 * Delete a split rule.
	 */
	public function ajax_delete_rule() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();

		global $wpdb;
		$post_data = wp_unslash( $_POST );
		$id        = absint( $post_data['id'] ?? 0 );

		if ( $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'spg_client_split_rules',
				array( 'is_active' => 0 ),
				array( 'id' => $id )
			);
		}

		wp_send_json_success( array( 'message' => __( 'Rule removed.', 'split-payment-gateway' ) ) );
	}

	/**
	 * Save QR Transfer global settings.
	 */
	public function ajax_save_qr_settings() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();
		$post_data = wp_unslash( $_POST );

		$alias_subtotal = sanitize_text_field( $post_data['qr_alias_subtotal'] ?? '' );
		$alias_shipping = sanitize_text_field( $post_data['qr_alias_shipping'] ?? '' );
		$webhook_secret = sanitize_text_field( $post_data['qr_webhook_secret'] ?? '' );
		$country        = sanitize_key( $post_data['qr_country'] ?? 'AR' );

		update_option( 'spg_qr_alias_subtotal', $alias_subtotal );
		update_option( 'spg_qr_alias_shipping', $alias_shipping );
		update_option( 'spg_qr_country', strtoupper( $country ) );

		// Only update the webhook secret if a non-empty value is provided (avoid overwriting).
		if ( ! empty( $webhook_secret ) ) {
			update_option( 'spg_qr_webhook_secret', $webhook_secret );
		}

		wp_send_json_success( array( 'message' => __( 'QR Transfer settings saved.', 'split-payment-gateway' ) ) );
	}

	/**
	 * Save MercadoPago settings (from the dedicated Gateways page).
	 */
	public function ajax_save_mp_settings() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();
		$post_data = wp_unslash( $_POST );

		$enabled      = sanitize_key( $post_data['mp_enabled'] ?? 'no' );
		$sandbox      = sanitize_key( $post_data['mp_sandbox'] ?? 'yes' );
		$access_token = sanitize_text_field( $post_data['mp_access_token'] ?? '' );
		$user_id      = sanitize_text_field( $post_data['mp_user_id'] ?? '' );

		update_option( 'spg_mp_enabled', 'yes' === $enabled ? 'yes' : 'no' );
		update_option( 'spg_mp_sandbox', 'yes' === $sandbox ? 'yes' : 'no' );
		update_option( 'spg_mp_user_id', $user_id );

		// Only overwrite the stored (encrypted) token if a new non-empty value is supplied.
		if ( ! empty( $access_token ) ) {
			$encrypted = $this->encrypt( $access_token );
			update_option( 'spg_mp_access_token', $encrypted );
		}

		wp_send_json_success( array( 'message' => __( 'MercadoPago settings saved.', 'split-payment-gateway' ) ) );
	}

	/**
	 * Verify MercadoPago credentials via the API.
	 */
	public function ajax_verify_mp_credentials() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();
		$post_data = wp_unslash( $_POST );

		$access_token = sanitize_text_field( $post_data['mp_access_token'] ?? '' );
		$user_id      = sanitize_text_field( $post_data['mp_user_id'] ?? '' );

		// If no token sent, try to use the stored one.
		if ( empty( $access_token ) ) {
			$encrypted    = get_option( 'spg_mp_access_token', '' );
			$access_token = ! empty( $encrypted ) ? (string) $this->decrypt( $encrypted ) : '';
		}

		$validator = new SPG_Gateway_Credentials_Validator();
		$result    = $validator->validate_mercadopago( $access_token, $user_id );

		if ( $result['valid'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Create (or verify) a MercadoPago webhook via the API.
	 */
	public function ajax_create_mp_webhook() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();
		$post_data = wp_unslash( $_POST );

		$access_token = sanitize_text_field( $post_data['mp_access_token'] ?? '' );

		// Fall back to stored token.
		if ( empty( $access_token ) ) {
			$encrypted    = get_option( 'spg_mp_access_token', '' );
			$access_token = ! empty( $encrypted ) ? (string) $this->decrypt( $encrypted ) : '';
		}

		$validator = new SPG_Gateway_Credentials_Validator();
		$result    = $validator->create_mercadopago_webhook( $access_token );

		if ( $result['success'] ) {
			// Persist the webhook ID.
			if ( ! empty( $result['webhook_id'] ) ) {
				update_option( 'spg_mercadopago_webhook_id', $result['webhook_id'] );
			}
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Save QR Transfer settings from the dedicated Gateways page.
	 */
	public function ajax_save_qr_gateways_settings() {
		check_ajax_referer( 'spg_admin_nonce', 'nonce' );
		$this->verify_ajax_nonce();
		$post_data = wp_unslash( $_POST );

		$enabled         = sanitize_key( $post_data['qr_enabled'] ?? 'no' );
		$alias_subtotal  = sanitize_text_field( $post_data['qr_alias_subtotal'] ?? '' );
		$cbu_subtotal    = sanitize_text_field( $post_data['qr_cbu_subtotal'] ?? '' );
		$holder_subtotal = sanitize_text_field( $post_data['qr_holder_subtotal'] ?? '' );
		$alias_shipping  = sanitize_text_field( $post_data['qr_alias_shipping'] ?? '' );
		$cbu_shipping    = sanitize_text_field( $post_data['qr_cbu_shipping'] ?? '' );
		$holder_shipping = sanitize_text_field( $post_data['qr_holder_shipping'] ?? '' );
		$webhook_secret  = sanitize_text_field( $post_data['qr_webhook_secret'] ?? '' );

		update_option( 'spg_qr_enabled', 'yes' === $enabled ? 'yes' : 'no' );
		update_option( 'spg_qr_alias_subtotal', $alias_subtotal );
		update_option( 'spg_qr_cbu_subtotal', $cbu_subtotal );
		update_option( 'spg_qr_holder_subtotal', $holder_subtotal );
		update_option( 'spg_qr_alias_shipping', $alias_shipping );
		update_option( 'spg_qr_cbu_shipping', $cbu_shipping );
		update_option( 'spg_qr_holder_shipping', $holder_shipping );

		if ( ! empty( $webhook_secret ) ) {
			update_option( 'spg_qr_webhook_secret', $webhook_secret );
		}

		wp_send_json_success( array( 'message' => __( 'QR Transfer settings saved.', 'split-payment-gateway' ) ) );
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Verify the AJAX nonce and capability.
	 */
	private function verify_ajax_nonce() {
		if ( ! check_ajax_referer( 'spg_admin_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'split-payment-gateway' ) ), 403 );
		}
		// phpcs:ignore WordPress.WP.Capabilities.Unknown
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, gateway_name, display_name, is_default_shipping, is_default_total,
				        fiscal_entity_name, fiscal_tax_id, is_active
				 FROM `{$wpdb->prefix}spg_client_gateways`
				 WHERE client_id = %s
				 ORDER BY id ASC",
				$client_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get all active split rules for a client.
	 *
	 * @param string $client_id Client ID.
	 * @return array
	 */
	private function get_client_rules( $client_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}spg_client_split_rules`
				 WHERE client_id = %s
				 ORDER BY priority ASC",
				$client_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
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
			return array( 'mercadopago', 'nave', 'stripe', 'paypal', 'qr_transfer' );
		}
	}

	/**
	 * Retrieve QR Transfer settings from wp_options.
	 *
	 * @return array
	 */
	private function get_qr_settings() {
		return array(
			'qr_alias_subtotal' => get_option( 'spg_qr_alias_subtotal', '' ),
			'qr_alias_shipping' => get_option( 'spg_qr_alias_shipping', '' ),
			'qr_webhook_secret' => get_option( 'spg_qr_webhook_secret', '' ) ? '••••••••' : '',
			'qr_country'        => get_option( 'spg_qr_country', 'AR' ),
		);
	}

	/**
	 * Retrieve MercadoPago settings (for the dedicated Gateways page).
	 *
	 * @return array
	 */
	private function get_mp_settings() {
		return array(
			'enabled'      => get_option( 'spg_mp_enabled', 'no' ),
			'sandbox'      => get_option( 'spg_mp_sandbox', 'yes' ),
			'access_token' => get_option( 'spg_mp_access_token', '' ) ? '••••••••' : '',
			'user_id'      => get_option( 'spg_mp_user_id', '' ),
			'webhook_id'   => get_option( 'spg_mercadopago_webhook_id', '' ),
		);
	}

	/**
	 * Retrieve QR Transfer settings for the dedicated Gateways page.
	 *
	 * @return array
	 */
	private function get_qr_gateways_settings() {
		return array(
			'enabled'         => get_option( 'spg_qr_enabled', 'no' ),
			'alias_subtotal'  => get_option( 'spg_qr_alias_subtotal', '' ),
			'cbu_subtotal'    => get_option( 'spg_qr_cbu_subtotal', '' ),
			'holder_subtotal' => get_option( 'spg_qr_holder_subtotal', '' ),
			'alias_shipping'  => get_option( 'spg_qr_alias_shipping', '' ),
			'cbu_shipping'    => get_option( 'spg_qr_cbu_shipping', '' ),
			'holder_shipping' => get_option( 'spg_qr_holder_shipping', '' ),
			'webhook_secret'  => get_option( 'spg_qr_webhook_secret', '' ) ? '••••••••' : '',
		);
	}
}
