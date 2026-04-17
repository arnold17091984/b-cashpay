<?php

declare(strict_types=1);

/**
 * B-Pay application configuration.
 *
 * Reads from environment variables (loaded via .env or system env).
 * All values have safe defaults — override via .env for production.
 */
return [
    'app' => [
        'env'    => env('APP_ENV', 'production'),
        'debug'  => env('APP_DEBUG', 'false') === 'true',
        'url'    => env('APP_URL', 'https://api.bcashpay.com'),
        'secret' => env('APP_SECRET', ''),
    ],

    'database' => [
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => (int) env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'bcashpay'),
        'username' => env('DB_USERNAME', 'bcashpay'),
        'password' => env('DB_PASSWORD', ''),
        'charset'  => 'utf8mb4',
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+09:00'",
        ],
    ],

    'scraper' => [
        // HMAC-SHA256 secret shared with the Python scraper
        'secret' => env('BANK_SCRAPER_TOKEN', ''),
    ],

    'pay_page' => [
        'url'    => env('PAY_PAGE_URL', 'https://pay.bcashpay.com'),
        'secret' => env('PAY_PAGE_SECRET', ''),
    ],

    'payment' => [
        // Default payment link lifetime in hours
        'expiry_hours'            => (int) env('PAYMENT_LINK_EXPIRY_HOURS', '72'),
        'reference_number_length' => (int) env('REFERENCE_NUMBER_LENGTH', '7'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', ''),
        'chat_id'   => env('TELEGRAM_CHAT_ID', ''),
    ],

    'webhook' => [
        'timeout_seconds'      => (int) env('WEBHOOK_TIMEOUT_SECONDS', '30'),
        'max_retries'          => (int) env('WEBHOOK_MAX_RETRIES', '3'),
        'retry_delay_seconds'  => (int) env('WEBHOOK_RETRY_DELAY_SECONDS', '60'),
    ],

    'rate_limit' => [
        'api_limit'          => (int) env('API_RATE_LIMIT', '60'),
        'window_minutes'     => (int) env('API_RATE_LIMIT_WINDOW_MINUTES', '1'),
        // Separate, stricter limit for public status polling
        'poll_limit'         => 30,
        'poll_window_minutes' => 1,
    ],

    'logging' => [
        'level' => env('LOG_LEVEL', 'info'),
        'file'  => env('LOG_FILE', '/var/log/bcashpay/api.log'),
    ],
];
