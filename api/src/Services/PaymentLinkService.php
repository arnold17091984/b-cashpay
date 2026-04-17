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
        $externalId   = isset($data['external_id']) ? trim((string) $data['external_id']) : null;
        $callbackUrl  = isset($data['callback_url']) ? trim((string) $data['callback_url']) : null;
        $metadata     = isset($data['metadata']) && is_array($data['metadata']) ? $data['metadata'] : null;
        $customerEmail = isset($data['customer_email']) ? trim((string) $data['customer_email']) : null;
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
            $amount, $customerName, $externalId, $callbackUrl, $metadata,
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
