<?php

declare(strict_types=1);

namespace BCashPay\Services;

use BCashPay\Database;
use Throwable;

/**
 * Interprets Telegram group chat input into B-Pay actions and replies on
 * behalf of the bot.  Keep this file free of HTTP / webhook plumbing — the
 * caller passes already-dispatched update objects.
 *
 * Supported commands (Phase 1):
 *   /help                            — usage reminder
 *   /bind <token>                    — associate this Telegram user with an admin
 *   /new <amount> <katakana_name>    — stage a payment link (shows confirmation card)
 *   /list                            — last 5 links issued by this user
 *
 * The happy path for /new is:
 *   operator      → /new 500000 タナカタロウ
 *   bot (card)    → 確認: ¥500,000 / タナカ タロウ    [✓ 発行] [✗ キャンセル]
 *   operator      → taps ✓
 *   bot → edits card to "発行済 → https://b-pay.ink/p/..." and DMs the URL.
 */
class TelegramCommandHandler
{
    private const CONFIRM_NONCE_TTL_MIN = 5;
    private const BIND_TOKEN_TTL_MIN    = 15;

    public function __construct(
        private readonly Database $db,
        private readonly TelegramGateway $tg,
        private readonly PaymentLinkService $linkService,
    ) {
    }

    /**
     * Handle a decoded `message` object.  Safe to call for any message —
     * non-command text is silently ignored.
     *
     * @param array<string, mixed> $message
     */
    public function handleMessage(array $message): void
    {
        $chatId    = (int) ($message['chat']['id'] ?? 0);
        $fromId    = (int) ($message['from']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $text      = trim((string) ($message['text'] ?? ''));

        if ($text === '' || $chatId === 0 || $fromId === 0) {
            return;
        }
        if (!str_starts_with($text, '/')) {
            return; // Free-text in groups is ignored — Phase 3 will handle this.
        }

        // Peel off any @botname mention the Telegram client appends to commands.
        $firstSpace = strpos($text, ' ');
        $head       = $firstSpace === false ? $text : substr($text, 0, $firstSpace);
        $rest       = $firstSpace === false ? '' : trim(substr($text, $firstSpace + 1));
        $command    = strtolower(explode('@', $head, 2)[0]);

        switch ($command) {
            case '/help':
            case '/start':
                $this->replyHelp($chatId, $messageId);
                return;
            case '/bind':
                $this->handleBind($chatId, $fromId, $messageId, $rest);
                return;
            case '/new':
                $this->handleNew($chatId, $fromId, $messageId, $rest);
                return;
            case '/list':
                $this->handleList($chatId, $fromId, $messageId);
                return;
            default:
                // Unknown commands are ignored silently to reduce group noise.
        }
    }

    /**
     * Handle a callback_query from the inline keyboard on a confirmation card.
     *
     * @param array<string, mixed> $callback
     */
    public function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $fromId     = (int) ($callback['from']['id'] ?? 0);
        $data       = (string) ($callback['data'] ?? '');
        $message    = $callback['message'] ?? null;
        $chatId     = (int) ($message['chat']['id'] ?? 0);
        $messageId  = (int) ($message['message_id'] ?? 0);

        if ($callbackId === '' || !is_array($message)) {
            return;
        }

        [$action, $nonce] = array_pad(explode(':', $data, 2), 2, '');
        if ($nonce === '') {
            $this->tg->answerCallbackQuery($callbackId, '無効な操作です', true);
            return;
        }

        if ($action === 'confirm') {
            $this->confirmIntent($callbackId, $fromId, $chatId, $messageId, $nonce);
            return;
        }
        if ($action === 'cancel') {
            $this->cancelIntent($callbackId, $fromId, $chatId, $messageId, $nonce);
            return;
        }

        $this->tg->answerCallbackQuery($callbackId, '不明な操作', true);
    }

    // ── /help ──────────────────────────────────────────────────────────────

    private function replyHelp(int $chatId, int $messageId): void
    {
        $txt = "<b>B-Pay コマンド一覧</b>\n\n"
            . "<code>/new 500000 タナカタロウ</code>\n"
            . "  決済リンクを発行（確認カードを返します）\n\n"
            . "<code>/bind &lt;token&gt;</code>\n"
            . "  管理画面で発行したワンタイムトークンで紐付け\n\n"
            . "<code>/list</code>\n"
            . "  あなたが最近発行したリンク上位5件\n\n"
            . "<code>/help</code>\n"
            . "  このヘルプ";
        $this->tg->sendMessage($chatId, $txt, null, $messageId);
    }

    // ── /bind ──────────────────────────────────────────────────────────────

    private function handleBind(int $chatId, int $fromId, int $messageId, string $rest): void
    {
        $token = trim($rest);
        if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
            $this->tg->sendMessage($chatId, '使い方: <code>/bind &lt;管理画面で発行したトークン&gt;</code>', null, $messageId);
            return;
        }

