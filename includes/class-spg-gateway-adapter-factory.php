<?php
/**
 * Gateway Adapter Factory.
 * Central registry for all payment gateway adapters.
 * Adapters are registered by name and instantiated on demand.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable Generic.Commenting.DocComment.MissingShort,WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Gateway adapter factory singleton.
 */
class SPG_Gateway_Adapter_Factory implements SPG_Gateway_Adapter_Factory_Interface {

	use SPG_Logger;
	use SPG_Security;

	/** @var array<string, string> Map of gateway_name => adapter class name. */
	private static $registry = array();

	/** @var SPG_Gateway_Adapter_Factory|null */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return SPG_Gateway_Adapter_Factory
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_defaults();
		}
		return self::$instance;
	}

	/**
	 * Register a gateway adapter class.
	 *
	 * @param string $name         Gateway identifier slug (e.g. 'mercadopago').
	 * @param string $adapter_class Fully-qualified class name (must extend SPG_Base_Adapter).
	 */
	public static function register( $name, $adapter_class ) {
		self::$registry[ sanitize_key( $name ) ] = $adapter_class;
	}

	/**
	 * Build and return an adapter instance for the given gateway.
	 *
	 * @param string $name   Gateway slug.
	 * @param array  $config Decrypted gateway configuration.
	 * @return SPG_Base_Adapter
	 * @throws InvalidArgumentException If the gateway is not registered.
	 * @throws RuntimeException         If the adapter class is invalid.
	 */
	public function get_adapter( $name, array $config = array() ) {
		$name = sanitize_key( $name );

		if ( ! isset( self::$registry[ $name ] ) ) {
			throw new InvalidArgumentException( "Gateway '{$name}' is not registered." );
		}

		$class = self::$registry[ $name ];

		if ( ! class_exists( $class ) ) {
			throw new RuntimeException( "Adapter class '{$class}' not found." );
		}

		if ( ! is_subclass_of( $class, 'SPG_Base_Adapter' ) ) {
			throw new RuntimeException( "'{$class}' must extend SPG_Base_Adapter." );
		}

		return new $class( $config );
	}

	/**
	 * Check whether a given gateway is registered.
	 *
	 * @param string $name Gateway slug.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( self::$registry[ sanitize_key( $name ) ] );
	}

	/**
	 * Return all registered gateway slugs.
	 *
	 * @return string[]
	 */
	public function get_registered_gateways() {
		return array_keys( self::$registry );
	}

	/**
	 * Register built-in adapters.
	 * Third-party plugins can add more via the `spg_register_gateway_adapters` action.
	 */
	private function register_defaults() {
		self::register( 'mercadopago', 'SPG_MercadoPago_Adapter' );
		self::register( 'nave', 'SPG_Nave_Adapter' );
		self::register( 'stripe', 'SPG_Stripe_Adapter' );
		self::register( 'paypal', 'SPG_PayPal_Adapter' );
		self::register( 'qr_transfer', 'SPG_QR_Transfer_Adapter' );

		/**
		 * Fires after default adapters are registered.
		 * Third-party code can call SPG_Gateway_Adapter_Factory::register() here.
		 *
		 * @since 1.0.0
		 */
		do_action( 'spg_register_gateway_adapters' );
	}
}
