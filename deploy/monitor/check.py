#!/usr/bin/env python3
"""B-Pay health monitor — runs periodically to detect failures and alert via Telegram.

Triggered by systemd timers (see bpay-monitor.timer) every 5 minutes for the
critical checks, and via bpay-alert@.service for immediate OnFailure alerts.

State is persisted to ``/var/lib/bpay-monitor/state.json`` so we alert on
transitions (OK -> FAIL, FAIL -> OK) and re-alert at most once per hour while
a check remains failing.  This keeps noise low while ensuring nobody misses
the initial event.

Usage:
    check.py                    # run the scheduled check cycle
    check.py --alert-failure <unit>   # immediate page for systemd OnFailure
    check.py --test             # send a test Telegram and exit

All config is read from /opt/bpay/api/.env (DB credentials + Telegram).
"""
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Callable

# ── Paths ────────────────────────────────────────────────────────────────────
STATE_FILE = Path("/var/lib/bpay-monitor/state.json")
LOG_FILE = Path("/var/log/bpay/monitor.log")
ENV_FILE = Path("/opt/bpay/api/.env")

# ── Policy ───────────────────────────────────────────────────────────────────
# Re-alert at most once per this interval while still failing (avoid spam).
REPEAT_SECONDS = 3600
# Scrape freshness threshold — 4h safely accommodates IDLE_SCRAPE_INTERVAL (180min).
SCRAPE_STALE_HOURS = 4
# Expired-but-still-pending threshold (indicates cleanup job is not running).
EXPIRED_PENDING_THRESHOLD = 10


# ── Config ───────────────────────────────────────────────────────────────────
def load_env() -> dict[str, str]:
    env: dict[str, str] = {}
    if not ENV_FILE.exists():
        return env
    for line in ENV_FILE.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        env[k.strip()] = v.strip().strip('"').strip("'")
    return env


ENV = load_env()
BOT_TOKEN = ENV.get("TELEGRAM_BOT_TOKEN", "")
CHAT_IDS = [c.strip() for c in ENV.get("TELEGRAM_CHAT_IDS", "").split(",") if c.strip()]
DB_USER = ENV.get("DB_USERNAME", "bpay")
DB_PASS = ENV.get("DB_PASSWORD", "")
DB_NAME = ENV.get("DB_DATABASE", "bpay")


# ── Logging / Notifications ──────────────────────────────────────────────────
def log(msg: str) -> None:
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    try:
        with LOG_FILE.open("a") as fh:
            fh.write(f"{datetime.now().isoformat(timespec='seconds')} {msg}\n")
    except OSError:
        pass
    print(msg, file=sys.stderr)