        try {
            $row = $this->db->fetchOne(
                'SELECT token, admin_user_id, expires_at, consumed_at
                 FROM telegram_bind_tokens
                 WHERE token = ? LIMIT 1',
                [$token]
            );
        } catch (Throwable $e) {
            $this->tg->sendMessage($chatId, '内部エラーで紐付けに失敗しました', null, $messageId);
            return;
        }

        if ($row === null) {
            $this->tg->sendMessage($chatId, 'トークンが見つかりません。管理画面で再発行してください。', null, $messageId);
            return;
        }
        if ($row['consumed_at'] !== null) {
            $this->tg->sendMessage($chatId, 'このトークンは使用済みです。', null, $messageId);
            return;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            $this->tg->sendMessage($chatId, 'トークンの有効期限が切れています。再発行してください。', null, $messageId);
            return;
        }

        $adminId = (int) $row['admin_user_id'];

        // Guard against the same Telegram user re-binding to a different admin.
        $existing = $this->db->fetchOne(
            'SELECT id FROM admin_users WHERE telegram_user_id = ? LIMIT 1',
            [$fromId]
        );
        if ($existing !== null && (int) $existing['id'] !== $adminId) {
            $this->tg->sendMessage($chatId, 'この Telegram アカウントは既に別の管理者に紐付いています。管理画面で解除してください。', null, $messageId);
            return;
        }

        $this->db->transaction(function () use ($adminId, $fromId, $token) {
            $this->db->update(
                'admin_users',
                ['telegram_user_id' => $fromId, 'telegram_enabled' => 1],
                ['id' => $adminId]
            );
            $this->db->update(
                'telegram_bind_tokens',
                ['consumed_at' => now_jst()],
                ['token' => $token]
            );
        });

