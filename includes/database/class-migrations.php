<?php
/**
 * Database schema and migration manager.
 *
 * @package SplitPaymentGateway
 */

defined( 'ABSPATH' ) || exit;

class SPG_Migrations {

	use SPG_Logger;

	/** Current schema version. */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Run all pending migrations.
	 * Called on plugin activation.
	 */
	public static function run() {
		$instance = new self();
		$instance->create_tables();
		update_option( 'spg_version', self::SCHEMA_VERSION );
	}

	/**
	 * Create/upgrade all custom tables using dbDelta.
	 */
	private function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
			`id`                  BIGINT(20)  UNSIGNED NOT NULL AUTO_INCREMENT,
			`client_id`           VARCHAR(50) NOT NULL,
			`rule_name`           VARCHAR(100) NOT NULL,
			`shipping_gateway`    VARCHAR(50)  NOT NULL DEFAULT '',
			`total_gateway`       VARCHAR(50)  NOT NULL DEFAULT '',
			`shipping_percentage` DECIMAL(5,2) NOT NULL DEFAULT 100.00,
			`total_percentage`    DECIMAL(5,2) NOT NULL DEFAULT 100.00,
			`conditions`          LONGTEXT     DEFAULT NULL,
			`priority`            INT(11)      NOT NULL DEFAULT 10,
			`is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
			`created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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

		dbDelta( $sql_split_payments );
		dbDelta( $sql_split_rules );
		dbDelta( $sql_client_gateways );
		dbDelta( $sql_webhook_logs );
		dbDelta( $sql_reconciliation );

		$this->log_info( 'SPG database tables created/verified.' );
	}
}
