<?php

declare(strict_types=1);

/**
 * B-Pay API — Single Entry Point + Router
 *
 * Routes:
 *   POST   /api/v1/payments                  — Create payment link      [API key]
 *   GET    /api/v1/payments/{id}             — Get payment link         [API key]
 *   POST   /api/v1/payments/{id}/cancel      — Cancel payment link      [API key]
 *   GET    /api/v1/payments                  — List payments            [API key]
 *   GET    /p/{token}                        — Payment page (HTML)      [public]
 *   GET    /api/v1/pay/{token}/status        — Poll payment status      [public, rate-limited]
 *   POST   /api/internal/scraper/deposits    — Receive deposits         [HMAC]
 *   GET    /api/internal/scraper/tasks       — Get scraper tasks        [HMAC]
 *   GET    /api/v1/health                    — Health check             [public]
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────────

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env from the project root (one level up from public/)
load_env(dirname(__DIR__) . '/.env');

// Set JST timezone globally
date_default_timezone_set('Asia/Tokyo');

// ── Security headers (apply to every response) ────────────────────────────────
// HSTS tells browsers to always use TLS for this host for a year.
// frame-ancestors blocks clickjacking on the payment + confirmed pages.
// no-referrer keeps the /p/{token} URL (a capability) out of third-party
// Referer headers when customers click outbound links on the page.
// CSP is conservative: self-hosted scripts/styles/images only + the one
// external font CDN (fonts.googleapis.com, rsms.me) the payment page uses.
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' 'unsafe-inline'; "
    . "style-src 'self' 'unsafe-inline' https://rsms.me https://fonts.googleapis.com https://cdn.jsdelivr.net; "
    . "font-src 'self' https://rsms.me https://fonts.gstatic.com https://cdn.jsdelivr.net data:; "
    . "img-src 'self' data:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'none'"
);

// ── CORS ──────────────────────────────────────────────────────────────────────
// Restrict to first-party origins.  The public status-poll and payment-page
// routes are same-origin only (served from b-pay.ink itself), and the admin
// dashboard calls api.*.b-pay.ink routes from admin.b-pay.ink.  Third-party
// cross-origin access to authenticated API routes is not a supported flow,
// so reflecting the wildcard origin would widen the attack surface for no
// legitimate benefit.
$allowedOrigins = [
    'https://b-pay.ink',
    'https://admin.b-pay.ink',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-BCashPay-Scraper-Signature');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Request parsing ───────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';

// Strip query string
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
// Normalize trailing slash (except root)
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

// ── Router ────────────────────────────────────────────────────────────────────

try {
    dispatch($method, $path);
} catch (\Throwable $e) {
    $isDebug = config('app.debug') === true || config('app.debug') === 'true';
    json_error(
        $isDebug ? $e->getMessage() : 'Internal server error',
        500,
        $isDebug ? ['trace' => $e->getTraceAsString()] : null
    );
}

// ── Dispatcher ────────────────────────────────────────────────────────────────

/**
 * Match method + path to a handler and call it.
 * All json_response / json_error calls terminate with exit — no return needed.
 */
function dispatch(string $method, string $path): void
{
    // ── Static assets for the customer payment page ───────────────────────────
    // Serves /assets/* from /pay/assets/* — used by payment.html / confirmed.html / expired.html
    if ($method === 'GET' && str_starts_with($path, '/assets/')) {
        serveStaticAsset($path);
        return;
    }

    // ── Health check ──────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === '/api/v1/health') {
        handleHealth();
        return;
    }

    // ── Payment page (HTML) — public ──────────────────────────────────────────
    if ($method === 'GET' && preg_match('#^/p/([A-Za-z0-9]{32})$#', $path, $m)) {
        $ctrl = new \BCashPay\Controllers\PaymentPageController();
        $ctrl->show($m[1]);
        return;
    }

    // ── Payment page — customer amount submit (awaiting_input + template) ─────
    if ($method === 'POST' && preg_match('#^/p/([A-Za-z0-9]{32})/submit$#', $path, $m)) {
        $ctrl = new \BCashPay\Controllers\PaymentPageController();
        $ctrl->submit($m[1]);
        return;
    }

    // ── Public status poll — rate-limited ─────────────────────────────────────
    if ($method === 'GET' && preg_match('#^/api/v1/pay/([A-Za-z0-9]{32})/status$#', $path, $m)) {
        rateLimitPublic();
        $ctrl = new \BCashPay\Controllers\PaymentPageController();
        $ctrl->pollStatus($m[1]);
        return;
    }

    // ── Telegram webhook — secret-token auth (NOT HMAC) ───────────────────────
    // Telegram cannot compute our HMAC, so this route ships with its own
    // `X-Telegram-Bot-Api-Secret-Token` verification inside the controller.
    // It must be matched BEFORE the generic /api/internal/ block below.
    if ($method === 'POST' && $path === '/api/internal/telegram/webhook') {
        (new \BCashPay\Controllers\TelegramWebhookController())->handle();
        return;
    }

    // ── Internal scraper routes — HMAC auth ───────────────────────────────────
    if (str_starts_with($path, '/api/internal/')) {
        \BCashPay\Middleware\HmacAuth::authenticate();

        $ctrl = new \BCashPay\Controllers\ScraperWebhookController();

        if ($method === 'POST' && $path === '/api/internal/scraper/deposits') {
            $ctrl->receiveDeposit();
            return;
        }
        if ($method === 'GET' && $path === '/api/internal/scraper/tasks') {
            $ctrl->getTasks();
            return;
        }

        json_error('Internal route not found', 404);
        return;
    }

    // ── API v1 routes — API key auth ──────────────────────────────────────────
    if (str_starts_with($path, '/api/v1/')) {
        $client = \BCashPay\Middleware\ApiKeyAuth::authenticate();
        rateLimitApiClient((int) $client['id']);

        $ctrl = new \BCashPay\Controllers\PaymentLinkController();

        // POST /api/v1/payments — create
        if ($method === 'POST' && $path === '/api/v1/payments') {
            $ctrl->create($client);
            return;
        }

        // GET /api/v1/payments — list
        if ($method === 'GET' && $path === '/api/v1/payments') {
            $ctrl->list($client);
            return;
        }

        // GET /api/v1/payments/{id}
        if ($method === 'GET' && preg_match('#^/api/v1/payments/(bp_[A-Z0-9]+)$#', $path, $m)) {
            $ctrl->get($m[1], $client);
            return;
        }

        // POST /api/v1/payments/{id}/cancel
        if ($method === 'POST' && preg_match('#^/api/v1/payments/(bp_[A-Z0-9]+)/cancel$#', $path, $m)) {
            $ctrl->cancel($m[1], $client);
            return;
        }

        json_error('API route not found', 404);
        return;
    }

    // ── No match ──────────────────────────────────────────────────────────────
    json_error('Not found', 404);
}

