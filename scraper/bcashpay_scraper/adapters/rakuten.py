"""
Rakuten Bank Business Online Banking adapter.

This adapter targets 楽天銀行 法人ビジネスオンラインバンキング (Rakuten Bank
Business Online Banking) without OTP / 2FA. The customer uses:
    - User ID (ユーザーID / ログインID)
    - Login password (ログインパスワード)

Flow:
    1. Navigate to the login page.
    2. Fill ユーザーID + パスワード, click ログイン.
    3. Navigate directly to the deposit history URL (入出金明細).
    4. Parse the transaction table.

Credentials stored in bank_accounts.scrape_credentials_json:
    {
        "username": "<ユーザーID>",
        "password": "<ログインパスワード>"
    }

Notes on selectors:
    Rakuten renames elements occasionally. The selectors below use a
    layered fallback strategy (explicit id/name first, then role+text,
    then placeholder) so one rename does not break the whole flow.
    If the site redesigns heavily, run with --no-headless and update.
"""
from __future__ import annotations

import asyncio
import hashlib
import logging
import re
from datetime import datetime
from typing import List, Optional

from playwright.async_api import Page, Locator, TimeoutError as PlaywrightTimeout

from ..engine.base_adapter import BankAdapter, RawTransaction


log = logging.getLogger('bcashpay_scraper.adapters.rakuten')


