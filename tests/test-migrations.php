<?php
/**
 * Unit tests for database migrations.
 *
 * @package SplitPaymentGateway
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb stub for migration tests.
 */
class SPG_Mock_Wpdb_Migrations {
	public string $prefix = 'wp_';
	public string $last_error = '';
	private array $existing_tables = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$escaped = str_replace( "'", "''", (string) $arg );
			$query   = preg_replace( '/%s/', "'" . $escaped . "'", $query, 1 );
		}

		return $query;
	}

	public function get_var( string $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches ) ) {
			return in_array( $matches[1], $this->existing_tables, true ) ? $matches[1] : null;
		}

		return null;
	}

	public function get_col( string $query ): array {
		return array();
	}

	public function query( string $query ): int {
		return 1;
	}

	public function set_existing_tables( array $tables ) {
		$this->existing_tables = array_values( array_unique( $tables ) );
	}

	public function add_table( string $table ) {
		if ( ! in_array( $table, $this->existing_tables, true ) ) {
			$this->existing_tables[] = $table;
		}
	}
}

$plugin_dir = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $plugin_dir . '/' );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ): string {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( string $sql ): array {
		global $wpdb;

		if ( preg_match( '/CREATE TABLE IF NOT EXISTS `([^`]+)`/', $sql, $matches ) ) {
			$wpdb->add_table( $matches[1] );
			return array( 'created ' . $matches[1] );
		}

		return array();
	}
}

$spg_test_options = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		global $spg_test_options;

		if ( array_key_exists( $name, $spg_test_options ) ) {
			return $spg_test_options[ $name ];
		}

		return $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value ): bool {
		global $spg_test_options;
		$spg_test_options[ $name ] = $value;
		return true;
	}
}

if ( ! function_exists( 'error_log' ) ) {
	function error_log( string $message ): bool {
		return true;
	}
}

require_once $plugin_dir . '/includes/traits/trait-logger.php';
require_once $plugin_dir . '/includes/database/class-spg-migrations.php';

class Test_Migrations extends TestCase {

	private SPG_Mock_Wpdb_Migrations $db;

	protected function setUp(): void {
		global $wpdb, $spg_test_options;

		$this->db         = new SPG_Mock_Wpdb_Migrations();
		$wpdb             = $this->db;
		$spg_test_options = array();
	}

	public function test_run_creates_all_required_tables() {
		SPG_Migrations::run();

		$this->assertSame( array(), SPG_Migrations::get_missing_tables() );
	}

	public function test_verify_tables_exist_returns_false_when_tables_are_missing() {
		$this->assertFalse( SPG_Migrations::verify_tables_exist() );
		$this->assertNotEmpty( SPG_Migrations::get_missing_tables() );
	}

	public function test_run_recreates_missing_tables_as_fallback() {
		$this->db->set_existing_tables(
			array(
				'wp_spg_split_payments',
				'wp_spg_client_split_rules',
			)
		);

		$this->assertNotEmpty( SPG_Migrations::get_missing_tables() );

		SPG_Migrations::run();

		$this->assertSame( array(), SPG_Migrations::get_missing_tables() );
	}
}
