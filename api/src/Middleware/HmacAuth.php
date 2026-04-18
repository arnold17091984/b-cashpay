<?php

declare(strict_types=1);

namespace BCashPay\Middleware;

/**
 * HMAC authentication for internal scraper endpoints.
 *
 * The Python scraper includes an HMAC-SHA256 signature in the
 * X-BCashPay-Scraper-Signature header. The signature is computed as:
 *
 *   HMAC-SHA256(secret, raw_request_body)
 *
 * where secret = BANK_SCRAPER_TOKEN from config.
 *
 * If the header is absent or the signature does not match, the request
 * is rejected with 401 JSON.
 *
 * For GET requests (no body), the signature is computed over an empty string.
 * The scraper must include the header even on GET requests.
 */
class HmacAuth
{
    private const HEADER = 'HTTP_X_BCASHPAY_SCRAPER_SIGNATURE';

    /**
     * Verify the scraper HMAC signature.
     *
     * Terminates with json_error(401) on failure.
     */
    public static function authenticate(): void
    {
        $secret    = (string) config('scraper.secret');
        $signature = $_SERVER[self::HEADER] ?? '';

        if ($signature === '') {
            json_error('X-BCashPay-Scraper-Signature header is required', 401);
        }

        if ($secret === '') {
            // Scraper secret not configured — deny all requests
            json_error('Scraper authentication is not configured', 500);
        }

        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $rawBody = '';
        }

        // Re-wind the input stream is not possible in PHP; the router must
        // pass the already-read body to the controller separately if needed.
        // Store in $_SERVER for later use by the controller.
        $_SERVER['BCASHPAY_RAW_BODY'] = $rawBody;

        // Accept either "sha256=<hex>" (GitHub/Stripe convention, used by
        // the Python scraper) or a bare "<hex>" — strip the prefix if
        // present before the constant-time compare.
        $signatureHex = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        $expected = hash_hmac('sha256', $rawBody, $secret);

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($expected, $signatureHex)) {
            json_error('Invalid scraper signature', 401);
        }
    }
}
