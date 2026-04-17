"""
B-CashPay Bank Scraper Runner — continuous scheduling loop.

Reads active bank_accounts from the database, decides which ones are due for
a scrape (pending payment_links + interval elapsed), runs the corresponding
adapter, and POSTs extracted deposits to the B-CashPay internal API.

Usage:
    # One-shot debug cycle:
    python3 -m bcashpay_scraper.runner --once

    # Single adapter key only:
    python3 -m bcashpay_scraper.runner --once --bank rakuten

    # Show browser (disable headless) for debugging:
    python3 -m bcashpay_scraper.runner --once --bank rakuten --no-headless

    # Run a specific account by DB id:
    python3 -m bcashpay_scraper.runner --once --account-id 3

    # Continuous mode (managed by PM2):
    python3 -m bcashpay_scraper.runner
"""
from __future__ import annotations

import argparse
import asyncio
import json
import logging
import os
import sqlite3
import sys
import time
import traceback
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional

# ── Env loading ──────────────────────────────────────────────────────────────

def _load_dotenv() -> None:
    """Load .env from cwd or /opt/bcashpay/scraper/.env into os.environ."""
    candidates = [
        Path.cwd() / '.env',
        Path('/opt/bcashpay/scraper/.env'),
    ]
    for path in candidates:
        if not path.exists():
            continue
        with open(path) as fh:
            for raw_line in fh:
                line = raw_line.strip()
                if not line or line.startswith('#') or '=' not in line:
                    continue
                key, _, value = line.partition('=')
                os.environ.setdefault(key.strip(), value.strip())
        break  # Only load the first file found.


_load_dotenv()


# ── Logging ──────────────────────────────────────────────────────────────────

LOG_DIR = Path(os.environ.get('LOG_DIR', '/tmp/bcashpay-scraper-logs'))
LOG_DIR.mkdir(parents=True, exist_ok=True)

logging.basicConfig(
    level=os.environ.get('LOG_LEVEL', 'INFO').upper(),
    format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler(
            LOG_DIR / f'scraper_{datetime.now().strftime("%Y%m%d")}.log'
        ),
    ],
)
log = logging.getLogger('bcashpay_scraper.runner')


# ── Adapter registry ─────────────────────────────────────────────────────────
# Import adapters here.  New adapters only need to be added to this dict.

from .adapters.rakuten import RakutenBusinessAdapter  # noqa: E402

ADAPTERS: Dict[str, type] = {
    'rakuten': RakutenBusinessAdapter,
}


# ── Config ───────────────────────────────────────────────────────────────────

DB_DRIVER = os.environ.get('DB_DRIVER', 'sqlite').lower()

# SQLite (development / single-process production):
DB_SQLITE_PATH = os.environ.get(
    'DB_SQLITE_PATH',
    str(Path(__file__).parent.parent.parent.parent / 'api' / 'database' / 'bcashpay.sqlite'),
)

# MySQL (production multi-process):
MYSQL_CONFIG: Dict[str, Any] = {
    'host': os.environ.get('DB_HOST', '127.0.0.1'),
    'port': int(os.environ.get('DB_PORT', '3306')),
    'database': os.environ.get('DB_DATABASE', 'bcashpay'),
    'user': os.environ.get('DB_USERNAME', 'bcashpay'),
    'password': os.environ.get('DB_PASSWORD', ''),
    'charset': 'utf8mb4',
}

BCASHPAY_API_URL = os.environ.get('BCASHPAY_API_URL', 'http://localhost:8000')
BCASHPAY_SCRAPER_SECRET = os.environ.get('BCASHPAY_SCRAPER_SECRET', 'change-me-shared-with-api')

POLL_INTERVAL_SECONDS = int(os.environ.get('POLL_INTERVAL_SECONDS', '60'))
DEFAULT_SCRAPE_INTERVAL_MINUTES = int(os.environ.get('DEFAULT_SCRAPE_INTERVAL_MINUTES', '15'))


# ── DB connection helpers ─────────────────────────────────────────────────────

def _sqlite_conn() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_SQLITE_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute('PRAGMA journal_mode=WAL')
    conn.execute('PRAGMA foreign_keys=ON')
    return conn