// ── Health check handler ──────────────────────────────────────────────────────

function handleHealth(): never
{
    $dbOk = false;
    try {
        $db   = \BCashPay\Database::getInstance();
        $row  = $db->fetchOne('SELECT 1 AS ok');
        $dbOk = ($row['ok'] ?? 0) == 1;
    } catch (\Throwable) {
        $dbOk = false;
    }

    $status = $dbOk ? 200 : 503;
    json_response([
        'success'  => $dbOk,
        'status'   => $dbOk ? 'ok' : 'degraded',
        'database' => $dbOk ? 'connected' : 'unreachable',
        'time'     => date('Y-m-d\TH:i:sP'),
        'version'  => '1.0.0',
    ], $status);
}

// ── Rate limiting (file-based, in-process) ────────────────────────────────────

/**
 * Simple rate limiter for authenticated API clients.
 * Uses a per-client counter stored in /tmp.
 * For production, replace with Redis-backed counter.
 */
function rateLimitApiClient(int $clientId): void
{
    $limit  = (int) config('rate_limit.api_limit', 60);
    $window = (int) config('rate_limit.window_minutes', 1) * 60;
    applyRateLimit("api_client_{$clientId}", $limit, $window, 429);
}

/**
 * Stricter rate limiter for the public status poll endpoint.
 * Keyed by client IP address.
 */
function rateLimitPublic(): void
{
    $ip     = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
    // Take only the first IP in case of X-Forwarded-For chain
    $ip     = trim(explode(',', $ip)[0]);
    $limit  = (int) config('rate_limit.poll_limit', 30);
    $window = (int) config('rate_limit.poll_window_minutes', 1) * 60;
    applyRateLimit('poll_' . md5($ip), $limit, $window, 429);
}

/**
 * Core rate-limit implementation backed by /tmp files.
 * Each bucket is a JSON file with {count, window_start}.
 */
function applyRateLimit(string $key, int $limit, int $windowSeconds, int $errorStatus): void
{
    $file    = sys_get_temp_dir() . '/bcashpay_rl_' . md5($key) . '.json';
    $now     = time();
    $bucket  = ['count' => 0, 'window_start' => $now];

    if (is_file($file)) {
        $raw    = @file_get_contents($file);
        $parsed = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($parsed) && ($now - (int) $parsed['window_start']) < $windowSeconds) {
            $bucket = $parsed;
        }
    }

    $bucket['count']++;

    if ($bucket['count'] > $limit) {
        $retryAfter = $windowSeconds - ($now - (int) $bucket['window_start']);
        header('Retry-After: ' . max(1, $retryAfter));
        json_error('Rate limit exceeded', $errorStatus);
    }

    @file_put_contents($file, json_encode($bucket), LOCK_EX);
}

/**
 * Serve a static file from the /pay/assets/ directory.
 * Only serves files inside the assets dir, no path traversal.
 */
function serveStaticAsset(string $path): void
{
    $requested = preg_replace('#^/assets/#', '', $path);
    $requested = str_replace(['..', '\\'], '', (string) $requested);

    $base = dirname(__DIR__, 2) . '/pay/assets';
    $full = $base . '/' . $requested;

    if (!is_file($full)) {
        http_response_code(404);
        exit('Not found');
    }

    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'css'           => 'text/css; charset=utf-8',
        'js'            => 'application/javascript; charset=utf-8',
        'svg'           => 'image/svg+xml',
        'png'           => 'image/png',
        'jpg', 'jpeg'   => 'image/jpeg',
        'webp'          => 'image/webp',
        'woff', 'woff2' => 'font/' . $ext,
        default         => 'application/octet-stream',
    };

    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=300');
    readfile($full);
    exit;
}
