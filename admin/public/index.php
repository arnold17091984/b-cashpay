<?php

declare(strict_types=1);

// ── Bootstrap ────────────────────────────────────────────────────────────────

$adminRoot = dirname(__DIR__);
$apiRoot   = dirname($adminRoot) . '/api';

// Load Composer autoloader from admin/vendor (which maps BCashPay\ → api/src/)
require $adminRoot . '/vendor/autoload.php';

// Use JST consistently — MySQL connections init with time_zone='+09:00', so
// PHP's date()/time() output must be in the same zone to avoid a 9-hour skew
// on TIMESTAMP columns (e.g. expires_at on bind tokens and pending intents).
date_default_timezone_set('Asia/Tokyo');

// Load .env files (admin first, then api fallback)
load_env($adminRoot . '/.env');
load_env($apiRoot . '/.env');

// Start session
$sessionName = 'bcashpay_admin_session';
session_name($sessionName);
session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

// ── Config override for SQLite path ─────────────────────────────────────────

// Ensure SQLite path resolves relative to api dir when given as relative
$sqlitePath = env('DB_SQLITE_PATH', './database/bcashpay.sqlite');
if ($sqlitePath !== '' && !str_starts_with($sqlitePath, '/')) {
    putenv('DB_SQLITE_PATH=' . $apiRoot . '/' . ltrim($sqlitePath, './'));
    $_ENV['DB_SQLITE_PATH'] = $apiRoot . '/' . ltrim($sqlitePath, './');
}

// ── Setup ────────────────────────────────────────────────────────────────────

use BCashPay\Admin\AdminRouter;
use BCashPay\Admin\Auth;
use BCashPay\Admin\View;
use BCashPay\Admin\Controllers\AuthController;
use BCashPay\Admin\Controllers\DashboardController;
use BCashPay\Admin\Controllers\PaymentLinkController;
use BCashPay\Admin\Controllers\BankAccountController;
use BCashPay\Admin\Controllers\DepositController;
use BCashPay\Admin\Controllers\ApiClientController;
use BCashPay\Admin\Controllers\ScraperController;
use BCashPay\Admin\Controllers\TelegramSettingsController;
use BCashPay\Database;

View::setViewsDir($adminRoot . '/views');

// Lazy-initialize DB to avoid connection errors on login page render
$db   = Database::getInstance();
$auth = new Auth($db);

// ── Routing ──────────────────────────────────────────────────────────────────

$router = new AdminRouter();

// Auth
$authCtrl = new AuthController($auth, $db);
$router->get('/login',  [$authCtrl, 'showLogin']);
$router->post('/login', [$authCtrl, 'login']);
$router->get('/logout', [$authCtrl, 'logout']);

// Dashboard
$dashCtrl = new DashboardController($auth, $db);
$router->get('/', [$dashCtrl, 'index']);

// Payment links
$payCtrl = new PaymentLinkController($auth, $db);
$router->get('/payments',                      [$payCtrl, 'index']);
$router->get('/payments/new',                  [$payCtrl, 'create']);
$router->post('/payments',                     [$payCtrl, 'store']);
$router->get('/payments/{id}',                 [$payCtrl, 'show']);
$router->post('/payments/{id}/cancel',         [$payCtrl, 'cancel']);
$router->post('/payments/{id}/match',          [$payCtrl, 'manualMatch']);

// Bank accounts
$bankCtrl = new BankAccountController($auth, $db);
$router->get('/banks',             [$bankCtrl, 'index']);
$router->get('/banks/new',         [$bankCtrl, 'create']);
$router->post('/banks',            [$bankCtrl, 'store']);
$router->get('/banks/{id}/edit',   fn(string $id) => $bankCtrl->edit((int) $id));
$router->post('/banks/{id}',       fn(string $id) => $bankCtrl->update((int) $id));
$router->post('/banks/{id}/delete', fn(string $id) => $bankCtrl->delete((int) $id));

// Deposits
$depositCtrl = new DepositController($auth, $db);
$router->get('/deposits',              [$depositCtrl, 'index']);
$router->post('/deposits/{id}/match',  fn(string $id) => $depositCtrl->match((int) $id));

// API clients
$clientCtrl = new ApiClientController($auth, $db);
$router->get('/clients',               [$clientCtrl, 'index']);
$router->get('/clients/new',           [$clientCtrl, 'create']);
$router->post('/clients',              [$clientCtrl, 'store']);
$router->post('/clients/{id}/rotate',  fn(string $id) => $clientCtrl->rotateKey((int) $id));
$router->post('/clients/{id}/delete',  fn(string $id) => $clientCtrl->delete((int) $id));

// Scraper
$scraperCtrl = new ScraperController($auth, $db);
$router->get('/scraper',                   [$scraperCtrl, 'index']);
$router->post('/scraper/{id}/run-now',     fn(string $id) => $scraperCtrl->runNow((int) $id));

// Telegram settings (chat-issuance feature)
$tgSettingsCtrl = new TelegramSettingsController($auth, $db);
$router->get('/settings/telegram',          [$tgSettingsCtrl, 'index']);
$router->post('/settings/telegram/toggle',  [$tgSettingsCtrl, 'toggle']);
$router->post('/settings/telegram/bind',    [$tgSettingsCtrl, 'issueBindToken']);
$router->post('/settings/telegram/unbind',  [$tgSettingsCtrl, 'unbind']);

// ── Dispatch ─────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Strip trailing slash (except root)
if ($path !== '/' && str_ends_with($path, '/')) {
    $path = rtrim($path, '/');
}

$matched = $router->dispatch($method, $path);

if (!$matched) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>404</title></head><body>';
    echo '<h1>404 — ページが見つかりません</h1>';
    echo '<a href="/">ダッシュボードへ戻る</a>';
    echo '</body></html>';
}
