-- ============================================================
-- Split Payment Gateway – MySQL Schema v1.1.0
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- All tables use the WordPress table prefix (wp_ by default).
-- ============================================================

-- ── 1. Split Payments ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wp_spg_split_payments` (
    `id`                     BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`               BIGINT(20)    UNSIGNED NOT NULL,
    `client_id`              VARCHAR(50)   NOT NULL DEFAULT '',
    `shipping_gateway`       VARCHAR(50)   NOT NULL DEFAULT '',
    `shipping_method_type`   VARCHAR(20)   NOT NULL DEFAULT 'gateway' COMMENT 'gateway or qr_transfer',
    `total_gateway`          VARCHAR(50)   NOT NULL DEFAULT '',
    `total_method_type`      VARCHAR(20)   NOT NULL DEFAULT 'gateway' COMMENT 'gateway or qr_transfer',
    `shipping_tx_id`         VARCHAR(255)  NOT NULL DEFAULT '',
    `total_tx_id`            VARCHAR(255)  NOT NULL DEFAULT '',
    `shipping_amount`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_amount`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `currency`               CHAR(3)       NOT NULL DEFAULT 'USD',
    `status`                 ENUM(
                                 'initiated',
                                 'shipping_pending',
                                 'total_pending',
                                 'completed',
                                 'partial_failed',
                                 'failed',
                                 'refunded'
                             )             NOT NULL DEFAULT 'initiated',
    `shipping_paid_at`       DATETIME      DEFAULT NULL,
    `total_paid_at`          DATETIME      DEFAULT NULL,
    `fiscal_entity_shipping` VARCHAR(200)  DEFAULT NULL  COMMENT 'Name of logistics operator for fiscal purposes',
    `fiscal_entity_total`    VARCHAR(200)  DEFAULT NULL  COMMENT 'Name of merchant for fiscal purposes',
    `metadata`               LONGTEXT      DEFAULT NULL  COMMENT 'JSON: extra gateway-specific data',
    `created_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY  (`id`),
    UNIQUE KEY `uq_order_id`  (`order_id`),
    KEY        `idx_client`   (`client_id`),
    KEY        `idx_status`   (`status`),
    KEY        `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Client Split Rules ───────────────────────────────────
CREATE TABLE IF NOT EXISTS `wp_spg_client_split_rules` (
    `id`                  BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           VARCHAR(50)  NOT NULL,
    `rule_name`           VARCHAR(100) NOT NULL,
    `shipping_gateway`    VARCHAR(50)  NOT NULL DEFAULT '',
    `total_gateway`       VARCHAR(50)  NOT NULL DEFAULT '',
    `shipping_percentage` DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT '% of shipping charged via shipping_gateway',
    `total_percentage`    DECIMAL(5,2) NOT NULL DEFAULT 100.00 COMMENT '% of total charged via total_gateway',
    `conditions`          LONGTEXT     DEFAULT NULL            COMMENT 'JSON: amount ranges, product categories, etc.',
    `priority`            INT(11)      NOT NULL DEFAULT 10,
    `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client`     (`client_id`),
    KEY `idx_active`     (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Client Gateways ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wp_spg_client_gateways` (
    `id`                  BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id`           VARCHAR(50)  NOT NULL,
    `gateway_name`        VARCHAR(50)  NOT NULL,
    `display_name`        VARCHAR(100) NOT NULL DEFAULT '',
    `credentials`         LONGTEXT     NOT NULL               COMMENT 'AES-256 encrypted JSON of API keys',
    `is_default_shipping` TINYINT(1)   NOT NULL DEFAULT 0,
    `is_default_total`    TINYINT(1)   NOT NULL DEFAULT 0,
    `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
    `fiscal_entity_name`  VARCHAR(200) DEFAULT NULL,
    `fiscal_tax_id`       VARCHAR(50)  DEFAULT NULL           COMMENT 'VAT / CUIT / RFC / NIF',
    `fiscal_address`      VARCHAR(500) DEFAULT NULL,
    `qr_alias`            VARCHAR(100) DEFAULT NULL           COMMENT 'Bank alias / CBU / CVU for QR Transfer',
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client`  (`client_id`),
    KEY `idx_gateway` (`gateway_name`),
    KEY `idx_active`  (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Webhook Logs ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wp_spg_webhook_logs` (
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
    PRIMARY KEY (`id`),
    KEY `idx_gateway`   (`gateway`),
    KEY `idx_order_id`  (`order_id`),
    KEY `idx_processed` (`processed`),
    KEY `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Transaction Reconciliation ──────────────────────────
CREATE TABLE IF NOT EXISTS `wp_spg_transaction_reconciliation` (
    `id`                 BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
    `split_payment_id`   BIGINT(20)    UNSIGNED NOT NULL,
    `order_id`           BIGINT(20)    UNSIGNED NOT NULL,
    `tx_type`            ENUM('shipping','total','refund') NOT NULL,
    `gateway`            VARCHAR(50)   NOT NULL,
    `tx_id`              VARCHAR(255)  NOT NULL,
    `amount`             DECIMAL(10,2) NOT NULL,
    `currency`           CHAR(3)       NOT NULL DEFAULT 'USD',
    `gateway_status`     VARCHAR(50)   NOT NULL DEFAULT '',
    `fiscal_document_id` VARCHAR(100)  DEFAULT NULL           COMMENT 'Invoice / receipt ID from the gateway',
    `reconciled`         TINYINT(1)    NOT NULL DEFAULT 0,
    `reconciled_at`      DATETIME      DEFAULT NULL,
    `raw_response`       LONGTEXT      DEFAULT NULL           COMMENT 'Full gateway API response JSON',
    `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_split_payment_id` (`split_payment_id`),
    KEY `idx_order_id`         (`order_id`),
    KEY `idx_tx_id`            (`tx_id`(100)),
    KEY `idx_reconciled`       (`reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. QR Transfers ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `wp_spg_qr_transfers` (
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
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qr_hash`    (`qr_hash`),
    KEY        `idx_order_ref` (`order_ref`(100)),
    KEY        `idx_status`    (`status`),
    KEY        `idx_expires`   (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
