<?php

declare(strict_types=1);

namespace BCashPay\Services;

use BCashPay\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * Core business logic for payment link lifecycle.
 *
 * Orchestrates bank selection, reference number generation, payment link
 * persistence, and confirmation flow. All monetary values are integers (JPY).
 */
class PaymentLinkService
{
    public function __construct(
        private readonly Database $db,
        private readonly ReferenceGenerator $referenceGenerator,
        private readonly TelegramNotifier $telegram,
        private readonly WebhookSender $webhookSender
    ) {
    }

    /**
     * Create a new payment link.
     *
     * @param array<string, mixed> $data  Validated input from controller
     * @param array<string, mixed> $client  api_clients row
     * @return array<string, mixed>  Payment link response data
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on bank selection or DB failure
     */
    public function create(array $data, array $client): array
    {
        // 1. Validate
        $amount       = isset($data['amount']) ? (int) $data['amount'] : 0;
        $customerName = trim((string) ($data['customer_name'] ?? ''));
        $customerKana = isset($data['customer_kana']) ? trim((string) $data['customer_kana']) : null;
        $externalId   = isset($data['external_id']) ? trim((string) $data['external_id']) : null;
        $callbackUrl  = isset($data['callback_url']) ? trim((string) $data['callback_url']) : null;
        $metadata     = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null;
        $customerEmail = isset($data['customer_email']) ? trim((string) $data['customer_email']) : null;

        // Normalize kana: hiragana → katakana, trim
        if ($customerKana !== null && $customerKana !== '') {
            $customerKana = mb_convert_kana($customerKana, 'C');
        } else {
            $customerKana = null;
        }
        $expiryHours  = (int) config('payment.expiry_hours', 72);

        if ($amount <= 0) {
            throw new InvalidArgumentException('amount is required and must be a positive integer');
        }
        if ($customerName === '') {
            throw new InvalidArgumentException('customer_name is required');
        }
        if ($callbackUrl !== null && !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('callback_url must be a valid URL');
        }
        if ($customerEmail !== null && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('customer_email must be a valid email address');
        }

        // 2. Select active bank account (first active one for simplicity)
        $bankAccount = $this->selectBankAccount();

        // 3. Generate IDs inside a transaction to guarantee uniqueness
        return $this->db->transaction(function () use (
            $amount, $customerName, $customerKana, $externalId, $callbackUrl, $metadata,
            $customerEmail, $expiryHours, $bankAccount, $client
        ): array {
            // 3a. Generate reference number (7-digit unique)
            $referenceNumber = $this->referenceGenerator->generate();

            // 3b. Generate URL token (32-char hex, URL-safe)
            $token = bin2hex(random_bytes(16));

            // 3c. Generate ULID with "bp_" prefix
            $id = 'bp_' . generate_ulid();

            // 3d. Calculate expiration
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours"));

            // 3e. Insert payment link
            $this->db->insert('payment_links', [
                'id'              => $id,
                'api_client_id'   => $client['id'],
                'bank_account_id' => $bankAccount['id'],
                'external_id'     => $externalId,
                'reference_number'=> $referenceNumber,
                'amount'          => $amount,
                'currency'        => 'JPY',
                'customer_name'   => $customerName,
                'customer_kana'   => $customerKana,
                'customer_email'  => $customerEmail,
                'callback_url'    => $callbackUrl ?? $client['callback_url'],
                'metadata'        => $metadata !== null ? json_encode($metadata) : null,
                'status'          => 'pending',
                'token'           => $token,
                'expires_at'      => $expiresAt,
                'created_at'      => now_jst(),
                'updated_at'      => now_jst(),
            ]);

            // 3f. Create or update scraper task for this bank account
            $existingTask = $this->db->fetchOne(
                'SELECT id FROM scraper_tasks
                 WHERE bank_account_id = ? AND status IN (?, ?)
                 LIMIT 1',
                [$bankAccount['id'], 'queued', 'running']
            );

            if ($existingTask === null) {
                $this->db->insert('scraper_tasks', [
                    'bank_account_id' => $bankAccount['id'],
                    'status'          => 'queued',
                    'next_run_at'     => now_jst(),
                    'run_count'       => 0,
                    'created_at'      => now_jst(),
                    'updated_at'      => now_jst(),
                ]);
            }

            $payUrl = rtrim((string) config('pay_page.url'), '/') . '/p/' . $token;

            $result = [
                'id'               => $id,
                'external_id'      => $externalId,
                'status'           => 'pending',
                'amount'           => $amount,
                'currency'         => 'JPY',
                'customer_name'    => $customerName,
                'customer_kana'    => $customerKana,
                'customer_email'   => $customerEmail,
                'reference_number' => $referenceNumber,
                'token'            => $token,
                'payment_url'      => $payUrl,
                'expires_at'       => $expiresAt,
                'bank'             => [
                    'bank_name'      => $bankAccount['bank_name'],
                    'branch_name'    => $bankAccount['branch_name'],
                    'branch_code'    => $bankAccount['branch_code'],
                    'account_type'   => $bankAccount['account_type'],
                    'account_number' => $bankAccount['account_number'],
                    'account_name'   => $bankAccount['account_name'],
                ],
                'created_at' => now_jst(),
            ];

            // Fire Telegram notification — outside transaction, fire-and-forget
            try {
                $this->telegram->notifyPaymentCreated($result);
            } catch (\Throwable) {
                // Non-fatal
            }

            return $result;
        });
    }

