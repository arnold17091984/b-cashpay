<?php

declare(strict_types=1);

namespace BCashPay\Admin;

use BCashPay\Database;

/**
 * Session-based authentication for the admin panel.
 */
class Auth
{
    private const SESSION_KEY = 'admin_user';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Attempt to log in with username and password.
     * Returns true on success, false on failure.
     */
    public function login(string $username, string $password): bool
    {
        $user = $this->db->fetchOne(
            'SELECT * FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1',
            [$username]
        );

        if ($user === null) {
            return false;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Update last login timestamp
        $now = date('Y-m-d H:i:s');
        $this->db->update('admin_users', ['last_login_at' => $now, 'updated_at' => $now], ['id' => $user['id']]);

        // Store safe user data in session (never store password_hash)
        $_SESSION[self::SESSION_KEY] = [
            'id'       => $user['id'],
            'username' => $user['username'],
            'name'     => $user['name'],
            'email'    => $user['email'],
            'role'     => $user['role'],
        ];

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        return true;
    }

    /**
     * Return true if a user is currently authenticated.
     */
    public function check(): bool
    {
        return isset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * Return the current authenticated user array, or null.
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Require authentication — redirect to /login if not authenticated.
     */
    public function requireAuth(): void
    {
        if (!$this->check()) {
            header('Location: /login');
            exit;
        }
    }

    /**
     * Require admin role — redirect to dashboard if insufficient role.
     */
    public function requireAdmin(): void
    {
        $this->requireAuth();
        $user = $this->user();
        if ($user === null || $user['role'] !== 'admin') {
            $_SESSION['flash_error'] = '管理者権限が必要です。';
            header('Location: /');
            exit;
        }
    }

    /**
     * Destroy the session and log the user out.
     */
    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Generate a CSRF token, storing it in the session.
     */
    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a submitted CSRF token.
     * Terminates with 403 if invalid.
     */
    public static function validateCsrf(): void
    {
        $submitted = $_POST['_csrf'] ?? '';
        $expected  = $_SESSION['csrf_token'] ?? '';

        if ($submitted === '' || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>Invalid CSRF token.</p>';
            exit;
        }
    }
}
