<?php
/**
 * Payment Routing Engine.
 * Decides which gateway to use for each payment type (shipping / total)
 * for a given client, based on configured rules and real-time statistics.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Payment_Routing_Engine {

	use SPG_Logger;

	/** @var wpdb WordPress database object. */
	private $db;

	/** @var SPG_Gateway_Adapter_Factory_Interface */
	private $factory;

	/**
	 * @param wpdb                                   $db      WordPress DB object.
	 * @param SPG_Gateway_Adapter_Factory_Interface  $factory Adapter factory.
	 */
	public function __construct( $db, SPG_Gateway_Adapter_Factory_Interface $factory ) {
		$this->db      = $db;
		$this->factory = $factory;
	}

	/**
	 * Determine the best gateway for a payment type.
	 *
	 * Resolution order:
	 *  1. Active split rule for the client that matches the context.
	 *  2. Default gateway marked in the client gateway table.
	 *  3. Any active gateway for the client.
	 *
	 * @param string $client_id    Client/store identifier.
	 * @param string $payment_type 'shipping' or 'total'.
	 * @param float  $amount       Payment amount (used for rule evaluation).
	 * @param array  $context      Optional additional context (currency, country, etc.).
	 * @return array {
	 *     @type string $name   Gateway slug.
	 *     @type array  $config Decrypted gateway configuration.
	 * }
	 * @throws RuntimeException When no gateway is available.
	 */
	public function resolve( $client_id, $payment_type, $amount = 0.0, array $context = array() ) {
		// 1. Try rule-based routing.
		$rule_gateway = $this->resolve_via_rules( $client_id, $payment_type, $amount, $context );
		if ( $rule_gateway ) {
			return $rule_gateway;
		}

		// 2. Fallback to the default gateway.
		$default_gateway = $this->resolve_default( $client_id, $payment_type );
		if ( $default_gateway ) {
			return $default_gateway;
		}

		// 3. Fallback to any active gateway.
		$any_gateway = $this->resolve_any_active( $client_id );
		if ( $any_gateway ) {
			return $any_gateway;
		}

		throw new RuntimeException(
			"No active gateway found for client '{$client_id}' / payment type '{$payment_type}'."
		);
	}

	/**
	 * Resolve gateway using the client's configured split rules.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $payment_type 'shipping' or 'total'.
	 * @param float  $amount       Payment amount.
	 * @param array  $context      Context data.
	 * @return array|null
	 */
	private function resolve_via_rules( $client_id, $payment_type, $amount, array $context ) {
		$col = 'shipping' === $payment_type ? 'shipping_gateway' : 'total_gateway';

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM `{$this->db->prefix}spg_client_split_rules`
				 WHERE client_id = %s AND is_active = 1
				 ORDER BY priority ASC",
				$client_id
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			if ( $this->rule_matches( $row, $payment_type, $amount, $context ) ) {
				$gateway_name = $row[ $col ] ?? '';
				if ( $gateway_name && $this->factory->has( $gateway_name ) ) {
					$config = $this->get_gateway_config( $client_id, $gateway_name );
					if ( $config ) {
						return array( 'name' => $gateway_name, 'config' => $config );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Resolve default gateway for a payment type.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $payment_type 'shipping' or 'total'.
	 * @return array|null
	 */
	private function resolve_default( $client_id, $payment_type ) {
		if ( 'shipping' === $payment_type ) {
			$row = $this->db->get_row(
				$this->db->prepare(
					"SELECT * FROM `{$this->db->prefix}spg_client_gateways`
					 WHERE client_id = %s AND is_default_shipping = 1 AND is_active = 1
					 LIMIT 1",
					$client_id
				),
				ARRAY_A
			);
		} else {
			$row = $this->db->get_row(
				$this->db->prepare(
					"SELECT * FROM `{$this->db->prefix}spg_client_gateways`
					 WHERE client_id = %s AND is_default_total = 1 AND is_active = 1
					 LIMIT 1",
					$client_id
				),
				ARRAY_A
			);
		}

		if ( ! $row ) {
			return null;
		}

		$gateway_name = $row['gateway_name'];
		$config       = $this->decrypt_credentials( $row['credentials'] );

		return array( 'name' => $gateway_name, 'config' => $config );
	}

	/**
	 * Resolve any active gateway as last resort.
	 *
	 * @param string $client_id Client ID.
	 * @return array|null
	 */
	private function resolve_any_active( $client_id ) {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM `{$this->db->prefix}spg_client_gateways`
				 WHERE client_id = %s AND is_active = 1
				 ORDER BY id ASC LIMIT 1",
				$client_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return array(
			'name'   => $row['gateway_name'],
			'config' => $this->decrypt_credentials( $row['credentials'] ),
		);
	}

	/**
	 * Fetch decrypted gateway config for a client.
	 *
	 * @param string $client_id    Client ID.
	 * @param string $gateway_name Gateway slug.
	 * @return array|null
	 */
	private function get_gateway_config( $client_id, $gateway_name ) {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT credentials FROM `{$this->db->prefix}spg_client_gateways`
				 WHERE client_id = %s AND gateway_name = %s AND is_active = 1
				 LIMIT 1",
				$client_id,
				$gateway_name
			),
			ARRAY_A
		);

		return $row ? $this->decrypt_credentials( $row['credentials'] ) : null;
	}

	/**
	 * Evaluate whether a rule applies to the current payment context.
	 *
	 * @param array  $rule         Rule row from DB.
	 * @param string $payment_type 'shipping' or 'total'.
	 * @param float  $amount       Payment amount.
	 * @param array  $context      Additional context.
	 * @return bool
	 */
	private function rule_matches( array $rule, $payment_type, $amount, array $context ) {
		$conditions = ! empty( $rule['conditions'] ) ? json_decode( $rule['conditions'], true ) : array();

		if ( empty( $conditions ) ) {
			return true; // No conditions → always matches.
		}

		// Amount range check.
		if ( isset( $conditions['min_amount'] ) && $amount < (float) $conditions['min_amount'] ) {
			return false;
		}
		if ( isset( $conditions['max_amount'] ) && $amount > (float) $conditions['max_amount'] ) {
			return false;
		}

		// Currency check.
		if ( isset( $conditions['currency'] ) && ! empty( $context['currency'] ) ) {
			if ( strtoupper( $context['currency'] ) !== strtoupper( $conditions['currency'] ) ) {
				return false;
			}
		}

		// Country check.
		if ( isset( $conditions['country'] ) && ! empty( $context['country'] ) ) {
			if ( strtoupper( $context['country'] ) !== strtoupper( $conditions['country'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Decrypt gateway credentials stored in the DB.
	 *
	 * @param string $encrypted_json Encrypted JSON string.
	 * @return array
	 */
	private function decrypt_credentials( $encrypted_json ) {
		// Use the Security trait via a temporary closure (trait is mixed in elsewhere).
		$security = new class {
			use SPG_Security;

			public function decrypt_public( $data ) {
				return $this->decrypt( $data );
			}
		};

		$json = $security->decrypt_public( $encrypted_json );
		$data = json_decode( $json, true );

		return is_array( $data ) ? $data : array();
	}
}
