<?php

declare(strict_types=1);

namespace BCashPay\Services;

use BCashPay\Database;
use RuntimeException;

/**
 * Sends notifications to a Telegram chat via the Bot API.
 *
 * All sends are fire-and-forget — failures are logged but do not
 * propagate exceptions to the caller. Each send is persisted in the
 * telegram_logs table for audit.
 *
 * Messages use HTML parse_mode. Special HTML characters in user-supplied
 * strings must be escaped before interpolation.
 */
class TelegramNotifier
{
    private string $botToken;
    private string $chatId;
    private bool $enabled;

    public function __construct(private readonly Database $db)
    {
        $this->botToken = (string) config('telegram.bot_token');
        $this->chatId   = (string) config('telegram.chat_id');
        $this->enabled  = $this->botToken !== '' && $this->chatId !== '';
    }

    /**
     * Send a raw HTML-formatted message.
     * Returns true on success, false on any failure (no exception thrown).
     */
    public function send(string $message, ?string $paymentLinkId = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n",
                'content' => json_encode([
                    'chat_id'                  => $this->chatId,
                    'text'                     => $message,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => true,
                ]),
                'timeout'        => 10,
                'ignore_errors'  => true,
            ],
        ]);

        $url    = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        $result = @file_get_contents($url, false, $context);
        $success = $result !== false;

        // Persist audit log — best effort, never throw
        try {
            $this->db->insert('telegram_logs', [
                'payment_link_id' => $paymentLinkId,
                'chat_id'         => $this->chatId,
                'message'         => $message,
                'sent_at'         => $success ? now_jst() : null,
                'created_at'      => now_jst(),
            ]);
        } catch (\Throwable) {
            // Logging failure must not affect the API response
        }

        return $success;
    }

    /**
     * Notify that a payment link was created.
     *
     * @param array<string, mixed> $data
     */
    public function notifyPaymentCreated(array $data): bool
    {
        $amount    = number_format((int) ($data['amount'] ?? 0));
        $name      = $this->escapeHtml((string) ($data['customer_name'] ?? '-'));
        $ref       = $this->escapeHtml((string) ($data['reference_number'] ?? '-'));
        $id        = $this->escapeHtml((string) ($data['id'] ?? '-'));
        $expiresAt = $this->escapeHtml((string) ($data['expires_at'] ?? '-'));

        $msg = "\xF0\x9F\x92\xB3 <b>新規支払いリンク作成</b>\n\n"
            . "ID: <code>{$id}</code>\n"
            . "お客様名: {$name}\n"
            . "金額: {$amount} JPY\n"
            . "参照番号: <code>{$ref}</code>\n"
            . "有効期限: {$expiresAt}\n"
            . "作成日時: " . now_jst();

        return $this->send($msg, $data['id'] ?? null);
    }

    /**
     * Notify that a deposit was detected and matched to a payment link.
     *
     * @param array<string, mixed> $data
     */
    public function notifyPaymentConfirmed(array $data): bool
    {
        $amount    = number_format((int) ($data['amount'] ?? 0));
        $name      = $this->escapeHtml((string) ($data['customer_name'] ?? '-'));
        $ref       = $this->escapeHtml((string) ($data['reference_number'] ?? '-'));
        $id        = $this->escapeHtml((string) ($data['id'] ?? '-'));
        $depositor = $this->escapeHtml((string) ($data['depositor_name'] ?? '-'));

        $msg = "\xE2\x9C\x85 <b>入金確認完了</b>\n\n"
            . "ID: <code>{$id}</code>\n"
            . "お客様名: {$name}\n"
            . "振込人名: {$depositor}\n"
            . "金額: {$amount} JPY\n"
            . "参照番号: <code>{$ref}</code>\n"
            . "確認日時: " . now_jst();

        return $this->send($msg, $data['id'] ?? null);
    }

    /**
     * Notify that a deposit was detected but could NOT be matched.
     *
     * @param array<string, mixed> $data
     */
    public function notifyDepositUnmatched(array $data): bool
    {
        $amount    = number_format((int) ($data['amount'] ?? 0));
        $depositor = $this->escapeHtml((string) ($data['depositor_name'] ?? '-'));
        $txId      = $this->escapeHtml((string) ($data['bank_transaction_id'] ?? '-'));

        $msg = "\xE2\x9A\xA0\xEF\xB8\x8F <b>未マッチ入金検知</b>\n\n"
            . "振込人名: {$depositor}\n"
            . "金額: {$amount} JPY\n"
            . "銀行取引ID: <code>{$txId}</code>\n"
            . "検知日時: " . now_jst();

        return $this->send($msg);
    }

    /**
     * Notify that a scraper encountered an error.
     *
     * @param array<string, mixed> $data
     */
    public function notifyScraperError(array $data): bool
    {
        $bankName = $this->escapeHtml((string) ($data['bank_name'] ?? '-'));
        $error    = $this->escapeHtml((string) ($data['error'] ?? 'Unknown error'));

        $msg = "\xF0\x9F\x9A\xA8 <b>スクレイパーエラー</b>\n\n"
            . "銀行: {$bankName}\n"
            . "エラー: {$error}\n"
            . "発生日時: " . now_jst();

        return $this->send($msg);
    }

    /**
     * Escape HTML special characters for safe use inside HTML-mode Telegram messages.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
