<?php
/**
 * Unit tests for Payment Routing Engine.
 *
 * Run with: phpunit tests/test-payment-routing.php
 *
 * @package SplitPaymentGateway
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal stub for wpdb that returns configurable query results.
 */
class SPG_Mock_Wpdb {
	public string $prefix = 'wp_';
	private array $query_map = array();

	public function set_result( string $pattern, $result ) {
		$this->query_map[ $pattern ] = $result;
	}

	public function prepare( string $sql, ...$args ): string {
		return vsprintf( str_replace( array( '%s', '%d', '%f' ), '%s', $sql ), $args );
	}

	public function get_row( string $sql, $output = OBJECT ) {
		foreach ( $this->query_map as $pattern => $result ) {
			if ( str_contains( $sql, $pattern ) ) {
				return $result ? (array) $result : null;
			}
		}
		return null;
	}

	public function get_results( string $sql, $output = OBJECT ): array {
		foreach ( $this->query_map as $pattern => $result ) {
			if ( str_contains( $sql, $pattern ) ) {
				return is_array( $result ) ? $result : array();
			}
		}
		return array();
	}
}

/**
 * Minimal stub for SPG_Gateway_Adapter_Factory.
 */
class SPG_Mock_Adapter_Factory implements SPG_Gateway_Adapter_Factory_Interface {
	private array $registry;

	public function __construct( array $registry = array() ) {
		$this->registry = $registry;
	}

	public function get_adapter( $name, array $config = array() ): SPG_Base_Adapter {
		throw new RuntimeException( 'get_adapter() not needed in routing tests.' );
	}

	public function has( $name ): bool {
		return isset( $this->registry[ $name ] );
	}

	public function get_registered_gateways(): array {
		return array_keys( $this->registry );
	}
}

// ── Bootstrap – load traits and class under test ───────────────────────────
$plugin_dir = dirname( __DIR__ );
require_once $plugin_dir . '/includes/traits/trait-logger.php';
require_once $plugin_dir . '/includes/traits/trait-security.php';
require_once $plugin_dir . '/includes/class-gateway-adapter-factory-interface.php';
require_once $plugin_dir . '/includes/class-payment-routing-engine.php';

// WordPress stubs (guarded for compatibility with bootstrap.php).
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}

// ── Test class ────────────────────────────────────────────────────────────

class Test_Payment_Routing_Engine extends TestCase {

	private SPG_Mock_Wpdb $db;
	private SPG_Mock_Adapter_Factory $factory;
	private SPG_Payment_Routing_Engine $engine;

	protected function setUp(): void {
		$this->db      = new SPG_Mock_Wpdb();
		$this->factory = new SPG_Mock_Adapter_Factory( array(
			'mercadopago' => true,
			'nave'        => true,
			'stripe'      => true,
		) );
		$this->engine = new SPG_Payment_Routing_Engine( $this->db, $this->factory );
	}

	/** Routing resolves via default shipping gateway when no rule exists. */
	public function test_resolve_uses_default_shipping_gateway() {
		$this->db->set_result( 'spg_client_split_rules', array() );      // no rules
		$this->db->set_result( 'is_default_shipping', array(
			'id'           => 1,
			'gateway_name' => 'nave',
			'credentials'  => $this->encrypt_creds( array( 'api_key' => 'test-key' ) ),
		) );

		$result = $this->engine->resolve( 'store_001', 'shipping', 15.0 );

		$this->assertSame( 'nave', $result['name'] );
		$this->assertArrayHasKey( 'api_key', $result['config'] );
	}

	/** Routing resolves via default total gateway. */
	public function test_resolve_uses_default_total_gateway() {
		$this->db->set_result( 'spg_client_split_rules', array() );
		$this->db->set_result( 'is_default_total', array(
			'id'           => 2,
			'gateway_name' => 'mercadopago',
			'credentials'  => $this->encrypt_creds( array( 'access_token' => 'mp-token' ) ),
		) );

		$result = $this->engine->resolve( 'store_001', 'total', 100.0 );

		$this->assertSame( 'mercadopago', $result['name'] );
	}

	/** An exception is thrown when no gateway is configured for a client. */
	public function test_resolve_throws_when_no_gateway() {
		$this->expectException( RuntimeException::class );

		$this->db->set_result( 'spg_client_split_rules', array() );
		// No default rows set → all queries return null.

		$this->engine->resolve( 'unknown_client', 'total', 50.0 );
	}

	/** Rule-based routing picks the higher-priority matching rule. */
	public function test_resolve_picks_matching_rule_over_default() {
		$this->db->set_result(
			'spg_client_split_rules',
			array(
				array(
					'id'              => 10,
					'shipping_gateway'=> 'stripe',
					'total_gateway'   => 'stripe',
					'priority'        => 5,
					'conditions'      => json_encode( array() ), // Always matches.
				),
			)
		);

		// Also simulate the stripe gateway credentials existing.
		$this->db->set_result( 'spg_client_gateways', array(
			'gateway_name' => 'stripe',
			'credentials'  => $this->encrypt_creds( array( 'secret_key' => 'sk_test' ) ),
		) );

		$result = $this->engine->resolve( 'store_001', 'shipping', 50.0 );

		$this->assertSame( 'stripe', $result['name'] );
	}

	/** Amount-range condition filters rules correctly. */
	public function test_rule_with_min_amount_condition_is_skipped_for_low_amounts() {
		$this->db->set_result(
			'spg_client_split_rules',
			array(
				array(
					'id'               => 11,
					'shipping_gateway' => 'stripe',
					'total_gateway'    => 'stripe',
					'priority'         => 1,
					'conditions'       => json_encode( array( 'min_amount' => 200 ) ),
				),
			)
		);

		// Default gateway will be returned because the rule condition doesn't match.
		$this->db->set_result( 'is_default_shipping', array(
			'id'           => 3,
			'gateway_name' => 'nave',
			'credentials'  => $this->encrypt_creds( array( 'api_key' => 'k' ) ),
		) );

		$result = $this->engine->resolve( 'store_001', 'shipping', 50.0 );

		// With amount=50 and min_amount=200, the rule should not match → fallback to default.
		$this->assertSame( 'nave', $result['name'] );
	}

	// ── Helper ────────────────────────────────────────────────────────────────

	/**
	 * Encrypt credentials using the same logic as the plugin.
	 *
	 * @param array $data Credential key/value pairs.
	 * @return string
	 */
	private function encrypt_creds( array $data ): string {
		$security = new class {
			use SPG_Security;
			public function encrypt_public( $v ) { return $this->encrypt( $v ); }
		};
		return $security->encrypt_public( json_encode( $data ) );
	}
}
