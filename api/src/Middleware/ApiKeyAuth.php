<?php

declare(strict_types=1);

namespace BCashPay\Middleware;

use BCashPay\Database;

/**
 * API key authentication middleware.
 *
 * Validates the Bearer token from the Authorization header against the
 * api_clients table. Returns client info on success, or calls json_error()
 * to terminate with 401 on failure.
 *
 * Usage:
 *   $client = ApiKeyAuth::authenticate();
 *   // $client is an associative array from api_clients row
 */
class ApiKeyAuth
{
    /**
     * Extract and validate the Bearer token.
     *
     * @return array<string, mixed> api_clients row
     */
    public static function authenticate(): array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($authHeader === '') {
            json_error('Authorization header is required', 401);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            json_error('Authorization header must use Bearer scheme', 401);
        }

        $apiKey = trim(substr($authHeader, 7));

        if ($apiKey === '') {
            json_error('API key must not be empty', 401);
        }

        $db     = Database::getInstance();
        $client = $db->fetchOne(
            'SELECT id, name, api_key, webhook_secret, callback_url, is_active
             FROM api_clients
             WHERE api_key = ?
             LIMIT 1',
            [$apiKey]
        );

        if ($client === null) {
            json_error('Invalid API key', 401);
        }

        if (!(bool) $client['is_active']) {
            json_error('API key is disabled', 401);
        }

        return $client;
    }
}
