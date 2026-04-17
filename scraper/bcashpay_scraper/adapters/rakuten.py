"""
Rakuten Bank Business Online Banking adapter.

This adapter is an intentional SKELETON — the login and navigation selectors
require a live Rakuten Business account to be verified against the actual DOM.
The helper methods (_parse_amount, _parse_date, _make_payment_id) and the
overall scaffold are fully implemented.

Login URL (tentative — verify with a real session):
    https://fes.rakuten-bank.co.jp/MS/main/RbS?CurrentPageID=START&COMMAND=LOGIN

Steps to complete this adapter:
    1. Run:  python3 -m bcashpay_scraper.runner --once --bank rakuten --no-headless
    2. DevTools → inspect the login form, note id/name attrs of every input.
    3. After login, navigate to 入出金明細, inspect the transaction table rows.
    4. Fill in the SELECTOR placeholders in login(), navigate_to_transactions(),
       and extract_transactions() below, then remove the NotImplementedError raises.
"""
import asyncio
import hashlib
import logging
import re
from datetime import datetime
from typing import List

from playwright.async_api import Page

from ..engine.base_adapter import BankAdapter, BankCredentials, RawTransaction


log = logging.getLogger('bcashpay_scraper.adapters.rakuten')


class RakutenBusinessAdapter(BankAdapter):
    """Rakuten Bank — Business Online Banking scraper.

    Credentials expected in ``scrape_credentials_json``:
        {
            "username": "<customer number or login ID>",
            "password": "<login password>",
            "totp_secret": "<base32 TOTP secret if 2FA is enabled>"
        }
    """

    login_url: str = (
        'https://fes.rakuten-bank.co.jp/MS/main/RbS'
        '?CurrentPageID=START&COMMAND=LOGIN'
    )
    session_timeout_minutes: int = 15

    # ── Abstract method implementations ──────────────────────────────────────

    async def login(self, page: Page) -> None:
        """Navigate to the Rakuten Business login page and authenticate.

        Flow (verify selectors against live DOM):
            1. Navigate to login_url.
            2. Detect whether the session is already active (skip re-login).
            3. Fill customer number / login ID field.
            4. Fill password field.
            5. Click the login button.
            6. Handle OTP / TOTP challenge if shown.
            7. Confirm we reached the post-login dashboard.

        Raises:
            NotImplementedError: Until selectors are confirmed against the live site.
        """
        log.info(f'[{self.bank_name}] navigating to login URL: {self.login_url}')
        await page.goto(self.login_url, wait_until='domcontentloaded', timeout=60_000)
        await asyncio.sleep(2)  # Allow SPA scripts to render.

        # Check if session is already active.
        # SELECTOR: replace with a reliable post-login element, e.g.:
        #   'text=ログアウト'  |  '#dashboard-header'  |  '.account-summary'
        already_logged_in = False
        try:
            already_logged_in = await page.locator('text=ログアウト').is_visible(timeout=3_000)
        except Exception:
            pass

        if already_logged_in:
            log.info(f'[{self.bank_name}] session already active — skipping login form')
            return

        # Fill login form.
        # SELECTOR: confirm actual input selectors from DevTools, then replace:
        #   Login ID / customer number : '#LOGIN_ID'  or  'input[name="LOGIN_ID"]'
        #   Password                   : '#PASSWORD'   or  'input[name="PASSWORD"]'
        #   Submit button              : 'input[type="submit"]'  or  'button:has-text("ログイン")'
        raise NotImplementedError(
            'Rakuten Bank login selectors have not yet been confirmed against the live site.\n'
            'Open the browser with --no-headless, inspect the DOM, and replace this raise with:\n'
            '    await page.wait_for_selector("<LOGIN_ID_SELECTOR>", timeout=15_000)\n'
            '    await page.fill("<LOGIN_ID_SELECTOR>", self.credentials.username)\n'
            '    await page.fill("<PASSWORD_SELECTOR>", self.credentials.password)\n'
            '    await page.click("<SUBMIT_SELECTOR>")\n'
            'Then handle OTP via self._handle_otp(page) if applicable.\n'
            'Finally wait for the dashboard: await page.wait_for_selector("<DASHBOARD_SELECTOR>")'
        )

        # OTP / 2FA — uncomment after login form is confirmed:
        # await self._handle_otp(page)

        # Verify dashboard reached — replace selector after inspection:
        # await page.wait_for_selector('<DASHBOARD_SELECTOR>', timeout=15_000)
        # log.info(f'[{self.bank_name}] login complete, URL: {page.url}')

    async def navigate_to_transactions(self, page: Page) -> None:
        """Navigate to the deposit history / transaction list page.

        Rakuten Business likely uses a URL such as:
            https://fes.rakuten-bank.co.jp/MS/main/RbS?CurrentPageID=STMAIN
        or a menu link labelled 入出金明細.

        SELECTOR: confirm the exact URL or menu path against a live session,
        then replace the raise below.

        Raises:
            NotImplementedError: Until the transaction page URL is confirmed.
        """
        # Tentative direct URL — verify against a live session, then uncomment:
        # transactions_url = (
        #     'https://fes.rakuten-bank.co.jp/MS/main/RbS'
        #     '?CurrentPageID=STMAIN'
        # )
        # log.info(f'[{self.bank_name}] navigating to {transactions_url}')
        # await page.goto(transactions_url, wait_until='domcontentloaded', timeout=60_000)
        # await asyncio.sleep(2)
        # SELECTOR: replace with the table/list element that appears when data is loaded:
        # await page.wait_for_selector('<TABLE_OR_LIST_SELECTOR>', timeout=15_000)
        # log.info(f'[{self.bank_name}] arrived at: {page.url}')

        raise NotImplementedError(
            'Rakuten Bank transaction page URL has not been confirmed.\n'
            'After login: Network tab → navigate to 入出金明細 → copy the URL.\n'
            'Then replace this raise with page.goto("<TRANSACTIONS_URL>") + '
            'page.wait_for_selector("<TABLE_SELECTOR>").'
        )

    async def extract_transactions(self, page: Page) -> List[RawTransaction]:
        """Parse deposit rows from the transaction list page.

        Expected table layout (verify column order against live DOM):
            col 0 — 日付          (date,        e.g. "2026/04/16")
            col 1 — 摘要/取引内容  (description / depositor name)
            col 2 — 入金金額       (deposit,     e.g. "10,000")
            col 3 — 出金金額       (withdrawal — skip rows where this is non-empty)
            col 4 — 残高           (balance —    ignore)

        A stable payment_id is computed as SHA-256(date|description|amount)
        for deduplication when the bank does not expose its own transaction ID.

        Implementation outline (activate after navigate_to_transactions works):

            results: List[RawTransaction] = []
            rows = page.locator('table tbody tr')
            count = await rows.count()
            for i in range(count):
                cells = await rows.nth(i).locator('td').all_text_contents()
                if len(cells) < 3:
                    continue
                date_str       = cells[0].strip()
                description    = cells[1].strip()
                deposit_str    = cells[2].strip()
                withdrawal_str = cells[3].strip() if len(cells) > 3 else ''
                if not deposit_str or withdrawal_str:
                    continue
                amount = self._parse_amount(deposit_str)
                if amount <= 0:
                    continue
                results.append(RawTransaction(
                    payment_id=self._make_payment_id(date_str, description, amount),
                    amount=amount,
                    date=self._parse_date(date_str),
                    depositor_name=description,
                    memo='',
                ))
            return results
        """
        # Returns empty list — navigate_to_transactions raises NotImplementedError
        # before this method is ever reached in the current state.
        return []

    async def logout(self, page: Page) -> None:
        """Attempt a graceful logout.  Failure is non-fatal."""
        try:
            # SELECTOR: confirm the logout link — common patterns:
            #   'a:has-text("ログアウト")'  |  '#logout-link'
            await page.click('a:has-text("ログアウト")', timeout=5_000)
            await page.wait_for_load_state('networkidle', timeout=10_000)
            log.info(f'[{self.bank_name}] logged out')
        except Exception:
            pass  # Best-effort — session expires on the bank's side anyway.

    # ── Private helpers ───────────────────────────────────────────────────────

    async def _handle_otp(self, page: Page) -> None:
        """Handle a TOTP or SMS one-time-password challenge if present.

        Generates a TOTP code from ``credentials.totp_secret`` via pyotp when
        an OTP input is detected on the page.

        SELECTOR: confirm the OTP input selector from live DOM inspection.
        """
        otp_input_selector = 'input[name*="otp" i], input[id*="otp" i], input[maxlength="6"]'
        try:
            otp_visible = await page.locator(otp_input_selector).first.is_visible(timeout=3_000)
        except Exception:
            otp_visible = False

        if not otp_visible:
            return

        if not self.credentials.totp_secret:
            raise RuntimeError(
                f'[{self.bank_name}] OTP challenge detected but totp_secret is not set '
                'in scrape_credentials_json'
            )

        import pyotp  # Lazy import — optional dependency.

        code = pyotp.TOTP(self.credentials.totp_secret).now()
        log.info(f'[{self.bank_name}] filling TOTP code')
        await page.locator(otp_input_selector).first.fill(code)

        # SELECTOR: confirm the OTP confirm button, then uncomment:
        # await page.click('button:has-text("認証")')
        await asyncio.sleep(1)

    @staticmethod
    def _parse_amount(s: str) -> int:
        """Parse a Japanese bank amount string to an integer JPY value.

        Handles formats like '¥10,000', '10,000円', '10000'.

        Args:
            s: Raw amount string from the bank table cell.

        Returns:
            Integer yen amount, or 0 if the string cannot be parsed.
        """
        digits = re.sub(r'[^\d]', '', s)
        return int(digits) if digits else 0

    @staticmethod
    def _parse_date(s: str) -> datetime:
        """Parse a date string from Rakuten Bank into a datetime.

        Tries common Japanese bank date formats:
            '2026/04/16', '2026-04-16', '2026年04月16日'

        Args:
            s: Raw date string from the bank table cell.

        Returns:
            Parsed datetime, or datetime.now() as a fallback.
        """
        for fmt in ('%Y/%m/%d', '%Y-%m-%d', '%Y年%m月%d日'):
            try:
                return datetime.strptime(s.strip(), fmt)
            except ValueError:
                continue
        log.warning(f'Could not parse date: {s!r} — using now()')
        return datetime.now()

    @staticmethod
    def _make_payment_id(date_str: str, description: str, amount: int) -> str:
        """Build a stable deduplication ID from transaction fields.

        Uses SHA-256 of ``date|description|amount`` so the same transaction
        always produces the same ID across scrape runs.

        Args:
            date_str: Raw date string as seen in the table.
            description: Depositor name / transaction description.
            amount: Integer yen amount.

        Returns:
            64-character hex SHA-256 digest.
        """
        raw = f'{date_str}|{description}|{amount}'
        return hashlib.sha256(raw.encode('utf-8')).hexdigest()
