<?php

declare(strict_types=1);

namespace BCashPay\Controllers;

use BCashPay\Database;

/**
 * PaymentPageController — Customer-facing payment pages (HTML).
 *
 * Renders the redesigned templates located at /pay/templates/*.html.
 * Uses {{PLACEHOLDER}} substitution — no template engine dependency.
 *
 * Routes:
 *   GET /p/{token}                 — Render payment instruction page
 *   GET /api/v1/pay/{token}/status — JSON status poll (public, rate-limited)
 */
class PaymentPageController
{
    private readonly Database $db;
    private readonly string $templateDir;
    private readonly string $assetsBaseUrl;

    public function __construct()
    {
        $this->db = Database::getInstance();
        // Templates and assets live outside the api/ dir
        $this->templateDir = dirname(__DIR__, 3) . '/pay/templates';
        $this->assetsBaseUrl = '/assets';  // nginx proxies /assets/* → /pay/assets/*
    }

    /**
     * GET /p/{token}
     */
    public function show(string $token): never
    {
        $row = $this->db->fetchOne(
            'SELECT pl.*, ba.bank_name, ba.bank_code, ba.branch_name, ba.branch_code,
                    ba.account_type, ba.account_number, ba.account_name
             FROM payment_links pl
             JOIN bank_accounts ba ON ba.id = pl.bank_account_id
             WHERE pl.token = ?
             LIMIT 1',
            [$token]
        );

        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');

        if ($row === null) {
            http_response_code(404);
            echo $this->renderTemplate('expired.html', [
                'MESSAGE' => '支払いリンクが見つかりません。',
                'BRAND_NAME' => 'B-Pay',
                'SERVICE_NAME' => 'B-Pay',
                'RETURN_URL' => '',
            ]);
            exit;
        }

        // Auto-expire if past expires_at
        if ($row['status'] === 'pending' && strtotime($row['expires_at']) < time()) {
            $now = date('Y-m-d H:i:s');
            $this->db->update(
                'payment_links',
                ['status' => 'expired', 'updated_at' => $now],
                ['id' => $row['id']]
            );
            $row['status'] = 'expired';
        }

        http_response_code(200);
        echo match ($row['status']) {
            'confirmed' => $this->renderConfirmed($row),
            'expired'   => $this->renderExpired($row, 'この支払いリンクは有効期限が切れています。'),
            'cancelled' => $this->renderExpired($row, 'この支払いリンクはキャンセルされました。'),
            default     => $this->renderPayment($row),
        };
        exit;
    }

