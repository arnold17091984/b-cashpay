<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

class DashboardController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        $today = date('Y-m-d');

        // Today's payment link counts by status
        $todayLinks = $this->db->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM payment_links
             WHERE DATE(created_at) = ? GROUP BY status",
            [$today]
        );
        $linkCounts = ['pending' => 0, 'confirmed' => 0, 'expired' => 0, 'cancelled' => 0];
        foreach ($todayLinks as $row) {
            $linkCounts[$row['status']] = (int) $row['cnt'];
        }

        // Today's deposit counts
        $todayDeposits = $this->db->fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN payment_link_id IS NOT NULL THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN payment_link_id IS NULL THEN 1 ELSE 0 END) as unmatched
             FROM deposits WHERE DATE(created_at) = ?",
            [$today]
        );

        // Active bank accounts
        $activeBanks = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM bank_accounts WHERE is_active = 1"
        )['cnt'] ?? 0);

        // Active API clients
        $activeClients = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM api_clients WHERE is_active = 1"
        )['cnt'] ?? 0);

        // Last 7 days revenue (confirmed payments)
        $revenueData = $this->db->fetchAll(
            "SELECT DATE(confirmed_at) as date, SUM(amount) as total
             FROM payment_links
             WHERE status = 'confirmed'
               AND confirmed_at >= ?
             GROUP BY DATE(confirmed_at)
             ORDER BY date ASC",
            [date('Y-m-d 00:00:00', strtotime('-6 days'))]
        );

        // Build complete 7-day array
        $revenueDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $revenueDays[date('Y-m-d', strtotime("-{$i} days"))] = 0;
        }
        foreach ($revenueData as $row) {
            $revenueDays[$row['date']] = (int) $row['total'];
        }

        // Recent activity: last 10 payment links
        $recentLinks = $this->db->fetchAll(
            "SELECT pl.*, ac.name as client_name
             FROM payment_links pl
             LEFT JOIN api_clients ac ON ac.id = pl.api_client_id
             ORDER BY pl.created_at DESC LIMIT 10"
        );

        // Scraper status: last task per bank account
        $scraperStatus = $this->db->fetchAll(
            "SELECT ba.id, ba.bank_name, ba.account_number, ba.scrape_status,
                    ba.scrape_last_success_at, ba.scrape_consecutive_failures,
                    st.status as last_task_status, st.last_run_at, st.transactions_found
             FROM bank_accounts ba
             LEFT JOIN scraper_tasks st ON st.id = (
                 SELECT id FROM scraper_tasks
                 WHERE bank_account_id = ba.id
                 ORDER BY created_at DESC LIMIT 1
             )
             WHERE ba.is_active = 1
             ORDER BY ba.id ASC"
        );

        View::render('dashboard/index', [
            'title'         => 'ダッシュボード',
            'linkCounts'    => $linkCounts,
            'todayDeposits' => $todayDeposits ?? ['total' => 0, 'matched' => 0, 'unmatched' => 0],
            'activeBanks'   => $activeBanks,
            'activeClients' => $activeClients,
            'revenueDays'   => $revenueDays,
            'recentLinks'   => $recentLinks,
            'scraperStatus' => $scraperStatus,
            'currentUser'   => $this->auth->user(),
        ]);
    }
}
