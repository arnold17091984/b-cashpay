<?php

declare(strict_types=1);

/**
 * Global helper functions for B-CashPay API.
 * Loaded automatically via Composer's "files" autoload.
 */

if (!function_exists('json_response')) {
    /**
     * Emit a JSON response and terminate execution.
     *
     * @param array<mixed> $data
     */
    function json_response(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('json_error')) {
    /**
     * Emit a standardised JSON error response and terminate execution.
     *
     * @param array<string, mixed>|null $details
     */
    function json_error(string $message, int $status = 400, ?array $details = null): never
    {
        $body = ['success' => false, 'message' => $message];
        if ($details !== null) {
            $body['errors'] = $details;
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('env')) {
    /**
     * Read a value from $_ENV or getenv(), falling back to $default.
     *
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('generate_ulid')) {
    /**
     * Generate a ULID (Universally Unique Lexicographically Sortable Identifier).
     *
     * Format: 26 character base32 string
     *   - 10 chars: 48-bit millisecond timestamp
     *   - 16 chars: 80-bit random component
     *
     * @throws RuntimeException on random_bytes failure
     */
    function generate_ulid(): string
    {
        // Crockford's base32 alphabet (no I, L, O, U to avoid ambiguity)
        $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        // 48-bit timestamp in milliseconds
        $timeMs = (int) (microtime(true) * 1000);

        $ulid = '';

        // Encode 10 characters of timestamp (each char = 5 bits, 10*5 = 50 bits)
        $time = $timeMs;
        for ($i = 9; $i >= 0; $i--) {
            $ulid[$i] = $encoding[$time & 0x1F];
            $time >>= 5;
        }

        // Encode 16 characters of randomness (80 bits)
        $randomBytes = random_bytes(10); // 10 bytes = 80 bits
        $random = 0;
        for ($i = 0; $i < 10; $i++) {
            $random = ($random << 8) | ord($randomBytes[$i]);
        }

        // We have 80 bits to fill 16 * 5 = 80 bit positions
        $temp = '';
        for ($i = 0; $i < 16; $i++) {
            $temp = $encoding[$random & 0x1F] . $temp;
            $random >>= 5;
        }
        $ulid .= $temp;

        return $ulid;
    }
}

if (!function_exists('load_env')) {
    /**
     * Parse a .env file and populate $_ENV and putenv().
     * Simple parser: supports KEY=VALUE and KEY="VALUE" (no multiline).
     */
    function load_env(string $filePath): void
    {
        if (!is_file($filePath)) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            // Skip comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"')
                    || ($value[0] === "'" && $value[-1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}

if (!function_exists('config')) {
    /**
     * Retrieve a nested config value using dot-notation key.
     *
     * Example: config('database.host')
     *
     * @param mixed $default
     * @return mixed
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = null;

        if ($cache === null) {
            $configPath = dirname(__DIR__) . '/config/bcashpay.php';
            $cache = is_file($configPath) ? (require $configPath) : [];
        }

        $parts   = explode('.', $key);
        $current = $cache;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }
}

if (!function_exists('now_jst')) {
    /**
     * Return the current datetime as a JST (Asia/Tokyo) formatted string.
     * Used for timestamp fields stored in MySQL.
     */
    function now_jst(string $format = 'Y-m-d H:i:s'): string
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        return $dt->format($format);
    }
}

if (!function_exists('html_escape')) {
    /**
     * HTML-escape a string for safe output in templates.
     */
    function html_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('request_body')) {
    /**
     * Decode and return the JSON request body as an associative array.
     *
     * @return array<string, mixed>
     */
    function request_body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