def _mysql_conn():
    """Return a pymysql DictCursor connection (lazy import)."""
    import pymysql
    import pymysql.cursors
    return pymysql.connect(**MYSQL_CONFIG, cursorclass=pymysql.cursors.DictCursor)


def _row_to_dict(row: Any) -> dict:
    """Convert sqlite3.Row or pymysql dict-like row to a plain dict."""
    if isinstance(row, sqlite3.Row):
        return dict(row)
    return dict(row)


# ── DB helpers ────────────────────────────────────────────────────────────────

def _fetchall(query: str, params: tuple = ()) -> List[dict]:
    """Execute *query* and return all rows as plain dicts."""
    if DB_DRIVER == 'sqlite':
        with _sqlite_conn() as conn:
            cur = conn.execute(query, params)
            return [_row_to_dict(r) for r in cur.fetchall()]
    else:
        conn = _mysql_conn()
        try:
            with conn.cursor() as cur:
                cur.execute(query, params)
                return list(cur.fetchall())
        finally:
            conn.close()


def _fetchone(query: str, params: tuple = ()) -> Optional[dict]:
    rows = _fetchall(query, params)
    return rows[0] if rows else None


def _execute(query: str, params: tuple = ()) -> None:
    """Execute a write statement (INSERT / UPDATE)."""
    if DB_DRIVER == 'sqlite':
        with _sqlite_conn() as conn:
            conn.execute(query, params)
            conn.commit()
    else:
        conn = _mysql_conn()
        try:
            with conn.cursor() as cur:
                cur.execute(query, params)
            conn.commit()
        finally:
            conn.close()


# ── DB domain functions ───────────────────────────────────────────────────────

def load_active_bank_accounts() -> List[dict]:
    """Return bank_accounts rows that are active and eligible to scrape.

    Eligible statuses: 'active', 'login_failed', 'scrape_failed'.
    Accounts in 'setup_pending' or 'disabled' are excluded.
    """
    statuses = ('active', 'login_failed', 'scrape_failed')

    if DB_DRIVER == 'sqlite':
        placeholders = ','.join(['?'] * len(statuses))
    else:
        placeholders = ','.join(['%s'] * len(statuses))

    query = f"""
        SELECT id, bank_name, account_number,
               scrape_login_url, scrape_credentials_json,
               scrape_adapter_key, scrape_interval_minutes,
               scrape_status, scrape_last_at,
               scrape_last_success_at, scrape_consecutive_failures
        FROM bank_accounts
        WHERE is_active = 1
          AND scrape_adapter_key IS NOT NULL
          AND scrape_status IN ({placeholders})
    """
    return _fetchall(query, statuses)


def load_recent_tasks(account_id: int, limit: int = 20) -> List[dict]:
    """Return the most recent scraper_tasks rows for *account_id*."""
    placeholder = '?' if DB_DRIVER == 'sqlite' else '%s'
    query = f"""
        SELECT id, bank_account_id, status, last_run_at, next_run_at,
               run_count, transactions_found, transactions_matched,
               duration_seconds, error_message, created_at, updated_at
        FROM scraper_tasks
        WHERE bank_account_id = {placeholder}
        ORDER BY id DESC
        LIMIT {placeholder}
    """
    return _fetchall(query, (account_id, limit))


def load_all_recent_tasks(status: Optional[str] = None, limit: int = 50) -> List[dict]:
    """Return recent scraper_tasks across all accounts, optionally filtered by status."""
    placeholder = '?' if DB_DRIVER == 'sqlite' else '%s'
    if status:
        query = f"""
            SELECT id, bank_account_id, status, last_run_at, next_run_at,
                   run_count, transactions_found, transactions_matched,
                   duration_seconds, error_message, created_at, updated_at
            FROM scraper_tasks
            WHERE status = {placeholder}
            ORDER BY id DESC
            LIMIT {placeholder}
        """
        return _fetchall(query, (status, limit))
    query = f"""
        SELECT id, bank_account_id, status, last_run_at, next_run_at,
               run_count, transactions_found, transactions_matched,
               duration_seconds, error_message, created_at, updated_at
        FROM scraper_tasks
        ORDER BY id DESC
        LIMIT {placeholder}
    """
    return _fetchall(query, (limit,))


