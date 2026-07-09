<?php
/**
 * Unit tests for plugin DB fallback.
 *
 * @package SplitPaymentGateway
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb stub for plugin fallback tests.
 */
class SPG_Mock_Wpdb_Plugin {
	public string $prefix = 'wp_';
	public string $last_error = '';
	private array $existing_tables = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
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

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
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

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.com/plugin/';
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( $file );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) {}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0 ): string {
		return json_encode( $data, $flags );
	}
}

require_once dirname( __DIR__ ) . '/includes/traits/trait-logger.php';
require_once dirname( __DIR__ ) . '/includes/database/class-spg-migrations.php';
require_once dirname( __DIR__ ) . '/split-payment-plugin.php';

class Test_Plugin_Database_Ready extends TestCase {

	private SPG_Mock_Wpdb_Plugin $db;

	protected function setUp(): void {
		global $wpdb, $spg_test_options;

		$this->db         = new SPG_Mock_Wpdb_Plugin();
		$wpdb             = $this->db;
		$spg_test_options = array();
	}

	public function test_ensure_database_ready_runs_migrations_when_tables_are_missing() {
		$this->db->set_existing_tables( array( 'wp_spg_split_payments' ) );

		$instance = $this->create_plugin_instance_without_constructor();
		$instance->ensure_database_ready();

		$this->assertSame( array(), SPG_Migrations::get_missing_tables() );
		$this->assertTrue( SPG_Migrations::verify_tables_exist() );
	}

	public function test_ensure_database_ready_skips_migrations_when_tables_exist() {
		$this->db->set_existing_tables( $this->get_all_tables() );
		update_option( 'spg_version', 'before-fallback' );

		$instance = $this->create_plugin_instance_without_constructor();
		$instance->ensure_database_ready();

		$this->assertSame( array(), SPG_Migrations::get_missing_tables() );
	}

	private function create_plugin_instance_without_constructor() {
		$class_reflection = new ReflectionClass( Split_Payment_Gateway_Plugin::class );
		return $class_reflection->newInstanceWithoutConstructor();
	}

	private function get_all_tables(): array {
		return array(
			'wp_spg_split_payments',
			'wp_spg_client_split_rules',
			'wp_spg_client_gateways',
			'wp_spg_webhook_logs',
			'wp_spg_transaction_reconciliation',
			'wp_spg_qr_transfers',
		);
	}
}