    /**
     * Create an `awaiting_input` link — operator issues without amount,
     * customer fills it in on the payment page before seeing bank details.
     *
     * Writes a row with link_type='awaiting_input', status='awaiting_input',
     * no amount, and (optionally) a customer kana already pre-filled.  A
     * reference_number is NOT allocated here — it is assigned atomically
     * when the customer submits, so unused links do not burn the reference
     * namespace.
     *
     * @param array<string, mixed> $data   Validated input from controller
     * @param array<string, mixed> $client api_clients row
     * @return array<string, mixed>
     */
    public function createAwaitingInput(array $data, array $client): array
    {
        $customerKana = isset($data['customer_kana']) ? trim((string) $data['customer_kana']) : null;
        if ($customerKana !== null && $customerKana !== '') {
            $customerKana = mb_convert_kana($customerKana, 'C');
        } else {
            $customerKana = null;
        }

        $bankAccount = $this->selectBankAccount();
        $minAmount   = isset($data['min_amount']) ? (int) $data['min_amount'] : 1000;
        $maxAmount   = isset($data['max_amount']) ? (int) $data['max_amount'] : 500_000;
        $presets     = isset($data['preset_amounts']) && is_array($data['preset_amounts'])
            ? array_values(array_filter(array_map('intval', $data['preset_amounts']), static fn($v) => $v > 0))
            : [];
        $expiryHours = isset($data['expires_hours']) ? (int) $data['expires_hours'] : (int) config('payment.expiry_hours', 72);

        $id    = 'bp_' . generate_ulid();
        $token = bin2hex(random_bytes(16));

        $this->db->insert('payment_links', [
            'id'              => $id,
            'api_client_id'   => $client['id'],
            'bank_account_id' => $bankAccount['id'],
            'external_id'     => $data['external_id'] ?? null,
            'reference_number'=> null,
            'amount'          => null,
            'currency'        => 'JPY',
            'customer_name'   => null,
            'customer_kana'   => $customerKana,
            'callback_url'    => $client['callback_url'] ?? null,
            'status'          => 'awaiting_input',
            'link_type'       => 'awaiting_input',
            'min_amount'      => $minAmount,
            'max_amount'      => $maxAmount,
            'preset_amounts'  => $presets !== [] ? json_encode($presets) : null,
            'token'           => $token,
            'expires_at'      => date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours")),
            'source'          => $data['source'] ?? 'admin_web',
            'created_at'      => now_jst(),
            'updated_at'      => now_jst(),
        ]);

        return [
            'id'          => $id,
            'token'       => $token,
            'payment_url' => rtrim((string) config('pay_page.url'), '/') . '/p/' . $token,
        ];
    }

