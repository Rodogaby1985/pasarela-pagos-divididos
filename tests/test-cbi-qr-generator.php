<?php
/**
 * Unit tests for SPG_CBI_QR_Generator.
 *
 * @package SplitPaymentGateway
 */

require_once dirname( __DIR__ ) . '/includes/class-spg-cbi-qr-generator.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests for CBI QR payload generation.
 */
class Test_CBI_QR_Generator extends TestCase {

	// ── TLV structure tests ─────────────────────────────────────────────────

	/**
	 * A generated payload must start with the Payload Format Indicator "000201".
	 */
	public function test_payload_starts_with_format_indicator() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'Buenos Aires' );
		$this->assertStringStartsWith( '000201', $payload );
	}

	/**
	 * A generated payload must contain the Point of Initiation field (tag 01) with value "12" (dynamic).
	 */
	public function test_payload_contains_point_of_initiation() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'Buenos Aires' );
		$this->assertStringContainsString( '010212', $payload );
	}

	/**
	 * A generated payload must contain tag 26 (Merchant Account Info).
	 */
	public function test_payload_contains_merchant_account_info_tag() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'Buenos Aires' );
		$this->assertStringContainsString( '26', $payload );
	}

	/**
	 * A generated payload must contain the country code field (tag 58) with the given country.
	 */
	public function test_payload_contains_country_code() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'Buenos Aires' );
		$this->assertStringContainsString( '5802AR', $payload );
	}

	/**
	 * A generated payload must end with the CRC tag (63) + 4-char hex value.
	 */
	public function test_payload_ends_with_crc_tag() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'Buenos Aires' );
		// Must match: ...6304<4 hex chars>
		$this->assertRegExp( '/6304[0-9A-F]{4}$/', $payload );
	}

	/**
	 * The CRC at the end of the payload must validate correctly.
	 */
	public function test_crc_checksum_is_valid() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'Buenos Aires' );

		// Strip the last 4 hex chars (the CRC value) and keep "6304" prefix.
		$payload_without_crc_value = substr( $payload, 0, -4 );
		$crc_hex                   = substr( $payload, -4 );
		$expected_crc              = SPG_CBI_QR_Generator::crc16( $payload_without_crc_value );

		$this->assertEquals(
			strtoupper( sprintf( '%04X', $expected_crc ) ),
			strtoupper( $crc_hex ),
			'CRC16 checksum in payload does not match recomputed value.'
		);
	}

	// ── Amount encoding tests ───────────────────────────────────────────────

	/**
	 * Amount must be encoded with two decimal places and included in tag 54.
	 */
	public function test_amount_is_encoded_correctly() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 150000.00, 'Store', 'La Plata' );
		// Tag 54, len 09, val "150000.00"
		$this->assertStringContainsString( '5409150000.00', $payload );
	}

	/**
	 * Small amounts are also formatted correctly.
	 */
	public function test_small_amount_is_formatted_correctly() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 1.50, 'Store', 'Rosario' );
		$this->assertStringContainsString( '54041.50', $payload );
	}

	// ── Identifier type tests ───────────────────────────────────────────────

	/**
	 * A 22-digit numeric string not starting with "000" should be identified as CBU.
	 */
	public function test_identifier_type_cbu() {
		$cbu  = '1234567890123456789012'; // 22 digits, no "000" prefix
		$type = SPG_CBI_QR_Generator::get_identifier_type( $cbu );
		$this->assertEquals( 'CBU', $type );
	}

	/**
	 * A 22-digit numeric string starting with "000" should be identified as CVU.
	 */
	public function test_identifier_type_cvu() {
		$cvu  = '0001234567890123456789'; // 22 digits, starts with "000"
		$type = SPG_CBI_QR_Generator::get_identifier_type( $cvu );
		$this->assertEquals( 'CVU', $type );
	}

	/**
	 * A non-numeric or non-22-digit string should be identified as ALIAS.
	 */
	public function test_identifier_type_alias() {
		$this->assertEquals( 'ALIAS', SPG_CBI_QR_Generator::get_identifier_type( 'mi.alias' ) );
		$this->assertEquals( 'ALIAS', SPG_CBI_QR_Generator::get_identifier_type( 'mobapp.2' ) );
		$this->assertEquals( 'ALIAS', SPG_CBI_QR_Generator::get_identifier_type( 'tienda.mp' ) );
	}

	/**
	 * CBU identifier is encoded inside tag 26 content.
	 */
	public function test_cbu_type_is_in_payload() {
		$cbu     = '2850590940090418135201';
		$payload = SPG_CBI_QR_Generator::generate( $cbu, 500.00, 'Store', 'Cordoba' );
		$this->assertStringContainsString( 'CBU', $payload );
	}

	/**
	 * ALIAS identifier is encoded inside tag 26 content.
	 */
	public function test_alias_type_is_in_payload() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.tienda', 200.00, 'Store', 'Mendoza' );
		$this->assertStringContainsString( 'ALIAS', $payload );
	}

	// ── Merchant name / city tests ──────────────────────────────────────────

	/**
	 * Merchant name must appear in tag 59.
	 */
	public function test_merchant_name_is_encoded() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Mi Tienda', 'La Plata' );
		$this->assertStringContainsString( '59', $payload );
		$this->assertStringContainsString( 'Mi Tienda', $payload );
	}

	/**
	 * Merchant city must appear in tag 60.
	 */
	public function test_merchant_city_is_encoded() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Mi Tienda', 'La Plata' );
		$this->assertStringContainsString( '60', $payload );
		$this->assertStringContainsString( 'La Plata', $payload );
	}

	/**
	 * Merchant name exceeding 25 characters is truncated.
	 */
	public function test_merchant_name_truncated_to_25_chars() {
		$long_name = 'Supermercado El Mayorista SRL';  // 29 chars
		$payload   = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, $long_name, 'CABA' );
		// Only first 25 chars should appear.
		$truncated = substr( $long_name, 0, 25 );
		$this->assertStringContainsString( $truncated, $payload );
		$this->assertStringNotContainsString( $long_name, $payload );
	}

	/**
	 * Merchant city exceeding 15 characters is truncated.
	 */
	public function test_merchant_city_truncated_to_15_chars() {
		$long_city = 'San Carlos de Bariloche'; // 23 chars
		$payload   = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', $long_city );
		$truncated = substr( $long_city, 0, 15 );
		$this->assertStringContainsString( $truncated, $payload );
		$this->assertStringNotContainsString( $long_city, $payload );
	}

	// ── PSP ID tests ────────────────────────────────────────────────────────

	/**
	 * Default PSP ID (00000031 for Red Link) is included in tag 26.
	 */
	public function test_default_psp_id_is_encoded() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'CABA' );
		$this->assertStringContainsString( '00000031', $payload );
	}

	/**
	 * Custom PSP ID is encoded when explicitly provided.
	 */
	public function test_custom_psp_id_is_encoded() {
		$payload = SPG_CBI_QR_Generator::generate( 'mi.alias', 100.00, 'Store', 'CABA', 'AR', '99999999' );
		$this->assertStringContainsString( '99999999', $payload );
	}

	// ── CRC16-CCITT tests ───────────────────────────────────────────────────

	/**
	 * CRC16 of the empty string should be 0xFFFF (only initial value).
	 */
	public function test_crc16_empty_string() {
		$crc = SPG_CBI_QR_Generator::crc16( '' );
		$this->assertEquals( 0xFFFF, $crc );
	}

	/**
	 * CRC16 of "123456789" is a known value for CRC-16/CCITT-FALSE: 0x29B1.
	 */
	public function test_crc16_known_value() {
		$crc = SPG_CBI_QR_Generator::crc16( '123456789' );
		$this->assertEquals( 0x29B1, $crc );
	}

	/**
	 * Two different payloads must produce different CRC values.
	 */
	public function test_crc16_different_inputs_different_outputs() {
		$crc1 = SPG_CBI_QR_Generator::crc16( 'payload1' );
		$crc2 = SPG_CBI_QR_Generator::crc16( 'payload2' );
		$this->assertNotEquals( $crc1, $crc2 );
	}

	// ── Full payload integrity test ─────────────────────────────────────────

	/**
	 * A complete generated payload has all required tags in correct order.
	 */
	public function test_complete_payload_has_all_required_tags() {
		$payload = SPG_CBI_QR_Generator::generate( 'mobapp.2', 150000.00, 'Mi Tienda', 'La Plata' );

		// Verify each required tag appears in the payload.
		foreach ( array( '00', '01', '26', '54', '58', '59', '60', '63' ) as $tag ) {
			$this->assertStringContainsString( $tag, $payload, "Tag {$tag} not found in payload." );
		}
	}
}
