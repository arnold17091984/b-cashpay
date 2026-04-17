<?php

declare(strict_types=1);

namespace BCashPay\Services;

use BCashPay\Database;
use RuntimeException;

/**
 * Generates unique 7-digit numeric reference numbers.
 *
 * The reference number (e.g. "1234567") is embedded in the depositor name
 * when the customer makes the bank transfer, so the scraper can match
 * the deposit to the correct payment link.
 *
 * Range: 1,000,000 – 9,999,999 (9 million unique values).
 * Collisions are checked against the payment_links table with retry.
 * The UNIQUE constraint on payment_links.reference_number is the final guard.
 */
class ReferenceGenerator
{
    private const MIN = 1_000_000;
    private const MAX = 9_999_999;
    private const MAX_RETRIES = 10;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Generate a unique 7-digit reference number.
     *
     * @throws RuntimeException after MAX_RETRIES consecutive collisions
     */
    public function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $candidate = (string) random_int(self::MIN, self::MAX);

            $exists = $this->db->fetchOne(
                'SELECT 1 FROM payment_links WHERE reference_number = ? LIMIT 1',
                [$candidate]
            );

            if ($exists === null) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'ReferenceGenerator: could not generate a unique reference number after '
            . self::MAX_RETRIES . ' attempts'
        );
    }

    /**
     * Check whether a specific reference number is still available.
     */
    public function isAvailable(string $referenceNumber): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM payment_links WHERE reference_number = ? LIMIT 1',
            [$referenceNumber]
        ) === null;
    }
}