    /**
     * Create a reusable `template` link — the same URL can be shared widely
     * (LINE group, flyer, chat), and every customer visit that submits an
     * amount spawns an independent child payment_link with its own
     * reference_number.
     *
     * The template row itself carries no amount / reference / customer data;
     * those live on children.  `expires_at` on the template is set far in
     * the future (1 year default) so it does not auto-expire like single
     * links do — operators cancel it manually when done.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    public function createTemplate(array $data, array $client): array
    {
        $bankAccount = $this->selectBankAccount();
        $minAmount   = isset($data['min_amount']) ? (int) $data['min_amount'] : 1000;
        $maxAmount   = isset($data['max_amount']) ? (int) $data['max_amount'] : 500_000;
        $presets     = isset($data['preset_amounts']) && is_array($data['preset_amounts'])
            ? array_values(array_filter(array_map('intval', $data['preset_amounts']), static fn($v) => $v > 0))
            : [];

        $id    = 'bp_' . generate_ulid();
        $token = bin2hex(random_bytes(16));

        $this->db->insert('payment_links', [
            'id'              => $id,
            'api_client_id'   => $client['id'],
            'bank_account_id' => $bankAccount['id'],
            'reference_number'=> null,
            'amount'          => null,
            'currency'        => 'JPY',
            'customer_name'   => null,
            'customer_kana'   => null,
            'callback_url'    => $client['callback_url'] ?? null,
            'status'          => 'pending',  // template stays 'pending' forever; children carry real status
            'link_type'       => 'template',
            'min_amount'      => $minAmount,
            'max_amount'      => $maxAmount,
            'preset_amounts'  => $presets !== [] ? json_encode($presets) : null,
            'token'           => $token,
            'expires_at'      => date('Y-m-d H:i:s', strtotime('+1 year')),
            'source'          => $data['source'] ?? 'admin_web',
            'created_at'      => now_jst(),
            'updated_at'      => now_jst(),
        ]);

        return [
            'id'          => $id,
            'token'       => $token,
            'payment_url' => rtrim((string) config('pay_page.url'), '/') . '/p/' . $token,
        ];
    }

    /**
     * Customer submitted amount + kana on a template URL — spawn an
     * independent child payment_link that behaves like a normal fixed link
     * from here on.  The template stays live for the next visitor.
     *
     * @param array<string, mixed> $template payment_links row (link_type=template)
     * @return array<string, mixed> child row data including fresh token + reference
     */
    public function createChildFromTemplate(array $template, int $amount, ?string $kana): array
    {
        return $this->db->transaction(function () use ($template, $amount, $kana) {
            $referenceNumber = $this->referenceGenerator->generate();
            $childId    = 'bp_' . generate_ulid();
            $childToken = bin2hex(random_bytes(16));
            $expiresAt  = date('Y-m-d H:i:s', strtotime('+72 hours'));

            $this->db->insert('payment_links', [
                'id'              => $childId,
                'api_client_id'   => $template['api_client_id'],
                'bank_account_id' => $template['bank_account_id'],
                'reference_number'=> $referenceNumber,
                'amount'          => $amount,
                'currency'        => 'JPY',
                'customer_name'   => $kana,  // display name == kana for customer-driven flow
                'customer_kana'   => $kana,
                'callback_url'    => $template['callback_url'],
                'status'          => 'pending',
                'link_type'       => 'child',
                'parent_link_id'  => $template['id'],
                'min_amount'      => null,
                'max_amount'      => null,
                'preset_amounts'  => null,
                'locked_at'       => now_jst(),
                'token'           => $childToken,
                'expires_at'      => $expiresAt,
                'source'          => 'customer_input',
                'created_at'      => now_jst(),
                'updated_at'      => now_jst(),
            ]);

            // Queue a scraper task for this bank account so the freshly-pending
            // link is polled on the next scrape cycle.
            $this->ensureScraperTask((int) $template['bank_account_id']);

            return [
                'id'               => $childId,
                'token'            => $childToken,
                'reference_number' => $referenceNumber,
                'payment_url'      => rtrim((string) config('pay_page.url'), '/') . '/p/' . $childToken,
            ];
        });
    }

