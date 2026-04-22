-- ============================================================
-- B-CashPay Database Schema — SQLite edition
-- Compatible with SQLite 3.35+
-- ============================================================

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ── Admin Users ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'operator' CHECK(role IN ('admin','operator')),
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_admin_username ON admin_users(username);

-- ── API Clients ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS api_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    api_key TEXT NOT NULL,
    webhook_secret TEXT NOT NULL DEFAULT '',
    callback_url TEXT DEFAULT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_api_key ON api_clients(api_key);
CREATE INDEX IF NOT EXISTS idx_api_clients_is_active ON api_clients(is_active);

-- ── Bank Accounts ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bank_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bank_name TEXT NOT NULL,
    bank_code TEXT NOT NULL DEFAULT '',
    branch_name TEXT NOT NULL DEFAULT '',
    branch_code TEXT NOT NULL DEFAULT '',
    account_type TEXT NOT NULL DEFAULT '普通',
    account_number TEXT NOT NULL,
    account_name TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    scrape_login_url TEXT DEFAULT NULL,
    scrape_credentials_json TEXT DEFAULT NULL,
    scrape_adapter_key TEXT DEFAULT NULL,
    scrape_interval_minutes INTEGER NOT NULL DEFAULT 15,
    scrape_status TEXT NOT NULL DEFAULT 'setup_pending',
    scrape_last_at TEXT DEFAULT NULL,
    scrape_last_success_at TEXT DEFAULT NULL,
    scrape_last_error_at TEXT DEFAULT NULL,
    scrape_last_error_message TEXT DEFAULT NULL,
    scrape_consecutive_failures INTEGER NOT NULL DEFAULT 0,
    current_balance INTEGER DEFAULT NULL,
    balance_updated_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_account_number ON bank_accounts(bank_code, account_number);
CREATE INDEX IF NOT EXISTS idx_bank_accounts_is_active ON bank_accounts(is_active);
CREATE INDEX IF NOT EXISTS idx_bank_accounts_scrape_status ON bank_accounts(scrape_status);

-- ── Payment Links ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payment_links (
    id TEXT NOT NULL,
    api_client_id INTEGER NOT NULL,
    bank_account_id INTEGER NOT NULL,
    external_id TEXT DEFAULT NULL,
    reference_number TEXT NOT NULL,
    amount REAL NOT NULL,
    currency TEXT NOT NULL DEFAULT 'JPY',
    customer_name TEXT NOT NULL,
    customer_kana TEXT DEFAULT NULL,
    customer_email TEXT DEFAULT NULL,
    callback_url TEXT DEFAULT NULL,
    metadata TEXT DEFAULT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','confirmed','expired','cancelled')),
    token TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    confirmed_at TEXT DEFAULT NULL,
    cancelled_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    PRIMARY KEY (id),
    FOREIGN KEY (api_client_id) REFERENCES api_clients(id),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_reference_number ON payment_links(reference_number);
CREATE UNIQUE INDEX IF NOT EXISTS uk_token ON payment_links(token);
CREATE INDEX IF NOT EXISTS idx_payment_links_api_client_id ON payment_links(api_client_id);
CREATE INDEX IF NOT EXISTS idx_payment_links_bank_account_id ON payment_links(bank_account_id);
CREATE INDEX IF NOT EXISTS idx_payment_links_status ON payment_links(status);
CREATE INDEX IF NOT EXISTS idx_payment_links_external_id ON payment_links(api_client_id, external_id);
CREATE INDEX IF NOT EXISTS idx_payment_links_expires_at ON payment_links(expires_at);
CREATE INDEX IF NOT EXISTS idx_payment_links_created_at ON payment_links(created_at);

-- ── Deposits ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS deposits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bank_account_id INTEGER NOT NULL,
    payment_link_id TEXT DEFAULT NULL,
    depositor_name TEXT NOT NULL,
    amount REAL NOT NULL,
    transaction_date TEXT NOT NULL,
    bank_transaction_id TEXT NOT NULL,
    matched_at TEXT DEFAULT NULL,
    raw_data TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id),
    FOREIGN KEY (payment_link_id) REFERENCES payment_links(id)
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_bank_transaction ON deposits(bank_account_id, bank_transaction_id);
CREATE INDEX IF NOT EXISTS idx_deposits_payment_link_id ON deposits(payment_link_id);
CREATE INDEX IF NOT EXISTS idx_deposits_transaction_date ON deposits(transaction_date);
CREATE INDEX IF NOT EXISTS idx_deposits_unmatched ON deposits(payment_link_id, bank_account_id);

-- ── Scraper Tasks ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS scraper_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    bank_account_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK(status IN ('queued','running','completed','failed')),
    last_run_at TEXT DEFAULT NULL,
    next_run_at TEXT DEFAULT NULL,
    run_count INTEGER NOT NULL DEFAULT 0,
    transactions_found INTEGER NOT NULL DEFAULT 0,
    transactions_matched INTEGER NOT NULL DEFAULT 0,
    duration_seconds REAL DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts(id)
);
CREATE INDEX IF NOT EXISTS idx_scraper_tasks_bank_account_id ON scraper_tasks(bank_account_id);
CREATE INDEX IF NOT EXISTS idx_scraper_tasks_status ON scraper_tasks(status);
CREATE INDEX IF NOT EXISTS idx_scraper_tasks_next_run_at ON scraper_tasks(next_run_at);

-- ── Webhook Logs ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_link_id TEXT NOT NULL,
    url TEXT NOT NULL,
    request_body TEXT NOT NULL,
    response_code INTEGER DEFAULT NULL,
    response_body TEXT DEFAULT NULL,
    attempt INTEGER NOT NULL DEFAULT 1,
    delivered_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
    FOREIGN KEY (payment_link_id) REFERENCES payment_links(id)
);
CREATE INDEX IF NOT EXISTS idx_webhook_logs_payment_link_id ON webhook_logs(payment_link_id);
CREATE INDEX IF NOT EXISTS idx_webhook_logs_delivered_at ON webhook_logs(delivered_at);

-- ── Telegram Logs ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS telegram_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_link_id TEXT DEFAULT NULL,
    chat_id TEXT NOT NULL,
    message TEXT NOT NULL,
    sent_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX IF NOT EXISTS idx_telegram_logs_payment_link_id ON telegram_logs(payment_link_id);
CREATE INDEX IF NOT EXISTS idx_telegram_logs_sent_at ON telegram_logs(sent_at);
