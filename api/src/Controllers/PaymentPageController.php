<?php

declare(strict_types=1);

namespace BCashPay\Controllers;

use BCashPay\Database;

/**
 * PaymentPageController — Customer-facing payment pages (HTML).
 *
 * Routes:
 *   GET /p/{token}               — Render payment instruction page
 *   GET /api/v1/pay/{token}/status — JSON status poll (public, rate-limited)
 */
class PaymentPageController
{
    private readonly Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /p/{token}
     * Render the customer-facing payment page as HTML.
     * No auth required — the 32-char random token is the access control.
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
            echo $this->renderExpiredPage('支払いリンクが見つかりません。');
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
            'confirmed' => $this->renderConfirmedPage($row),
            'expired'   => $this->renderExpiredPage('この支払いリンクは有効期限が切れています。'),
            'cancelled' => $this->renderExpiredPage('この支払いリンクはキャンセルされました。'),
            default     => $this->renderPaymentPage($row),
        };
        exit;
    }

    /**
     * GET /api/v1/pay/{token}/status
     * Public JSON polling endpoint. Returns just the status fields.
     * Rate-limited by the router before reaching here.
     */
    public function pollStatus(string $token): never
    {
        $row = $this->db->fetchOne(
            'SELECT status, confirmed_at, expires_at FROM payment_links
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
                ['id' => $row['id'] ?? '']
            );

            // Re-fetch the row using token since we may not have the id
            $row = $this->db->fetchOne(
                'SELECT status, confirmed_at, expires_at FROM payment_links WHERE token = ? LIMIT 1',
                [$token]
            );
        }

        json_response([
            'success'      => true,
            'status'       => $row['status'],
            'confirmed_at' => $row['confirmed_at'],
            'expires_at'   => $row['expires_at'],
        ]);
    }

    // -------------------------------------------------------------------------
    // HTML template renderers — inline for portability (no template engine)
    // -------------------------------------------------------------------------

    /**
     * Render the pending payment instruction page.
     *
     * @param array<string, mixed> $row
     */
    private function renderPaymentPage(array $row): string
    {
        $e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $amount      = number_format((int) $row['amount']);
        $bankName    = $e((string) ($row['bank_name'] ?? ''));
        $branchName  = $e((string) ($row['branch_name'] ?? ''));
        $branchCode  = $e((string) ($row['branch_code'] ?? ''));
        $accountType = $e((string) ($row['account_type'] ?? '普通'));
        $accountNum  = $e((string) ($row['account_number'] ?? ''));
        $accountName = $e((string) ($row['account_name'] ?? ''));
        $reference   = $e((string) ($row['reference_number'] ?? ''));
        $customerName = $e((string) ($row['customer_name'] ?? ''));
        $expiresAt   = $e((string) ($row['expires_at'] ?? ''));
        $token       = $e((string) ($row['token'] ?? ''));
        $appUrl      = rtrim((string) config('pay_page.url'), '/');

        return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>銀行振込のご案内 — B-CashPay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Hiragino Sans', Meiryo, sans-serif; background: #f4f6f8; color: #333; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,0.10); max-width: 520px; width: 100%; padding: 36px 32px; }
  .logo { text-align: center; font-size: 22px; font-weight: 700; color: #1a56db; margin-bottom: 8px; letter-spacing: -0.5px; }
  .title { text-align: center; font-size: 18px; font-weight: 600; color: #111; margin-bottom: 24px; }
  .amount-box { background: #eff6ff; border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 24px; }
  .amount-label { font-size: 13px; color: #6b7280; margin-bottom: 4px; }
  .amount-value { font-size: 32px; font-weight: 700; color: #1a56db; }
  .amount-currency { font-size: 16px; color: #6b7280; margin-left: 4px; }
  .section-title { font-size: 13px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
  .info-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
  .info-table tr { border-bottom: 1px solid #f0f0f0; }
  .info-table tr:last-child { border-bottom: none; }
  .info-table td { padding: 10px 4px; font-size: 14px; }
  .info-table td:first-child { color: #6b7280; width: 110px; }
  .info-table td:last-child { font-weight: 500; }
  .ref-box { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 16px; text-align: center; margin-bottom: 24px; }
  .ref-label { font-size: 13px; color: #92400e; margin-bottom: 6px; font-weight: 600; }
  .ref-number { font-size: 36px; font-weight: 700; color: #92400e; letter-spacing: 4px; font-variant-numeric: tabular-nums; }
  .ref-note { font-size: 12px; color: #92400e; margin-top: 8px; }
  .notice { background: #f9fafb; border-left: 4px solid #d1d5db; padding: 12px 16px; border-radius: 0 6px 6px 0; margin-bottom: 24px; font-size: 13px; color: #4b5563; line-height: 1.6; }
  .expires { text-align: center; font-size: 13px; color: #6b7280; margin-bottom: 20px; }
  .status-bar { text-align: center; font-size: 13px; color: #6b7280; padding: 12px; background: #f9fafb; border-radius: 8px; }
  #status-indicator { display: inline-block; width: 8px; height: 8px; background: #f59e0b; border-radius: 50%; margin-right: 6px; animation: pulse 2s infinite; vertical-align: middle; }
  @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
</style>
</head>
<body>
<div class="card">
  <div class="logo">B-CashPay</div>
  <div class="title">銀行振込のご案内</div>

  <div class="amount-box">
    <div class="amount-label">お振込金額</div>
    <div>
      <span class="amount-value">{$amount}</span>
      <span class="amount-currency">JPY</span>
    </div>
  </div>

  <div class="section-title">振込先口座</div>
  <table class="info-table">
    <tr><td>銀行名</td><td>{$bankName}</td></tr>
    <tr><td>支店名</td><td>{$branchName} ({$branchCode})</td></tr>
    <tr><td>口座種別</td><td>{$accountType}</td></tr>
    <tr><td>口座番号</td><td>{$accountNum}</td></tr>
    <tr><td>口座名義</td><td>{$accountName}</td></tr>
  </table>

  <div class="ref-box">
    <div class="ref-label">振込依頼人名に必ず含めてください</div>
    <div class="ref-number">{$reference}</div>
    <div class="ref-note">例: ヤマダ タロウ {$reference}</div>
  </div>

  <div class="notice">
    <strong>ご注意：</strong>振込依頼人名（カナ）の先頭または末尾に参照番号
    <strong>{$reference}</strong> を含めてお振込ください。
    参照番号がない場合、自動照合ができません。
  </div>

  <div class="expires">有効期限: {$expiresAt}</div>

  <div class="status-bar">
    <span id="status-indicator"></span>
    <span id="status-text">入金確認を待っています...</span>
  </div>
</div>
<script>
(function() {
  var token = '{$token}';
  var apiUrl = '{$appUrl}/api/v1/pay/' + token + '/status';
  var redirected = false;

  function checkStatus() {
    if (redirected) return;
    fetch(apiUrl, { headers: { 'Accept': 'application/json' } })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.status === 'confirmed') {
          redirected = true;
          document.getElementById('status-indicator').style.background = '#10b981';
          document.getElementById('status-text').textContent = '入金が確認されました。';
        } else if (data.status === 'expired' || data.status === 'cancelled') {
          redirected = true;
          document.getElementById('status-indicator').style.background = '#ef4444';
          document.getElementById('status-text').textContent = 'この支払いリンクは無効です。';
        }
      })
      .catch(function() {});
  }

  checkStatus();
  setInterval(checkStatus, 10000);
})();
</script>
</body>
</html>
HTML;
    }

    /**
     * Render the confirmation page shown after payment is verified.
     *
     * @param array<string, mixed> $row
     */
    private function renderConfirmedPage(array $row): string
    {
        $e = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $amount      = number_format((int) $row['amount']);
        $reference   = $e((string) ($row['reference_number'] ?? ''));
        $confirmedAt = $e((string) ($row['confirmed_at'] ?? ''));

        return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>入金確認完了 — B-CashPay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Hiragino Sans', Meiryo, sans-serif; background: #f4f6f8; color: #333; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,0.10); max-width: 520px; width: 100%; padding: 48px 32px; text-align: center; }
  .icon { font-size: 64px; margin-bottom: 16px; }
  .title { font-size: 24px; font-weight: 700; color: #059669; margin-bottom: 12px; }
  .subtitle { font-size: 15px; color: #4b5563; margin-bottom: 32px; line-height: 1.6; }
  .detail { background: #ecfdf5; border-radius: 8px; padding: 20px; display: inline-block; text-align: left; }
  .detail-row { font-size: 14px; color: #065f46; margin: 4px 0; }
  .detail-label { font-weight: 600; display: inline-block; width: 100px; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">&#x2705;</div>
  <div class="title">入金が確認されました</div>
  <div class="subtitle">お振込ありがとうございます。<br>入金が正常に確認されました。</div>
  <div class="detail">
    <div class="detail-row"><span class="detail-label">金額</span>{$amount} JPY</div>
    <div class="detail-row"><span class="detail-label">参照番号</span>{$reference}</div>
    <div class="detail-row"><span class="detail-label">確認日時</span>{$confirmedAt}</div>
  </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Render the expired/cancelled/not-found page.
     */
    private function renderExpiredPage(string $reason): string
    {
        $e = htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>支払いリンク無効 — B-CashPay</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Helvetica Neue', Arial, 'Hiragino Kaku Gothic ProN', 'Hiragino Sans', Meiryo, sans-serif; background: #f4f6f8; color: #333; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 16px rgba(0,0,0,0.10); max-width: 520px; width: 100%; padding: 48px 32px; text-align: center; }
  .icon { font-size: 64px; margin-bottom: 16px; }
  .title { font-size: 22px; font-weight: 700; color: #dc2626; margin-bottom: 12px; }
  .message { font-size: 15px; color: #4b5563; line-height: 1.6; }
</style>
</head>
<body>
<div class="card">
  <div class="icon">&#x274C;</div>
  <div class="title">支払いリンクが無効です</div>
  <div class="message">{$e}</div>
</div>
</body>
</html>
HTML;
    }
}
