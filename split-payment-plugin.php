<?php
/**
 * Plugin Name:       Split Payment Gateway for WooCommerce
 * Plugin URI:        https://github.com/Rodogaby1985/pasarela-pagos-divididos
 * Description:       Pasarela de pagos agregadora multi-procesador con segregación fiscal de envíos y totales. Conecta múltiples procesadores de pago (MercadoPago, Nave, Stripe, PayPal, etc.) permitiendo rutas de pago independientes para el envío y el total de la compra.
 * Version:           1.1.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Split Payment Gateway
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       split-payment-gateway
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   8.0
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'SPG_VERSION', '1.1.0' );
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

	/** @var Split_Payment_Gateway_Plugin|null */
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
		// Traits (loaded first)
		require_once SPG_PLUGIN_DIR . 'includes/traits/trait-logger.php';
		require_once SPG_PLUGIN_DIR . 'includes/traits/trait-security.php';

		// Database
		require_once SPG_PLUGIN_DIR . 'includes/database/class-migrations.php';

		// Core adapters
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-base-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-mercadopago-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-nave-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-stripe-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-paypal-adapter.php';
		require_once SPG_PLUGIN_DIR . 'includes/adapters/class-qr-transfer-adapter.php';

		// Core services
		require_once SPG_PLUGIN_DIR . 'includes/class-gateway-adapter-factory-interface.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-gateway-adapter-factory.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-payment-routing-engine.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-split-distribution-engine.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-webhook-orchestrator.php';
		require_once SPG_PLUGIN_DIR . 'includes/class-split-payment-service.php';

		// WooCommerce Gateway
		require_once SPG_PLUGIN_DIR . 'includes/class-split-payment-gateway.php';

		// REST API
		require_once SPG_PLUGIN_DIR . 'includes/api/class-rest-api.php';

		// Admin
		if ( is_admin() ) {
			require_once SPG_PLUGIN_DIR . 'admin/class-admin-settings.php';
			require_once SPG_PLUGIN_DIR . 'admin/class-admin-dashboard.php';
		}
	}

	/**
	 * Initialise WordPress/WooCommerce hooks.
	 */
	private function init_hooks() {
		// Activation / deactivation
		register_activation_hook( SPG_PLUGIN_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( SPG_PLUGIN_FILE, array( $this, 'deactivate' ) );

		// Register WooCommerce payment gateway
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );

		// REST API
		add_action( 'rest_api_init', array( 'SPG_Rest_Api', 'register_routes' ) );

		// Admin
		if ( is_admin() ) {
			new SPG_Admin_Settings();
			new SPG_Admin_Dashboard();
		}

		// Load plugin text domain
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Enqueue frontend assets on checkout
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// HPOS (High-Performance Order Storage) compatibility declaration
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Plugin activation: run DB migrations.
	 */
	public function activate() {
		SPG_Migrations::run();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate() {
		flush_rewrite_rules();
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
	 * Enqueue frontend JS/CSS on checkout page.
	 */
	public function enqueue_frontend_assets() {
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

		// Collect registered gateways to pass to the frontend.
		$available_methods = array();
		try {
			$factory  = SPG_Gateway_Adapter_Factory::instance();
			$gateways = $factory->get_registered_gateways();
			foreach ( $gateways as $slug ) {
				$available_methods[] = array(
					'slug'  => $slug,
					'label' => $this->get_gateway_label( $slug ),
					'type'  => ( 'qr_transfer' === $slug ) ? 'qr' : 'gateway',
				);
			}
		} catch ( Exception $e ) {
			$available_methods = array();
		}

		wp_localize_script(
			'spg-modal-js',
			'spgData',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'restUrl'          => rest_url( 'spg/v1/' ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'currency'         => get_woocommerce_currency(),
				'orderReceivedUrl' => '', // Populated per-order on the checkout page.
				'availableMethods' => $available_methods,
				'qrExpirySeconds'  => SPG_QR_Transfer_Adapter::EXPIRY_SECONDS,
				'i18n'             => array(
					'payTitle'       => __( 'Complete Your Payment', 'split-payment-gateway' ),
					'shippingLabel'  => __( 'Shipping', 'split-payment-gateway' ),
					'subtotalLabel'  => __( 'Subtotal', 'split-payment-gateway' ),
					'totalLabel'     => __( 'Order Total', 'split-payment-gateway' ),
					'selectMethod'   => __( 'Select payment method:', 'split-payment-gateway' ),
					'qrInstruction'  => __( 'Scan with your banking app', 'split-payment-gateway' ),
					'qrAlias'        => __( 'Alias:', 'split-payment-gateway' ),
					'qrExpires'      => __( 'Expires in', 'split-payment-gateway' ),
					'qrExpired'      => __( 'QR expired. Refresh to get a new one.', 'split-payment-gateway' ),
					'qrRefresh'      => __( 'Refresh QR', 'split-payment-gateway' ),
					'paying'         => __( 'Processing...', 'split-payment-gateway' ),
					'paid'           => __( 'Paid', 'split-payment-gateway' ),
					'failed'         => __( 'Failed', 'split-payment-gateway' ),
					'finalize'       => __( 'Finalize Order', 'split-payment-gateway' ),
					'payShipping'    => __( 'Pay Shipping', 'split-payment-gateway' ),
					'payTotal'       => __( 'Pay Total', 'split-payment-gateway' ),
					'methodQR'       => __( 'QR Transfer', 'split-payment-gateway' ),
					'methodGateway'  => __( 'Pay with card / wallet', 'split-payment-gateway' ),
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
		return $labels[ $slug ] ?? ucfirst( str_replace( '_', ' ', $slug ) );
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
