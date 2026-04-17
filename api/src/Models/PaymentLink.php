<?php

declare(strict_types=1);

namespace BCashPay\Models;

use BCashPay\Database;

/**
 * PaymentLink — query helpers for the payment_links table.
 *
 * Encapsulates common read/write patterns for payment link lifecycle.
 * All state transitions validate the current status before mutating.
 */
class PaymentLink
{
    // Status constants — match the DB ENUM definition
    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Find a payment link by its public URL token.
     *
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
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
     * Find a payment link by reference number (used during deposit matching).
     *
     * @return array<string, mixed>|null
     */
    public function findByReferenceNumber(string $referenceNumber): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM payment_links
             WHERE reference_number = ?
             LIMIT 1',
            [$referenceNumber]
        );
    }

    /**
     * Find a payment link by its primary key (ULID).
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM payment_links WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Transition a pending payment link to confirmed.
     * Returns false if the link is not in pending status.
     */
    public function confirm(string $id): bool
    {
        $now  = date('Y-m-d H:i:s');
        $rows = $this->db->update(
            'payment_links',
            ['status' => self::STATUS_CONFIRMED, 'confirmed_at' => $now, 'updated_at' => $now],
            ['id' => $id, 'status' => self::STATUS_PENDING]
        );
        return $rows > 0;
    }

    /**
     * Transition a pending payment link to cancelled.
     * Returns false if the link is not in pending status.
     */
    public function cancel(string $id): bool
    {
        $now  = date('Y-m-d H:i:s');
        $rows = $this->db->update(
            'payment_links',
            ['status' => self::STATUS_CANCELLED, 'cancelled_at' => $now, 'updated_at' => $now],
            ['id' => $id, 'status' => self::STATUS_PENDING]
        );
        return $rows > 0;
    }

    /**
     * Transition a pending payment link to expired.
     * Returns false if the link is not in pending status.
     */
    public function expire(string $id): bool
    {
        $now  = date('Y-m-d H:i:s');
        $rows = $this->db->update(
            'payment_links',
            ['status' => self::STATUS_EXPIRED, 'updated_at' => $now],
            ['id' => $id, 'status' => self::STATUS_PENDING]
        );
        return $rows > 0;
    }

    /**
     * Return true when the payment link's expiry time is in the past.
     *
     * @param array<string, mixed> $row  payment_links row
     */
    public function isExpired(array $row): bool
    {
        return strtotime($row['expires_at']) < time();
    }

    /**
     * Batch-expire all pending links whose expires_at has passed.
     * Called by a scheduled job or the health check.
     *
     * @return int  Number of rows updated
     */
    public function expirePending(): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            'UPDATE payment_links
             SET status = ?, updated_at = ?
             WHERE status = ? AND expires_at < ?',
            [self::STATUS_EXPIRED, $now, self::STATUS_PENDING, $now]
        );
        return $stmt->rowCount();
    }
}
