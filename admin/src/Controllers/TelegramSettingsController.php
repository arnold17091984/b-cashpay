<?php

declare(strict_types=1);

namespace BCashPay\Admin\Controllers;

use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Database;

/**
 * Admin-side configuration for the Telegram chat-issuance feature.
 *
 *   GET  /settings/telegram          — overview (bot on/off, operators, their caps, issue-token button)
 *   POST /settings/telegram/toggle   — flip the `telegram_bot_enabled` kill switch
 *   POST /settings/telegram/bind     — issue a one-time bind token for the given admin_user
 *   POST /settings/telegram/unbind   — clear Telegram binding for an admin_user
 */
class TelegramSettingsController
{
    private const BIND_TOKEN_TTL_MIN = 15;

    public function __construct(
        private readonly Auth $auth,
        private readonly Database $db,
    ) {
    }

    public function index(): void
    {
        $this->auth->requireAuth();

        $botEnabled = $this->botEnabled();
        $admins = $this->db->fetchAll(
            'SELECT id, username, name, role, telegram_user_id, telegram_enabled,
                    per_link_cap, daily_amount_cap
             FROM admin_users
             WHERE is_active = 1
             ORDER BY id ASC'
        );

        // Include the caller's freshly-issued token once (passed via session flash)
        $freshToken = $_SESSION['telegram_fresh_token'] ?? null;
        unset($_SESSION['telegram_fresh_token']);

        // Pull any active (unconsumed, unexpired) tokens so the admin can re-show them
        $activeTokens = $this->db->fetchAll(
            'SELECT t.token, t.admin_user_id, t.expires_at, u.username
             FROM telegram_bind_tokens t
             JOIN admin_users u ON u.id = t.admin_user_id
             WHERE t.consumed_at IS NULL AND t.expires_at > ?
             ORDER BY t.expires_at DESC',
            [date('Y-m-d H:i:s')]
        );

        View::render('settings/telegram', [
            'title'        => 'Telegram設定',
            'botEnabled'   => $botEnabled,
            'admins'       => $admins,
            'freshToken'   => $freshToken,
            'activeTokens' => $activeTokens,
            'csrf'         => $this->csrfToken(),
            'currentUser'  => $this->auth->user(),
        ]);
    }

    public function toggle(): void
    {
        $this->auth->requireAuth();
        $this->verifyCsrf();

        $desired = isset($_POST['enable']) && $_POST['enable'] === '1' ? '1' : '0';
        $this->db->query(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            ['telegram_bot_enabled', $desired]
        );
        $_SESSION['flash'] = $desired === '1' ? 'Telegram bot を有効にしました' : 'Telegram bot を無効にしました';
        header('Location: /settings/telegram');
    }

    public function issueBindToken(): void
    {
        $this->auth->requireAuth();
        $this->verifyCsrf();

        $adminId = (int) ($_POST['admin_user_id'] ?? 0);
        if ($adminId <= 0) {
            $_SESSION['flash_error'] = '対象の管理者を指定してください';
            header('Location: /settings/telegram');
            return;
        }

        $target = $this->db->fetchOne('SELECT id, username FROM admin_users WHERE id = ? LIMIT 1', [$adminId]);
        if ($target === null) {
            $_SESSION['flash_error'] = '管理者が見つかりません';
            header('Location: /settings/telegram');
            return;
        }

        // Invalidate any prior unconsumed tokens for the same admin (only one
        // active binding token per admin at a time — reduces accidental leaks).
        $this->db->query(
            'UPDATE telegram_bind_tokens
             SET consumed_at = ?
             WHERE admin_user_id = ? AND consumed_at IS NULL',
            [date('Y-m-d H:i:s'), $adminId]
        );

        $token = bin2hex(random_bytes(16)); // 32 hex chars, safe for /bind command
        $this->db->insert('telegram_bind_tokens', [
            'token'         => $token,
            'admin_user_id' => $adminId,
            'expires_at'    => date('Y-m-d H:i:s', time() + self::BIND_TOKEN_TTL_MIN * 60),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $_SESSION['telegram_fresh_token'] = [
            'token'         => $token,
            'admin_user_id' => $adminId,
            'username'      => $target['username'],
            'expires_at'    => date('Y-m-d H:i:s', time() + self::BIND_TOKEN_TTL_MIN * 60),
        ];
        header('Location: /settings/telegram');
    }

    public function unbind(): void
    {
        $this->auth->requireAuth();
        $this->verifyCsrf();

        $adminId = (int) ($_POST['admin_user_id'] ?? 0);
        if ($adminId <= 0) {
            header('Location: /settings/telegram');
            return;
        }
        $this->db->update(
            'admin_users',
            ['telegram_user_id' => null, 'telegram_enabled' => 0],
            ['id' => $adminId]
        );
        $_SESSION['flash'] = '紐付けを解除しました';
        header('Location: /settings/telegram');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function botEnabled(): bool
    {
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1',
            ['telegram_bot_enabled']
        );
        return $row !== null && (string) $row['setting_value'] === '1';
    }

    private function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(16));
        }
        return (string) $_SESSION['_csrf'];
    }

    private function verifyCsrf(): void
    {
        $provided = (string) ($_POST['_csrf'] ?? '');
        $expected = (string) ($_SESSION['_csrf'] ?? '');
        if ($expected === '' || !hash_equals($expected, $provided)) {
            http_response_code(403);
            echo 'CSRF verification failed';
            exit;
        }
    }
}
