<?php

declare(strict_types=1);

namespace BCashPay\Services;

use BCashPay\Database;

/**
 * Sends signed webhook callbacks to API client callback URLs.
 *
 * Each attempt is logged to the webhook_logs table regardless of outcome.
 * Failures are caught and returned as false — they do not propagate to callers.
 *
 * Signature scheme:
 *   X-BCashPay-Signature: sha256=HMAC-SHA256(webhook_secret, raw_json_body)
 *
 * The receiving end should verify this signature before trusting the payload.
 */
class WebhookSender
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * POST a signed JSON payload to the given URL and log the attempt.
     *
     * @param array<string, mixed> $payload
     * @param string $callbackUrl  Destination URL
     * @param string $webhookSecret  Client's webhook_secret for HMAC signing
     * @param string $paymentLinkId  For audit log FK
     * @param int    $attempt  Current attempt number (1-based)
     * @return bool True when the server returned a 2xx status
     */
    public function send(
        array $payload,
        string $callbackUrl,
        string $webhookSecret,
        string $paymentLinkId,
        int $attempt = 1
    ): bool {
        $body      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $webhookSecret);
        $timeout   = (int) config('webhook.timeout_seconds', 30);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($body),
                    'X-BCashPay-Signature: ' . $signature,
                    'User-Agent: BCashPay-Webhook/1.0',
                ]) . "\r\n",
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseCode = null;
        $responseBody = null;
        $deliveredAt  = null;

        try {
            $responseBody = @file_get_contents($callbackUrl, false, $context);

            if (isset($http_response_header) && is_array($http_response_header)) {
                // Parse status line: "HTTP/1.1 200 OK"
                preg_match('/HTTP\/\d\.\d\s+(\d{3})/', $http_response_header[0] ?? '', $m);
                $responseCode = isset($m[1]) ? (int) $m[1] : null;
            }

            $success = $responseCode !== null && $responseCode >= 200 && $responseCode < 300;
            if ($success) {
                $deliveredAt = now_jst();
            }
        } catch (\Throwable $e) {
            $success      = false;
            $responseBody = 'Exception: ' . $e->getMessage();
        }

        // Log the attempt — best effort, never throw
        try {
            $this->db->insert('webhook_logs', [
                'payment_link_id' => $paymentLinkId,
                'url'             => $callbackUrl,
                'request_body'    => $body,
                'response_code'   => $responseCode,
                'response_body'   => $responseBody !== false ? substr((string) $responseBody, 0, 4096) : null,
                'attempt'         => $attempt,
                'delivered_at'    => $deliveredAt,
                'created_at'      => now_jst(),
            ]);
        } catch (\Throwable) {
            // Log failure must never block the payment flow
        }

        return $success ?? false;
    }

    /**
     * Generate the HMAC-SHA256 signature for a payload body.
     * Exposed separately for testing.
     */
    public function generateSignature(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }
}