def count_pending_payments_for_account(account_id: int) -> int:
    """Count payment_links that are pending and not yet expired for *account_id*."""
    if DB_DRIVER == 'sqlite':
        now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        row = _fetchone(
            """
            SELECT COUNT(*) AS cnt
            FROM payment_links
            WHERE bank_account_id = ?
              AND status = 'pending'
              AND expires_at > ?
            """,
            (account_id, now_str),
        )
    else:
        row = _fetchone(
            """
            SELECT COUNT(*) AS cnt
            FROM payment_links
            WHERE bank_account_id = %s
              AND status = 'pending'
              AND expires_at > NOW()
            """,
            (account_id,),
        )
    return int((row or {}).get('cnt', 0))


def update_bank_scrape_status(
    account_id: int,
    status: str,
    error: Optional[str] = None,
) -> None:
    """Update scrape_status and related timestamp columns on bank_accounts.

    Args:
        account_id: bank_accounts.id
        status: ``'active'`` (success) or an error status string.
        error: Error message stored in scrape_last_error_message on failure.
    """
    now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    ph = '?' if DB_DRIVER == 'sqlite' else '%s'

    if status == 'active':
        _execute(
            f"""
            UPDATE bank_accounts
               SET scrape_status = 'active',
                   scrape_last_at = {ph},
                   scrape_last_success_at = {ph},
                   scrape_consecutive_failures = 0
             WHERE id = {ph}
            """,
            (now_str, now_str, account_id),
        )
    else:
        _execute(
            f"""
            UPDATE bank_accounts
               SET scrape_status = {ph},
                   scrape_last_at = {ph},
                   scrape_last_error_at = {ph},
                   scrape_last_error_message = {ph},
                   scrape_consecutive_failures = scrape_consecutive_failures + 1
             WHERE id = {ph}
            """,
            (status, now_str, now_str, (error or '')[:500], account_id),
        )


def log_scraper_task(
    account_id: int,
    status: str,
    transactions_found: int,
    transactions_matched: int,
    duration_seconds: float,
    error: Optional[str] = None,
) -> None:
    """Insert a row into scraper_tasks to record this scrape run.

    Args:
        account_id: bank_accounts.id
        status: ``'completed'`` or ``'failed'``
        transactions_found: Number of transactions extracted from the bank.
        transactions_matched: Number matched to payment_links by the API.
        duration_seconds: Wall-clock seconds the scrape took.
        error: Error message for failed runs.
    """
    now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    ph = '?' if DB_DRIVER == 'sqlite' else '%s'

    _execute(
        f"""
        INSERT INTO scraper_tasks
            (bank_account_id, status, last_run_at, run_count,
             transactions_found, transactions_matched,
             duration_seconds, error_message, created_at, updated_at)
        VALUES
            ({ph}, {ph}, {ph}, 1,
             {ph}, {ph},
             {ph}, {ph}, {ph}, {ph})
        """,
        (
            account_id,
            status,
            now_str,
            transactions_found,
            transactions_matched,
            round(duration_seconds, 3),
            (error or '')[:1000] if error else None,
            now_str,
            now_str,
        ),
    )


# ── Scheduling helpers ────────────────────────────────────────────────────────

def is_due_for_scrape(account_row: dict) -> bool:
    """Return True if enough time has elapsed since the last scrape.

    Accounts that have never been scraped (``scrape_last_at`` is NULL) are
    always considered due.
    """
    last_str = account_row.get('scrape_last_at')
    interval = int(account_row.get('scrape_interval_minutes') or DEFAULT_SCRAPE_INTERVAL_MINUTES)

    if not last_str:
        return True

    # Handle both datetime objects (pymysql) and ISO strings (sqlite3).
    if isinstance(last_str, datetime):
        last = last_str
    else:
        for fmt in ('%Y-%m-%d %H:%M:%S', '%Y-%m-%dT%H:%M:%S'):
            try:
                last = datetime.strptime(last_str, fmt)
                break
            except ValueError:
                continue
        else:
            return True  # Unparseable — treat as due.

    return datetime.now() >= last + timedelta(minutes=interval)


