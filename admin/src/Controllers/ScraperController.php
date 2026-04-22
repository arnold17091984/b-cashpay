<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class ScraperController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        // Bank accounts with their last scraper run info
        $banks = $this->db->fetchAll(
            "SELECT ba.*,
                    st.status as last_task_status,
                    st.last_run_at,
                    st.next_run_at,
                    st.transactions_found,
                    st.transactions_matched,
                    st.duration_seconds,
                    st.error_message as last_error_msg
             FROM bank_accounts ba
             LEFT JOIN scraper_tasks st ON st.id = (
                 SELECT id FROM scraper_tasks
                 WHERE bank_account_id = ba.id
                 ORDER BY created_at DESC LIMIT 1
             )
             ORDER BY ba.id ASC"
        );

        // Last 20 scraper task log rows
        $tasks = $this->db->fetchAll(
            "SELECT st.*, ba.bank_name, ba.account_number
             FROM scraper_tasks st
             LEFT JOIN bank_accounts ba ON ba.id = st.bank_account_id
             ORDER BY st.created_at DESC LIMIT 20"
        );

        // Overall stats
        $stats = $this->db->fetchOne(
            "SELECT
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                COUNT(*) as total
             FROM scraper_tasks
             WHERE created_at >= ?",
            [date('Y-m-d 00:00:00', strtotime('-7 days'))]
        );

        View::render('scraper/index', [
            'title'       => 'スクレイパー',
            'banks'       => $banks,
            'tasks'       => $tasks,
            'stats'       => $stats ?? [],
            'currentUser' => $this->auth->user(),
        ]);
    }

    public function runNow(int $bankId): void
    {
        $this->auth->requireAuth();
        Auth::validateCsrf();

        $bank = $this->db->fetchOne(
            "SELECT id, bank_name FROM bank_accounts WHERE id = ? AND is_active = 1 LIMIT 1",
            [$bankId]
        );

        if ($bank === null) {
            View::setFlash('error', '対象の銀行口座が見つかりません。');
            header('Location: /scraper');
            exit;
        }

        $now     = date('Y-m-d H:i:s');
        $nextRun = date('Y-m-d H:i:s', strtotime('+5 seconds'));

        // Create a queued scraper task
        $this->db->insert('scraper_tasks', [
            'bank_account_id'      => $bankId,
            'status'               => 'queued',
            'next_run_at'          => $nextRun,
            'run_count'            => 0,
            'transactions_found'   => 0,
            'transactions_matched' => 0,
            'created_at'           => $now,
            'updated_at'           => $now,
        ]);

        View::setFlash('success', "{$bank['bank_name']} のスクレイプをキューに追加しました。");
        header('Location: /scraper');
        exit;
    }
}