    /**
     * GET /api/v1/pay/{token}/status
     */
    public function pollStatus(string $token): never
    {
        $row = $this->db->fetchOne(
            'SELECT id, status, confirmed_at, expires_at FROM payment_links
             WHERE token = ?
             LIMIT 1',
            [$token]
        );

        if ($row === null) {
            json_error('Payment link not found', 404);
        }

        // Auto-expire check
        if ($row['status'] === 'pending' && strtotime($row['expires_at']) < time()) {
            $now = date('Y-m-d H:i:s');
            $this->db->update(
                'payment_links',
                ['status' => 'expired', 'updated_at' => $now],
                ['id' => $row['id']]
            );
            $row['status'] = 'expired';
        }

        json_response([
            'success'      => true,
            'status'       => $row['status'],
            'confirmed_at' => $row['confirmed_at'],
            'expires_at'   => $row['expires_at'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Template rendering
    // -------------------------------------------------------------------------

    /**
     * Load a template file and substitute {{PLACEHOLDER}} markers.
     * All values are HTML-escaped unless the key ends with _RAW.
     *
     * @param array<string, string> $vars
     */
    private function renderTemplate(string $filename, array $vars): string
    {
        $path = $this->templateDir . '/' . $filename;
        if (!is_file($path)) {
            return '<!DOCTYPE html><html><body><h1>Template not found: ' . htmlspecialchars($filename) . '</h1></body></html>';
        }
        $html = file_get_contents($path);
        if ($html === false) {
            return '<!DOCTYPE html><html><body><h1>Template read error</h1></body></html>';
        }

        // Fix relative asset paths (../assets → /assets via nginx or local serve)
        $html = str_replace(
            ['href="../assets/', 'src="../assets/'],
            ['href="' . $this->assetsBaseUrl . '/', 'src="' . $this->assetsBaseUrl . '/'],
            $html
        );

        // Substitute placeholders
        $replacements = [];
        foreach ($vars as $key => $value) {
            $escaped = str_ends_with($key, '_RAW')
                ? (string) $value
                : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $replacements['{{' . $key . '}}'] = $escaped;
        }
        return strtr($html, $replacements);
    }

    /**
     * Build the variables for the payment-pending template.
     *
     * @param array<string, mixed> $row
     */
    private function renderPayment(array $row): string
    {
        $amount = (int) $row['amount'];
        $reference = (string) ($row['reference_number'] ?? '');
        $kana = $this->extractKana($row);
        $depositorName = trim($reference . ' ' . $kana);
        $deadlineIso = date('c', strtotime((string) $row['expires_at']));
        $deadlineDisplay = date('Y/m/d H:i', strtotime((string) $row['expires_at']));

        $token = (string) $row['token'];
        $statusPollUrl = '/api/v1/pay/' . $token . '/status';

        return $this->renderTemplate('payment.html', [
            'TOKEN'              => $token,
            'STATUS_URL'         => $statusPollUrl,
            'STATUS_POLL_URL'    => $statusPollUrl,
            'AMOUNT'             => (string) $amount,
            'AMOUNT_RAW'         => (string) $amount,
            'AMOUNT_FORMATTED'   => number_format($amount),
            'CURRENCY'           => (string) ($row['currency'] ?? 'JPY'),
            'REFERENCE_NUMBER'   => $reference,
            'CUSTOMER_NAME'      => (string) ($row['customer_name'] ?? ''),
            'CUSTOMER_NAME_KANA' => $kana,
            'DEPOSITOR_NAME'     => $depositorName,
            'STATUS'             => 'pending',
            'EXPIRES_AT'         => $deadlineDisplay,
            'EXPIRES_AT_ISO'     => $deadlineIso,
            'DEADLINE_DATE'      => $deadlineDisplay,
            'DEADLINE_ISO'       => $deadlineIso,
            'BANK_NAME'          => (string) ($row['bank_name'] ?? ''),
            'BANK_CODE'          => (string) ($row['bank_code'] ?? ''),
            'BRANCH_NAME'        => (string) ($row['branch_name'] ?? ''),
            'BRANCH_CODE'        => (string) ($row['branch_code'] ?? ''),
            'ACCOUNT_TYPE'       => (string) ($row['account_type'] ?? '普通'),
            'ACCOUNT_NUMBER'     => (string) ($row['account_number'] ?? ''),
            'ACCOUNT_NAME'       => (string) ($row['account_name'] ?? ''),
            'PAYMENT_ID'         => (string) $row['id'],
            'BRAND_NAME'         => 'B-Pay',
            'SERVICE_NAME'       => 'B-Pay',
            'RETURN_URL'         => '',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderConfirmed(array $row): string
    {
        $confirmedAt = $row['confirmed_at']
            ? date('Y/m/d H:i', strtotime((string) $row['confirmed_at']))
            : date('Y/m/d H:i');

        return $this->renderTemplate('confirmed.html', [
            'AMOUNT'            => (string) (int) $row['amount'],
            'AMOUNT_FORMATTED'  => number_format((int) $row['amount']),
            'CURRENCY'          => (string) ($row['currency'] ?? 'JPY'),
            'REFERENCE_NUMBER'  => (string) ($row['reference_number'] ?? ''),
            'CUSTOMER_NAME'     => (string) ($row['customer_name'] ?? ''),
            'CONFIRMED_AT'      => $confirmedAt,
            'STATUS'            => 'confirmed',
            'BRAND_NAME'        => 'B-Pay',
            'SERVICE_NAME'      => 'B-Pay',
            'RETURN_URL'        => '',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderExpired(array $row, string $message): string
    {
        return $this->renderTemplate('expired.html', [
            'MESSAGE'           => $message,
            'AMOUNT_FORMATTED'  => number_format((int) ($row['amount'] ?? 0)),
            'CURRENCY'          => (string) ($row['currency'] ?? 'JPY'),
            'REFERENCE_NUMBER'  => (string) ($row['reference_number'] ?? ''),
            'STATUS'            => (string) ($row['status'] ?? 'expired'),
            'BRAND_NAME'        => 'B-Pay',
            'SERVICE_NAME'      => 'B-Pay',
            'RETURN_URL'        => '',
        ]);
    }

    /**
     * Extract the katakana customer name used on the bank depositor line.
     *
     * Source priority:
     *   1. payment_links.customer_kana         — dedicated column (preferred)
     *   2. metadata.customer_kana              — legacy fallback from JSON metadata
     *   3. customer_name if it's already kana  — heuristic for pure-kana input
     *
     * If no kana is available, returns an empty string so the UI can highlight
     * that the customer should transfer using the reference number only.
     *
     * @param array<string, mixed> $row
     */
    private function extractKana(array $row): string
    {
        // 1) Preferred: dedicated column
        if (!empty($row['customer_kana'])) {
            return (string) $row['customer_kana'];
        }

        // 2) Legacy: metadata.customer_kana
        $metadata = $row['metadata'] ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded) && !empty($decoded['customer_kana'])) {
                return (string) $decoded['customer_kana'];
            }
        }

        // 3) Heuristic: if customer_name contains only katakana + whitespace, use it
        $name = (string) ($row['customer_name'] ?? '');
        if ($name !== '' && preg_match('/^[\p{Katakana}\p{Hiragana}ー\s　]+$/u', $name)) {
            // If hiragana, convert to katakana for consistency
            return mb_convert_kana($name, 'C');
        }

        return '';
    }
}
