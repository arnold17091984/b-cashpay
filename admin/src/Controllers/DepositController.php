<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class DepositController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        $filter   = $_GET['filter'] ?? '';   // 'matched' | 'unmatched' | ''
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo   = $_GET['date_to'] ?? '';
        $page     = max(1, (int) ($_GET['page'] ?? 1));
        $perPage  = 20;

        $where  = ['1=1'];
        $params = [];

        if ($filter === 'matched') {
            $where[] = 'd.payment_link_id IS NOT NULL';
        } elseif ($filter === 'unmatched') {
            $where[] = 'd.payment_link_id IS NULL';
        }
        if ($dateFrom !== '') {
            $where[]  = "DATE(d.transaction_date) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[]  = "DATE(d.transaction_date) <= ?";
            $params[] = $dateTo;
        }

        $whereStr = implode(' AND ', $where);

        $totalRow = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM deposits d WHERE {$whereStr}",
            $params
        );
        $total      = (int) ($totalRow['cnt'] ?? 0);
        $pagination = View::paginate($total, $page, $perPage);

        $deposits = $this->db->fetchAll(
            "SELECT d.*, ba.bank_name, ba.account_number,
                    pl.reference_number, pl.customer_name, pl.amount as link_amount
             FROM deposits d
             LEFT JOIN bank_accounts ba ON ba.id = d.bank_account_id
             LEFT JOIN payment_links pl ON pl.id = d.payment_link_id
             WHERE {$whereStr}
             ORDER BY d.transaction_date DESC
             LIMIT ? OFFSET ?",
            [...$params, $perPage, $pagination['offset']]
        );

        View::render('deposits/index', [
            'title'      => '入金履歴',
            'deposits'   => $deposits,
            'pagination' => $pagination,
            'filter'     => $filter,
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function match(int $id): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $paymentLinkId = trim($_POST['payment_link_id'] ?? '');
        if ($paymentLinkId === '') {
            View::setFlash('error', '決済リンクIDを指定してください。');
            header('Location: /deposits');
            exit;
        }

        $deposit = $this->db->fetchOne(
            "SELECT * FROM deposits WHERE id = ? AND payment_link_id IS NULL LIMIT 1",
            [$id]
        );
        if ($deposit === null) {
            View::setFlash('error', '対象の入金が見つかりません。');
            header('Location: /deposits');
            exit;
        }

        $link = $this->db->fetchOne(
            "SELECT * FROM payment_links WHERE id = ? AND status = 'pending' LIMIT 1",
            [$paymentLinkId]
        );
        if ($link === null) {
            View::setFlash('error', '対象の決済リンクが見つかりません（pending のみ対応）。');
            header('Location: /deposits');
            exit;
        }

        $now = date('Y-m-d H:i:s');

        $this->db->update(
            'deposits',
            ['payment_link_id' => $paymentLinkId, 'matched_at' => $now],
            ['id' => $id]
        );

        $this->db->update(
            'payment_links',
            ['status' => 'confirmed', 'confirmed_at' => $now, 'updated_at' => $now],
            ['id' => $paymentLinkId]
        );

        View::setFlash('success', '入金を決済リンクにマッチしました。');
        header('Location: /deposits');
        exit;
    }
}
