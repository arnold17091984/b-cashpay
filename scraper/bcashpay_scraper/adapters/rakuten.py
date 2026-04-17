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
    4. Primary extraction path — download the bank's CSV export and parse it.
       Rakuten Business caps the in-page table at the 50 most recent entries,
       so table-scraping alone loses older deposits the moment a busy tenant
       posts more than 50 transactions in one interval.  The CSV contains the
       full history in a stable Shift_JIS format, so we use it whenever the
       download button is reachable.
    5. Fallback — if the CSV button is missing or the download fails, fall
       back to parsing the on-page HTML table (legacy behaviour).

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
import csv
import hashlib
import io
import logging
import os
import re
from datetime import datetime
from pathlib import Path
from typing import List, Optional

from playwright.async_api import Page, Locator, TimeoutError as PlaywrightTimeout

from ..engine.base_adapter import BankAdapter, RawTransaction


# Where Playwright saves CSV downloads before we parse them. Overridable so
# deployments on read-only rootfs can redirect to a writable volume.
CSV_DOWNLOAD_DIR = Path(
    os.environ.get('SCRAPER_CSV_DOWNLOAD_DIR', '/tmp/bcashpay-scraper-csv')
)


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
        """Extract deposit rows.

        Primary path: download Rakuten's CSV export (complete history, stable
        format).  Fallback: parse the on-page HTML table (capped at ~50 rows).

        Row-level failures never abort the scan; the worst case is a single
        malformed row being skipped with a warning.
        """
        csv_txns = await self._extract_via_csv(page)
        if csv_txns is not None:
            log.info(
                f'[{self.bank_name}] extracted {len(csv_txns)} deposit transactions via CSV'
            )
            return csv_txns

        log.warning(
            f'[{self.bank_name}] CSV download unavailable, falling back to table parse '
            f'(capped at ~50 rows — older deposits may be missed)'
        )
        return await self._extract_via_table(page)

    async def _extract_via_csv(self, page: Page) -> Optional[List[RawTransaction]]:
        """Try the CSV-download path.

        Returns ``None`` when the CSV button is not reachable or the download
        itself fails — the caller then falls back to table parsing.  Returns
        an empty list when the CSV downloaded cleanly but held no deposits.
        """
        download_btn = await self._find_first_visible(page, [
            "input[value*='ダウンロード']",
            "button:has-text('ダウンロード')",
            "a:has-text('ダウンロード')",
            "input[type='submit'][value*='CSV']",
            "a:has-text('CSV')",
        ])
        if download_btn is None:
            return None

        CSV_DOWNLOAD_DIR.mkdir(parents=True, exist_ok=True)

        try:
            async with page.expect_download(timeout=30_000) as download_info:
                await download_btn.click()
            download = await download_info.value
            csv_path = CSV_DOWNLOAD_DIR / download.suggested_filename
            await download.save_as(str(csv_path))
            log.info(
                f'[{self.bank_name}] CSV downloaded: {csv_path.name} '
                f'({csv_path.stat().st_size} bytes)'
            )
        except Exception as exc:  # noqa: BLE001 — any failure triggers fallback
            log.warning(f'[{self.bank_name}] CSV download failed: {exc}')
            return None

        return self._parse_csv_file(csv_path)

    def _parse_csv_file(self, csv_path: Path) -> List[RawTransaction]:
        """Parse a Rakuten Bank CSV into RawTransactions.

        Rakuten CSV columns (legacy 楽天銀行フォーマット):
            取引日, 入出金内容, 入出金（円）, 残高（円）

        Incoming amounts are positive; withdrawals negative — we keep only
        positive rows (deposits).  Encoding is usually Shift_JIS but we try
        several to be safe.
        """
        results: List[RawTransaction] = []

        content = None
        for encoding in ('shift_jis', 'cp932', 'utf-8-sig', 'utf-8'):
            try:
                content = csv_path.read_text(encoding=encoding)
                break
            except UnicodeDecodeError:
                continue
        if content is None:
            log.error(f'[{self.bank_name}] CSV encoding not recognised: {csv_path}')
            return results

        reader = csv.reader(io.StringIO(content))
        rows = list(reader)
        if not rows:
            return results

        # Find the header row; data starts on the next row.
        header_idx = 0
        for i, row in enumerate(rows):
            if any('取引日' in cell for cell in row):
                header_idx = i
                break

        skipped = 0
        for idx, row in enumerate(rows[header_idx + 1:]):
            if len(row) < 3:
                continue

            date_str = row[0].strip().strip('"')
            description = row[1].strip().strip('"')
            amount_str = row[2].strip().strip('"').replace(',', '')

            if not date_str or not amount_str:
                continue

            try:
                amount = int(amount_str)
            except ValueError:
                skipped += 1
                continue

            # Deposits only — negative means withdrawal.
            if amount <= 0:
                continue

            results.append(RawTransaction(
                payment_id=self._make_payment_id(date_str, description, amount),
                amount=amount,
                date=self._parse_date(date_str),
                depositor_name=description,
                memo='',
            ))

        if skipped:
            log.debug(f'[{self.bank_name}] CSV skipped {skipped} unparseable rows')
        return results

    async def _extract_via_table(self, page: Page) -> List[RawTransaction]:
        """Parse deposit rows from the on-page transaction table (fallback).

        Expected column layout (common Rakuten Bank format):
            col 0 : 日付       e.g. "2026/04/16"  (sometimes with year header)
            col 1 : 摘要       e.g. "振込 1234567 ヤマダ タロウ"
            col 2 : 入金金額   e.g. "10,000" or "10,000円"
            col 3 : 出金金額   empty for deposit rows
            col 4 : 残高       ignored

        The table view is capped at ~50 rows by Rakuten, so this path only
        sees the most recent deposits.  Prefer the CSV path when available.
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

        log.info(f'[{self.bank_name}] extracted {len(results)} deposit transactions via table')
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