def send_telegram(text: str) -> None:
    if not BOT_TOKEN or not CHAT_IDS:
        log(f"telegram skip (no token/chat): {text[:80]}")
        return
    for chat_id in CHAT_IDS:
        body = urllib.parse.urlencode(
            {
                "chat_id": chat_id,
                "text": text,
                "parse_mode": "HTML",
                "disable_web_page_preview": "true",
            }
        ).encode("utf-8")
        req = urllib.request.Request(
            f"https://api.telegram.org/bot{BOT_TOKEN}/sendMessage",
            data=body,
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                resp.read()
        except (urllib.error.URLError, TimeoutError) as exc:
            log(f"telegram error chat={chat_id}: {exc}")


# ── Probe helpers ────────────────────────────────────────────────────────────
def svc_active(unit: str) -> bool:
    r = subprocess.run(
        ["systemctl", "is-active", "--quiet", unit], timeout=10, check=False
    )
    return r.returncode == 0


def http_ok(url: str) -> bool:
    try:
        with urllib.request.urlopen(url, timeout=10) as resp:
            return 200 <= resp.status < 300
    except Exception:
        return False


def sql_scalar(query: str) -> str:
    """Run a scalar SQL query and return the first column of the first row as str."""
    r = subprocess.run(
        ["mysql", f"-u{DB_USER}", f"-p{DB_PASS}", DB_NAME, "-N", "-s", "-e", query],
        capture_output=True,
        text=True,
        timeout=15,
        check=False,
    )
    if r.returncode != 0:
        # Hide password from stderr in logs
        stderr = (r.stderr or "").replace(DB_PASS, "***") if DB_PASS else (r.stderr or "")
        log(f"sql error: {stderr.strip()[:200]}")
        return ""
    return (r.stdout or "").strip().split("\n")[0].strip()


def disk_percent(path: str = "/") -> int:
    try:
        r = subprocess.run(
            ["df", "-P", path],
            capture_output=True,
            text=True,
            timeout=10,
            check=True,
        )
        lines = r.stdout.strip().splitlines()
        if len(lines) >= 2:
            return int(lines[1].split()[4].rstrip("%"))
    except Exception:
        pass
    return 0


# ── Check definitions ────────────────────────────────────────────────────────
# Shared failure detail string — the systemctl probe already narrows the cause
# down to "unit not active" (the monitor calls `systemctl is-active`), so a
# single common wording beats five copies of the same literal.
SVC_INACTIVE_DETAIL = "service is not active"

@dataclass(frozen=True)
class Check:
    key: str
    label: str
    tier: str  # 'S' critical, 'A' operational, 'B' capacity
    probe: Callable[[], bool]
    failure_detail: Callable[[], str]


def _detail_scrape_fail() -> str:
    row = sql_scalar(
        "SELECT GROUP_CONCAT(bank_name, ' (failures=', scrape_consecutive_failures, ')' SEPARATOR ', ') "
        "FROM bank_accounts WHERE is_active=1 AND scrape_consecutive_failures >= 3"
    )
    return row or "unknown"


def _detail_scrape_stale() -> str:
    row = sql_scalar(
        "SELECT GROUP_CONCAT(bank_name, ' (last_success=', "
        "IFNULL(scrape_last_success_at,'never'), ')' SEPARATOR ', ') "
        "FROM bank_accounts WHERE is_active=1 AND "
        "(scrape_last_success_at IS NULL OR scrape_last_success_at < "
        f"DATE_SUB(NOW(), INTERVAL {SCRAPE_STALE_HOURS} HOUR))"
    )
    return row or "unknown"


def _detail_expired_pending() -> str:
    cnt = sql_scalar(
        "SELECT COUNT(*) FROM payment_links WHERE status='pending' "
        "AND link_type!='template' AND expires_at < NOW()"
    )
    return f"{cnt} links"


def _detail_disk() -> str:
    return f"root disk at {disk_percent('/')}%"


CHECKS: list[Check] = [
    Check(
        "svc_scraper",
        "runner (bpay-scraper.service)",
        "S",
        lambda: svc_active("bpay-scraper.service"),
        lambda: SVC_INACTIVE_DETAIL,
    ),
    Check(
        "svc_api",
        "scraper control API (bpay-scraper-api.service)",
        "S",
        lambda: svc_active("bpay-scraper-api.service"),
        lambda: SVC_INACTIVE_DETAIL,
    ),
    Check(
        "svc_mysql",
        "MySQL",
        "S",
        lambda: svc_active("mysql"),
        lambda: SVC_INACTIVE_DETAIL,
    ),
    Check(
        "svc_nginx",
        "nginx",
        "S",
        lambda: svc_active("nginx"),
        lambda: SVC_INACTIVE_DETAIL,
    ),
    Check(
        "svc_phpfpm",
        "php8.1-fpm",
        "S",
        lambda: svc_active("php8.1-fpm"),
        lambda: SVC_INACTIVE_DETAIL,
    ),
    Check(
        "http_health",
        "API /api/v1/health",
        "S",
        lambda: http_ok("https://b-pay.ink/api/v1/health"),
        lambda: "endpoint returned non-2xx or timed out",
    ),
    Check(
        "scrape_fail",
        "scrape_consecutive_failures < 3",
        "A",
        lambda: sql_scalar(
            "SELECT COUNT(*) FROM bank_accounts WHERE is_active=1 "
            "AND scrape_consecutive_failures >= 3"
        )
        == "0",
        _detail_scrape_fail,
    ),
    Check(
        "scrape_stale",
        f"scrape success within {SCRAPE_STALE_HOURS}h",
        "A",
        lambda: sql_scalar(
            "SELECT COUNT(*) FROM bank_accounts WHERE is_active=1 "
            "AND (scrape_last_success_at IS NULL OR scrape_last_success_at < "
            f"DATE_SUB(NOW(), INTERVAL {SCRAPE_STALE_HOURS} HOUR))"
        )
        == "0",
        _detail_scrape_stale,
    ),
    Check(
        "expired_pending",
        f"expired pending links < {EXPIRED_PENDING_THRESHOLD}",
        "A",
        lambda: (
            int(
                sql_scalar(
                    "SELECT COUNT(*) FROM payment_links WHERE status='pending' "
                    "AND link_type!='template' AND expires_at < NOW()"
                )
                or "0"
            )
            < EXPIRED_PENDING_THRESHOLD
        ),
        _detail_expired_pending,
    ),
    Check(
        "disk",
        "root disk < 80%",
        "B",
        lambda: disk_percent("/") < 80,
        _detail_disk,
    ),
]


# ── State store ──────────────────────────────────────────────────────────────
def load_state() -> dict[str, dict]:
    if not STATE_FILE.exists():
        return {}
    try:
        return json.loads(STATE_FILE.read_text())
    except (json.JSONDecodeError, OSError):
        return {}


def save_state(state: dict[str, dict]) -> None:
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    tmp = STATE_FILE.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(state, indent=2))
    tmp.replace(STATE_FILE)


