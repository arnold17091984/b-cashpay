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
    `current_balance` BIGINT DEFAULT NULL COMMENT 'Latest observed account balance in JPY',
    `balance_updated_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'When current_balance was last captured',
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
    `id` VARCHAR(32) NOT NULL COMMENT 'ULID primary key (bp_ prefix + 26-char ULID = 29 chars; 32 for headroom)',
    `api_client_id` BIGINT UNSIGNED NOT NULL,
    `bank_account_id` BIGINT UNSIGNED NOT NULL,
    `external_id` VARCHAR(128) DEFAULT NULL COMMENT 'Caller-provided order/transaction ID',
    `reference_number` VARCHAR(20) DEFAULT NULL COMMENT 'Unique numeric ref for depositor name (e.g. 1234567). Null for templates',
    `amount` DECIMAL(12,0) DEFAULT NULL COMMENT 'Amount in JPY (integer). Null when link_type=template or awaiting_input',
    `currency` VARCHAR(3) NOT NULL DEFAULT 'JPY',
    `customer_name` VARCHAR(255) DEFAULT NULL COMMENT 'Display name. Null when link_type=template or customer-entered',
    `customer_kana` VARCHAR(255) DEFAULT NULL COMMENT 'Katakana for bank depositor name — required for matching',
    `customer_email` VARCHAR(255) DEFAULT NULL,
    `callback_url` VARCHAR(512) DEFAULT NULL COMMENT 'Override per-link callback URL',
    `metadata` JSON DEFAULT NULL COMMENT 'Arbitrary JSON metadata from the caller',
    `status` ENUM('awaiting_input','pending','confirmed','expired','cancelled') NOT NULL DEFAULT 'pending',
    `link_type` ENUM('single','awaiting_input','template','child') NOT NULL DEFAULT 'single' COMMENT 'single=one-shot fixed; awaiting_input=customer will fill amount; template=shareable URL that spawns children; child=created from a template',
    `parent_link_id` VARCHAR(32) DEFAULT NULL COMMENT 'When link_type=child, references the template that spawned it',
    `min_amount` BIGINT DEFAULT NULL COMMENT 'Customer-input lower bound (JPY)',
    `max_amount` BIGINT DEFAULT NULL COMMENT 'Customer-input upper bound (JPY)',
    `preset_amounts` JSON DEFAULT NULL COMMENT 'Array of quick-pick amounts on the payment page',
    `locked_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Set when customer confirms their entered amount so the form cannot be resubmitted',
    `token` VARCHAR(32) NOT NULL COMMENT 'Unique opaque token for the payment page URL',
    `expires_at` TIMESTAMP NOT NULL COMMENT 'When this payment link expires',
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
    `source` VARCHAR(32) NOT NULL DEFAULT 'api' COMMENT 'Origin of the link: api, admin_web, telegram',
    `issued_by_admin_id` BIGINT UNSIGNED DEFAULT NULL COMMENT 'admin_users.id when human-issued',
    `issued_by_telegram_user_id` BIGINT DEFAULT NULL COMMENT 'Telegram user_id when chat-issued',
    `issued_by_telegram_message_id` BIGINT DEFAULT NULL COMMENT 'Original Telegram message_id for audit trail',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reference_number` (`reference_number`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_api_client_id` (`api_client_id`),
    KEY `idx_bank_account_id` (`bank_account_id`),
    KEY `idx_status` (`status`),
    KEY `idx_link_type` (`link_type`),
    KEY `idx_parent_link_id` (`parent_link_id`),
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
-- Admin dashboard login accounts.  Telegram columns bind a chat user_id to
-- an admin account so chat-originated commands carry the admin's identity
-- and the authorization caps attached to it.
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `email` VARCHAR(255) NOT NULL DEFAULT '',
    `role` ENUM('admin','operator') NOT NULL DEFAULT 'operator',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `telegram_user_id` BIGINT DEFAULT NULL COMMENT 'Bound Telegram numeric user_id',
    `telegram_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Allow this user to issue links via chat',
    `per_link_cap` BIGINT NOT NULL DEFAULT 500000 COMMENT 'Hard cap per chat-issued link (JPY)',
    `daily_amount_cap` BIGINT NOT NULL DEFAULT 3000000 COMMENT 'Hard cap per 24h rolling window (JPY)',
    `default_bank_account_id` BIGINT DEFAULT NULL COMMENT 'Default bank account for chat-issued links',
    `default_api_client_id` BIGINT DEFAULT NULL COMMENT 'Default api client for chat-issued links',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_admin_username` (`username`),
    UNIQUE KEY `uk_admin_telegram_user` (`telegram_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Telegram Bind Tokens ────────────────────────────────────
-- Single-use tokens issued from the admin dashboard and redeemed by an
-- operator via `/bind <token>` in the Telegram group.  Expires in 15 min.
CREATE TABLE IF NOT EXISTS `telegram_bind_tokens` (
    `token` VARCHAR(64) NOT NULL,
    `admin_user_id` BIGINT UNSIGNED NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`token`),
    KEY `idx_admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Telegram Updates ────────────────────────────────────────
-- Idempotency log — every Telegram update we process lands here keyed by
-- its update_id so bot restarts / Telegram retries cannot create duplicate
-- payment links.  INSERT IGNORE on the unique key is the gate.
CREATE TABLE IF NOT EXISTS `telegram_updates` (
    `update_id` BIGINT NOT NULL,
    `chat_id` BIGINT DEFAULT NULL,
    `user_id` BIGINT DEFAULT NULL,
    `message_id` BIGINT DEFAULT NULL,
    `kind` VARCHAR(32) NOT NULL DEFAULT '',
    `payload` TEXT NOT NULL,
    `processed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`update_id`),
    KEY `idx_chat_user` (`chat_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Telegram Pending Intents ────────────────────────────────
-- A `/new` command creates a pending intent that lives until the operator
-- taps "✓ 発行" on the confirmation card (or 5 minutes pass).  Nonce is
-- embedded in the inline keyboard callback_data.
CREATE TABLE IF NOT EXISTS `telegram_pending_intents` (
    `nonce` VARCHAR(32) NOT NULL,
    `admin_user_id` BIGINT UNSIGNED NOT NULL,
    `chat_id` BIGINT NOT NULL,
    `message_id` BIGINT NOT NULL COMMENT 'message_id of the confirmation card we sent',
    `intent_json` TEXT NOT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`nonce`),
    KEY `idx_admin_user_id` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── App Settings ────────────────────────────────────────────
-- Singleton key/value for feature flags (telegram_bot_enabled, etc.).
-- Backed by the database so admins can flip flags from the UI without SSH.
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default: bot disabled until admin explicitly turns it on.
INSERT INTO `app_settings` (`setting_key`, `setting_value`)
VALUES ('telegram_bot_enabled', '0')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
