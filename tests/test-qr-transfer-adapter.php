<?php
/**
 * Unit tests for SPG_QR_Transfer_Adapter.
 *
 * @package SplitPaymentGateway
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['spg_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, $value ): bool {
		$GLOBALS['spg_test_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'Test Store';
	}
}

require_once dirname( __DIR__ ) . '/includes/class-spg-cbi-qr-generator.php';
require_once dirname( __DIR__ ) . '/includes/adapters/class-spg-qr-transfer-adapter.php';

/**
 * Minimal wpdb double for QR adapter inserts.
 */
class SPG_Test_WPDB_QR_Transfer {
	public $prefix = 'wp_';
	public $insert_calls = array();

	public function insert( $table, $data ) {
		$this->insert_calls[] = array(
			'table' => $table,
			'data'  => $data,
		);

		return true;
	}
}

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_QR_Transfer_Adapter extends TestCase {

	/** @var SPG_Test_WPDB_QR_Transfer */
	private $wpdb_double;

	protected function setUp(): void {
		global $wpdb;

		$GLOBALS['spg_test_options'] = array(
			'spg_qr_hash_secret'    => 'test-secret',
			'spg_qr_merchant_name'  => 'Mi Tienda',
			'spg_qr_merchant_city'  => 'La Plata',
			'spg_qr_psp_id'         => SPG_CBI_QR_Generator::DEFAULT_PSP_ID,
		);

		$this->wpdb_double = new SPG_Test_WPDB_QR_Transfer();
		$wpdb              = $this->wpdb_double;
	}

	public function test_initiate_returns_cbi_payload_for_argentina(): void {
		$adapter = new SPG_QR_Transfer_Adapter(
			array(
				'alias'   => 'mi.alias',
				'country' => 'AR',
			)
		);

		$result = $adapter->initiate(
			array(
				'order_id'    => 'order-42-total',
				'amount'      => 100.00,
				'currency'    => 'ARS',
				'description' => 'Orden 42',
			)
		);

		$this->assertSame( 'qr_transfer', $result['payment_type'] );
		$this->assertSame( 'cbi', $result['qr_type'] );
		$this->assertIsString( $result['qr_data'] );
		$this->assertStringStartsWith( '000201', $result['qr_data'] );
		$this->assertNotEmpty( $result['transaction_id'] );
		$this->assertCount( 1, $this->wpdb_double->insert_calls );
		$this->assertSame( $result['qr_data'], $this->wpdb_double->insert_calls[0]['data']['qr_payload'] );
	}

	public function test_initiate_requires_merchant_city_for_argentina(): void {
		$GLOBALS['spg_test_options']['spg_qr_merchant_city'] = '';

		$adapter = new SPG_QR_Transfer_Adapter(
			array(
				'alias'   => 'mi.alias',
				'country' => 'AR',
			)
		);

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Merchant city is not configured' );

		$adapter->initiate(
			array(
				'order_id'    => 'order-43-total',
				'amount'      => 100.00,
				'currency'    => 'ARS',
				'description' => 'Orden 43',
			)
		);
	}

	public function test_initiate_keeps_legacy_json_for_non_argentina(): void {
		$adapter = new SPG_QR_Transfer_Adapter(
			array(
				'alias'   => 'mi.alias',
				'country' => 'CL',
			)
		);

		$result = $adapter->initiate(
			array(
				'order_id'    => 'order-44-total',
				'amount'      => 100.00,
				'currency'    => 'CLP',
				'description' => 'Orden 44',
			)
		);

		$this->assertSame( 'json', $result['qr_type'] );
		$this->assertIsArray( $result['qr_data'] );
		$this->assertSame( 'mi.alias', $result['qr_data']['alias'] );
		$this->assertCount( 1, $this->wpdb_double->insert_calls );
		$this->assertJson( $this->wpdb_double->insert_calls[0]['data']['qr_payload'] );
	}
}
