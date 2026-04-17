-- ============================================================
-- B-CashPay Database Schema
-- MySQL 8.0+ / MariaDB 10.6+
-- ============================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ── API Clients ─────────────────────────────────────────────
-- Each integrated EC site (EC-CUBE, Shopify, etc.) is an API client.
-- api_key is used for Bearer auth; webhook_secret signs callbacks.
CREATE TABLE IF NOT EXISTS `api_clients` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL COMMENT 'Human-readable client name (e.g. "Lion Express")',
    `api_key` VARCHAR(64) NOT NULL COMMENT 'Bearer token for API authentication',
    `webhook_secret` VARCHAR(128) NOT NULL COMMENT 'HMAC-SHA256 secret for signing webhook callbacks',
    `callback_url` VARCHAR(512) DEFAULT NULL COMMENT 'Default webhook callback URL',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_api_key` (`api_key`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bank Accounts ───────────────────────────────────────────
-- Physical bank accounts used to receive deposits.
-- scrape_* fields configure the Python scraper for this account.
CREATE TABLE IF NOT EXISTS `bank_accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bank_name` VARCHAR(100) NOT NULL COMMENT 'e.g. 楽天銀行',
    `bank_code` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '4-digit bank code',
    `branch_name` VARCHAR(100) NOT NULL DEFAULT '',
    `branch_code` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '3-digit branch code',
    `account_type` VARCHAR(20) NOT NULL DEFAULT '普通' COMMENT '普通/当座',
    `account_number` VARCHAR(20) NOT NULL,
    `account_name` VARCHAR(255) NOT NULL COMMENT 'Account holder name (kana)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `scrape_login_url` VARCHAR(512) DEFAULT NULL,
    `scrape_credentials_json` TEXT DEFAULT NULL COMMENT 'Encrypted JSON: {username, password, totp_secret, ...}',
    `scrape_adapter_key` VARCHAR(50) DEFAULT NULL COMMENT 'Adapter key: rakuten, gmo_aozora, etc.',
    `scrape_interval_minutes` INT UNSIGNED NOT NULL DEFAULT 15,
    `scrape_status` ENUM('setup_pending','active','inactive','login_failed','scrape_failed','error','paused') NOT NULL DEFAULT 'setup_pending',
    `scrape_last_at` TIMESTAMP NULL DEFAULT NULL,
    `scrape_last_success_at` TIMESTAMP NULL DEFAULT NULL,
    `scrape_last_error_at` TIMESTAMP NULL DEFAULT NULL,
    `scrape_last_error_message` TEXT DEFAULT NULL,
    `scrape_consecutive_failures` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_account_number` (`bank_code`, `account_number`),
    KEY `idx_is_active` (`is_active`),
    KEY `idx_scrape_status` (`scrape_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Payment Links ───────────────────────────────────────────
-- Each payment link represents a single payment request from an API client.
-- The customer sees a payment page with bank details and a reference number.
-- Uses ULID as primary key for URL-safe, sortable, collision-free IDs.
CREATE TABLE IF NOT EXISTS `payment_links` (
    `id` VARCHAR(26) NOT NULL COMMENT 'ULID primary key',
    `api_client_id` BIGINT UNSIGNED NOT NULL,
    `bank_account_id` BIGINT UNSIGNED NOT NULL,
    `external_id` VARCHAR(128) DEFAULT NULL COMMENT 'Caller-provided order/transaction ID',
    `reference_number` VARCHAR(20) NOT NULL COMMENT 'Unique numeric ref for depositor name (e.g. 1234567)',
    `amount` DECIMAL(12,0) NOT NULL COMMENT 'Amount in JPY (integer, no decimals)',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'JPY',
    `customer_name` VARCHAR(255) NOT NULL COMMENT 'Display name (any script)',
    `customer_kana` VARCHAR(255) DEFAULT NULL COMMENT 'Katakana for bank depositor name — required for matching',
    `customer_email` VARCHAR(255) DEFAULT NULL,
    `callback_url` VARCHAR(512) DEFAULT NULL COMMENT 'Override per-link callback URL',
    `metadata` JSON DEFAULT NULL COMMENT 'Arbitrary JSON metadata from the caller',
    `status` ENUM('pending','confirmed','expired','cancelled') NOT NULL DEFAULT 'pending',
    `token` VARCHAR(32) NOT NULL COMMENT 'Unique opaque token for the payment page URL',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'When this payment link expires',
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reference_number` (`reference_number`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_api_client_id` (`api_client_id`),
    KEY `idx_bank_account_id` (`bank_account_id`),
    KEY `idx_status` (`status`),
    KEY `idx_external_id` (`api_client_id`, `external_id`),
    KEY `idx_expires_at` (`expires_at`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_payment_links_api_client` FOREIGN KEY (`api_client_id`) REFERENCES `api_clients` (`id`),
    CONSTRAINT `fk_payment_links_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Deposits ────────────────────────────────────────────────
-- Raw deposit transactions detected by the bank scraper.
-- Matched to payment_links when possible; unmatched deposits stay with NULL.
CREATE TABLE IF NOT EXISTS `deposits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bank_account_id` BIGINT UNSIGNED NOT NULL,
    `payment_link_id` VARCHAR(26) DEFAULT NULL COMMENT 'NULL until matched',
    `depositor_name` VARCHAR(255) NOT NULL COMMENT 'Name as it appears in bank statement',
    `amount` DECIMAL(12,0) NOT NULL,
    `transaction_date` TIMESTAMP NOT NULL,
    `bank_transaction_id` VARCHAR(128) NOT NULL COMMENT 'Unique ID from bank for deduplication',
    `matched_at` TIMESTAMP NULL DEFAULT NULL,
    `raw_data` JSON DEFAULT NULL COMMENT 'Full raw transaction data from scraper',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_bank_transaction` (`bank_account_id`, `bank_transaction_id`),
    KEY `idx_payment_link_id` (`payment_link_id`),
    KEY `idx_transaction_date` (`transaction_date`),
    KEY `idx_unmatched` (`payment_link_id`, `bank_account_id`),
    CONSTRAINT `fk_deposits_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
    CONSTRAINT `fk_deposits_payment_link` FOREIGN KEY (`payment_link_id`) REFERENCES `payment_links` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Scraper Tasks ───────────────────────────────────────────
-- Tracks individual scraping runs for observability and scheduling.
CREATE TABLE IF NOT EXISTS `scraper_tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bank_account_id` BIGINT UNSIGNED NOT NULL,
    `status` ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
    `last_run_at` TIMESTAMP NULL DEFAULT NULL,
    `next_run_at` TIMESTAMP NULL DEFAULT NULL,
    `run_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `transactions_found` INT UNSIGNED NOT NULL DEFAULT 0,
    `transactions_matched` INT UNSIGNED NOT NULL DEFAULT 0,
    `duration_seconds` DECIMAL(8,2) DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bank_account_id` (`bank_account_id`),
    KEY `idx_status` (`status`),
    KEY `idx_next_run_at` (`next_run_at`),
    CONSTRAINT `fk_scraper_tasks_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Webhook Logs ────────────────────────────────────────────
-- Every webhook callback attempt is logged for debugging and retry.
CREATE TABLE IF NOT EXISTS `webhook_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_link_id` VARCHAR(26) NOT NULL,
    `url` VARCHAR(512) NOT NULL,
    `request_body` JSON NOT NULL,
    `response_code` INT DEFAULT NULL,
    `response_body` TEXT DEFAULT NULL,
    `attempt` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Retry attempt number',
    `delivered_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Set when response_code is 2xx',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payment_link_id` (`payment_link_id`),
    KEY `idx_delivered_at` (`delivered_at`),
    CONSTRAINT `fk_webhook_logs_payment_link` FOREIGN KEY (`payment_link_id`) REFERENCES `payment_links` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Telegram Logs ───────────────────────────────────────────
-- Audit trail for all Telegram notifications sent.
CREATE TABLE IF NOT EXISTS `telegram_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payment_link_id` VARCHAR(26) DEFAULT NULL,
    `chat_id` VARCHAR(64) NOT NULL,
    `message` TEXT NOT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_payment_link_id` (`payment_link_id`),
    KEY `idx_sent_at` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Admin Users ─────────────────────────────────────────────
-- Admin dashboard login accounts.
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `role` ENUM('admin','operator') NOT NULL DEFAULT 'operator',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
