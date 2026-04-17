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

    public function cancel(string $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $link = $this->db->fetchOne(
            "SELECT id, status FROM payment_links WHERE id = ? LIMIT 1",
            [$id]
        );

        if ($link === null) {
            http_response_code(404);
            exit;
        }

        if ($link['status'] !== 'pending') {
            View::setFlash('error', 'ステータスが pending の決済リンクのみキャンセルできます。');
            header("Location: /payments/{$id}");
            exit;
        }

        $now = date('Y-m-d H:i:s');
        $this->db->update(
            'payment_links',
            ['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now],
            ['id' => $id]
        );

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