# ── Core scrape cycle ─────────────────────────────────────────────────────────

async def run_bank_scrape(account_row: dict, headless: bool = True) -> bool:
    """Execute one full scrape cycle for a single bank_accounts row.

    Steps:
        1. Parse scrape_credentials_json.
        2. Instantiate the adapter.
        3. Open a Playwright browser (with session persistence).
        4. Run login → navigate → extract.
        5. POST extracted deposits to the B-CashPay API.
        6. Update bank_accounts status and insert a scraper_tasks log row.

    Args:
        account_row: A dict from :func:`load_active_bank_accounts`.
        headless: Pass False to show the browser window (debugging).

    Returns:
        True if the cycle completed and the webhook call succeeded.
    """
    from .engine.browser import ScraperBrowser
    from .engine.webhook import WebhookSender

    account_id = int(account_row['id'])
    bank_name = account_row.get('bank_name', f'bank#{account_id}')
    adapter_key = (account_row.get('scrape_adapter_key') or '').strip()

    AdapterClass = ADAPTERS.get(adapter_key)
    if AdapterClass is None:
        msg = f'No adapter registered for key {adapter_key!r}'
        log.error(f'[{bank_name}] {msg}')
        update_bank_scrape_status(account_id, 'scrape_failed', msg)
        log_scraper_task(account_id, 'failed', 0, 0, 0.0, msg)
        return False

    # Parse credentials from the JSON column.
    try:
        creds_raw: dict = json.loads(account_row.get('scrape_credentials_json') or '{}')
    except json.JSONDecodeError as exc:
        msg = f'scrape_credentials_json is invalid JSON: {exc}'
        log.error(f'[{bank_name}] {msg}')
        update_bank_scrape_status(account_id, 'scrape_failed', msg)
        log_scraper_task(account_id, 'failed', 0, 0, 0.0, msg)
        return False

    from .engine.base_adapter import BankCredentials

    credentials = BankCredentials(
        username=creds_raw.get('username') or creds_raw.get('user_id', ''),
        password=creds_raw.get('password', ''),
        totp_secret=creds_raw.get('totp_secret') or None,
        extra={
            k: v
            for k, v in creds_raw.items()
            if k not in ('username', 'user_id', 'password', 'totp_secret')
        },
    )

    adapter = AdapterClass(
        bank_id=account_id,
        bank_name=bank_name,
        credentials=credentials,
    )

    # Allow DB login_url to override the class-level default.
    db_login_url = account_row.get('scrape_login_url')
    if db_login_url:
        adapter.login_url = db_login_url
        log.info(f'[{bank_name}] using DB login_url: {db_login_url}')

    start_time = time.monotonic()
    transactions: list = []
    screenshot_path: Optional[str] = None

    try:
        browser = ScraperBrowser(bank_id=account_id, headless=headless)
        async with browser as page:
            try:
                log.info(f'[{bank_name}] starting login')
                await adapter.login(page)

                log.info(f'[{bank_name}] navigating to transactions')
                await adapter.navigate_to_transactions(page)

                log.info(f'[{bank_name}] extracting transactions')
                transactions = await adapter.extract_transactions(page)
                log.info(f'[{bank_name}] extracted {len(transactions)} transactions')

            except Exception:
                screenshot_path = await browser.screenshot(page, label='error')
                log.error(f'[{bank_name}] scrape error — screenshot: {screenshot_path}')
                raise

            finally:
                try:
                    await adapter.logout(page)
                except Exception:
                    pass

    except Exception as exc:
        duration = time.monotonic() - start_time
        err_msg = f'{type(exc).__name__}: {exc}'
        log.exception(f'[{bank_name}] scrape failed: {err_msg}')

        # Distinguish login failures from other scrape failures.
        status = 'login_failed' if 'login' in err_msg.lower() else 'scrape_failed'
        update_bank_scrape_status(account_id, status, err_msg[:500])
        log_scraper_task(account_id, 'failed', 0, 0, duration, err_msg[:500])
        return False

    # Deliver deposits to the B-CashPay API.
    webhook = WebhookSender(
        api_url=BCASHPAY_API_URL,
        scraper_secret=BCASHPAY_SCRAPER_SECRET,
    )
    matched = await webhook.send_deposits(
        bank_account_id=account_id,
        transactions=transactions,
        current_balance=adapter.current_balance,
    )

    duration = time.monotonic() - start_time

    # Report overall status via the status endpoint (best-effort).
    await webhook.send_status(
        bank_account_id=account_id,
        status='success',
        message=f'Extracted {len(transactions)}, matched {matched}',
        stats={
            'transactions_found': len(transactions),
            'transactions_matched': matched,
            'duration_seconds': round(duration, 3),
        },
    )

    update_bank_scrape_status(account_id, 'active')
    log_scraper_task(
        account_id,
        status='completed',
        transactions_found=len(transactions),
        transactions_matched=matched,
        duration_seconds=duration,
    )

    log.info(
        f'[{bank_name}] done — found={len(transactions)}'
        f' matched={matched} duration={duration:.1f}s'
    )
    return True


