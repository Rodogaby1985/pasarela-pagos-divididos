<?php
/**
 * Database schema and migration manager.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Database migrations manager.
 */
class SPG_Migrations {

	use SPG_Logger;

	/** Current schema version. */
	const SCHEMA_VERSION = '1.2.0';

	/**
	 * Run all pending migrations.
	 * Called on plugin activation.
	 */
	public static function run() {
		$instance = new self();
		$instance->create_tables();
		$instance->run_upgrades();
		// Verification logs missing tables when needed.
		$instance->verify_tables_exist();
		update_option( 'spg_version', self::SCHEMA_VERSION );
	}

	/**
	 * Return full table names required by the plugin.
	 *
	 * @return array
	 */
	private static function get_required_tables() {
		global $wpdb;

		return array(
			$wpdb->prefix . 'spg_split_payments',
			$wpdb->prefix . 'spg_client_split_rules',
			$wpdb->prefix . 'spg_client_gateways',
			$wpdb->prefix . 'spg_webhook_logs',
			$wpdb->prefix . 'spg_transaction_reconciliation',
			$wpdb->prefix . 'spg_qr_transfers',
		);
	}

	/**
	 * Return missing plugin tables.
	 *
	 * @return array
	 */
	public static function get_missing_tables() {
		global $wpdb;

		$missing_tables = array();

		foreach ( self::get_required_tables() as $table_name ) {
			$table_exists = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table_name
				)
			);

