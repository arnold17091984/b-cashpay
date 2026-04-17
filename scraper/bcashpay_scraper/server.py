"""
B-CashPay Scraper Control Server — FastAPI HTTP server.

Provides endpoints for on-demand scrape triggers, status checks,
and health monitoring. Runs alongside the runner loop (as a separate
PM2 process) so the admin UI can poke individual bank accounts without
waiting for the scheduled interval.

Endpoints:
    GET  /health                    — Server health check
    GET  /status                    — Current scraper status for all accounts
    POST /scrape/{account_id}       — Trigger an immediate scrape for one bank
    GET  /logs/{account_id}?limit=N — Recent scraper_tasks for an account
    GET  /tasks                     — Pending/running tasks snapshot

Authentication:
    All endpoints except /health require header:
        Authorization: Bearer ${SCRAPER_API_TOKEN}

Usage:
    # Dev
    python3 -m bcashpay_scraper.server

    # Prod (PM2)
    uvicorn bcashpay_scraper.server:app --host 127.0.0.1 --port 8020
"""
from __future__ import annotations

import asyncio
import logging
import os
from typing import Optional

from fastapi import FastAPI, Header, HTTPException, Query
from pydantic import BaseModel
from dotenv import load_dotenv

from . import runner

# ─── Configuration ──────────────────────────────────────────────────
load_dotenv()

SCRAPER_API_TOKEN = os.environ.get('SCRAPER_API_TOKEN', '')
SCRAPER_API_PORT = int(os.environ.get('SCRAPER_API_PORT', '8020'))

log = logging.getLogger('bcashpay_scraper.server')
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(name)s: %(message)s',
)

app = FastAPI(title='B-CashPay Scraper Control', version='1.0')


# ─── Auth helper ─────────────────────────────────────────────────────
def _require_auth(authorization: Optional[str]) -> None:
    """Verify Bearer token from Authorization header."""
    if not SCRAPER_API_TOKEN:
        raise HTTPException(
            status_code=500,
            detail='SCRAPER_API_TOKEN not configured on server',
        )
    if not authorization or not authorization.startswith('Bearer '):
        raise HTTPException(status_code=401, detail='Missing Bearer token')
    token = authorization[len('Bearer '):].strip()
    if token != SCRAPER_API_TOKEN:
        raise HTTPException(status_code=401, detail='Invalid token')


# ─── Response models ────────────────────────────────────────────────
class HealthResponse(BaseModel):
    status: str
    version: str = '1.0'


class AccountStatus(BaseModel):
    id: int
    bank_name: str
    account_number: str
    scrape_status: str
    scrape_last_at: Optional[str]
    scrape_last_success_at: Optional[str]
    scrape_consecutive_failures: int
    pending_payments: int


class TaskLog(BaseModel):
    id: int
    bank_account_id: int
    status: str
    last_run_at: Optional[str]
    transactions_found: int
    transactions_matched: int
    duration_seconds: Optional[float]
    error_message: Optional[str]
    created_at: str


class ScrapeResult(BaseModel):
    success: bool
    bank_account_id: int
    message: str
    transactions_found: Optional[int] = None
    transactions_matched: Optional[int] = None


# ─── Endpoints ──────────────────────────────────────────────────────
@app.get('/health', response_model=HealthResponse)
async def health() -> HealthResponse:
    """Unauthenticated health check."""
    return HealthResponse(status='ok')


@app.get('/status')
async def status(authorization: Optional[str] = Header(None)) -> list[AccountStatus]:
    """Return scrape status for all active bank accounts."""
    _require_auth(authorization)
    accounts = runner.load_active_bank_accounts()
    out = []
    for a in accounts:
        out.append(AccountStatus(
            id=a['id'],
            bank_name=a.get('bank_name', ''),
            account_number=a.get('account_number', ''),
            scrape_status=a.get('scrape_status', 'unknown'),
            scrape_last_at=str(a['scrape_last_at']) if a.get('scrape_last_at') else None,
            scrape_last_success_at=str(a['scrape_last_success_at']) if a.get('scrape_last_success_at') else None,
            scrape_consecutive_failures=a.get('scrape_consecutive_failures', 0) or 0,
            pending_payments=runner.count_pending_payments_for_account(a['id']),
        ))
    return out


@app.post('/scrape/{account_id}', response_model=ScrapeResult)
async def trigger_scrape(
    account_id: int,
    authorization: Optional[str] = Header(None),
    headless: bool = Query(True, description='Run browser in headless mode'),
) -> ScrapeResult:
    """Trigger an immediate scrape for a specific account.

    Runs in a background task so the HTTP response returns quickly.
    The actual result is logged to scraper_tasks and visible via /logs.
    """
    _require_auth(authorization)

    accounts = runner.load_active_bank_accounts()
    account = next((a for a in accounts if a['id'] == account_id), None)
    if account is None:
        raise HTTPException(status_code=404, detail=f'Bank account {account_id} not found or inactive')

    log.info('manual scrape trigger: account_id=%s bank=%s', account_id, account.get('bank_name'))

    # Run scrape in background so the HTTP call returns quickly
    asyncio.create_task(runner.run_bank_scrape(account, headless=headless))

    return ScrapeResult(
        success=True,
        bank_account_id=account_id,
        message='Scrape triggered — check /logs/{account_id} for result',
    )


@app.get('/logs/{account_id}', response_model=list[TaskLog])
async def logs(
    account_id: int,
    limit: int = Query(20, ge=1, le=200),
    authorization: Optional[str] = Header(None),
) -> list[TaskLog]:
    """Return recent scraper_tasks rows for an account."""
    _require_auth(authorization)
    rows = runner.load_recent_tasks(account_id, limit)
    out = []
    for r in rows:
        out.append(TaskLog(
            id=r['id'],
            bank_account_id=r['bank_account_id'],
            status=r.get('status', ''),
            last_run_at=str(r['last_run_at']) if r.get('last_run_at') else None,
            transactions_found=r.get('transactions_found', 0) or 0,
            transactions_matched=r.get('transactions_matched', 0) or 0,
            duration_seconds=float(r['duration_seconds']) if r.get('duration_seconds') else None,
            error_message=r.get('error_message'),
            created_at=str(r.get('created_at', '')),
        ))
    return out


@app.get('/tasks')
async def all_tasks(
    status: Optional[str] = Query(None, description='Filter by status: queued|running|completed|failed'),
    limit: int = Query(50, ge=1, le=500),
    authorization: Optional[str] = Header(None),
) -> list[TaskLog]:
    """Return recent scraper_tasks across all accounts (for admin dashboard)."""
    _require_auth(authorization)
    rows = runner.load_all_recent_tasks(status=status, limit=limit)
    out = []
    for r in rows:
        out.append(TaskLog(
            id=r['id'],
            bank_account_id=r['bank_account_id'],
            status=r.get('status', ''),
            last_run_at=str(r['last_run_at']) if r.get('last_run_at') else None,
            transactions_found=r.get('transactions_found', 0) or 0,
            transactions_matched=r.get('transactions_matched', 0) or 0,
            duration_seconds=float(r['duration_seconds']) if r.get('duration_seconds') else None,
            error_message=r.get('error_message'),
            created_at=str(r.get('created_at', '')),
        ))
    return out


# ─── Entry point ────────────────────────────────────────────────────
def main() -> None:
    """Start uvicorn server."""
    import uvicorn
    uvicorn.run(
        'bcashpay_scraper.server:app',
        host='127.0.0.1',
        port=SCRAPER_API_PORT,
        log_level='info',
    )


if __name__ == '__main__':
    main()
