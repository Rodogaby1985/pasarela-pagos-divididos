<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name:       Split Payment Gateway for WooCommerce
 * Plugin URI:        https://github.com/Rodogaby1985/pasarela-pagos-divididos
 * Description:       Pasarela de pagos agregadora multi-procesador con segregación fiscal de envíos y totales. Conecta múltiples procesadores de pago (MercadoPago, Nave, Stripe, PayPal, etc.) p[...]
 * Version:           1.3.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Split Payment Gateway
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       split-payment-gateway
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   8.0
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO
// Plugin constants.
define( 'SPG_VERSION', '1.3.0' );
define( 'SPG_PLUGIN_FILE', __FILE__ );
define( 'SPG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SPG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check if WooCommerce is active before loading the plugin.
 */
function spg_check_woocommerce() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'spg_woocommerce_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Admin notice for missing WooCommerce.
 */
function spg_woocommerce_missing_notice() {
	?>
	<div class="error">
		<p><?php esc_html_e( 'Split Payment Gateway requires WooCommerce to be installed and active.', 'split-payment-gateway' ); ?></p>
	</div>
	<?php
}

/**
 * Main plugin class (singleton).
 */
final class Split_Payment_Gateway_Plugin {

	/**
	 * Singleton plugin instance.
	 *
	 * @var Split_Payment_Gateway_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance of the plugin.
	 *
	 * @return Split_Payment_Gateway_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor – loads all dependencies and initialises hooks.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Load required files.
	 */
	private function load_dependencies() {
		// Traits (loaded first).
		require_once SPG_PLUGIN_DIR . 'includes/traits/trait-logger.php';
		require_once SPG_PLUGIN_DIR . 'includes/traits/trait-security.php';

		// Database.
		require_once SPG_PLUGIN_DIR . 'includes/database/class-spg-migrations.php';

		// Core adapters.
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-spg-base-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-spg-mercadopago-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-spg-nave-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-spg-stripe-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-spg-paypal-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-spg-qr-transfer-adapter.php';

		// Core services.
		require_once SPG_PLUGIN_DIR . 'includes/class-gateway-adapter-factory-interface.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-gateway-adapter-factory.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-payment-routing-engine.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-split-distribution-engine.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-webhook-orchestrator.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-split-payment-service.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-gateway-credentials-validator.php';

		// WooCommerce Gateway.
		require_once SPG_PLUGIN_DIR . 'includes/class-spg-split-payment-gateway.php';

		// REST API.
		require_once SPG_PLUGIN_DIR . 'includes/api/class-spg-rest-api.php';

		// Admin.
		if ( is_admin() ) {
			require_once SPG_PLUGIN_DIR . 'admin/class-spg-admin-settings.php';
			require_once SPG_PLUGIN_DIR . 'admin/class-spg-admin-dashboard.php';
		}
	}

	/**
	 * Initialise WordPress/WooCommerce hooks.
	 */
	private function init_hooks() {
		// Activation / deactivation.
		register_activation_hook( SPG_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SPG_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Register WooCommerce payment gateway.
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );

		// REST API.
		add_action( 'rest_api_init', array( 'SPG_Rest_Api', 'register_routes' ) );

		// Admin.
		if ( is_admin() ) {
			new SPG_Admin_Settings();
			new SPG_Admin_Dashboard();
		}

		// Load plugin text domain.
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Ensure database tables exist in every request (self-healing requirement).
		add_action( 'wp_loaded', array( $this, 'ensure_database_ready' ) );

		// Enqueue frontend assets on checkout.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// HPOS (High-Performance Order Storage) compatibility declaration.
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		// Full-page payment UI: rewrite rule + template handler.
		add_action( 'init', array( $this, 'add_payment_page_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_payment_page_query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve_payment_page' ) );
	}

	/**
	 * Plugin activation: run DB migrations.
	 */
	public function activate() {
		SPG_Migrations::run();
		// Register rewrite rule before flushing.
		$this->add_payment_page_rewrite();
		flush_rewrite_rules();
	}

	/**
	 * Ensure required DB tables exist. Re-run migrations as fallback when needed.
	 */
	public function ensure_database_ready() {
		$missing_tables = SPG_Migrations::get_missing_tables();

		if ( empty( $missing_tables ) ) {
			return;
		}

		$this->log_warning(
			'SPG missing database tables detected. Running fallback migrations.',
			array(
				'missing_tables' => $missing_tables,
			)
		);

		SPG_Migrations::run();

		$missing_after_migration = SPG_Migrations::get_missing_tables();
		if ( empty( $missing_after_migration ) ) {
			$this->log_info( 'SPG fallback migrations completed successfully.' );
			return;
		}

		$this->log_error(
			'SPG fallback migrations failed to create all required tables.',
			array(
				'missing_tables' => $missing_after_migration,
			)
		);
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		flush_rewrite_rules();
	}

	// ── Full-page payment UI ──────────────────────────────────────────────────

	/**
	 * Register the /spg-payment-page/ rewrite rule.
	 * Called on 'init' so it runs on every request.
	 */
	public function add_payment_page_rewrite() {
		add_rewrite_rule( '^spg-payment-page/?$', 'index.php?spg_payment_page=1', 'top' );
	}

	/**
	 * Register the spg_payment_page query variable with WordPress.
	 *
	 * @param array $vars Existing public query variables.
	 * @return array
	 */
	public function add_payment_page_query_var( $vars ) {
		$vars[] = 'spg_payment_page';
		return $vars;
	}

	/**
	 * Serve the full-page payment template when /spg-payment-page/ is requested.
	 * Hooked on 'template_redirect'.
	 */
	public function serve_payment_page() {
		if ( ! get_query_var( 'spg_payment_page' ) ) {
			return;
		}

		require_once SPG_PLUGIN_DIR . 'includes/templates/split-payment-page.php';
		exit;
	}

	/**
	 * Enqueue assets needed exclusively on the full-page payment UI.
	 * Called via wp_enqueue_scripts when serving /spg-payment-page/.
	 */
	public function enqueue_payment_page_assets() {
		wp_enqueue_style(
			'spg-payment-page-css',
			SPG_PLUGIN_URL . 'assets/css/split-payment-page.css',
			array(),
			SPG_VERSION
		);

		wp_enqueue_script(
			'spg-payment-page-js',
			SPG_PLUGIN_URL . 'assets/js/split-payment-page.js',
			array(),
			SPG_VERSION,
			true
		);
	}

	/**
	 * Register the custom payment gateway with WooCommerce.
	 *
	 * @param array $gateways Existing gateways.
	 * @return array
	 */
	public function register_gateway( $gateways ) {
		$gateways[] = 'SPG_Split_Payment_Gateway';
		return $gateways;
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'split-payment-gateway',
			false,
			dirname( SPG_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Enqueue frontend JS/CSS on checkout page and the full-page payment UI.
	 */
	public function enqueue_frontend_assets() {
		// Full-page payment UI (/spg-payment-page/).
		if ( get_query_var( 'spg_payment_page' ) ) {
			$this->enqueue_payment_page_assets();
			return;
		}

		if ( ! is_checkout() ) {
			return;
		}

		// Legacy modal (kept for backward compatibility).
		wp_enqueue_style(
			'spg-modal-css',
			SPG_PLUGIN_URL . 'assets/css/split-payment-modal.css',
			array(),
			SPG_VERSION
		);

		// Multi-method modal (extends legacy with QR support).
		wp_enqueue_style(
			'spg-modal-multi-css',
			SPG_PLUGIN_URL . 'assets/css/split-payment-modal-multi-method.css',
			array( 'spg-modal-css' ),
			SPG_VERSION
		);

		wp_enqueue_script(
			'spg-modal-js',
			SPG_PLUGIN_URL . 'assets/js/split-payment-modal-multi-method.js',
			array( 'jquery' ),
			SPG_VERSION,
			true
		);

		// Collect only active/configured gateways from the database.
		$available_methods = array();
		global $wpdb;
		$active_gateways = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT gateway_name FROM `{$wpdb->prefix}spg_client_gateways` WHERE is_active = %d",
				1
			)
		);
		foreach ( $active_gateways as $gw ) {
			$available_methods[] = array(
				'slug'  => $gw->gateway_name,
				'label' => $this->get_gateway_label( $gw->gateway_name ),
				'type'  => ( 'qr_transfer' === $gw->gateway_name ) ? 'qr' : 'gateway',
			);
		}

		// Also include QR Transfer if it is configured (stored in wp_options, not spg_client_gateways).
		if ( get_option( 'spg_qr_alias_subtotal', '' ) || get_option( 'spg_qr_alias_shipping', '' ) ) {
			$qr_already_added = false;
			foreach ( $available_methods as $m ) {
				if ( 'qr_transfer' === $m['slug'] ) {
					$qr_already_added = true;
					break;
				}
			}
			if ( ! $qr_already_added ) {
				$available_methods[] = array(
					'slug'  => 'qr_transfer',
					'label' => $this->get_gateway_label( 'qr_transfer' ),
					'type'  => 'qr',
				);
			}
		}

		// Resolve the current order to supply the thank-you page URL.
		$order_received_url = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id_param = isset( $_GET['spg_order_id'] ) ? absint( $_GET['spg_order_id'] ) : 0;
		if ( $order_id_param ) {
			$current_order = wc_get_order( $order_id_param );
			if ( $current_order ) {
				$order_received_url = $current_order->get_checkout_order_received_url();
			}
		}

		wp_localize_script(
			'spg-modal-js',
			'spgData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'restUrl'          => rest_url( 'spg/v1/' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'currency'         => get_woocommerce_currency(),
				'orderReceivedUrl' => $order_received_url,
				'availableMethods' => $available_methods,
				'qrExpirySeconds'  => SPG_QR_Transfer_Adapter::EXPIRY_SECONDS,
				'i18n'             => array(
					'payTitle'      => __( 'Complete Your Payment', 'split-payment-gateway' ),
					'shippingLabel' => __( 'Shipping', 'split-payment-gateway' ),
					'subtotalLabel' => __( 'Subtotal', 'split-payment-gateway' ),
					'totalLabel'    => __( 'Order Total', 'split-payment-gateway' ),
					'selectMethod'  => __( 'Select payment method:', 'split-payment-gateway' ),
					'qrInstruction' => __( 'Scan with your banking app', 'split-payment-gateway' ),
					'qrAlias'       => __( 'Alias:', 'split-payment-gateway' ),
					'qrExpires'     => __( 'Expires in', 'split-payment-gateway' ),
					'qrExpired'     => __( 'QR expired. Refresh to get a new one.', 'split-payment-gateway' ),
					'qrRefresh'     => __( 'Refresh QR', 'split-payment-gateway' ),
					'paying'        => __( 'Processing...', 'split-payment-gateway' ),
					'paid'          => __( 'Paid', 'split-payment-gateway' ),
					'failed'        => __( 'Failed', 'split-payment-gateway' ),
					'finalize'      => __( 'Finalize Order', 'split-payment-gateway' ),
					'payShipping'   => __( 'Pay Shipping', 'split-payment-gateway' ),
					'payTotal'      => __( 'Pay Total', 'split-payment-gateway' ),
					'methodQR'      => __( 'QR Transfer', 'split-payment-gateway' ),
					'methodGateway' => __( 'Pay with card / wallet', 'split-payment-gateway' ),
				),
			)
		);
	}

	/**
	 * Return a human-readable label for a gateway slug.
	 *
	 * @param string $slug Gateway slug.
	 * @return string
	 */
	private function get_gateway_label( $slug ) {
		$labels = array(
			'mercadopago' => 'Mercado Pago',
			'nave'        => 'Nave',
			'stripe'      => 'Stripe',
			'paypal'      => 'PayPal',
			'qr_transfer' => __( 'QR Transfer', 'split-payment-gateway' ),
		);
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}

		return ucfirst( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Declare compatibility with WooCommerce HPOS.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				SPG_PLUGIN_FILE,
				true
			);
		}
	}

	// ── Logger methods (delegated to SPG_Logger trait) ───────────────────────

	/**
	 * Log info message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context array.
	 */
	private function log_info( $message, $context = array() ) {
		error_log( wp_json_encode( array( 'level' => 'info', 'message' => $message, 'context' => $context ) ) );
	}

	/**
	 * Log warning message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context array.
	 */
	private function log_warning( $message, $context = array() ) {
		error_log( wp_json_encode( array( 'level' => 'warning', 'message' => $message, 'context' => $context ) ) );
	}

	/**
	 * Log error message.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context array.
	 */
	private function log_error( $message, $context = array() ) {
		error_log( wp_json_encode( array( 'level' => 'error', 'message' => $message, 'context' => $context ) ) );
	}
}

/**
 * Bootstraps the plugin after WooCommerce is confirmed to be active.
 */
function spg_init() {
	if ( spg_check_woocommerce() ) {
		Split_Payment_Gateway_Plugin::instance();
	}
}
add_action( 'plugins_loaded', 'spg_init' );
