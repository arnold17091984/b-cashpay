<?php

declare(strict_types=1);

namespace BCashPay\Controllers;

use BCashPay\Database;
use BCashPay\Services\PaymentLinkService;
use BCashPay\Services\ReferenceGenerator;
use BCashPay\Services\TelegramCommandHandler;
use BCashPay\Services\TelegramGateway;
use BCashPay\Services\TelegramNotifier;
use BCashPay\Services\WebhookSender;

/**
 * POST /api/internal/telegram/webhook — Telegram Bot API delivery endpoint.
 *
 * Security controls applied here, in order:
 *   1. `X-Telegram-Bot-Api-Secret-Token` header must match our configured
 *      secret (set when we called `setWebhook`).  If it does not, return
 *      200 with no action — never leak the existence of the endpoint.
 *   2. Feature flag `app_settings.telegram_bot_enabled` must be truthy.
 *      Admins flip it via the dashboard; while off every request short-
 *      circuits to 200.
 *   3. Idempotency gate: an `INSERT IGNORE` into `telegram_updates` keyed
 *      on `update_id`.  Telegram retries on webhook timeouts; without this,
 *      a retry could duplicate a payment link.
 *
 * After those, dispatch the update to TelegramCommandHandler.  We ALWAYS
 * reply 200 quickly — Telegram retries 4xx/5xx aggressively.
 */
class TelegramWebhookController
{
    private readonly Database $db;
    private readonly TelegramGateway $tg;
    private readonly TelegramCommandHandler $handler;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->tg = new TelegramGateway();

        $linkService = new PaymentLinkService(
            $this->db,
            new ReferenceGenerator($this->db),
            new TelegramNotifier($this->db),
            new WebhookSender($this->db)
        );

        $this->handler = new TelegramCommandHandler($this->db, $this->tg, $linkService);
    }

    public function handle(): never
    {
        // ── 1. Verify Telegram secret token header ────────────────────────
        $providedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
        $expectedSecret = (string) config('telegram.webhook_secret', '');
        if ($expectedSecret === '' || !hash_equals($expectedSecret, (string) $providedSecret)) {
            // Do not leak — just 200 and drop.
            json_response(['success' => true], 200);
        }

        // ── 2. Kill switch ────────────────────────────────────────────────
        if (!$this->isBotEnabled()) {
            json_response(['success' => true, 'note' => 'disabled'], 200);
        }

        // ── 3. Parse + idempotency gate ──────────────────────────────────
        // Cap inbound body at 1 MB so a crafted oversized payload cannot
        // exhaust PHP memory or starve the worker on json_decode.  Telegram
        // itself normally sends <10 KB updates.
        $maxBody = 1_048_576;
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > $maxBody) {
            json_response(['success' => true, 'note' => 'oversize'], 200);
        }
        $fp  = fopen('php://input', 'rb');
        $raw = $fp !== false ? (string) stream_get_contents($fp, $maxBody) : '';
        if ($fp !== false) {
            fclose($fp);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['update_id'])) {
            json_response(['success' => true], 200);
        }

        $updateId = (int) $data['update_id'];
        [$chatId, $userId, $messageId, $kind] = $this->extractMeta($data);

        // Chat-ID allowlist.  TELEGRAM_ALLOWED_CHAT_IDS (comma-separated
        // env) ensures the bot only responds in chats the operator
        // explicitly authorised — a defence in depth in case the bot is
        // accidentally added to another group.  When the allowlist is
        // empty, behaviour is unchanged (accept any chat passing the
        // secret check).
        $allowedChatIds = array_map(
            static fn($v) => (int) $v,
            (array) config('telegram.allowed_chat_ids', [])
        );
        if (
            !empty($allowedChatIds)
            && $chatId !== null
            && !in_array((int) $chatId, $allowedChatIds, true)
        ) {
            json_response(['success' => true, 'note' => 'chat-not-allowed'], 200);
        }

        try {
            $this->db->insert('telegram_updates', [
                'update_id'    => $updateId,
                'chat_id'      => $chatId,
                'user_id'      => $userId,
                'message_id'   => $messageId,
                'kind'         => $kind,
                'payload'      => mb_substr($raw, 0, 8000),
                'processed_at' => now_jst(),
            ]);
        } catch (\Throwable) {
            // Duplicate update_id — already handled, bail out.
            json_response(['success' => true, 'note' => 'duplicate'], 200);
        }

        // ── 4. Dispatch ──────────────────────────────────────────────────
        try {
            if ($kind === 'message' && isset($data['message']) && is_array($data['message'])) {
                $this->handler->handleMessage($data['message']);
            } elseif ($kind === 'callback_query' && isset($data['callback_query']) && is_array($data['callback_query'])) {
                $this->handler->handleCallback($data['callback_query']);
            }
        } catch (\Throwable $e) {
            // Surface errors to app logs but never to Telegram — they would
            // cause the Bot API to retry.
            error_log('[telegram webhook] dispatch error: ' . $e->getMessage());
        }

        json_response(['success' => true], 200);
    }

    private function isBotEnabled(): bool
    {
        try {
            $row = $this->db->fetchOne(
                'SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1',
                ['telegram_bot_enabled']
            );
        } catch (\Throwable) {
            return false;
        }
        return $row !== null && (string) $row['setting_value'] === '1';
    }

    /**
     * @param array<string, mixed> $update
     * @return array{0:?int,1:?int,2:?int,3:string}
     */
    private function extractMeta(array $update): array
    {
        if (isset($update['message']) && is_array($update['message'])) {
            return [
                (int) ($update['message']['chat']['id'] ?? 0) ?: null,
                (int) ($update['message']['from']['id'] ?? 0) ?: null,
                (int) ($update['message']['message_id'] ?? 0) ?: null,
                'message',
            ];
        }
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $msg = $update['callback_query']['message'] ?? [];
            return [
                (int) ($msg['chat']['id'] ?? 0) ?: null,
                (int) ($update['callback_query']['from']['id'] ?? 0) ?: null,
                (int) ($msg['message_id'] ?? 0) ?: null,
                'callback_query',
            ];
        }
        return [null, null, null, 'other'];
    }
}
