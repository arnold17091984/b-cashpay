<?php

declare(strict_types=1);

namespace BCashPay\Models;

use BCashPay\Database;

/**
 * BankAccount — query helpers for the bank_accounts table.
 *
 * Encapsulates common read/write patterns so controllers and services
 * do not embed raw SQL for bank account operations.
 */
class BankAccount
{
    // Scrape status constants — match the DB ENUM definition
    public const SCRAPE_STATUS_SETUP_PENDING  = 'setup_pending';
    public const SCRAPE_STATUS_ACTIVE         = 'active';
    public const SCRAPE_STATUS_INACTIVE       = 'inactive';
    public const SCRAPE_STATUS_LOGIN_FAILED   = 'login_failed';
    public const SCRAPE_STATUS_SCRAPE_FAILED  = 'scrape_failed';
    public const SCRAPE_STATUS_ERROR          = 'error';
    public const SCRAPE_STATUS_PAUSED         = 'paused';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Return all active bank accounts available for payment assignment.
     *
     * @return list<array<string, mixed>>
     */
    public function findActive(): array
    {
        return $this->db->fetchAll(
            'SELECT id, bank_name, bank_code, branch_name, branch_code,
                    account_type, account_number, account_name
             FROM bank_accounts
             WHERE is_active = 1
             ORDER BY id ASC'
        );
    }

    /**
     * Return the first active bank account, or null if none exists.
     *
     * @return array<string, mixed>|null
     */
    public function findFirstActive(): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, bank_name, bank_code, branch_name, branch_code,
                    account_type, account_number, account_name
             FROM bank_accounts
             WHERE is_active = 1
             ORDER BY id ASC
             LIMIT 1'
        );
    }

    /**
     * Return bank accounts that are configured for scraping and due for a run.
     *
     * @return list<array<string, mixed>>
     */
    public function findScrapeable(): array
    {
        return $this->db->fetchAll(
            'SELECT id, bank_name, bank_code, account_number,
                    scrape_login_url, scrape_adapter_key, scrape_interval_minutes,
                    scrape_status, scrape_last_at
             FROM bank_accounts
             WHERE is_active = 1
               AND scrape_adapter_key IS NOT NULL
               AND scrape_status NOT IN (?, ?)
               AND (
                     scrape_last_at IS NULL
                  OR DATE_ADD(scrape_last_at, INTERVAL scrape_interval_minutes MINUTE) <= NOW()
               )
             ORDER BY scrape_last_at ASC',
            [self::SCRAPE_STATUS_INACTIVE, self::SCRAPE_STATUS_PAUSED]
        );
    }

    /**
     * Update the scraper status and related timestamps for a bank account.
     *
     * @param array<string, mixed> $fields  Columns to set (subset of scrape_* fields)
     */
    public function updateScrapeStatus(int $id, array $fields): int
    {
        $allowed = [
            'scrape_status', 'scrape_last_at', 'scrape_last_success_at',
            'scrape_last_error_at', 'scrape_last_error_message',
            'scrape_consecutive_failures',
        ];

        $data = array_filter(
            $fields,
            fn($key) => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($data)) {
            return 0;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->db->update('bank_accounts', $data, ['id' => $id]);
    }

    /**
     * Find a bank account by its primary key.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM bank_accounts WHERE id = ? LIMIT 1',
            [$id]
        );
    }
}
