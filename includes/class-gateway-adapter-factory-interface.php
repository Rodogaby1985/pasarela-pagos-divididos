<?php
/**
 * Gateway Adapter Factory interface.
 * Allows test doubles to be substituted for the real factory.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

interface SPG_Gateway_Adapter_Factory_Interface {

	/**
	 * Build and return an adapter instance.
	 *
	 * @param string $name   Gateway slug.
	 * @param array  $config Decrypted gateway configuration.
	 * @return SPG_Base_Adapter
	 */
	public function get_adapter( $name, array $config = array() );

	/**
	 * Check whether a gateway is registered.
	 *
	 * @param string $name Gateway slug.
	 * @return bool
	 */
	public function has( $name );

	/**
	 * Return all registered gateway slugs.
	 *
	 * @return string[]
	 */
	public function get_registered_gateways();
}
