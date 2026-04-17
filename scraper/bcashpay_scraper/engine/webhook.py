"""
Webhook sender — delivers scraped deposits to the B-CashPay internal API.

Two endpoints:
    POST /api/internal/scraper/deposits — batch of raw deposits for deduplication
                                           and payment-link matching
    POST /api/internal/scraper/status   — scraper run status / health report

Authentication:
    Every request is signed with HMAC-SHA256 over the raw JSON body using
    the shared ``BCASHPAY_SCRAPER_SECRET``.  The signature is sent in the
    ``X-BCashPay-Scraper-Signature: sha256=<hex>`` header so the PHP API can
    verify the request came from this process and has not been tampered with.
"""
import hashlib
import hmac
import json
import logging
from datetime import datetime
from typing import List, Optional

import aiohttp

from .base_adapter import RawTransaction


log = logging.getLogger('bcashpay_scraper.webhook')


class WebhookSender:
    """Sends scraped deposit data to the B-CashPay API.

    Args:
        api_url: Base URL of the B-CashPay API, e.g. ``http://localhost:8000``.
        scraper_secret: Shared HMAC secret that must match
                        ``BCASHPAY_SCRAPER_SECRET`` on the API side.
    """

    def __init__(self, api_url: str, scraper_secret: str) -> None:
        self.api_url = api_url.rstrip('/')
        self.scraper_secret = scraper_secret

    # ── Helpers ──────────────────────────────────────────────────────────────

    def _sign(self, body: bytes) -> str:
        """Compute HMAC-SHA256 of *body* and return ``sha256=<hex>``."""
        sig = hmac.new(
            self.scraper_secret.encode('utf-8'),
            body,
            hashlib.sha256,
        ).hexdigest()
        return f'sha256={sig}'

    def _headers(self, body: bytes) -> dict:
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-BCashPay-Scraper-Signature': self._sign(body),
        }

    # ── Public API ────────────────────────────────────────────────────────────

    async def send_deposits(
        self,
        bank_account_id: int,
        transactions: List[RawTransaction],
    ) -> int:
        """POST a batch of deposits to the API for matching.

        The API response body contains ``{"matched": N, ...}``; we return
        the matched count.  Returns 0 on error so the caller can log and
        continue.

        Args:
            bank_account_id: The bank_accounts.id this batch belongs to.
            transactions: List of RawTransaction objects extracted by the adapter.

        Returns:
            Number of deposits successfully matched to payment links.
        """
        if not transactions:
            log.info('[webhook] no transactions to send — skipping')
            return 0

        payload: dict = {
            'bank_account_id': bank_account_id,
            'sent_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'deposits': [
                {
                    'depositor_name': txn.depositor_name,
                    'amount': txn.amount,
                    'transaction_date': txn.date.strftime('%Y-%m-%d %H:%M:%S'),
                    'bank_transaction_id': txn.payment_id,
                    'memo': txn.memo or '',
                }
                for txn in transactions
            ],
        }

        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        endpoint = f'{self.api_url}/api/internal/scraper/deposits'

        try:
            async with aiohttp.ClientSession() as session:
                async with session.post(
                    endpoint,
                    data=body,
                    headers=self._headers(body),
                    timeout=aiohttp.ClientTimeout(total=60),
                ) as resp:
                    text = await resp.text()
                    if 200 <= resp.status < 300:
                        try:
                            data = json.loads(text)
                            matched = int(data.get('matched', 0))
                        except (ValueError, KeyError):
                            matched = 0
                        log.info(
                            f'[webhook] deposits OK — sent={len(transactions)}'
                            f' matched={matched} status={resp.status}'
                        )
                        return matched
                    log.error(
                        f'[webhook] deposits failed — status={resp.status}'
                        f' body={text[:400]}'
                    )
                    return 0
        except Exception as exc:
            log.exception(f'[webhook] deposits exception: {exc}')
            return 0

    async def send_status(
        self,
        bank_account_id: int,
        status: str,
        message: str = '',
        stats: Optional[dict] = None,
    ) -> bool:
        """POST a status update to ``/api/internal/scraper/status``.

        Args:
            bank_account_id: The bank_accounts.id this report belongs to.
            status: Short status string, e.g. ``'success'``, ``'failed'``,
                    ``'login_failed'``.
            message: Human-readable description or error message.
            stats: Optional dict with ``transactions_found``,
                   ``transactions_matched``, ``duration_seconds``.

        Returns:
            True if the API accepted the request (2xx), False otherwise.
        """
        payload: dict = {
            'bank_account_id': bank_account_id,
            'status': status,
            'message': message,
            'sent_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            **(stats or {}),
        }

        body = json.dumps(payload, ensure_ascii=False).encode('utf-8')
        endpoint = f'{self.api_url}/api/internal/scraper/status'

        try:
            async with aiohttp.ClientSession() as session:
                async with session.post(
                    endpoint,
                    data=body,
                    headers=self._headers(body),
                    timeout=aiohttp.ClientTimeout(total=30),
                ) as resp:
                    if 200 <= resp.status < 300:
                        log.info(f'[webhook] status OK — {status} for bank_account_id={bank_account_id}')
                        return True
                    text = await resp.text()
                    log.error(
                        f'[webhook] status failed — status={resp.status}'
                        f' body={text[:400]}'
                    )
                    return False
        except Exception as exc:
            log.exception(f'[webhook] status exception: {exc}')
            return False
