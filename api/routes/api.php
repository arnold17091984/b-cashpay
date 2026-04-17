<?php

declare(strict_types=1);

/**
 * B-CashPay API Route Reference
 *
 * All routing is implemented directly in public/index.php via the dispatch()
 * function. This file documents the full route table for reference.
 *
 * ── Public routes (no auth) ──────────────────────────────────────────────────
 *
 *   GET  /api/v1/health
 *        Health check. Returns {"status":"ok","database":"connected",...}
 *
 *   GET  /p/{token}
 *        Customer-facing payment page (HTML).
 *        token = 32-char hex string (bin2hex(random_bytes(16))).
 *        Renders pending/confirmed/expired state pages.
 *
 *   GET  /api/v1/pay/{token}/status
 *        JSON status poll for the payment page JS.
 *        Rate-limited: 30 req/min per IP.
 *        Returns {"status":"pending|confirmed|expired|cancelled","confirmed_at":...}
 *
 * ── API client routes (Bearer token auth) ────────────────────────────────────
 *
 *   POST /api/v1/payments
 *        Create a new payment link.
 *        Body: {amount, customer_name, external_id?, customer_email?,
 *               callback_url?, metadata?}
 *        Returns 201 with payment link data including payment_url.
 *
 *   GET  /api/v1/payments
 *        List payment links with pagination.
 *        Query: status?, external_id?, page?, per_page? (max 100)
 *
 *   GET  /api/v1/payments/{id}
 *        Get a single payment link by ULID (bp_ prefix).
 *
 *   POST /api/v1/payments/{id}/cancel
 *        Cancel a pending payment link. Returns 409 if not pending.
 *
 * ── Internal scraper routes (HMAC-SHA256 auth) ───────────────────────────────
 *
 *   POST /api/internal/scraper/deposits
 *        Receive a batch of scraped deposits and attempt matching.
 *        Auth: X-BCashPay-Scraper-Signature: HMAC-SHA256(BANK_SCRAPER_TOKEN, body)
 *        Body: {bank_account_id?, deposits:[{depositor_name, amount,
 *               transaction_date, bank_transaction_id, ...}]}
 *        Returns {matched, skipped, errors, received} summary.
 *
 *   GET  /api/internal/scraper/tasks
 *        Return pending scraper tasks grouped by bank account.
 *        Auth: same HMAC header (body = empty string for GET).
 *        Returns tasks with pending_payments list for each bank account.
 */
