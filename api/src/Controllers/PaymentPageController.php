<?php

declare(strict_types=1);

namespace BCashPay\Controllers;

use BCashPay\Database;
use BCashPay\Services\PaymentLinkService;
use BCashPay\Services\ReferenceGenerator;
use BCashPay\Services\TelegramNotifier;
use BCashPay\Services\WebhookSender;

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
    private readonly PaymentLinkService $service;
    private readonly string $templateDir;
    private readonly string $assetsBaseUrl;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->service = new PaymentLinkService(
            $this->db,
            new ReferenceGenerator($this->db),
            new TelegramNotifier($this->db),
            new WebhookSender($this->db)
        );
        // Templates and assets live outside the api/ dir
        $this->templateDir = dirname(__DIR__, 3) . '/pay/templates';
        $this->assetsBaseUrl = '/assets';  // nginx proxies /assets/* → /pay/assets/*
    }

    /**
     * GET /p/{token}
     */
    public function show(string $token, ?string $errorMessage = null, array $prev = []): never
    {
        $row = $this->loadByToken($token);

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

        // Auto-expire pending links past expires_at.  Templates don't expire
        // on schedule — they stay live until an operator cancels them.
        if (
            $row['status'] === 'pending'
            && $row['link_type'] !== 'template'
            && strtotime($row['expires_at']) < time()
        ) {
            $this->db->update(
                'payment_links',
                ['status' => 'expired', 'updated_at' => now_jst()],
                ['id' => $row['id']]
            );
            $row['status'] = 'expired';
        }

        http_response_code(200);

        // ── Route by link_type first, then by status ──────────────────────
        if ($row['link_type'] === 'template' || $row['link_type'] === 'awaiting_input') {
            // Customer hasn't submitted their amount yet → show input form.
            echo $this->renderAwaitingInput($row, $errorMessage, $prev);
            exit;
        }

        echo match ($row['status']) {
            'confirmed' => $this->renderConfirmed($row),
            'expired'   => $this->renderExpired($row, 'この支払いリンクは有効期限が切れています。'),
            'cancelled' => $this->renderExpired($row, 'この支払いリンクはキャンセルされました。'),
            default     => $this->renderPayment($row),
        };
        exit;
    }

    /**
     * POST /p/{token}/submit — customer-entered amount + optional kana.
     *
     * For link_type='awaiting_input': upgrade the existing row in place
     * (status pending, amount set, locked_at set) and re-render the payment
     * page with the now-known depositor-name line.
     *
     * For link_type='template': spawn a brand-new child row with its own
     * token + reference_number and redirect the customer to the child URL
     * so subsequent visitors to the template link still see a blank form.
     */
    public function submit(string $token): never
    {
        $row = $this->loadByToken($token);

        if ($row === null) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderTemplate('expired.html', [
                'MESSAGE'      => '支払いリンクが見つかりません。',
                'BRAND_NAME'   => 'B-Pay',
                'SERVICE_NAME' => 'B-Pay',
                'RETURN_URL'   => '',
            ]);
            exit;
        }

        // Only awaiting_input and template links accept submits.  Anything
        // else — a fixed `single` link that has already been filled, a
        // confirmed/expired/cancelled link, a freshly-finalised awaiting
        // link — gets redirected to the display page, which knows how to
        // render the correct state.  We key on status (the authoritative
        // state field) rather than link_type so the guard stays correct
        // even if finaliseAwaitingInput flips link_type to 'single'.
        if (!in_array($row['status'], ['awaiting_input', 'pending'], true)
            || !in_array($row['link_type'], ['awaiting_input', 'template'], true)
        ) {
            header('Location: /p/' . $token, true, 303);
            exit;
        }

        // Rate limit every inbound submit.  Two windows:
        //   - per IP + per token: 5 submits / minute — stops repeated double
        //     taps on one link from flooding the matcher
        //   - per IP across all templates: 30 submits / 5 minutes — caps
        //     the blast radius of a single attacker trying to spam many
        //     template URLs
        $clientIp = $this->clientIp();
        \applyRateLimit('submit_' . md5($clientIp . '|' . $token), 5, 60, 429);
        if ($row['link_type'] === 'template') {
            \applyRateLimit('submit_tpl_' . md5($clientIp), 30, 300, 429);
        }

        $rawAmount = trim((string) ($_POST['amount'] ?? ''));
        $rawKana   = trim((string) ($_POST['customer_kana'] ?? ''));
        $prev      = ['amount' => $rawAmount, 'kana' => $rawKana];

        // ── Amount validation ─────────────────────────────────────────────
        // Normalise full-width digits / commas before casting to int, so
        // copy-pasted bank-style ¥50,000 or ５０,０００ parse correctly.
        $normalised = mb_convert_kana($rawAmount, 'asn');
        $normalised = str_replace([',', ' ', '　', '¥', '￥'], '', $normalised);
        if (!preg_match('/^\d+$/', $normalised)) {
            $this->show($token, '金額は半角数字で入力してください。', $prev);
        }
        $amount = (int) $normalised;

        // Absolute server-side ceiling, independent of the per-link max.  The
        // schema stores `amount` as DECIMAL(12,0) and the admin UI caps input
        // at 7 digits, so anything beyond this is either operator data
        // corruption or a malicious direct POST.
        if ($amount > 9_999_999) {
            $this->show($token, '金額の上限を超えています。', $prev);
        }

        $min = $row['min_amount'] !== null ? (int) $row['min_amount'] : 1000;
        $max = $row['max_amount'] !== null ? (int) $row['max_amount'] : 500_000;
        if ($amount < $min || $amount > $max) {
            $this->show(
                $token,
                sprintf('金額は ¥%s 〜 ¥%s の範囲でご入力ください。', number_format($min), number_format($max)),
                $prev
            );
        }

        // ── Kana validation (optional) ────────────────────────────────────
        // Policy: accept full-width / half-width katakana AND hiragana.
        // Hiragana is silently converted to katakana so the stored value is
        // always in the form the bank shows on deposit lines.  If you want
        // to reject hiragana instead, add a `preg_match('/\p{Hiragana}/u',
        // $rawKana)` check BEFORE the mb_convert_kana call.
        $kana = null;
        if ($rawKana !== '') {
            // Pre-normalisation charset guard — reject anything that is not
            // katakana/hiragana/prolonged-sound/spaces so junk input fails
            // before we run it through the converter.
            if (!preg_match('/^[\p{Katakana}\p{Hiragana}ー\s　]{1,40}$/u', $rawKana)) {
                $this->show(
                    $token,
                    '振込依頼人名カナはカタカナで入力してください（空欄でも構いません）。',
                    $prev
                );
            }
            $kana = mb_convert_kana($rawKana, 'KVC');  // → full-width katakana
        }

        // ── Branch on link_type ───────────────────────────────────────────
        if ($row['link_type'] === 'template') {
            $child = $this->service->createChildFromTemplate($row, $amount, $kana);
            header('Location: /p/' . $child['token'], true, 303);
            exit;
        }

        // awaiting_input: upgrade the existing row in place, transactionally,
        // so two concurrent submits can't both succeed.
        $this->service->finaliseAwaitingInput($row, $amount, $kana);
        header('Location: /p/' . $token, true, 303);
        exit;
    }

    /**
     * Best-effort client IP extraction that respects the first hop of
     * X-Forwarded-For when nginx is in front.  Same logic as
     * rateLimitPublic() in api/public/index.php.
     */
    private function clientIp(): string
    {
        $raw = $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
        return trim(explode(',', (string) $raw)[0]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadByToken(string $token): ?array
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
        return is_array($row) ? $row : null;
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

    /**
     * Render the amount-entry form for awaiting_input / template links.
     *
     * @param array<string, mixed>    $row
     * @param string|null             $errorMessage shown above the form on retry
     * @param array<string, string>   $prev previous submit values to repopulate
     */
    private function renderAwaitingInput(array $row, ?string $errorMessage, array $prev): string
    {
        $min = $row['min_amount'] !== null ? (int) $row['min_amount'] : 1000;
        $max = $row['max_amount'] !== null ? (int) $row['max_amount'] : 500_000;

        $presetsRaw = $row['preset_amounts'];
        $presets = [];
        if (is_string($presetsRaw) && $presetsRaw !== '') {
            $decoded = json_decode($presetsRaw, true);
            if (is_array($decoded)) {
                $presets = array_values(array_filter(array_map(
                    static fn($v) => is_numeric($v) ? (int) $v : null,
                    $decoded
                )));
            }
        }

        $intro = $row['link_type'] === 'template'
            ? 'お振込金額をご入力ください。複数の方にご利用いただける共有リンクです。'
            : 'お振込金額をご入力いただくと、お振込先情報を表示します。';

        $errorHtml = '';
        if ($errorMessage !== null && $errorMessage !== '') {
            $errorHtml = '<div class="bcp-error">'
                . htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8')
                . '</div>';
        }

        return $this->renderTemplate('awaiting_input.html', [
            'TOKEN'               => (string) $row['token'],
            'SUBMIT_URL'          => '/p/' . $row['token'] . '/submit',
            'TEMPLATE_INTRO'      => $intro,
            'IS_TEMPLATE'         => $row['link_type'] === 'template' ? '1' : '0',
            'BANK_NAME'           => (string) ($row['bank_name'] ?? ''),
            'BRANCH_NAME'         => (string) ($row['branch_name'] ?? ''),
            'ACCOUNT_NUMBER'      => (string) ($row['account_number'] ?? ''),
            'ACCOUNT_TYPE'        => (string) ($row['account_type'] ?? '普通'),
            'MIN_AMOUNT'          => (string) $min,
            'MAX_AMOUNT'          => (string) $max,
            'MIN_AMOUNT_FMT'      => number_format($min),
            'MAX_AMOUNT_FMT'      => number_format($max),
            'PRESET_AMOUNTS_JSON_RAW' => json_encode($presets, JSON_UNESCAPED_UNICODE),
            'ERROR_HTML_RAW'      => $errorHtml,
            'PREV_AMOUNT'         => (string) ($prev['amount'] ?? ''),
            'PREV_KANA'           => (string) ($prev['kana'] ?? ''),
            'BRAND_NAME'          => 'B-Pay',
            'SERVICE_NAME'        => 'B-Pay',
        ]);
    }
}