# ── Main cycle ───────────────────────────────────────────────────────────────
def _probe(check: Check) -> bool:
    try:
        return bool(check.probe())
    except Exception as exc:
        log(f"probe error for {check.key}: {exc}")
        return False


def _safe_detail(check: Check) -> str:
    try:
        return check.failure_detail()
    except Exception as exc:
        return f"(detail error: {exc})"


def _alert_new_failure(check: Check, state: dict, now: int) -> None:
    send_telegram(
        f"🔴 <b>[{check.tier}] {check.label}</b> — 異常検知\n{_safe_detail(check)}"
    )
    state[check.key] = {"failing": True, "last_alert_ts": now}


def _alert_repeat_failure(check: Check, state: dict, now: int) -> None:
    send_telegram(
        f"🔴 <b>[{check.tier}] {check.label}</b> — まだ異常（継続中）\n{_safe_detail(check)}"
    )
    state[check.key]["last_alert_ts"] = now


def _alert_recovery(check: Check, state: dict, now: int) -> None:
    send_telegram(f"✅ <b>[{check.tier}] {check.label}</b> — 復旧しました")
    state[check.key] = {"failing": False, "last_alert_ts": now}


def _process_check(check: Check, state: dict, now: int) -> None:
    ok = _probe(check)
    prev = state.get(check.key, {})
    was_failing = bool(prev.get("failing", False))
    last_alert = int(prev.get("last_alert_ts", 0))

    if ok and was_failing:
        _alert_recovery(check, state, now)
        return
    if ok:
        # Healthy — preserve any prior alert timestamp (no-op from user view).
        state[check.key] = {
            "failing": False,
            "last_alert_ts": prev.get("last_alert_ts", 0),
        }
        return
    if not was_failing:
        _alert_new_failure(check, state, now)
        return
    if now - last_alert >= REPEAT_SECONDS:
        _alert_repeat_failure(check, state, now)
    # else: still failing but within suppression window — no-op


def run_cycle() -> int:
    """Run all checks, alert on transitions, and persist state.

    Always returns 0 — failed checks are reported via Telegram + state file,
    not via exit code.  Returning non-zero would mark the systemd service as
    "failed" on every cycle where anything is wrong, cluttering
    ``systemctl --failed`` and preventing OnFailure hooks (if later added to
    the monitor itself) from distinguishing "monitor is broken" from
    "something the monitor is watching is broken".
    """
    state = load_state()
    now = int(time.time())

    for check in CHECKS:
        _process_check(check, state, now)

    save_state(state)
    return 0


def alert_unit_failure(unit: str) -> int:
    """Immediate page for systemd OnFailure hook — bypasses state/throttling."""
    send_telegram(
        f"🚨 <b>systemd OnFailure</b> — <code>{unit}</code> が異常終了しました。"
        f"直近ログを確認してください: <code>journalctl -u {unit} -n 50 --no-pager</code>"
    )
    log(f"OnFailure alert sent for {unit}")
    return 0


def send_test() -> int:
    send_telegram(
        "🟢 <b>B-Pay monitor test</b> — 手動テスト送信です。"
        f"\nchat_ids: <code>{', '.join(CHAT_IDS)}</code>"
    )
    return 0


# ── Entrypoint ───────────────────────────────────────────────────────────────
def main() -> int:
    parser = argparse.ArgumentParser(description="B-Pay health monitor")
    parser.add_argument(
        "--alert-failure",
        metavar="UNIT",
        help="systemd OnFailure hook — immediate page for the given unit name",
    )
    parser.add_argument(
        "--test",
        action="store_true",
        help="Send a test Telegram message and exit",
    )
    args = parser.parse_args()

    if args.test:
        return send_test()
    if args.alert_failure:
        return alert_unit_failure(args.alert_failure)
    return run_cycle()


if __name__ == "__main__":
    sys.exit(main())
