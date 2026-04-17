<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class PaymentLinkController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        $status    = $_GET['status'] ?? '';
        $search    = $_GET['search'] ?? '';
        $dateFrom  = $_GET['date_from'] ?? '';
        $dateTo    = $_GET['date_to'] ?? '';
        $page      = max(1, (int) ($_GET['page'] ?? 1));
        $perPage   = 20;

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]  = "pl.status = ?";
            $params[] = $status;
        }
        if ($search !== '') {
            $where[]  = "(pl.reference_number LIKE ? OR pl.external_id LIKE ? OR pl.customer_name LIKE ?)";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($dateFrom !== '') {
            $where[]  = "DATE(pl.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[]  = "DATE(pl.created_at) <= ?";
            $params[] = $dateTo;
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM payment_links pl WHERE {$whereStr}",
            $params
        );
        $total = (int) ($totalRow['cnt'] ?? 0);

        $pagination = View::paginate($total, $page, $perPage);
        $offset     = $pagination['offset'];

        $links = $this->db->fetchAll(
            "SELECT pl.*, ac.name as client_name
             FROM payment_links pl
             LEFT JOIN api_clients ac ON ac.id = pl.api_client_id
             WHERE {$whereStr}
             ORDER BY pl.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        View::render('payments/index', [
            'title'      => '決済リンク',
            'links'      => $links,
            'pagination' => $pagination,
            'status'     => $status,
            'search'     => $search,
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function show(string $id): void
    {
        $this->auth->requireAuth();

        $link = $this->db->fetchOne(
            "SELECT pl.*, ac.name as client_name,
                    ba.bank_name, ba.branch_name, ba.account_type,
                    ba.account_number, ba.account_name
             FROM payment_links pl
             LEFT JOIN api_clients ac ON ac.id = pl.api_client_id
             LEFT JOIN bank_accounts ba ON ba.id = pl.bank_account_id
             WHERE pl.id = ? LIMIT 1",
            [$id]
        );

        if ($link === null) {
            http_response_code(404);
            echo '<h1>404 Not Found</h1>';
            exit;
        }

        $deposit = $this->db->fetchOne(
            "SELECT * FROM deposits WHERE payment_link_id = ? LIMIT 1",
            [$id]
        );

        $webhookLogs = $this->db->fetchAll(
            "SELECT * FROM webhook_logs WHERE payment_link_id = ? ORDER BY created_at DESC LIMIT 20",
            [$id]
        );

        // For manual match: unmatched deposits for the same bank
        $unmatchedDeposits = $this->db->fetchAll(
            "SELECT * FROM deposits
             WHERE bank_account_id = ? AND payment_link_id IS NULL
             ORDER BY transaction_date DESC LIMIT 50",
            [$link['bank_account_id']]
        );

        View::render('payments/show', [
            'title'             => '決済詳細 #' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8'),
            'link'              => $link,
            'deposit'           => $deposit,
            'webhookLogs'       => $webhookLogs,
            'unmatchedDeposits' => $unmatchedDeposits,
            'currentUser'       => $this->auth->user(),
        ]);
    }

    /**
     * GET /payments/new — show form to create a new payment link.
     */
    public function create(): void
    {
        $this->auth->requireAuth();

        // Pre-fetch active API clients and bank accounts for the form dropdowns
        $clients = $this->db->fetchAll(
            "SELECT id, name FROM api_clients WHERE is_active = 1 ORDER BY id ASC"
        );
        $banks = $this->db->fetchAll(
            "SELECT id, bank_name, branch_name, account_number
             FROM bank_accounts
             WHERE is_active = 1
             ORDER BY id ASC"
        );

        if (empty($banks)) {
            View::setFlash('error', 'アクティブな銀行口座がありません。先に銀行口座を登録してください。');
            header('Location: /banks/new');
            exit;
        }
        if (empty($clients)) {
            View::setFlash('error', 'API クライアントが存在しません。先に作成してください。');
            header('Location: /clients/new');
            exit;
        }

        View::render('payments/new', [
            'title'       => '決済リンクを新規作成',
            'clients'     => $clients,
            'banks'       => $banks,
            'old'         => $_SESSION['_old_input'] ?? [],
            'errors'      => $_SESSION['_errors'] ?? [],
            'csrf'        => Auth::csrfToken(),
            'currentUser' => $this->auth->user(),
        ]);

        unset($_SESSION['_old_input'], $_SESSION['_errors']);
    }

    /**
     * POST /payments — create the payment link.
     *
     * Three modes (selected by `mode` form field):
     *   - fixed (default)          : operator specifies amount + kana upfront
     *   - awaiting_input           : single-use link with blank amount; customer fills it in
     *   - template                 : reusable URL; each customer visit spawns a child link
     *
     * For awaiting_input/template we skip the per-customer fields and instead
     * accept min_amount / max_amount / preset_amounts to constrain what the
     * customer can submit.
     */
    public function store(): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $mode = (string) ($_POST['mode'] ?? 'fixed');
        if (!in_array($mode, ['fixed', 'awaiting_input', 'template'], true)) {
            $mode = 'fixed';
        }

        // Shared fields
        $clientId     = (int) ($_POST['api_client_id'] ?? 0);
        $bankId       = (int) ($_POST['bank_account_id'] ?? 0);
        $expiresHours = max(1, min(720, (int) ($_POST['expires_hours'] ?? 72)));
        $errors       = [];

        if ($clientId <= 0) {
            $errors['api_client_id'] = 'API クライアントを選択してください。';
        }
        if ($bankId <= 0) {
            $errors['bank_account_id'] = '振込先銀行口座を選択してください。';
        }

        // Mode-specific collection
        if ($mode === 'fixed') {
            $amount       = (int) ($_POST['amount'] ?? 0);
            $customerName = trim((string) ($_POST['customer_name'] ?? ''));
            $customerKana = trim((string) ($_POST['customer_kana'] ?? ''));
            $customerEmail= trim((string) ($_POST['customer_email'] ?? ''));
            $externalId   = trim((string) ($_POST['external_id'] ?? ''));

            if ($amount <= 0) {
                $errors['amount'] = '金額は 1 円以上で入力してください。';
            }
            if ($customerName === '') {
                $errors['customer_name'] = '顧客名は必須です。';
            }
            if ($customerKana === '') {
                $errors['customer_kana'] = '振込依頼人名（カナ）は必須です。';
            } elseif (!preg_match('/^[\p{Katakana}\p{Hiragana}ー\s　A-Za-z0-9]+$/u', $customerKana)) {
                $errors['customer_kana'] = 'カタカナ（または半角英数字）のみ入力してください。';
            }
            if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                $errors['customer_email'] = 'メールアドレスの形式が正しくありません。';
            }
        } else {
            // awaiting_input / template
            $minAmount = (int) ($_POST['min_amount'] ?? 1000);
            $maxAmount = (int) ($_POST['max_amount'] ?? 500_000);
            $presetsIn = trim((string) ($_POST['preset_amounts'] ?? ''));
            $presets   = [];
            if ($presetsIn !== '') {
                foreach (preg_split('/[,\s、　]+/u', $presetsIn) ?: [] as $v) {
                    $v = (int) str_replace(',', '', $v);
                    if ($v > 0) {
                        $presets[] = $v;
                    }
                }
            }
            if ($minAmount < 1 || $minAmount > 9_999_999) {
                $errors['min_amount'] = '最小金額は 1 〜 9,999,999 で指定してください。';
            }
            if ($maxAmount < $minAmount || $maxAmount > 9_999_999) {
                $errors['max_amount'] = '最大金額は最小金額以上、9,999,999 以下で指定してください。';
            }
        }

        if ($errors) {
            $_SESSION['_old_input'] = $_POST;
            $_SESSION['_errors'] = $errors;
            header('Location: /payments/new');
            exit;
        }

        // Verify client and bank exist
        $client = $this->db->fetchOne(
            "SELECT id, callback_url FROM api_clients WHERE id = ? AND is_active = 1 LIMIT 1",
            [$clientId]
        );
        $bank = $this->db->fetchOne(
            "SELECT id FROM bank_accounts WHERE id = ? AND is_active = 1 LIMIT 1",
            [$bankId]
        );
        if ($client === null || $bank === null) {
            View::setFlash('error', '選択した API クライアントまたは銀行口座が無効です。');
            header('Location: /payments/new');
            exit;
        }

        $token     = bin2hex(random_bytes(16));
        $id        = 'bp_' . $this->generateUlid();
        $now       = date('Y-m-d H:i:s');

        if ($mode === 'fixed') {
            // Normalize hiragana → katakana
            $customerKana = mb_convert_kana($customerKana, 'C');
            $reference    = $this->generateReferenceNumber();
            $expiresAt    = date('Y-m-d H:i:s', time() + $expiresHours * 3600);

            $this->db->query(
                "INSERT INTO payment_links
                    (id, api_client_id, bank_account_id, external_id, reference_number,
                     amount, currency, customer_name, customer_kana, customer_email,
                     callback_url, status, link_type, token, expires_at, source, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'JPY', ?, ?, ?, ?, 'pending', 'single', ?, ?, 'admin_web', ?, ?)",
                [
                    $id, $clientId, $bankId,
                    $externalId !== '' ? $externalId : null,
                    $reference, $amount,
                    $customerName, $customerKana,
                    $customerEmail !== '' ? $customerEmail : null,
                    $client['callback_url'] ?? null,
                    $token, $expiresAt, $now, $now,
                ]
            );
        } elseif ($mode === 'awaiting_input') {
            // Blank link — customer supplies amount + (optional) kana at /p/{token}.
            $expiresAt     = date('Y-m-d H:i:s', time() + $expiresHours * 3600);
            $presetsJson   = !empty($presets) ? json_encode($presets) : null;

            $this->db->query(
                "INSERT INTO payment_links
                    (id, api_client_id, bank_account_id, callback_url,
                     status, link_type, min_amount, max_amount, preset_amounts,
                     currency, token, expires_at, source, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'awaiting_input', 'awaiting_input', ?, ?, ?, 'JPY', ?, ?, 'admin_web', ?, ?)",
                [
                    $id, $clientId, $bankId,
                    $client['callback_url'] ?? null,
                    $minAmount, $maxAmount, $presetsJson,
                    $token, $expiresAt, $now, $now,
                ]
            );
        } else {
            // mode === 'template' — reusable URL that spawns children.
            $expiresAt     = date('Y-m-d H:i:s', strtotime('+1 year'));
            $presetsJson   = !empty($presets) ? json_encode($presets) : null;

            $this->db->query(
                "INSERT INTO payment_links
                    (id, api_client_id, bank_account_id, callback_url,
                     status, link_type, min_amount, max_amount, preset_amounts,
                     currency, token, expires_at, source, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'pending', 'template', ?, ?, ?, 'JPY', ?, ?, 'admin_web', ?, ?)",
                [
                    $id, $clientId, $bankId,
                    $client['callback_url'] ?? null,
                    $minAmount, $maxAmount, $presetsJson,
                    $token, $expiresAt, $now, $now,
                ]
            );
        }

        // Ensure a scraper task is queued for this bank (awaiting_input has
        // no amount yet, but the task will pick up any children it spawns).
        $existingTask = $this->db->fetchOne(
            "SELECT id FROM scraper_tasks
             WHERE bank_account_id = ? AND status IN ('queued', 'running')
             LIMIT 1",
            [$bankId]
        );
        if ($existingTask === null) {
            $this->db->query(
                "INSERT INTO scraper_tasks
                    (bank_account_id, status, next_run_at, run_count, created_at, updated_at)
                 VALUES (?, 'queued', ?, 0, ?, ?)",
                [$bankId, $now, $now, $now]
            );
        }

        $flash = match ($mode) {
            'template'        => '使いまわせる決済リンク（テンプレート）を作成しました。URL をお客様にお送りください。',
            'awaiting_input'  => '金額未確定の決済リンクを作成しました。お客様が金額を入力すると有効になります。',
            default           => '決済リンクを作成しました。下記のリンクをお客様にお送りください。',
        };
        View::setFlash('success', $flash);
        header("Location: /payments/{$id}?created=1");
        exit;
    }

    /**
     * Generate a unique 7-digit numeric reference number.
     */
    private function generateReferenceNumber(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $candidate = (string) random_int(1_000_000, 9_999_999);
            $existing = $this->db->fetchOne(
                "SELECT id FROM payment_links WHERE reference_number = ? LIMIT 1",
                [$candidate]
            );
            if ($existing === null) {
                return $candidate;
            }
        }
        throw new \RuntimeException('Could not generate unique reference number after 20 attempts');
    }

    /**
     * Generate a Crockford Base32 ULID (26 chars).
     */
    private function generateUlid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time     = (int) (microtime(true) * 1000);
        $timeStr  = '';
        for ($i = 9; $i >= 0; $i--) {
            $timeStr = $alphabet[$time & 31] . $timeStr;
            $time >>= 5;
        }
        $randStr = '';
        for ($i = 0; $i < 16; $i++) {
            $randStr .= $alphabet[random_int(0, 31)];
        }
        return $timeStr . $randStr;
    }

    public function cancel(string $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $link = $this->db->fetchOne(
            "SELECT id, status, link_type FROM payment_links WHERE id = ? LIMIT 1",
            [$id]
        );

        if ($link === null) {
            http_response_code(404);
            exit;
        }

        // Cancellable statuses include awaiting_input (blank links issued by
        // mistake) and pending (including template links).  Confirmed /
        // expired / already-cancelled rows are terminal.
        if (!in_array($link['status'], ['pending', 'awaiting_input'], true)) {
            View::setFlash('error', 'キャンセルできないステータスです（既に入金済・期限切れ・キャンセル済）。');
            header("Location: /payments/{$id}");
            exit;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->update(
            'payment_links',
            ['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now],
            ['id' => $id]
        );

        // Template cancellation cascades to every still-pending child so a
        // customer who is mid-way through a template-spawned flow does not
        // keep a live payment page open after the operator pulled the plug.
        if ($link['link_type'] === 'template') {
            $this->db->query(
                "UPDATE payment_links
                    SET status = 'cancelled', cancelled_at = ?, updated_at = ?
                  WHERE parent_link_id = ? AND status = 'pending'",
                [$now, $now, $id]
            );
        }

        View::setFlash('success', '決済リンクをキャンセルしました。');
        header("Location: /payments/{$id}");
        exit;
    }

    public function manualMatch(string $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $depositId = (int) ($_POST['deposit_id'] ?? 0);
        if ($depositId <= 0) {
            View::setFlash('error', '入金IDを指定してください。');
            header("Location: /payments/{$id}");
            exit;
        }

        $link = $this->db->fetchOne(
            "SELECT * FROM payment_links WHERE id = ? AND status = 'pending' LIMIT 1",
            [$id]
        );

        if ($link === null) {
            View::setFlash('error', '対象の決済リンクが見つかりません。');
            header("Location: /payments/{$id}");
            exit;
        }

        $deposit = $this->db->fetchOne(
            "SELECT * FROM deposits WHERE id = ? AND payment_link_id IS NULL LIMIT 1",
            [$depositId]
        );

        if ($deposit === null) {
            View::setFlash('error', '対象の入金が見つかりません。すでにマッチ済みの可能性があります。');
            header("Location: /payments/{$id}");
            exit;
        }

        $now = date('Y-m-d H:i:s');

        // Match the deposit
        $this->db->update(
            'deposits',
            ['payment_link_id' => $id, 'matched_at' => $now],
            ['id' => $depositId]
        );

        // Confirm the payment link
        $this->db->update(
            'payment_links',
            ['status' => 'confirmed', 'confirmed_at' => $now, 'updated_at' => $now],
            ['id' => $id]
        );

        View::setFlash('success', '入金と決済リンクを手動マッチしました。');
        header("Location: /payments/{$id}");
        exit;
    }
}
