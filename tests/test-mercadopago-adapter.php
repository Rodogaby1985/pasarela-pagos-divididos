<?php
/**
 * Unit tests for SPG_MercadoPago_Adapter.
 *
 * Run with:
 *   phpunit tests/test-mercadopago-adapter.php
 *
 * Uses a testable subclass that overrides http_request() so no real HTTP
 * calls are made.  No eval() or global function redefinition required.
 *
 * @package SplitPaymentGateway
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

// ── WordPress / WooCommerce stubs not covered by bootstrap ─────────────────

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return $response['response']['code'] ?? 200;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return $response['body'] ?? '';
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value ): bool { return true; }
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) { return $default; }
}

// ── Load the adapter under test ────────────────────────────────────────────
require_once dirname( __DIR__ ) . '/includes/adapters/class-spg-mercadopago-adapter.php';

// ── Testable adapter subclass ──────────────────────────────────────────────

/**
 * Exposes http_request() for injection in tests.
 */
class SPG_MercadoPago_Adapter_Testable extends SPG_MercadoPago_Adapter {

	/** @var callable|null */
	private $http_handler = null;

	/**
	 * Set a callable to handle HTTP requests instead of making real ones.
	 *
	 * @param callable $handler function( string $url, array $args ): array
	 */
	public function set_http_handler( callable $handler ): void {
		$this->http_handler = $handler;
	}

	/**
	 * @inheritdoc
	 */
	protected function http_request( $url, array $args = array(), $retries = 2 ) {
		if ( $this->http_handler ) {
			return call_user_func( $this->http_handler, $url, $args );
		}
		// Return a safe stub so tests fail clearly if no handler is set.
		return array(
			'response' => array( 'code' => 500, 'message' => 'No HTTP handler set in test.' ),
			'body'     => '{"message":"No HTTP handler set in test."}',
		);
	}
}

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Build a fake WP HTTP response array.
 *
 * @param int   $code HTTP status code.
 * @param array $body Response body (JSON-encoded automatically).
 * @return array
 */
function spg_fake_response( int $code, array $body ): array {
	return array(
		'response' => array( 'code' => $code, 'message' => 'OK' ),
		'body'     => json_encode( $body ),
	);
}

// ── Tests ──────────────────────────────────────────────────────────────────

class Test_MercadoPago_Adapter extends TestCase {

	/** @var SPG_MercadoPago_Adapter_Testable */
	private $adapter;

	protected function setUp(): void {
		$this->adapter = new SPG_MercadoPago_Adapter_Testable( array(
			'access_token'   => 'TEST-fake-token',
			'sandbox'        => 'yes',
			'webhook_secret' => 'secret-key',
		) );
	}

	// ── initiate() ────────────────────────────────────────────────────────────

	public function test_initiate_returns_redirect_url_and_transaction_id(): void {
		$this->adapter->set_http_handler( function ( $url, $args ) {
			$this->assertStringContainsString( '/checkout/preferences', $url );
			$this->assertSame( 'POST', $args['method'] );

			return spg_fake_response( 201, array(
				'id'                 => 'pref-123',
				'init_point'         => 'https://www.mercadopago.com.ar/checkout?pref_id=pref-123',
				'sandbox_init_point' => 'https://sandbox.mercadopago.com.ar/checkout?pref_id=pref-123',
			) );
		} );

		$result = $this->adapter->initiate( array(
			'order_id'    => 'order-42',
			'amount'      => 100.00,
			'currency'    => 'ARS',
			'description' => 'Test order',
			'return_url'  => 'https://example.com/thank-you',
		) );

		$this->assertNotEmpty( $result['redirect_url'] );
		$this->assertSame( 'pref-123', $result['transaction_id'] );
		// In sandbox mode the sandbox_init_point should be preferred.
		$this->assertStringContainsString( 'sandbox', $result['redirect_url'] );
	}

	public function test_initiate_throws_on_api_error(): void {
		$this->adapter->set_http_handler( function () {
			return spg_fake_response( 401, array( 'message' => 'Unauthorized' ) );
		} );

		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/Unauthorized|401/' );

		$this->adapter->initiate( array(
			'order_id'    => 'order-1',
			'amount'      => 50.00,
			'currency'    => 'ARS',
			'description' => 'Test',
			'return_url'  => 'https://example.com/',
		) );
	}

	// ── get_status() ──────────────────────────────────────────────────────────

	public function test_get_status_returns_normalised_status(): void {
		$this->adapter->set_http_handler( function ( $url ) {
			$this->assertStringContainsString( '/v1/payments/', $url );

			return spg_fake_response( 200, array(
				'id'                 => 'pay-999',
				'status'             => 'approved',
				'transaction_amount' => 100.00,
				'external_reference' => 'order-42',
			) );
		} );

		$result = $this->adapter->get_status( 'pay-999' );

		$this->assertSame( 'approved', $result['status'] );
		$this->assertSame( 100.0, $result['amount'] );
		$this->assertSame( 'order-42', $result['reference'] );
	}

	/**
	 * @dataProvider provideStatusMappings
	 */
	public function test_get_status_normalises_various_statuses( string $raw, string $expected ): void {
		$this->adapter->set_http_handler( function () use ( $raw ) {
			return spg_fake_response( 200, array(
				'status'             => $raw,
				'transaction_amount' => 0,
				'external_reference' => '',
			) );
		} );

		$result = $this->adapter->get_status( 'tx-1' );
		$this->assertSame( $expected, $result['status'] );
	}