    /**
     * Customer submitted amount + kana on an awaiting_input link — upgrade
     * the existing row in place to a ready-to-pay 'pending' link.
     *
     * Concurrency strategy:
     *   1. Claim the row with a no-op UPDATE filtered on
     *      status='awaiting_input'.  Only one concurrent caller observes
     *      rowCount() == 1.
     *   2. Allocate the reference number.  Losers never reach this step,
     *      so the reference-number namespace is not burned on races.
     *   3. Write the real fields + status='pending' to the same row.
     *
     * @param array<string, mixed> $row payment_links row
     */
    public function finaliseAwaitingInput(array $row, int $amount, ?string $kana): void
    {
        $this->db->transaction(function () use ($row, $amount, $kana) {
            // Step 1: claim the slot.  `locked_at IS NULL` plus the status
            // predicate guarantees only one winner even across the gap
            // between steps 1 and 3.
            $claim = $this->db->query(
                'UPDATE payment_links
                    SET locked_at = ?
                  WHERE id = ?
                    AND status = ?
                    AND locked_at IS NULL',
                [now_jst(), $row['id'], 'awaiting_input']
            );
            if ($claim->rowCount() === 0) {
                throw new RuntimeException('Link already finalised or cancelled');
            }

            // Step 2: allocate reference only after winning the claim.
            $referenceNumber = $this->referenceGenerator->generate();

            // Step 3: fill in the real fields and promote to pending.
            $this->db->query(
                'UPDATE payment_links
                    SET amount = ?,
                        customer_kana = COALESCE(?, customer_kana),
                        customer_name = COALESCE(customer_name, ?),
                        reference_number = ?,
                        status = ?,
                        link_type = ?,
                        updated_at = ?
                  WHERE id = ?',
                [
                    $amount,
                    $kana,
                    $kana,
                    $referenceNumber,
                    'pending',
                    'single',
                    now_jst(),
                    $row['id'],
                ]
            );

            $this->ensureScraperTask((int) $row['bank_account_id']);
        });
    }

    private function ensureScraperTask(int $bankAccountId): void
    {
        $existing = $this->db->fetchOne(
            'SELECT id FROM scraper_tasks
             WHERE bank_account_id = ? AND status IN (?, ?)
             LIMIT 1',
            [$bankAccountId, 'queued', 'running']
        );
        if ($existing === null) {
            $this->db->insert('scraper_tasks', [
                'bank_account_id' => $bankAccountId,
                'status'          => 'queued',
                'next_run_at'     => now_jst(),
                'run_count'       => 0,
                'created_at'      => now_jst(),
                'updated_at'      => now_jst(),
            ]);
        }
    }

    /**
     * Confirm a payment link that has been matched to a deposit.
     * Updates status, fires webhook, and sends Telegram notification.
     *
     * @param array<string, mixed> $paymentLink  payment_links row
     * @param array<string, mixed> $deposit  deposits row
     * @param array<string, mixed> $client  api_clients row
     */
    public function confirm(array $paymentLink, array $deposit, array $client): void
    {
        $now = now_jst();

        $this->db->update(
            'payment_links',
            ['status' => 'confirmed', 'confirmed_at' => $now, 'updated_at' => $now],
            ['id' => $paymentLink['id']]
        );

        // Build webhook payload
        $callbackUrl   = $paymentLink['callback_url'] ?? $client['callback_url'] ?? null;
        $webhookSecret = $client['webhook_secret'] ?? '';

        if ($callbackUrl !== null && $callbackUrl !== '' && $webhookSecret !== '') {
            $webhookPayload = [
                'event'            => 'payment.confirmed',
                'payment_link_id'  => $paymentLink['id'],
                'external_id'      => $paymentLink['external_id'],
                'reference_number' => $paymentLink['reference_number'],
                'amount'           => (int) $paymentLink['amount'],
                'currency'         => $paymentLink['currency'],
                'status'           => 'confirmed',
                'confirmed_at'     => $now,
                'timestamp'        => time(),
            ];

            $this->webhookSender->send(
                $webhookPayload,
                $callbackUrl,
                $webhookSecret,
                $paymentLink['id'],
                1
            );
        }

        // Telegram notification
        try {
            $this->telegram->notifyPaymentConfirmed([
                'id'               => $paymentLink['id'],
                'customer_name'    => $paymentLink['customer_name'],
                'amount'           => (int) $paymentLink['amount'],
                'reference_number' => $paymentLink['reference_number'],
                'depositor_name'   => $deposit['depositor_name'],
            ]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    /**
     * Select an active bank account.
     * Simple strategy: pick the first active account.
     *
     * @return array<string, mixed>  bank_accounts row
     * @throws RuntimeException when no active bank accounts exist
     */
    private function selectBankAccount(): array
    {
        $bank = $this->db->fetchOne(
            'SELECT id, bank_name, bank_code, branch_name, branch_code,
                    account_type, account_number, account_name
             FROM bank_accounts
             WHERE is_active = 1
             ORDER BY id ASC
             LIMIT 1'
        );

        if ($bank === null) {
            throw new RuntimeException('No active bank accounts available');
        }

        return $bank;
    }
}
