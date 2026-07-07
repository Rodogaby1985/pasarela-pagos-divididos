<?php
/**
 * Unit tests for Split Distribution Engine.
 *
 * Run with: phpunit tests/test-split-distribution.php
 *
 * @package SplitPaymentGateway
 */

use PHPUnit\Framework\TestCase;

// ── Bootstrap ──────────────────────────────────────────────────────────────
$plugin_dir = dirname( __DIR__ );

// Traits and main class under test (bootstrap.php may have already loaded traits).
require_once $plugin_dir . '/includes/traits/trait-logger.php';
require_once $plugin_dir . '/includes/traits/trait-security.php';
require_once $plugin_dir . '/includes/class-split-distribution-engine.php';

// WordPress stubs (guarded so bootstrap.php stubs take precedence).
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ): string { return json_encode( $data ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public string $code;
		public string $message;
		public function __construct( string $code, string $message ) {
			$this->code    = $code;
			$this->message = $message;
		}
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}

// ── Test class ─────────────────────────────────────────────────────────────
class Test_Split_Distribution_Engine extends TestCase {

	private SPG_Split_Distribution_Engine $engine;

	protected function setUp(): void {
		$this->engine = new SPG_Split_Distribution_Engine();
	}

	// ── calculate() ──────────────────────────────────────────────────────────

	/** Basic 100/100 split (no rule): amounts are returned as-is. */
	public function test_calculate_full_amounts_by_default() {
		$result = $this->engine->calculate( 15.00, 100.00 );

		$this->assertSame( 15.00, $result['shipping_amount'] );
		$this->assertSame( 100.00, $result['total_amount'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	/** 50% split on both shipping and total. */
	public function test_calculate_fifty_percent_split() {
		$rule   = array( 'shipping_percentage' => 50, 'total_percentage' => 50 );
		$result = $this->engine->calculate( 20.00, 200.00, $rule, 'ARS' );

		$this->assertSame( 10.00, $result['shipping_amount'] );
		$this->assertSame( 100.00, $result['total_amount'] );
		$this->assertSame( 'ARS', $result['currency'] );
	}

	/** Percentage values are clamped between 0 and 100. */
	public function test_calculate_clamps_percentage_above_100() {
		$rule   = array( 'shipping_percentage' => 150, 'total_percentage' => -10 );
		$result = $this->engine->calculate( 30.00, 300.00, $rule );

		// Shipping clamped to 100 → full amount.
		$this->assertSame( 30.00, $result['shipping_amount'] );
		// Total clamped to 0 → zero.
		$this->assertSame( 0.00, $result['total_amount'] );
	}

	/** Currency code is normalised to uppercase. */
	public function test_calculate_currency_is_uppercase() {
		$result = $this->engine->calculate( 10, 100, array(), 'usd' );
		$this->assertSame( 'USD', $result['currency'] );
	}

	/** The breakdown array contains all expected keys. */
	public function test_calculate_breakdown_keys_present() {
		$result = $this->engine->calculate( 15, 100 );
		$keys   = array(
			'shipping_original',
			'shipping_percentage',
			'shipping_charged',
			'total_original',
			'total_percentage',
			'total_charged',
		);
		foreach ( $keys as $key ) {
			$this->assertArrayHasKey( $key, $result['breakdown'] );
		}
	}

	// ── validate_rules() ─────────────────────────────────────────────────────

	/** Valid rules pass without error. */
	public function test_validate_rules_passes_for_valid_rules() {
		$rules = array(
			array( 'rule_name' => 'Rule A', 'shipping_percentage' => 100, 'total_percentage' => 100 ),
			array( 'rule_name' => 'Rule B', 'shipping_percentage' => 50,  'total_percentage' => 75  ),
		);

		$result = $this->engine->validate_rules( $rules );
		$this->assertTrue( $result );
	}

	/** A shipping percentage > 100 returns WP_Error. */
	public function test_validate_rules_fails_for_shipping_over_100() {
		$rules  = array( array( 'rule_name' => 'Bad Rule', 'shipping_percentage' => 110, 'total_percentage' => 100 ) );
		$result = $this->engine->validate_rules( $rules );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_shipping_percentage', $result->code );
	}

	/** A total percentage < 0 returns WP_Error. */
	public function test_validate_rules_fails_for_total_below_0() {
		$rules  = array( array( 'rule_name' => 'Bad Rule', 'shipping_percentage' => 100, 'total_percentage' => -1 ) );
		$result = $this->engine->validate_rules( $rules );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_total_percentage', $result->code );
	}

	// ── calculate_refund_split() ──────────────────────────────────────────────

	/** Full refund split proportional to original charges. */
	public function test_refund_split_proportional() {
		$result = $this->engine->calculate_refund_split( 115.00, 15.00, 100.00 );

		$this->assertEqualsWithDelta( 15.00,  $result['shipping_refund'], 0.01 );
		$this->assertEqualsWithDelta( 100.00, $result['total_refund'],    0.01 );
	}

	/** Partial refund is split proportionally. */
	public function test_refund_split_partial() {
		// 15 shipping + 100 total = 115 grand total. shipping_ratio = 15/115 ≈ 0.130.
		$result = $this->engine->calculate_refund_split( 23.00, 15.00, 100.00 );

		$expected_shipping = round( 23.00 * ( 15.00 / 115.00 ), 2 );
		$expected_total    = round( 23.00 - $expected_shipping, 2 );

		$this->assertEqualsWithDelta( $expected_shipping, $result['shipping_refund'], 0.01 );
		$this->assertEqualsWithDelta( $expected_total,    $result['total_refund'],    0.01 );
	}

	/** Zero grand total returns zeroes without division by zero. */
	public function test_refund_split_zero_total_does_not_divide_by_zero() {
		$result = $this->engine->calculate_refund_split( 50.00, 0.00, 0.00 );

		$this->assertSame( 0.0, $result['shipping_refund'] );
		$this->assertSame( 0.0, $result['total_refund'] );
	}
}
