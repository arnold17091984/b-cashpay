<?php

declare(strict_types=1);

namespace BCashPay\Services;

/**
 * Thin wrapper around Telegram Bot API methods that the chat command flow
 * needs beyond the fire-and-forget notifications in TelegramNotifier —
 * sendMessage with inline keyboards, editMessageText, deleteMessage, and
 * answerCallbackQuery.
 *
 * All calls return the raw Telegram response as an associative array on
 * success, or null on transport / HTTP failure.  Callers are expected to
 * treat nulls as non-fatal and log — chat replies must never block the
 * webhook handler from returning 200.
 */
class TelegramGateway
{
    private string $botToken;

    public function __construct()
    {
        $this->botToken = (string) config('telegram.bot_token');
    }

    public function isConfigured(): bool
    {
        return $this->botToken !== '';
    }

    /**
     * Send a plain text or HTML message to a chat, optionally attaching an
     * inline keyboard.  Pass $replyToMessageId to thread under an operator's
     * command for easier mobile reading.
     *
     * @param array<int, array<int, array{text: string, callback_data?: string, url?: string}>>|null $inlineKeyboard
     * @return array<string, mixed>|null
     */
    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $inlineKeyboard = null,
        ?int $replyToMessageId = null,
        string $parseMode = 'HTML',
    ): ?array {
        $body = [
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($inlineKeyboard !== null) {
            $body['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }
        if ($replyToMessageId !== null) {
            $body['reply_parameters'] = ['message_id' => $replyToMessageId];
        }

        return $this->call('sendMessage', $body);
    }

    /**
     * Replace the content of a previously-sent bot message (e.g. change a
     * confirmation card to "発行済み: <URL>" once the operator confirms).
     *
     * @return array<string, mixed>|null
     */
    public function editMessageText(
        int|string $chatId,
        int $messageId,
        string $text,
        ?array $inlineKeyboard = null,
        string $parseMode = 'HTML',
    ): ?array {
        $body = [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => $parseMode,
            'disable_web_page_preview' => true,
        ];
        if ($inlineKeyboard !== null) {
            $body['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        } else {
            // Explicitly clear any previous buttons.
            $body['reply_markup'] = ['inline_keyboard' => []];
        }

        return $this->call('editMessageText', $body);
    }

    public function deleteMessage(int|string $chatId, int $messageId): ?array
    {
        return $this->call('deleteMessage', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
        ]);
    }

    /**
     * Acknowledge a callback_query so Telegram removes the "loading" spinner
     * on the operator's button.  The optional $text flashes as a toast.
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $alert = false): ?array
    {
        $body = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $body['text'] = $text;
            $body['show_alert'] = $alert;
        }
        return $this->call('answerCallbackQuery', $body);
    }

    public function setWebhook(string $url, string $secretToken): ?array
    {
        return $this->call('setWebhook', [
            'url'            => $url,
            'secret_token'   => $secretToken,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    public function deleteWebhook(): ?array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => true]);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->call('getWebhookInfo', []);
    }

    /**
     * Low-level Bot API caller.  Returns decoded response or null on failure.
     * Non-2xx responses still decode — Telegram's error envelope is useful.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function call(string $method, array $body): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\n",
                'content'       => json_encode($body, JSON_UNESCAPED_UNICODE),
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $url  = "https://api.telegram.org/bot{$this->botToken}/{$method}";
        $raw  = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
