<?php

declare(strict_types=1);

/**
 * Seed script for B-Pay Admin.
 *
 * Creates default admin user, sample API client, bank account, and payment links.
 * Run: php admin/database/seed.php
 */

$dbPath = dirname(__DIR__, 2) . '/api/database/bcashpay.sqlite';

if (!is_file($dbPath)) {
    fwrite(STDERR, "Database not found. Run migrate.php first.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, options: [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Seeding database: {$dbPath}\n\n";

// ── Admin User ──────────────────────────────────────────────
$existing = $pdo->query("SELECT id FROM admin_users WHERE username = 'admin'")->fetch();
if (!$existing) {
    $hash = password_hash('admin123', PASSWORD_BCRYPT);
    $pdo->prepare(
        "INSERT INTO admin_users (username, password_hash, name, email, role, is_active) VALUES (?, ?, ?, ?, ?, ?)"
    )->execute(['admin', $hash, '管理者', 'admin@bcashpay.com', 'admin', 1]);
    echo "[OK] Admin user created: admin / admin123\n";
} else {
    echo "[SKIP] Admin user already exists.\n";
}

// ── API Client ──────────────────────────────────────────────
$existingClient = $pdo->query("SELECT id FROM api_clients WHERE name = 'Telebet'")->fetch();
$apiKey = bin2hex(random_bytes(32)); // 64-char hex
$webhookSecret = bin2hex(random_bytes(64));

if (!$existingClient) {
    $pdo->prepare(
        "INSERT INTO api_clients (name, api_key, webhook_secret, callback_url, is_active) VALUES (?, ?, ?, ?, ?)"
    )->execute(['Telebet', $apiKey, $webhookSecret, 'https://telebet.example.com/webhooks/payment', 1]);
    $clientId = (int) $pdo->lastInsertId();
    echo "[OK] API Client created: Telebet\n";
    echo "     api_key: {$apiKey}\n";
    echo "     webhook_secret: {$webhookSecret}\n";
} else {
    $clientId = (int) $existingClient['id'];
    // Retrieve existing key for display
    $row = $pdo->prepare("SELECT api_key FROM api_clients WHERE id = ?")->execute([$clientId]);
    echo "[SKIP] API Client 'Telebet' already exists (id={$clientId}).\n";
}

// ── Bank Account ────────────────────────────────────────────
$existingBank = $pdo->query("SELECT id FROM bank_accounts WHERE account_number = '1234567' AND bank_code = '0036'")->fetch();

if (!$existingBank) {
    $credentials = json_encode([
        'username' => 'testuser',
        'password' => 'testpass',
        'totp_secret' => '',
    ]);
    $pdo->prepare(
        "INSERT INTO bank_accounts
            (bank_name, bank_code, branch_name, branch_code, account_type, account_number, account_name,
             scrape_adapter_key, scrape_login_url, scrape_credentials_json, scrape_interval_minutes,
             scrape_status, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        '楽天銀行', '0036', '法人営業部', '001', '普通', '1234567', 'カ）テストコーポレーション',
        'rakuten', 'https://fes.rakuten-bank.co.jp/MS/main/RbS', $credentials, 15,
        'active', 1,
    ]);
    $bankId = (int) $pdo->lastInsertId();
    echo "[OK] Bank account created: 楽天銀行 / 1234567\n";
} else {
    $bankId = (int) $existingBank['id'];
    echo "[SKIP] Bank account already exists (id={$bankId}).\n";
}

// ── Payment Links ───────────────────────────────────────────
$existingLinks = $pdo->query("SELECT COUNT(*) as cnt FROM payment_links")->fetch();

if ((int) ($existingLinks['cnt'] ?? 0) === 0) {
    // Helper: generate ULID-like ID for SQLite
    $makeId = function (): string {
        $chars = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $timeMs = (int) (microtime(true) * 1000);
        $ulid = '';
        $time = $timeMs;
        for ($i = 9; $i >= 0; $i--) {
            $ulid[$i] = $chars[$time & 0x1F];
            $time >>= 5;
        }
        $randomBytes = random_bytes(10);
        $random = 0;
        for ($i = 0; $i < 10; $i++) {
            $random = ($random << 8) | ord($randomBytes[$i]);
        }
        $temp = '';
        for ($i = 0; $i < 16; $i++) {
            $temp = $chars[$random & 0x1F] . $temp;
            $random >>= 5;
        }
        return $ulid . $temp;
    };

    $now = date('Y-m-d H:i:s');
    $expires72h = date('Y-m-d H:i:s', strtotime('+72 hours'));
    $expiredTime = date('Y-m-d H:i:s', strtotime('-1 hour'));

    // Pending payment link
    $id1 = $makeId();
    usleep(1000); // Ensure unique ULID timestamps
    $pdo->prepare(
        "INSERT INTO payment_links
            (id, api_client_id, bank_account_id, external_id, reference_number, amount, currency,
             customer_name, customer_kana, customer_email, callback_url, status, token, expires_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $id1, $clientId, $bankId, 'ORDER-001', '1000001', 50000, 'JPY',
        '山田 太郎', 'ヤマダ タロウ', 'yamada@example.com', 'https://telebet.example.com/webhooks/payment',
        'pending', bin2hex(random_bytes(16)), $expires72h, $now, $now,
    ]);

    // Confirmed payment link
    $id2 = $makeId();
    $confirmedAt = date('Y-m-d H:i:s', strtotime('-2 hours'));
    $pdo->prepare(
        "INSERT INTO payment_links
            (id, api_client_id, bank_account_id, external_id, reference_number, amount, currency,
             customer_name, customer_kana, customer_email, callback_url, status, token, expires_at, confirmed_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $id2, $clientId, $bankId, 'ORDER-002', '1000002', 120000, 'JPY',
        '鈴木 花子', 'スズキ ハナコ', 'suzuki@example.com', 'https://telebet.example.com/webhooks/payment',
        'confirmed', bin2hex(random_bytes(16)), $expires72h, $confirmedAt, $confirmedAt, $now,
    ]);

    // Add a matching deposit for the confirmed link
    $pdo->prepare(
        "INSERT INTO deposits
            (bank_account_id, payment_link_id, depositor_name, amount, transaction_date,
             bank_transaction_id, matched_at, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $bankId, $id2, 'スズキ ハナコ 1000002', 120000,
        $confirmedAt, 'TXN-' . uniqid(), $confirmedAt, $now,
    ]);

    // Add scraper task record
    $pdo->prepare(
        "INSERT INTO scraper_tasks
            (bank_account_id, status, last_run_at, next_run_at, run_count,
             transactions_found, transactions_matched, duration_seconds, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $bankId, 'completed', $confirmedAt,
        date('Y-m-d H:i:s', strtotime('+15 minutes', strtotime($confirmedAt))),
        5, 3, 1, 4.23, $now, $now,
    ]);

    echo "[OK] Sample payment links created (1 pending, 1 confirmed with deposit)\n";
    echo "[OK] Sample scraper task created\n";
} else {
    echo "[SKIP] Payment links already exist.\n";
}

echo "\n=== Seed complete ===\n";
echo "URL: http://localhost:8001\n";
echo "Login: admin / admin123\n";