# ── Main loop ─────────────────────────────────────────────────────────────────

async def main_loop(poll_interval_seconds: int = POLL_INTERVAL_SECONDS) -> None:
    """Continuous loop: check due accounts every *poll_interval_seconds* seconds.

    An account is scraped when:
      - It has at least one pending payment_link.
      - Enough time has elapsed since its last scrape (per scrape_interval_minutes).

    Both conditions must be true to prevent unnecessary browser launches.
    """
    log.info(f'B-CashPay scraper runner started (poll every {poll_interval_seconds}s)')

    while True:
        try:
            accounts = load_active_bank_accounts()
            log.info(f'Loaded {len(accounts)} active bank accounts')

            for account in accounts:
                account_id = int(account['id'])
                bank_name = account.get('bank_name', f'bank#{account_id}')

                pending = count_pending_payments_for_account(account_id)
                if pending == 0:
                    log.debug(f'[{bank_name}] no pending payments — skipping')
                    continue

                if not is_due_for_scrape(account):
                    log.debug(f'[{bank_name}] not yet due — skipping')
                    continue

                log.info(f'[{bank_name}] due — pending={pending}')
                await run_bank_scrape(account)

        except Exception as exc:
            log.exception(f'Main loop error: {exc}')

        await asyncio.sleep(poll_interval_seconds)


# ── CLI entry point ───────────────────────────────────────────────────────────

def main() -> int:
    """Parse CLI arguments and start the runner.

    Flags:
        --once         Run a single scheduling cycle and exit.
        --bank KEY     Only process accounts with this scrape_adapter_key.
        --no-headless  Show the browser window (useful for debugging selectors).
        --account-id N Only process the account with this DB id.
    """
    parser = argparse.ArgumentParser(description='B-CashPay Bank Scraper Runner')
    parser.add_argument('--once', action='store_true', help='Run one cycle and exit')
    parser.add_argument(
        '--bank',
        metavar='KEY',
        help='Only process accounts with this scrape_adapter_key (e.g. rakuten)',
    )
    parser.add_argument(
        '--no-headless',
        action='store_true',
        help='Show browser window for debugging',
    )
    parser.add_argument(
        '--account-id',
        type=int,
        metavar='N',
        dest='account_id',
        help='Only process the bank_accounts row with this id',
    )
    args = parser.parse_args()

    headless = not args.no_headless

    if args.once or args.bank or args.account_id:
        accounts = load_active_bank_accounts()

        if args.bank:
            accounts = [a for a in accounts if a.get('scrape_adapter_key') == args.bank]

        if args.account_id:
            accounts = [a for a in accounts if int(a['id']) == args.account_id]

        if not accounts:
            log.error('No matching bank accounts found')
            return 1

        async def _run_once() -> None:
            for account in accounts:
                await run_bank_scrape(account, headless=headless)

        asyncio.run(_run_once())
        return 0

    # Continuous mode.
    asyncio.run(main_loop())
    return 0


if __name__ == '__main__':
    sys.exit(main())
