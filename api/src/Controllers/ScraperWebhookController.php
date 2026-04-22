<?php

declare(strict_types=1);

namespace BCashPay\Controllers;

use BCashPay\Database;
use BCashPay\Services\PaymentLinkService;
use BCashPay\Services\ReferenceGenerator;
use BCashPay\Services\TelegramNotifier;
use BCashPay\Services\WebhookSender;

/**
 * ScraperWebhookController — Internal endpoints for the Python bank scraper.
 *
 * These endpoints are protected by HMAC authentication (HmacAuth middleware).
 * They are NOT exposed to public API clients.
 *
 * POST /api/internal/scraper/deposits — Receive matched deposits
 * GET  /api/internal/scraper/tasks    — Get pending scraper tasks
 * POST /api/internal/scraper/status   — Receive scraper run status / health report
 */
class ScraperWebhookController
{
    private readonly Database $db;
    private readonly PaymentLinkService $service;
    private readonly TelegramNotifier $telegram;

    public function __construct()
    {
        $this->db      = Database::getInstance();
        $this->telegram = new TelegramNotifier($this->db);
        $this->service  = new PaymentLinkService(
            $this->db,
            new ReferenceGenerator($this->db),
            $this->telegram,
            new WebhookSender($this->db)
        );
    }

    /**
     * POST /api/internal/scraper/deposits
     * Receive a batch of scraped deposits, deduplicate, and match to payment links.
     *
     * Expected payload:
     * {
     *   "bank_account_id": 1,
     *   "deposits": [
     *     {
     *       "depositor_name": "ヤマダ タロウ 1234567",
     *       "amount": 10000,
     *       "transaction_date": "2026-04-16 12:00:00",
     *       "bank_transaction_id": "unique-bank-id-123"
     *     }
     *   ]
     * }
     */
    public function receiveDeposit(): never
    {
        // Body may have been read by HmacAuth; retrieve from the stashed copy
        $rawBody = $_SERVER['BCASHPAY_RAW_BODY'] ?? file_get_contents('php://input');
        $data    = json_decode((string) $rawBody, true);

        if (!is_array($data)) {
            json_error('Invalid JSON payload', 400);
        }

        $deposits       = $data['deposits'] ?? [];
        $bankAccountId  = isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $currentBalance = array_key_exists('current_balance', $data) && is_numeric($data['current_balance'])
            ? (int) $data['current_balance']
            : null;

        // Verify bank account exists when provided
        if ($bankAccountId !== null) {
            $bankAccount = $this->db->fetchOne(
                'SELECT id FROM bank_accounts WHERE id = ? LIMIT 1',
                [$bankAccountId]
            );
            if ($bankAccount === null) {
                json_error('Bank account not found', 404);
            }
        }

        // Persist balance whenever the scraper reports one, independent of
        // whether there are deposits to match.
        if ($bankAccountId !== null && $currentBalance !== null) {
            $this->db->update(
                'bank_accounts',
                [
                    'current_balance'    => $currentBalance,
                    'balance_updated_at' => now_jst(),
                ],
                ['id' => $bankAccountId]
            );
        }

        if (!is_array($deposits) || count($deposits) === 0) {
            json_response([
                'success' => true,
                'matched' => 0,
                'message' => $currentBalance !== null ? 'Balance updated, no deposits' : 'No deposits received',
            ]);
        }

        $matched  = 0;
        $skipped  = 0;
        $errors   = [];
        $received = count($deposits);

        foreach ($deposits as $deposit) {
            $depositorName    = trim((string) ($deposit['depositor_name'] ?? ''));
            $amount           = (int) ($deposit['amount'] ?? 0);
            $transactionDate  = $deposit['transaction_date'] ?? now_jst();
            $bankTransactionId = trim((string) ($deposit['bank_transaction_id'] ?? ''));
            $rawData          = $deposit;

            if ($amount <= 0 || $depositorName === '' || $bankTransactionId === '') {
                $skipped++;
                continue;
            }

            // Determine bank_account_id — prefer payload-level, fallback to deposit-level
            $resolvedBankAccountId = $bankAccountId
                ?? (isset($deposit['bank_account_id']) ? (int) $deposit['bank_account_id'] : null);

            if ($resolvedBankAccountId === null) {
                $skipped++;
                continue;
            }

            try {
                $result = $this->processDeposit(
                    $resolvedBankAccountId,
                    $depositorName,
                    $amount,
                    $transactionDate,
                    $bankTransactionId,
                    $rawData
                );

                if ($result === 'matched') {
                    $matched++;
                } elseif ($result === 'duplicate') {
                    $skipped++;
                } else {
                    // 'unmatched' — deposit saved but no payment link matched
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        json_response([
            'success'  => true,
            'received' => $received,
            'matched'  => $matched,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    /**
     * GET /api/internal/scraper/tasks
     * Return pending scraper tasks grouped by bank_account_id.
     *
     * The scraper uses this to know which bank accounts to scrape and what
     * reference numbers + amounts to look for.
     */
    public function getTasks(): never
    {
        // Fetch bank accounts that have pending scraper tasks
        $tasks = $this->db->fetchAll(
            'SELECT st.id AS task_id,
                    st.bank_account_id,
                    st.next_run_at,
                    ba.bank_name,
                    ba.bank_code,
                    ba.branch_name,
                    ba.account_number,
                    ba.scrape_adapter_key,
                    ba.scrape_login_url,
                    ba.scrape_interval_minutes
             FROM scraper_tasks st
             JOIN bank_accounts ba ON ba.id = st.bank_account_id
             WHERE st.status = ?
               AND (st.next_run_at IS NULL OR st.next_run_at <= ?)
               AND ba.is_active = 1
             ORDER BY st.next_run_at ASC
             LIMIT 50',
            ['queued', now_jst()]
        );

        // For each task, fetch the pending payment links that need to be matched
        $result = [];
        foreach ($tasks as $task) {
            $bankAccountId = (int) $task['bank_account_id'];

            $pendingLinks = $this->db->fetchAll(
                'SELECT id, reference_number, amount, customer_name, expires_at, created_at
                 FROM payment_links
                 WHERE bank_account_id = ?
                   AND status = ?
                   AND expires_at > ?
                   AND created_at >= ?
                 ORDER BY created_at DESC',
                [
                    $bankAccountId,
                    'pending',
                    now_jst(),
                    date('Y-m-d H:i:s', strtotime('-14 days')),
                ]
            );

            $result[] = [
                'task_id'         => $task['task_id'],
                'bank_account_id' => $bankAccountId,
                'bank_name'       => $task['bank_name'],
                'bank_code'       => $task['bank_code'],
                'branch_name'     => $task['branch_name'],
                'account_number'  => $task['account_number'],
                'adapter_key'     => $task['scrape_adapter_key'],
                'login_url'       => $task['scrape_login_url'],
                'interval_minutes'=> (int) $task['scrape_interval_minutes'],
                'next_run_at'     => $task['next_run_at'],
                'pending_payments'=> array_map(fn($link) => [
                    'id'               => $link['id'],
                    'reference_number' => $link['reference_number'],
                    'amount'           => (int) $link['amount'],
                    'customer_name'    => $link['customer_name'],
                    'expires_at'       => $link['expires_at'],
                ], $pendingLinks),
            ];
        }

        json_response([
            'success' => true,
            'data'    => $result,
            'count'   => count($result),
        ]);
    }

    /**
     * POST /api/internal/scraper/status
     * Receive a per-run status / health report from the Python scraper.
     *
     * The scraper posts a lightweight summary at the end of every cycle so
     * the API-side health view has an end-to-end picture (login succeeded,
     * N transactions extracted, M matched, duration).  This endpoint does
     * not mutate domain state — it only records the reported run metadata
     * into scraper_tasks for observability, which the monitor and admin
     * dashboard can read.
     *
     * Expected payload:
     * {
     *   "bank_account_id": 1,
     *   "status": "success" | "failure",
     *   "message": "Extracted 24, matched 0",
     *   "stats": {
     *     "transactions_found": 24,
     *     "transactions_matched": 0,
     *     "duration_seconds": 38.14
     *   }
     * }
     */
    public function receiveStatus(): never
    {
        $rawBody = $_SERVER['BCASHPAY_RAW_BODY'] ?? file_get_contents('php://input');
        $data    = json_decode((string) $rawBody, true);

        if (!is_array($data)) {
            json_error('Invalid JSON payload', 400);
        }

        $bankAccountId = isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $status        = (string) ($data['status'] ?? '');
        $message       = isset($data['message']) ? (string) $data['message'] : null;
        $stats         = isset($data['stats']) && is_array($data['stats']) ? $data['stats'] : [];

        if ($bankAccountId === null || $bankAccountId <= 0) {
            json_error('bank_account_id is required', 400);
        }
        if ($status === '') {
            json_error('status is required', 400);
        }

        // Accept 'success' / 'failure' from the scraper and normalise to the
        // scraper_tasks enum used elsewhere in the system.
        $normalisedStatus = match ($status) {
            'success', 'completed' => 'completed',
            'failure', 'failed'    => 'failed',
            default                 => 'completed',
        };

        $transactionsFound   = (int) ($stats['transactions_found'] ?? 0);
        $transactionsMatched = (int) ($stats['transactions_matched'] ?? 0);
        $durationSeconds     = isset($stats['duration_seconds']) ? (float) $stats['duration_seconds'] : null;

        // Note: run_bank_scrape() in runner.py also inserts a scraper_tasks
        // row locally, so this endpoint is additive/redundant by design.
        // We log it anyway so an API-only observer has the full picture.
        $this->db->insert('scraper_tasks', [
            'bank_account_id'      => $bankAccountId,
            'status'               => $normalisedStatus,
            'last_run_at'          => now_jst(),
            'run_count'            => 1,
            'transactions_found'   => $transactionsFound,
            'transactions_matched' => $transactionsMatched,
            'duration_seconds'     => $durationSeconds,
            'error_message'        => $normalisedStatus === 'failed' ? $message : null,
            'created_at'           => now_jst(),
            'updated_at'           => now_jst(),
        ]);

        json_response([
            'success' => true,
            'status'  => $normalisedStatus,
        ]);
    }

    /**
     * Process a single deposit: deduplicate, persist, and attempt matching.
     *
     * Matching algorithm (ported from BankScraperController::scraperDeposits):
     *   Stage 1: Candidate set — same amount + bank_account_id, status=pending, <= 14 days old
     *   Stage 2: Reference number prefix match with full-width normalization
     *            (mb_convert_kana 'as': 全角ASCII->半角, 全角スペース->半角)
     *   Stage 3: Fallback — if exactly 1 candidate, auto-match
     *
     * @return string 'matched' | 'unmatched' | 'duplicate'
     */
    private function processDeposit(
        int $bankAccountId,
        string $depositorName,
        int $amount,
        string $transactionDate,
        string $bankTransactionId,
        array $rawData
    ): string {
        // Idempotency check: if this (bank_account_id, bank_transaction_id) already exists, skip
        $existing = $this->db->fetchOne(
            'SELECT id, payment_link_id FROM deposits
             WHERE bank_account_id = ? AND bank_transaction_id = ?
             LIMIT 1',
            [$bankAccountId, $bankTransactionId]
        );

        if ($existing !== null) {
            return 'duplicate';
        }

        // Normalize depositor name for matching:
        // mb_convert_kana 'as': 全角英数・記号 -> 半角, 全角スペース -> 半角スペース
        $normalizedDepositor = mb_strtoupper(mb_convert_kana($depositorName, 'as'));

        // Parse the deposit timestamp.  If it is unparseable we refuse to
        // match — silently defaulting to "now" would undo the guard below
        // and let stale bank-history rows match freshly-created links.
        $depositTs = strtotime((string) $transactionDate);
        if ($depositTs === false) {
            // Persist as unmatched so the operator can see it.
            $this->db->insert('deposits', [
                'bank_account_id'    => $bankAccountId,
                'payment_link_id'    => null,
                'depositor_name'     => $depositorName,
                'amount'             => $amount,
                'transaction_date'   => null,
                'bank_transaction_id'=> $bankTransactionId,
                'matched_at'         => null,
                'raw_data'           => json_encode($rawData),
                'created_at'         => now_jst(),
            ]);
            return 'unmatched';
        }

        // Stage 1: candidate set — status=pending, same bank, created <= 14
        // days ago, and the candidate must have been created on or before
        // the deposit's calendar day (a deposit cannot belong to a link
        // that did not yet exist when the money arrived — this stops old
        // deposits in bank history from being attributed to newly-created
        // links).
        //
        // The upper bound is compared at the **date** level rather than the
        // timestamp level because Rakuten's transaction list exposes only
        // the date (no clock time), so the scraper hydrates it to 00:00 JST.
        // A strict timestamp compare would exclude every link created after
        // midnight on the same day the deposit posted — i.e. the common
        // case of "customer creates a link at 16:24 JST and pays the same
        // afternoon".
        $candidates = $this->db->fetchAll(
            'SELECT pl.id, pl.reference_number, pl.customer_name,
                    pl.callback_url, pl.amount,
                    ac.webhook_secret, ac.callback_url AS client_callback_url,
                    ac.id AS api_client_id
             FROM payment_links pl
             JOIN api_clients ac ON ac.id = pl.api_client_id
             WHERE pl.bank_account_id = ?
               AND pl.amount = ?
               AND pl.status = ?
               AND pl.created_at >= ?
               AND DATE(pl.created_at) <= DATE(?)
             ORDER BY pl.created_at DESC',
            [
                $bankAccountId,
                $amount,
                'pending',
                date('Y-m-d H:i:s', strtotime('-14 days')),
                date('Y-m-d H:i:s', $depositTs),
            ]
        );

        $matched = null;

        // Stage 2: STRICT reference-number presence check.  The request's
        // reference_number must appear verbatim (after full-width→half-width
        // normalisation) in the depositor-name string.
        //
        // We deliberately do NOT fall back to a "single-candidate amount
        // match" — that behaviour lets past deposits and look-alike
        // transactions attach themselves to unrelated links.  If no
        // reference matches, the deposit goes to the unmatched queue and
        // an operator decides.
        if (!empty($candidates)) {
            foreach ($candidates as $candidate) {
                $ref = mb_strtoupper(mb_convert_kana((string) $candidate['reference_number'], 'as'));
                if ($ref !== '' && mb_strpos($normalizedDepositor, $ref) !== false) {
                    $matched = $candidate;
                    break;
                }
            }
        }

        // Persist deposit record
        $now = now_jst();
        $this->db->insert('deposits', [
            'bank_account_id'    => $bankAccountId,
            'payment_link_id'    => $matched !== null ? $matched['id'] : null,
            'depositor_name'     => $depositorName,
            'amount'             => $amount,
            'transaction_date'   => $transactionDate,
            'bank_transaction_id'=> $bankTransactionId,
            'matched_at'         => $matched !== null ? $now : null,
            'raw_data'           => json_encode($rawData),
            'created_at'         => $now,
        ]);

        if ($matched === null) {
            // Unmatched — fire Telegram for visibility
            try {
                $this->telegram->notifyDepositUnmatched([
                    'depositor_name'     => $depositorName,
                    'amount'             => $amount,
                    'bank_transaction_id'=> $bankTransactionId,
                ]);
            } catch (\Throwable) {
                // Non-fatal
            }
            return 'unmatched';
        }

        // Confirm the payment link and fire webhook + Telegram
        $paymentLink = $this->db->fetchOne(
            'SELECT * FROM payment_links WHERE id = ? LIMIT 1',
            [$matched['id']]
        );
        $apiClient = $this->db->fetchOne(
            'SELECT * FROM api_clients WHERE id = ? LIMIT 1',
            [$matched['api_client_id']]
        );

        if ($paymentLink !== null && $apiClient !== null) {
            $this->service->confirm($paymentLink, [
                'depositor_name'     => $depositorName,
                'amount'             => $amount,
                'bank_transaction_id'=> $bankTransactionId,
            ], $apiClient);
        }

        return 'matched';
    }
}
