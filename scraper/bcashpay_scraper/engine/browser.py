"""
Playwright browser management — stealth, session persistence, screenshots.

ScraperBrowser is an async context manager that:
    - Launches Chromium with anti-bot-detection flags
    - Restores session cookies / localStorage from the previous run
    - Saves session state on exit so the next run can skip re-login
    - Captures full-page screenshots for debugging

Usage:
    async with ScraperBrowser(bank_id=1, headless=True) as page:
        await page.goto('https://...')
        # ... scrape logic
"""
import os
from datetime import datetime
from pathlib import Path
from typing import Optional

from playwright.async_api import async_playwright, Browser, BrowserContext, Page


# Directories — overridable via environment variables.
SESSION_DIR = Path(os.environ.get('SCRAPER_SESSION_DIR', '/tmp/bcashpay-scraper-sessions'))
SCREENSHOT_DIR = Path(os.environ.get('SCRAPER_SCREENSHOT_DIR', '/tmp/bcashpay-scraper-screenshots'))

DEFAULT_UA = (
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
    'AppleWebKit/537.36 (KHTML, like Gecko) '
    'Chrome/131.0.0.0 Safari/537.36'
)

# Injected into every new page to hide Playwright / webdriver fingerprints.
_STEALTH_SCRIPT = """
Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
Object.defineProperty(navigator, 'languages', { get: () => ['ja-JP', 'ja', 'en'] });
Object.defineProperty(navigator, 'plugins',   { get: () => [1, 2, 3, 4, 5] });
"""


class ScraperBrowser:
    """Manages a Playwright Chromium instance with persistent session per bank.

    Session state (cookies, localStorage) is stored in
    ``{SESSION_DIR}/bank_{bank_id}.json`` and reloaded on the next run so
    authenticated sessions survive between scrape cycles.

    Args:
        bank_id: The bank_accounts.id value; used to namespace session files.
        headless: Whether to run Chromium in headless mode.  Defaults to
            False because bank login pages (Rakuten in particular) detect
            headless Chromium and refuse to render the login form.  On
            servers without a display, the systemd wrapper runs the runner
            under xvfb-run so a "visible" browser still works.
    """

    def __init__(self, bank_id: int, headless: bool = False) -> None:
        self.bank_id = bank_id
        self.headless = headless

        SESSION_DIR.mkdir(parents=True, exist_ok=True)
        SCREENSHOT_DIR.mkdir(parents=True, exist_ok=True)

        self._session_path = SESSION_DIR / f'bank_{bank_id}.json'
        self._playwright = None
        self._browser: Optional[Browser] = None
        self._context: Optional[BrowserContext] = None
        self._page: Optional[Page] = None

    async def __aenter__(self) -> Page:
        self._playwright = await async_playwright().start()

        self._browser = await self._playwright.chromium.launch(
            headless=self.headless,
            args=[
                '--disable-blink-features=AutomationControlled',
                '--disable-features=IsolateOrigins,site-per-process',
                '--no-sandbox',
                '--disable-dev-shm-usage',
            ],
        )

        context_kwargs: dict = dict(
            user_agent=DEFAULT_UA,
            viewport={'width': 1366, 'height': 900},
            locale='ja-JP',
            timezone_id='Asia/Tokyo',
            extra_http_headers={'Accept-Language': 'ja-JP,ja;q=0.9,en;q=0.8'},
        )

        # Restore previous session if it exists.
        if self._session_path.exists():
            context_kwargs['storage_state'] = str(self._session_path)

        self._context = await self._browser.new_context(**context_kwargs)

        # Inject stealth script into every new page opened by this context.
        await self._context.add_init_script(_STEALTH_SCRIPT)

        self._page = await self._context.new_page()
        self._page.set_default_timeout(int(os.environ.get('BROWSER_TIMEOUT_MS', '30000')))

        return self._page

    async def __aexit__(self, exc_type, exc_val, exc_tb) -> None:
        await self.save_session()

        if self._context is not None:
            await self._context.close()
        if self._browser is not None:
            await self._browser.close()
        if self._playwright is not None:
            await self._playwright.stop()

    async def save_session(self) -> None:
        """Persist session cookies and storage to disk for the next run."""
        if self._context is not None:
            try:
                await self._context.storage_state(path=str(self._session_path))
            except Exception:
                pass  # Non-fatal — session will be rebuilt on next run.

    async def screenshot(self, page: Optional[Page] = None, label: str = 'debug') -> str:
        """Capture a full-page screenshot and return the file path.

        Args:
            page: The Playwright Page to screenshot.  Defaults to the page
                  created by this context manager.
            label: Short label appended to the filename (e.g. 'error').

        Returns:
            Absolute path to the saved PNG file.
        """
        target = page or self._page
        if target is None:
            return ''

        ts = datetime.now().strftime('%Y%m%d_%H%M%S')
        path = SCREENSHOT_DIR / f'bank_{self.bank_id}_{label}_{ts}.png'

        try:
            await target.screenshot(path=str(path), full_page=True)
        except Exception:
            pass  # Best-effort — don't crash the caller.

        return str(path)

    def clear_session(self) -> None:
        """Delete the saved session file so the next run starts fresh."""
        if self._session_path.exists():
            self._session_path.unlink()
