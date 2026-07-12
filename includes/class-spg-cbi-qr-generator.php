<?php
/**
 * CBI QR Generator.
 *
 * Generates CBI (Código de Barras Interoperable) QR payloads following the
 * BCRA Comunicación "A" 6506 standard, based on the EMV QR Code Specification
 * for Payment Systems.
 *
 * The output is a TLV-formatted string that can be encoded into a QR image
 * and scanned by any Argentine banking app or digital wallet (MercadoPago,
 * MODO, BBVA, Santander, etc.).
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * CBI QR data generator (static utility class).
 */
class SPG_CBI_QR_Generator {

	/**
	 * Default PSP ID for Red Link (Argentina).
	 */
	const DEFAULT_PSP_ID = '00000031';

	/**
	 * Maximum allowed length for merchant name (EMV spec).
	 */
	const MERCHANT_NAME_MAX_LEN = 25;

	/**
	 * Maximum allowed length for merchant city (EMV spec).
	 */
	const MERCHANT_CITY_MAX_LEN = 15;

	/**
	 * Generate a CBI-compliant QR payload string.
	 *
	 * The returned string encodes all payment details in TLV format and
	 * ends with a CRC16-CCITT-FALSE checksum, making it ready for QR encoding.
	 *
	 * @param string $alias_or_cbu  Bank alias, CBU, or CVU of the payee.
	 * @param float  $amount        Transaction amount (two decimal places).
	 * @param string $merchant_name Store / merchant name (max 25 chars).
	 * @param string $merchant_city City (max 15 chars).
	 * @param string $country       ISO 3166-1 alpha-2 country code (default 'AR').
	 * @param string $psp_id        PSP identifier (default '00000031' for Red Link).
	 * @return string CBI TLV string ready for QR encoding.
	 */
	public static function generate(
		string $alias_or_cbu,
		float $amount,
		string $merchant_name,
		string $merchant_city,
		string $country = 'AR',
		string $psp_id = self::DEFAULT_PSP_ID
	): string {
		// Determine identifier type based on the alias/CBU/CVU format.
		$id_type = self::get_identifier_type( $alias_or_cbu );

		// Build Merchant Account Info (tag 26) inner content (TLV sub-fields).
		$inner  = self::tlv( '00', $id_type );       // Identifier type (CBU / CVU / ALIAS)
		$inner .= self::tlv( '01', $alias_or_cbu );  // Actual CBU / CVU / Alias value
		$inner .= self::tlv( '02', $psp_id );        // PSP identifier (e.g. "00000031" Red Link)

		// Sanitise merchant name and city to stay within spec limits.
		$name = substr( $merchant_name, 0, self::MERCHANT_NAME_MAX_LEN );
		$city = substr( $merchant_city, 0, self::MERCHANT_CITY_MAX_LEN );

		// Format amount with exactly two decimal places and no thousands separator.
		$amount_str = number_format( $amount, 2, '.', '' );

		// Build the main TLV payload (CRC placeholder added last).
		$payload  = self::tlv( '00', '01' );                   // Payload Format Indicator (always "01")
		$payload .= self::tlv( '01', '12' );                   // Point of Initiation (12 = dynamic per-transaction)
		$payload .= self::tlv( '26', $inner );                 // Merchant Account Info (CBI)
		$payload .= self::tlv( '54', $amount_str );            // Transaction Amount
		$payload .= self::tlv( '58', strtoupper( $country ) ); // Country Code
		$payload .= self::tlv( '59', $name );                  // Merchant Name
		$payload .= self::tlv( '60', $city );                  // Merchant City

		// Append CRC tag + length placeholder, then compute and append the checksum.
		// CRC covers the entire payload including "6304" (tag + len).
		$payload .= '6304';
		$crc      = self::crc16( $payload );
		$payload .= strtoupper( sprintf( '%04X', $crc ) );

		return $payload;
	}

	/**
	 * Determine the identifier type label for a CBU / CVU / Alias value.
	 *
	 * @param string $value The raw alias, CBU, or CVU string.
	 * @return string 'CBU', 'CVU', or 'ALIAS'.
	 */
	public static function get_identifier_type( string $value ): string {
		// Both CBU and CVU are exactly 22 numeric digits.
		// CVU (virtual wallets) begin with "000".
		if ( preg_match( '/^\d{22}$/', $value ) ) {
			return ( strncmp( $value, '000', 3 ) === 0 ) ? 'CVU' : 'CBU';
		}
		return 'ALIAS';
	}

	/**
	 * Calculate the CRC16-CCITT-FALSE checksum.
	 *
	 * Parameters:
	 *   Polynomial : 0x1021
	 *   Initial    : 0xFFFF
	 *   Reflect In : false
	 *   Reflect Out: false
	 *   Final XOR  : 0x0000
	 *
	 * @param string $data Input string.
	 * @return int 16-bit CRC value (0–65535).
	 */
	public static function crc16( string $data ): int {
		$crc = 0xFFFF;
		$len = strlen( $data );

		for ( $i = 0; $i < $len; $i++ ) {
			$crc ^= ( ord( $data[ $i ] ) << 8 );
			for ( $j = 0; $j < 8; $j++ ) {
				if ( ( $crc & 0x8000 ) !== 0 ) {
					$crc = ( ( $crc << 1 ) ^ 0x1021 ) & 0xFFFF;
				} else {
					$crc = ( $crc << 1 ) & 0xFFFF;
				}
			}
		}

		return $crc;
	}

	/**
	 * Encode a single TLV (Tag-Length-Value) triplet.
	 *
	 * Both tag and length are zero-padded to 2 digits. Length is the
	 * byte length of the value string.
	 *
	 * @param string $tag   2-character field identifier.
	 * @param string $value Field value.
	 * @return string TLV-encoded segment.
	 */
	private static function tlv( string $tag, string $value ): string {
		return $tag . sprintf( '%02d', strlen( $value ) ) . $value;
	}
}