        $admin = $this->db->fetchOne('SELECT username, name FROM admin_users WHERE id = ?', [$adminId]);
        $who   = htmlspecialchars((string) ($admin['name'] ?? $admin['username'] ?? '?'), ENT_QUOTES, 'UTF-8');
        $this->tg->sendMessage($chatId, "✅ 紐付け完了：<b>{$who}</b> として発行可能になりました。<code>/help</code> で使い方を確認できます。", null, $messageId);
    }

    // ── /new ───────────────────────────────────────────────────────────────

    private function handleNew(int $chatId, int $fromId, int $messageId, string $rest): void
    {
        $admin = $this->resolveAdmin($fromId);
        if ($admin === null) {
            $this->tg->sendMessage($chatId, '⛔ 紐付けがありません。管理画面で /bind トークンを発行してください。', null, $messageId);
            return;
        }

        // Expect: "<amount> <katakana_name>"   amount can have commas or 全角digits
        $restNormalised = mb_convert_kana(trim($rest), 'a');
        if (!preg_match('/^(\d[\d,]*)\s+(.+)$/u', $restNormalised, $m)) {
            $this->tg->sendMessage($chatId, '使い方: <code>/new &lt;金額&gt; &lt;カタカナ氏名&gt;</code>  例: <code>/new 500000 タナカタロウ</code>', null, $messageId);
            return;
        }
        $amount = (int) str_replace(',', '', $m[1]);
        $kanaRaw = trim($m[2]);
        $kana    = mb_convert_kana($kanaRaw, 'KC'); // half→full width katakana + space collapse

        if ($amount < 100 || $amount > 9_999_999) {
            $this->tg->sendMessage($chatId, '金額は ¥100 〜 ¥9,999,999 の整数で指定してください。', null, $messageId);
            return;
        }
        if (!preg_match('/^[\p{Katakana}ー\s　]+$/u', $kana)) {
            $this->tg->sendMessage($chatId, '振込名義はカタカナで指定してください。例: <code>タナカ タロウ</code>', null, $messageId);
            return;
        }

        // Caps
        $perLinkCap = (int) $admin['per_link_cap'];
        if ($amount > $perLinkCap) {
            $fmt = number_format($perLinkCap);
            $this->tg->sendMessage($chatId, "⛔ 1件あたり上限 ¥{$fmt} を超えています。", null, $messageId);
            return;
        }
        $dailyUsed = $this->rollingDailyAmount((int) $admin['id']);
        $dailyCap  = (int) $admin['daily_amount_cap'];
        if ($dailyUsed + $amount > $dailyCap) {
            $remaining = max(0, $dailyCap - $dailyUsed);
            $this->tg->sendMessage(
                $chatId,
                "⛔ 24時間累計上限を超えます（使用済 ¥" . number_format($dailyUsed) . " / 上限 ¥" . number_format($dailyCap) . " / 残 ¥" . number_format($remaining) . "）",
                null,
                $messageId
            );
            return;
        }

        // Defaults
        $bankId   = $admin['default_bank_account_id'] !== null ? (int) $admin['default_bank_account_id'] : null;
        $clientId = $admin['default_api_client_id']   !== null ? (int) $admin['default_api_client_id']   : null;
        if ($bankId === null) {
            $bank = $this->db->fetchOne('SELECT id FROM bank_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
            $bankId = $bank !== null ? (int) $bank['id'] : null;
        }
        if ($clientId === null) {
            $cl = $this->db->fetchOne('SELECT id FROM api_clients WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
            $clientId = $cl !== null ? (int) $cl['id'] : null;
        }
        if ($bankId === null || $clientId === null) {
            $this->tg->sendMessage($chatId, '⛔ 銀行口座または API クライアントが未設定です。管理画面で追加してください。', null, $messageId);
            return;
        }

        // Confirmation card
        $bank = $this->db->fetchOne('SELECT bank_name, branch_name, account_number FROM bank_accounts WHERE id = ?', [$bankId]);
        $amountFmt = number_format($amount);
        $kanji     = $this->amountInKanji($amount);
        $bankLabel = htmlspecialchars(trim(($bank['bank_name'] ?? '') . ' ' . ($bank['branch_name'] ?? '') . ' ' . ($bank['account_number'] ?? '')), ENT_QUOTES, 'UTF-8');
        $kanaEsc   = htmlspecialchars($kana, ENT_QUOTES, 'UTF-8');

        $nonce = bin2hex(random_bytes(12));
        $intent = [
            'amount'           => $amount,
            'customer_kana'    => $kana,
            'bank_account_id'  => $bankId,
            'api_client_id'    => $clientId,
            'origin_message_id' => $messageId,
        ];

        $body = "🧾 <b>決済リンクを発行しますか？</b>\n\n"
              . "金額: <b>¥{$amountFmt}</b>  ({$kanji})\n"
              . "振込名義: <b>{$kanaEsc}</b>\n"
              . "振込先: {$bankLabel}\n"
              . "有効期限: 72時間 (既定)\n\n"
              . "<i>5分以内に確認してください</i>";

        $keyboard = [[
            ['text' => '✓ 発行',     'callback_data' => "confirm:{$nonce}"],
            ['text' => '✗ キャンセル', 'callback_data' => "cancel:{$nonce}"],
        ]];

        $resp = $this->tg->sendMessage($chatId, $body, $keyboard, $messageId);
        $cardId = (int) ($resp['result']['message_id'] ?? 0);
        if ($cardId === 0) {
            // Couldn't send — don't persist intent.
            return;
        }

        $this->db->insert('telegram_pending_intents', [
            'nonce'         => $nonce,
            'admin_user_id' => (int) $admin['id'],
            'chat_id'       => $chatId,
            'message_id'    => $cardId,
            'intent_json'   => json_encode($intent, JSON_UNESCAPED_UNICODE),
            'expires_at'    => date('Y-m-d H:i:s', time() + self::CONFIRM_NONCE_TTL_MIN * 60),
            'created_at'    => now_jst(),
        ]);
    }

    private function confirmIntent(string $callbackId, int $fromId, int $chatId, int $cardMessageId, string $nonce): void
    {
        $intent = $this->consumePendingIntent($nonce, $fromId);
        if ($intent === null) {
            $this->tg->answerCallbackQuery($callbackId, '確認カードが無効か、期限切れです', true);
            return;
        }

        $data = json_decode((string) $intent['intent_json'], true);
        if (!is_array($data)) {
            $this->tg->answerCallbackQuery($callbackId, '内部エラー', true);
            return;
        }

        $client = $this->db->fetchOne('SELECT * FROM api_clients WHERE id = ? LIMIT 1', [(int) $data['api_client_id']]);
        if ($client === null) {
            $this->tg->answerCallbackQuery($callbackId, 'API クライアントが見つかりません', true);
            return;
        }

        try {
            $result = $this->linkService->create([
                'amount'        => (int) $data['amount'],
                'customer_name' => (string) $data['customer_kana'],  // kanji not collected in chat; use kana as display name
                'customer_kana' => (string) $data['customer_kana'],
                'metadata'      => [
                    'source'            => 'telegram',
                    'telegram_user_id'  => $fromId,
                    'admin_user_id'     => (int) $intent['admin_user_id'],
                    'origin_message_id' => (int) ($data['origin_message_id'] ?? 0),
                ],
            ], $client);
        } catch (Throwable $e) {
            $this->tg->answerCallbackQuery($callbackId, '発行失敗: ' . $e->getMessage(), true);
            return;
        }

        // Stamp audit columns on the row PaymentLinkService just inserted.
        $this->db->update(
            'payment_links',
            [
                'source'                         => 'telegram',
                'issued_by_admin_id'             => (int) $intent['admin_user_id'],
                'issued_by_telegram_user_id'     => $fromId,
                'issued_by_telegram_message_id'  => (int) ($data['origin_message_id'] ?? 0),
            ],
            ['id' => $result['id']]
        );

        $url      = (string) $result['payment_url'];
        $urlSafe  = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $kanaEsc  = htmlspecialchars((string) $data['customer_kana'], ENT_QUOTES, 'UTF-8');
        $ref      = htmlspecialchars((string) $result['reference_number'], ENT_QUOTES, 'UTF-8');
        $amountFmt = number_format((int) $data['amount']);

        $this->tg->editMessageText(
            $chatId,
            $cardMessageId,
            "✅ <b>発行済</b>\n\n"
          . "金額: ¥{$amountFmt}\n"
          . "振込名義: {$kanaEsc}\n"
          . "参照番号: <code>{$ref}</code>\n"
          . "URL: <a href=\"{$urlSafe}\">{$urlSafe}</a>"
        );
        $this->tg->answerCallbackQuery($callbackId, '発行しました', false);
    }

    private function cancelIntent(string $callbackId, int $fromId, int $chatId, int $cardMessageId, string $nonce): void
    {
        $intent = $this->consumePendingIntent($nonce, $fromId);
        if ($intent === null) {
            $this->tg->answerCallbackQuery($callbackId, '確認カードが既に無効です', true);
            return;
        }
        $this->tg->editMessageText($chatId, $cardMessageId, '✗ <i>キャンセルしました</i>');
        $this->tg->answerCallbackQuery($callbackId, 'キャンセルしました', false);
    }

    // ── /list ──────────────────────────────────────────────────────────────

    private function handleList(int $chatId, int $fromId, int $messageId): void
    {
        $admin = $this->resolveAdmin($fromId);
        if ($admin === null) {
            $this->tg->sendMessage($chatId, '⛔ 紐付けがありません。', null, $messageId);
            return;
        }

        $rows = $this->db->fetchAll(
            "SELECT id, amount, customer_kana, reference_number, status, created_at, expires_at, token
             FROM payment_links
             WHERE issued_by_admin_id = ?
             ORDER BY created_at DESC LIMIT 5",
            [(int) $admin['id']]
        );
        if (count($rows) === 0) {
            $this->tg->sendMessage($chatId, 'あなたが発行したリンクはまだありません。', null, $messageId);
            return;
        }

        $payBase = rtrim((string) config('pay_page.url'), '/');
        $lines   = ['📄 <b>直近の発行リンク</b>'];
        foreach ($rows as $r) {
            $fmt   = number_format((int) $r['amount']);
            $kana  = htmlspecialchars((string) ($r['customer_kana'] ?? '-'), ENT_QUOTES, 'UTF-8');
            $url   = $payBase . '/p/' . (string) $r['token'];
            $stat  = htmlspecialchars((string) $r['status'], ENT_QUOTES, 'UTF-8');
            $lines[] = "— ¥{$fmt} / {$kana} / {$stat}\n<a href=\"{$url}\">{$url}</a>";
        }
        $this->tg->sendMessage($chatId, implode("\n\n", $lines), null, $messageId);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function resolveAdmin(int $telegramUserId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, username, name, per_link_cap, daily_amount_cap,
                    default_bank_account_id, default_api_client_id
             FROM admin_users
             WHERE telegram_user_id = ? AND telegram_enabled = 1 AND is_active = 1
             LIMIT 1',
            [$telegramUserId]
        );
        return is_array($row) ? $row : null;
    }

    private function rollingDailyAmount(int $adminUserId): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - 86_400);
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM payment_links
             WHERE issued_by_admin_id = ? AND created_at >= ?",
            [$adminUserId, $cutoff]
        );
        return (int) ($row['total'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function consumePendingIntent(string $nonce, int $telegramUserId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT p.*, u.telegram_user_id
             FROM telegram_pending_intents p
             JOIN admin_users u ON u.id = p.admin_user_id
             WHERE p.nonce = ? LIMIT 1',
            [$nonce]
        );
        if ($row === null) {
            return null;
        }
        if ($row['consumed_at'] !== null) {
            return null;
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        if ((int) $row['telegram_user_id'] !== $telegramUserId) {
            return null; // Only the operator who staged it may confirm.
        }

        $this->db->update(
            'telegram_pending_intents',
            ['consumed_at' => now_jst()],
            ['nonce' => $nonce]
        );
        return $row;
    }

    private function amountInKanji(int $amount): string
    {
        // Simple "万" splitter, good enough for operator sanity check.
        if ($amount < 10_000) {
            return $amount . '円';
        }
        $man   = intdiv($amount, 10_000);
        $below = $amount % 10_000;
        if ($below === 0) {
            return "{$man}万円";
        }
        return "{$man}万" . number_format($below) . '円';
    }
}