class RakutenBusinessAdapter(BankAdapter):
    """Rakuten Bank — Business Online Banking scraper (no OTP variant)."""

    # Login landing page. The corporate portal redirects here from
    # https://www.rakuten-bank.co.jp/corp/ "ログイン" button.
    login_url: str = (
        'https://fes.rakuten-bank.co.jp/MS/main/RbS'
        '?CurrentPageID=START&COMMAND=LOGIN'
    )

    # Direct URL for 入出金明細 after login. SPA route — exact param may vary,
    # adjust once you confirm in a real session.
    transactions_url: str = (
        'https://fes.rakuten-bank.co.jp/MS/main/RbS'
        '?CurrentPageID=STMAIN'
    )

    session_timeout_minutes: int = 15

    # ── Public abstract method implementations ───────────────────────────────

    async def login(self, page: Page) -> None:
        """Navigate to the Rakuten Business login page and authenticate.

        Raises RuntimeError on selector / navigation failure. Caller wraps
        exceptions and marks the bank_account as scrape_failed.
        """
        log.info(f'[{self.bank_name}] navigating to login URL')
        await page.goto(self.login_url, wait_until='domcontentloaded', timeout=60_000)
        await asyncio.sleep(2)  # Allow SPA scripts to settle

        # ── 1. Detect an already-valid session ─────────────────────────────
        if await self._is_logged_in(page):
            log.info(f'[{self.bank_name}] session already active, skipping form')
            return

        # ── 2. Fill ユーザーID ─────────────────────────────────────────────
        user_input = await self._find_first_visible(page, [
            'input#LOGIN_ID',
            'input[name="LOGIN_ID"]',
            'input[name="loginId"]',
            'input[placeholder*="ユーザー"]',
            'input[placeholder*="ID"]',
        ])
        if user_input is None:
            raise RuntimeError(
                f'[{self.bank_name}] login form not found — site may have changed'
            )
        await user_input.fill(self.credentials.username)

        # ── 3. Fill パスワード ─────────────────────────────────────────────
        pw_input = await self._find_first_visible(page, [
            'input#PASSWORD',
            'input[name="PASSWORD"]',
            'input[type="password"]',
        ])
        if pw_input is None:
            raise RuntimeError(f'[{self.bank_name}] password field not found')
        await pw_input.fill(self.credentials.password)

        # ── 4. Click ログイン ──────────────────────────────────────────────
        submit = await self._find_first_visible(page, [
            'button:has-text("ログイン")',
            'input[type="submit"][value*="ログイン"]',
            'input[type="image"][alt*="ログイン"]',
            'button[type="submit"]',
        ])
        if submit is None:
            raise RuntimeError(f'[{self.bank_name}] login submit button not found')

        log.info(f'[{self.bank_name}] submitting login form')
        await submit.click()

        # ── 5. Wait for post-login dashboard ───────────────────────────────
        # Rakuten redirects to a dashboard page after login. Detect via any
        # of these signals. Timeout if none appear within 30 seconds.
        try:
            await page.wait_for_function(
                """() => {
                    const t = document.body?.innerText || '';
                    return /ログアウト|口座残高|入出金|マイ|ホーム/.test(t);
                }""",
                timeout=30_000,
            )
        except PlaywrightTimeout as exc:
            # Check for an inline error message before giving up.
            error_text = await self._read_error_message(page)
            if error_text:
                raise RuntimeError(
                    f'[{self.bank_name}] login failed: {error_text}'
                ) from exc
            raise RuntimeError(
                f'[{self.bank_name}] login timed out — no dashboard signal detected. '
                f'Current URL: {page.url}'
            ) from exc

        log.info(f'[{self.bank_name}] login OK, URL: {page.url}')

    async def navigate_to_transactions(self, page: Page) -> None:
        """Navigate to the 入出金明細 (deposit history) page."""
        log.info(f'[{self.bank_name}] navigating to transactions page')

        # Try direct URL first. Rakuten SPA typically accepts this.
        await page.goto(self.transactions_url, wait_until='domcontentloaded', timeout=60_000)
        await asyncio.sleep(2)

        # If direct URL did not land on a transaction view, try clicking a
        # menu link labelled 入出金明細 as fallback.
        if not await self._has_transaction_table(page):
            log.info(f'[{self.bank_name}] direct URL did not load table, trying menu link')
            link = await self._find_first_visible(page, [
                'a:has-text("入出金明細")',
                'a:has-text("明細照会")',
                'a[href*="STMAIN"]',
            ])
            if link is not None:
                await link.click()
                await asyncio.sleep(2)

        # Final check — the table must be present before we extract.
        try:
            await page.wait_for_selector(
                'table tbody tr, .transaction-list, [data-role="transaction-list"]',
                timeout=15_000,
            )
        except PlaywrightTimeout as exc:
            raise RuntimeError(
                f'[{self.bank_name}] transaction table not found on {page.url}'
            ) from exc

        log.info(f'[{self.bank_name}] transactions page ready, URL: {page.url}')

    async def extract_transactions(self, page: Page) -> List[RawTransaction]:
        """Parse deposit rows from the transaction table.

        Expected column layout (common Rakuten Bank format):
            col 0 : 日付       e.g. "2026/04/16"  (sometimes with year header)
            col 1 : 摘要       e.g. "振込 1234567 ヤマダ タロウ"
            col 2 : 入金金額   e.g. "10,000" or "10,000円"
            col 3 : 出金金額   empty for deposit rows
            col 4 : 残高       ignored

        If the layout differs, adjust the index constants below.
        """
        results: List[RawTransaction] = []

        rows = page.locator('table tbody tr')
        count = await rows.count()
        log.info(f'[{self.bank_name}] found {count} table rows')

        COL_DATE = 0
        COL_DESC = 1
        COL_DEPOSIT = 2
        COL_WITHDRAW = 3

        for i in range(count):
            row = rows.nth(i)
            try:
                cells = await row.locator('td').all_text_contents()
                if len(cells) < COL_DEPOSIT + 1:
                    continue  # Header or separator row

                date_str = cells[COL_DATE].strip()
                description = cells[COL_DESC].strip()
                deposit_str = cells[COL_DEPOSIT].strip()
                withdraw_str = cells[COL_WITHDRAW].strip() if len(cells) > COL_WITHDRAW else ''

                # Skip withdrawals and zero-amount rows
                if withdraw_str and self._parse_amount(withdraw_str) > 0:
                    continue
                if not deposit_str:
                    continue

                amount = self._parse_amount(deposit_str)
                if amount <= 0:
                    continue
                if not date_str:
                    continue

                results.append(RawTransaction(
                    payment_id=self._make_payment_id(date_str, description, amount),
                    amount=amount,
                    date=self._parse_date(date_str),
                    depositor_name=description,
                    memo='',
                ))
            except Exception as e:  # noqa: BLE001 — row-level failure must not stop scan
                log.warning(f'[{self.bank_name}] row {i} parse failed: {e}')

        log.info(f'[{self.bank_name}] extracted {len(results)} deposit transactions')
        return results

    async def logout(self, page: Page) -> None:
        """Attempt a graceful logout. Failure is non-fatal."""
        try:
            btn = await self._find_first_visible(page, [
                'a:has-text("ログアウト")',
                'button:has-text("ログアウト")',
                'a[href*="LOGOUT"]',
            ])
            if btn is not None:
                await btn.click()
                await page.wait_for_load_state('networkidle', timeout=10_000)
                log.info(f'[{self.bank_name}] logged out')
        except Exception:
            pass  # Session will expire server-side regardless

    # ── Private helpers ──────────────────────────────────────────────────────

    async def _find_first_visible(self, page: Page, selectors: List[str]) -> Optional[Locator]:
        """Return the first visible Locator from a list of candidate selectors."""
        for sel in selectors:
            try:
                loc = page.locator(sel).first
                if await loc.is_visible(timeout=1_500):
                    return loc
            except Exception:
                continue
        return None

    async def _is_logged_in(self, page: Page) -> bool:
        """Heuristic: a logout link is visible somewhere on the page."""
        try:
            return await page.locator('text=ログアウト').first.is_visible(timeout=3_000)
        except Exception:
            return False

    async def _has_transaction_table(self, page: Page) -> bool:
        """Quick probe: is a non-empty transaction table visible?"""
        try:
            count = await page.locator('table tbody tr').count()
            return count > 0
        except Exception:
            return False

    async def _read_error_message(self, page: Page) -> Optional[str]:
        """Return the visible error text after a failed login attempt, if any."""
        for sel in [
            '.error-message',
            '.errorText',
            '.js-error',
            '[role="alert"]',
            '.msgError',
        ]:
            try:
                loc = page.locator(sel).first
                if await loc.is_visible(timeout=1_500):
                    text = (await loc.inner_text()).strip()
                    if text:
                        return text
            except Exception:
                continue
        return None

    # ── Static parsing helpers ───────────────────────────────────────────────

    @staticmethod
    def _parse_amount(s: str) -> int:
        """Parse a Japanese bank amount string to an integer JPY value.

        Handles '¥10,000', '10,000円', '10000', full-width digits, etc.
        """
        # Normalise full-width digits / commas to half-width
        import unicodedata
        normalised = unicodedata.normalize('NFKC', s)
        digits = re.sub(r'[^\d]', '', normalised)
        return int(digits) if digits else 0

    @staticmethod
    def _parse_date(s: str) -> datetime:
        """Parse a Rakuten Bank date cell into a datetime.

        Handles the common Japanese bank formats and returns now() as a
        fallback rather than raising, so one bad row does not abort the scan.
        """
        import unicodedata
        normalised = unicodedata.normalize('NFKC', s).strip()

        # Extract first YYYY/MM/DD-ish substring (may have year-only prefix)
        m = re.search(r'(\d{4})[/\-年](\d{1,2})[/\-月](\d{1,2})', normalised)
        if m:
            y, mo, d = (int(x) for x in m.groups())
            try:
                return datetime(y, mo, d)
            except ValueError:
                pass

        # Short form MM/DD (current year implied)
        m = re.search(r'(\d{1,2})[/\-月](\d{1,2})', normalised)
        if m:
            mo, d = (int(x) for x in m.groups())
            try:
                return datetime(datetime.now().year, mo, d)
            except ValueError:
                pass

        log.warning(f'Could not parse date: {s!r} — using now()')
        return datetime.now()

    @staticmethod
    def _make_payment_id(date_str: str, description: str, amount: int) -> str:
        """Build a stable deduplication ID from transaction fields.

        Rakuten does not always expose an internal transaction ID in the
        HTML table, so we hash date + description + amount. The same row
        across scrape runs produces the same ID → idempotent inserts.
        """
        raw = f'{date_str}|{description}|{amount}'
        return hashlib.sha256(raw.encode('utf-8')).hexdigest()
