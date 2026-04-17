<?php

declare(strict_types=1);

namespace BCashPay\Controllers;

use BCashPay\Database;
use BCashPay\Services\PaymentLinkService;
use BCashPay\Services\ReferenceGenerator;
use BCashPay\Services\TelegramNotifier;
use BCashPay\Services\WebhookSender;
use InvalidArgumentException;
use RuntimeException;

/**
 * PaymentLinkController — CRUD for payment links.
 *
 * Endpoints (all require API key auth):
 *   POST /api/v1/payments                  — Create payment link
 *   GET  /api/v1/payments/{id}             — Get payment link details
 *   POST /api/v1/payments/{id}/cancel      — Cancel a pending payment link
 *   GET  /api/v1/payments                  — List payment links with filters
 */
class PaymentLinkController
{
    private readonly Database $db;
    private readonly PaymentLinkService $service;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->service = new PaymentLinkService(
            $this->db,
            new ReferenceGenerator($this->db),
            new TelegramNotifier($this->db),
            new WebhookSender($this->db)
        );
    }

    /**
     * POST /api/v1/payments
     * Create a new payment link.
     *
     * @param array<string, mixed> $client  Authenticated API client
     */
    public function create(array $client): never
    {
        $data = request_body();

        try {
            $result = $this->service->create($data, $client);
        } catch (InvalidArgumentException $e) {
            json_error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 503);
        }

        json_response(['success' => true, 'data' => $result], 201);
    }

    /**
     * GET /api/v1/payments/{id}
     * Fetch a single payment link by ID.
     *
     * @param array<string, mixed> $client  Authenticated API client
     */
    public function get(string $id, array $client): never
    {
        $row = $this->db->fetchOne(
            'SELECT pl.*, ba.bank_name, ba.branch_name, ba.branch_code,
                    ba.account_type, ba.account_number, ba.account_name
             FROM payment_links pl
             JOIN bank_accounts ba ON ba.id = pl.bank_account_id
             WHERE pl.id = ? AND pl.api_client_id = ?
             LIMIT 1',
            [$id, $client['id']]
        );

        if ($row === null) {
            json_error('Payment link not found', 404);
        }

        json_response(['success' => true, 'data' => $this->formatPaymentLink($row)]);
    }

    /**
     * POST /api/v1/payments/{id}/cancel
     * Cancel a pending payment link.
     *
     * @param array<string, mixed> $client  Authenticated API client
     */
    public function cancel(string $id, array $client): never
    {
        $row = $this->db->fetchOne(
            'SELECT id, status, api_client_id FROM payment_links
             WHERE id = ? AND api_client_id = ?
             LIMIT 1',
            [$id, $client['id']]
        );

        if ($row === null) {
            json_error('Payment link not found', 404);
        }

        if ($row['status'] !== 'pending') {
            json_error(
                'Only pending payment links can be cancelled (current status: ' . $row['status'] . ')',
                409
            );
        }

        $now = now_jst();
        $this->db->update(
            'payment_links',
            ['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now],
            ['id' => $id]
        );

        $updated = $this->db->fetchOne(
            'SELECT pl.*, ba.bank_name, ba.branch_name, ba.branch_code,
                    ba.account_type, ba.account_number, ba.account_name
             FROM payment_links pl
             JOIN bank_accounts ba ON ba.id = pl.bank_account_id
             WHERE pl.id = ?
             LIMIT 1',
            [$id]
        );

        json_response(['success' => true, 'data' => $this->formatPaymentLink($updated)]);
    }

    /**
     * GET /api/v1/payments
     * List payment links with optional filters and pagination.
     *
     * Query params: status, external_id, page (1-based), per_page (max 100)
     *
     * @param array<string, mixed> $client  Authenticated API client
     */
    public function list(array $client): never
    {
        $status     = $_GET['status'] ?? null;
        $externalId = $_GET['external_id'] ?? null;
        $page       = max(1, (int) ($_GET['page'] ?? 1));
        $perPage    = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
        $offset     = ($page - 1) * $perPage;

        // Build query dynamically — only whitelisted columns
        $conditions = ['pl.api_client_id = ?'];
        $params     = [$client['id']];

        $allowedStatuses = ['pending', 'confirmed', 'expired', 'cancelled'];
        if ($status !== null && in_array($status, $allowedStatuses, true)) {
            $conditions[] = 'pl.status = ?';
            $params[]     = $status;
        }

        if ($externalId !== null && $externalId !== '') {
            $conditions[] = 'pl.external_id = ?';
            $params[]     = $externalId;
        }

        $whereClause = implode(' AND ', $conditions);

        // Count total matching rows
        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) AS total FROM payment_links pl WHERE {$whereClause}",
            $params
        );
        $total = (int) ($countRow['total'] ?? 0);

        // Fetch page
        $rows = $this->db->fetchAll(
            "SELECT pl.*, ba.bank_name, ba.account_number
             FROM payment_links pl
             JOIN bank_accounts ba ON ba.id = pl.bank_account_id
             WHERE {$whereClause}
             ORDER BY pl.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        $items = array_map(fn($row) => $this->formatPaymentLink($row), $rows);

        json_response([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
    }

    /**
     * GET /p/{token} — Fetch payment link by public URL token.
     * Used by the payment page renderer.
     *
     * @return array<string, mixed>  payment_links row joined with bank_accounts
     */
    public function getByToken(string $token): ?array
    {
        return $this->db->fetchOne(
            'SELECT pl.*, ba.bank_name, ba.bank_code, ba.branch_name, ba.branch_code,
                    ba.account_type, ba.account_number, ba.account_name
             FROM payment_links pl
             JOIN bank_accounts ba ON ba.id = pl.bank_account_id
             WHERE pl.token = ?
             LIMIT 1',
            [$token]
        );
    }

    /**
     * GET /api/v1/pay/{token}/status
     * Return just the status fields for client-side polling.
     *
     * @param array<string, mixed> $client  Authenticated API client
     */
    public function pollStatus(string $token, array $client): never
    {
        $row = $this->db->fetchOne(
            'SELECT status, confirmed_at, expires_at
             FROM payment_links
             WHERE token = ? AND api_client_id = ?
             LIMIT 1',
            [$token, $client['id']]
        );

        if ($row === null) {
            json_error('Payment link not found', 404);
        }

        json_response([
            'success'      => true,
            'status'       => $row['status'],
            'confirmed_at' => $row['confirmed_at'],
            'expires_at'   => $row['expires_at'],
        ]);
    }

    /**
     * Format a raw DB row into a standardised API response shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPaymentLink(array $row): array
    {
        $payUrl = rtrim((string) config('pay_page.url'), '/') . '/p/' . ($row['token'] ?? '');

        $result = [
            'id'               => $row['id'],
            'external_id'      => $row['external_id'],
            'status'           => $row['status'],
            'amount'           => (int) $row['amount'],
            'currency'         => $row['currency'],
            'customer_name'    => $row['customer_name'],
            'customer_email'   => $row['customer_email'],
            'reference_number' => $row['reference_number'],
            'token'            => $row['token'],
            'payment_url'      => $payUrl,
            'expires_at'       => $row['expires_at'],
            'confirmed_at'     => $row['confirmed_at'],
            'cancelled_at'     => $row['cancelled_at'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'],
        ];

        // Include bank details if present in the row (join was requested)
        if (isset($row['bank_name'])) {
            $result['bank'] = [
                'bank_name'      => $row['bank_name'],
                'branch_name'    => $row['branch_name'] ?? null,
                'branch_code'    => $row['branch_code'] ?? null,
                'account_type'   => $row['account_type'] ?? null,
                'account_number' => $row['account_number'] ?? null,
                'account_name'   => $row['account_name'] ?? null,
            ];
        }

        // Decode metadata JSON if present
        if (isset($row['metadata']) && $row['metadata'] !== null) {
            $decoded = json_decode($row['metadata'], true);
            $result['metadata'] = is_array($decoded) ? $decoded : null;
        } else {
            $result['metadata'] = null;
        }

        return $result;
    }
}