	/** @return array<string, array{string, string}> */
	public static function provideStatusMappings(): array {
		return array(
			'approved → approved'     => array( 'approved', 'approved' ),
			'authorized → approved'   => array( 'authorized', 'approved' ),
			'pending → pending'       => array( 'pending', 'pending' ),
			'in_process → pending'    => array( 'in_process', 'pending' ),
			'in_mediation → pending'  => array( 'in_mediation', 'pending' ),
			'rejected → rejected'     => array( 'rejected', 'rejected' ),
			'cancelled → cancelled'   => array( 'cancelled', 'cancelled' ),
			'refunded → refunded'     => array( 'refunded', 'refunded' ),
			'charged_back → refunded' => array( 'charged_back', 'refunded' ),
			'unknown → pending'       => array( 'unknown_xyz', 'pending' ),
		);
	}

	// ── refund() ──────────────────────────────────────────────────────────────

	public function test_refund_returns_success_and_refund_id(): void {
		$this->adapter->set_http_handler( function ( $url, $args ) {
			$this->assertStringContainsString( '/refunds', $url );
			$this->assertSame( 'POST', $args['method'] );

			return spg_fake_response( 201, array( 'id' => 'ref-77' ) );
		} );

		$result = $this->adapter->refund( 'pay-999', 50.00 );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'ref-77', $result['refund_id'] );
	}

	public function test_refund_returns_failure_when_no_id_returned(): void {
		$this->adapter->set_http_handler( function () {
			return spg_fake_response( 200, array( 'status' => 'error' ) );
		} );

		$result = $this->adapter->refund( 'pay-999', 10.00 );

		$this->assertFalse( $result['success'] );
	}

	// ── verify_webhook() ──────────────────────────────────────────────────────

	public function test_verify_webhook_accepts_valid_signature(): void {
		$secret  = 'secret-key';
		$payload = '{"type":"payment","data":{"id":"123"}}';
		$sig     = hash_hmac( 'sha256', $payload, $secret );

		$result = $this->adapter->verify_webhook( $payload, array( 'x-signature' => $sig ) );

		$this->assertTrue( $result );
	}

	public function test_verify_webhook_rejects_invalid_signature(): void {
		$result = $this->adapter->verify_webhook(
			'{"type":"payment"}',
			array( 'x-signature' => 'bad-signature' )
		);

		$this->assertFalse( $result );
	}

	public function test_verify_webhook_rejects_when_no_signature(): void {
		$result = $this->adapter->verify_webhook( '{"type":"payment"}', array() );
		$this->assertFalse( $result );
	}

	// ── parse_webhook() ───────────────────────────────────────────────────────

	public function test_parse_webhook_extracts_transaction_id_and_type(): void {
		$payload = json_encode( array(
			'type'   => 'payment',
			'data'   => array( 'id' => '9876' ),
			'status' => 'approved',
		) );

		$result = $this->adapter->parse_webhook( $payload );

		$this->assertSame( 'payment', $result['event_type'] );
		$this->assertSame( '9876', $result['transaction_id'] );
		$this->assertSame( 'approved', $result['status'] );
	}

	public function test_parse_webhook_returns_empty_array_on_invalid_json(): void {
		$result = $this->adapter->parse_webhook( 'not-valid-json' );
		$this->assertSame( array(), $result );
	}

	// ── create_webhook() ──────────────────────────────────────────────────────

	public function test_create_webhook_creates_new_when_none_exist(): void {
		$calls = 0;

		$this->adapter->set_http_handler( function ( $url, $args ) use ( &$calls ) {
			$calls++;
			if ( 1 === $calls ) {
				// First call: GET /v1/webhooks – empty list.
				return spg_fake_response( 200, array() );
			}
			// Second call: POST /v1/webhooks – created.
			return spg_fake_response( 201, array( 'id' => 'hook-55' ) );
		} );

		$result = $this->adapter->create_webhook();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hook-55', $result['webhook_id'] );
	}

	public function test_create_webhook_returns_existing_when_url_matches(): void {
		$notification_url = rest_url( 'spg/v1/webhooks/mercadopago' );

		$this->adapter->set_http_handler( function () use ( $notification_url ) {
			return spg_fake_response( 200, array(
				array( 'id' => 'hook-existing', 'url' => $notification_url ),
			) );
		} );

		$result = $this->adapter->create_webhook();

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'hook-existing', $result['webhook_id'] );
	}

	public function test_create_webhook_fails_when_no_access_token(): void {
		$adapter = new SPG_MercadoPago_Adapter_Testable( array() );
		$result  = $adapter->create_webhook();

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'Access Token', $result['message'] );
	}

	// ── verify_webhook_registration() ────────────────────────────────────────

	public function test_verify_webhook_registration_returns_active_when_found(): void {
		$notification_url = rest_url( 'spg/v1/webhooks/mercadopago' );

		$this->adapter->set_http_handler( function () use ( $notification_url ) {
			return spg_fake_response( 200, array(
				array( 'id' => 'hook-77', 'url' => $notification_url ),
			) );
		} );

		$result = $this->adapter->verify_webhook_registration();

		$this->assertTrue( $result['active'] );
		$this->assertSame( 'hook-77', $result['webhook_id'] );
	}

	public function test_verify_webhook_registration_returns_inactive_when_not_found(): void {
		$this->adapter->set_http_handler( function () {
			return spg_fake_response( 200, array() );
		} );

		$result = $this->adapter->verify_webhook_registration();

		$this->assertFalse( $result['active'] );
	}
}