			if ( $table_exists !== $table_name ) {
				$missing_tables[] = $table_name;
			}
		}

		return $missing_tables;
	}

	/**
	 * Verify that all required tables exist and log errors when any are missing.
	 *
	 * @return bool
	 */
	public static function verify_tables_exist() {
		$missing_tables = self::get_missing_tables();

		if ( empty( $missing_tables ) ) {
			return true;
		}

		$instance = new self();

		foreach ( $missing_tables as $missing_table ) {
			$instance->log_error(
				'SPG missing database table.',
				array(
					'table' => $missing_table,
				)
			);
		}

		return false;
	}

	/**
	 * Run upgrade migrations for existing installations.
	 */
	private function run_upgrades() {
		$installed_version = get_option( 'spg_version', '1.0.0' );

		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {
			$this->upgrade_to_1_1_0();
		}

		if ( version_compare( $installed_version, '1.2.0', '<' ) ) {
			$this->upgrade_to_1_2_0();
		}
	}

	/**
	 * Upgrade to 1.1.0: add QR Transfer support columns.
	 */
	private function upgrade_to_1_1_0() {
		global $wpdb;

		// Add shipping_method_type and total_method_type to split_payments.
		$table = $wpdb->prefix . 'spg_split_payments';
		$cols  = $wpdb->get_col( "DESCRIBE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! in_array( 'shipping_method_type', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `shipping_method_type` VARCHAR(20) NOT NULL DEFAULT 'gateway' AFTER `shipping_gateway`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( ! in_array( 'total_method_type', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `total_method_type` VARCHAR(20) NOT NULL DEFAULT 'gateway' AFTER `total_gateway`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		// Add qr_alias_shipping and qr_alias_total to client_gateways.
		$gw_table = $wpdb->prefix . 'spg_client_gateways';
		$gw_cols  = $wpdb->get_col( "DESCRIBE `{$gw_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! in_array( 'qr_alias', $gw_cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$gw_table}` ADD COLUMN `qr_alias` VARCHAR(100) DEFAULT NULL COMMENT 'Bank alias / CBU / CVU for QR Transfer'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Upgrade to 1.2.0: remove percentage columns from split_rules (replaced by simple gateway selection).
	 */
	private function upgrade_to_1_2_0() {
		global $wpdb;

		$rules_table = $wpdb->prefix . 'spg_client_split_rules';
		$cols        = $wpdb->get_col( "DESCRIBE `{$rules_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( in_array( 'shipping_percentage', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$rules_table}` DROP COLUMN `shipping_percentage`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( in_array( 'total_percentage', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$rules_table}` DROP COLUMN `total_percentage`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/**
	 * Create/upgrade all custom tables using dbDelta.
	 */
	private function create_tables() {
		global $wpdb;

		$upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( file_exists( $upgrade_file ) ) {
			require_once $upgrade_file;
		} elseif ( ! function_exists( 'dbDelta' ) ) {
			$this->log_error(
				'SPG migration bootstrap failed: upgrade.php is missing and dbDelta() is unavailable.',
				array(
					'upgrade_file' => $upgrade_file,
				)
			);
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// ── 1. Split Payments ──────────────────────────────────────────────────
		$sql_split_payments = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}spg_split_payments` (
			`id`                   BIGINT(20)      UNSIGNED NOT NULL AUTO_INCREMENT,
			`order_id`             BIGINT(20)      UNSIGNED NOT NULL,
			`client_id`            VARCHAR(50)     NOT NULL DEFAULT '',
			`shipping_gateway`     VARCHAR(50)     NOT NULL DEFAULT '',
			`total_gateway`        VARCHAR(50)     NOT NULL DEFAULT '',
			`shipping_tx_id`       VARCHAR(255)    NOT NULL DEFAULT '',
			`total_tx_id`          VARCHAR(255)    NOT NULL DEFAULT '',
			`shipping_amount`      DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			`total_amount`         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
			`currency`             CHAR(3)         NOT NULL DEFAULT 'USD',
			`status`               ENUM('initiated','shipping_pending','total_pending','completed','partial_failed','failed','refunded') NOT NULL DEFAULT 'initiated',
			`shipping_paid_at`     DATETIME        DEFAULT NULL,
			`total_paid_at`        DATETIME        DEFAULT NULL,
			`fiscal_entity_shipping` VARCHAR(200)  DEFAULT NULL,
			`fiscal_entity_total`    VARCHAR(200)  DEFAULT NULL,
			`metadata`             LONGTEXT        DEFAULT NULL,
			`created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			UNIQUE KEY `order_id` (`order_id`),
			KEY `client_id`  (`client_id`),
			KEY `status`     (`status`),
			KEY `created_at` (`created_at`)
		) $charset_collate;";

		// ── 2. Client Split Rules ──────────────────────────────────────────────
		$sql_split_rules = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}spg_client_split_rules` (
			`id`               BIGINT(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			`client_id`        VARCHAR(50) NOT NULL,
			`rule_name`        VARCHAR(100) NOT NULL,
			`shipping_gateway` VARCHAR(50)  NOT NULL DEFAULT '',
			`total_gateway`    VARCHAR(50)  NOT NULL DEFAULT '',
			`conditions`       LONGTEXT     DEFAULT NULL,
			`priority`         INT(11)      NOT NULL DEFAULT 10,
			`is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
			`created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			KEY `client_id` (`client_id`),
			KEY `is_active`  (`is_active`)
		) $charset_collate;";

		// ── 3. Client Gateways ─────────────────────────────────────────────────
		$sql_client_gateways = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}spg_client_gateways` (
			`id`                  BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			`client_id`           VARCHAR(50)  NOT NULL,
			`gateway_name`        VARCHAR(50)  NOT NULL,
			`display_name`        VARCHAR(100) NOT NULL DEFAULT '',
			`credentials`         LONGTEXT     NOT NULL,
			`is_default_shipping` TINYINT(1)   NOT NULL DEFAULT 0,
			`is_default_total`    TINYINT(1)   NOT NULL DEFAULT 0,
			`is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
			`fiscal_entity_name`  VARCHAR(200) DEFAULT NULL,
			`fiscal_tax_id`       VARCHAR(50)  DEFAULT NULL,
			`fiscal_address`      VARCHAR(500) DEFAULT NULL,
			`created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			KEY `client_id`    (`client_id`),
			KEY `gateway_name` (`gateway_name`),
			KEY `is_active`    (`is_active`)
		) $charset_collate;";

		// ── 4. Webhook Logs ────────────────────────────────────────────────────
		$sql_webhook_logs = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}spg_webhook_logs` (
			`id`           BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			`gateway`      VARCHAR(50)  NOT NULL,
			`event_type`   VARCHAR(100) NOT NULL DEFAULT '',
			`order_id`     BIGINT(20)   UNSIGNED DEFAULT NULL,
			`tx_id`        VARCHAR(255) DEFAULT NULL,
			`payload`      LONGTEXT     DEFAULT NULL,
			`headers`      LONGTEXT     DEFAULT NULL,
			`processed`    TINYINT(1)   NOT NULL DEFAULT 0,
			`processed_at` DATETIME     DEFAULT NULL,
			`error`        TEXT         DEFAULT NULL,
			`created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			KEY `gateway`    (`gateway`),
			KEY `order_id`   (`order_id`),
			KEY `processed`  (`processed`),
			KEY `created_at` (`created_at`)
		) $charset_collate;";

		// ── 5. Transaction Reconciliation ──────────────────────────────────────
		$sql_reconciliation = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}spg_transaction_reconciliation` (
			`id`                 BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
			`split_payment_id`   BIGINT(20)   UNSIGNED NOT NULL,
			`order_id`           BIGINT(20)   UNSIGNED NOT NULL,
			`tx_type`            ENUM('shipping','total','refund') NOT NULL,
			`gateway`            VARCHAR(50)  NOT NULL,
			`tx_id`              VARCHAR(255) NOT NULL,
			`amount`             DECIMAL(10,2) NOT NULL,
			`currency`           CHAR(3)       NOT NULL DEFAULT 'USD',
			`gateway_status`     VARCHAR(50)  NOT NULL DEFAULT '',
			`fiscal_document_id` VARCHAR(100) DEFAULT NULL,
			`reconciled`         TINYINT(1)   NOT NULL DEFAULT 0,
			`reconciled_at`      DATETIME     DEFAULT NULL,
			`raw_response`       LONGTEXT     DEFAULT NULL,
			`created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			KEY `split_payment_id` (`split_payment_id`),
			KEY `order_id`         (`order_id`),
			KEY `tx_id`            (`tx_id`(100)),
			KEY `reconciled`       (`reconciled`)
		) $charset_collate;";

		$migration_results = dbDelta( $sql_split_payments );
		$migration_results = array_merge( $migration_results, dbDelta( $sql_split_rules ) );
		$migration_results = array_merge( $migration_results, dbDelta( $sql_client_gateways ) );
		$migration_results = array_merge( $migration_results, dbDelta( $sql_webhook_logs ) );
		$migration_results = array_merge( $migration_results, dbDelta( $sql_reconciliation ) );

		// ── 6. QR Transfers ────────────────────────────────────────────────────
		$sql_qr_transfers = "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}spg_qr_transfers` (
			`id`           BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
			`order_ref`    VARCHAR(255)  NOT NULL              COMMENT 'Internal order reference (e.g. 123-shipping)',
			`alias`        VARCHAR(100)  NOT NULL DEFAULT ''   COMMENT 'Bank alias / CBU / CVU',
			`amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
			`currency`     CHAR(3)       NOT NULL DEFAULT 'ARS',
			`concept`      VARCHAR(255)  NOT NULL DEFAULT '',
			`qr_hash`      VARCHAR(64)   NOT NULL              COMMENT 'SHA-256 HMAC used as transaction_id',
			`qr_payload`   LONGTEXT      DEFAULT NULL          COMMENT 'Full JSON payload encoded in QR',
			`status`       ENUM('pending','confirmed','expired','cancelled','refunded') NOT NULL DEFAULT 'pending',
			`expires_at`   DATETIME      NOT NULL,
			`confirmed_at` DATETIME      DEFAULT NULL,
			`created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (`id`),
			UNIQUE KEY `qr_hash` (`qr_hash`),
			KEY `order_ref`    (`order_ref`(100)),
			KEY `status`       (`status`),
			KEY `expires_at`   (`expires_at`)
		) $charset_collate;";

		$migration_results = array_merge( $migration_results, dbDelta( $sql_qr_transfers ) );

		if ( ! empty( $wpdb->last_error ) ) {
			$this->log_error(
				'SPG database migration encountered a SQL error.',
				array(
					'last_error' => $wpdb->last_error,
				)
			);
		}

		$this->log_info(
			'SPG database tables created/verified.',
			array(
				'dbdelta_results' => $migration_results,
			)
		);
	}
}
