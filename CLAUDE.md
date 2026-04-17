# B-CashPay — Bank Transfer Payment Gateway

## Project Overview

B-CashPay is a standalone bank transfer payment gateway extracted from the LionExpressPay system. It provides a clean, API-driven way for e-commerce sites to accept bank transfer (furikomi) payments with automatic deposit matching via bank scraping.

**Core value proposition**: API client creates a payment link -> customer sees a payment page with bank details + reference number -> bank scraper detects the deposit -> system auto-matches and sends webhook callback.

## Architecture

```
┌─────────────┐     ┌──────────────┐     ┌─────────────────┐
│  EC-CUBE /   │────>│  B-CashPay   │────>│  Payment Page   │
│  Shopify /   │<────│  API (PHP)   │     │  (HTML/JS)      │
│  Any Client  │     └──────┬───────┘     └─────────────────┘
└─────────────┘            │
                           │ webhook
                    ┌──────┴───────┐
                    │   Bank       │
                    │   Scraper    │──── Playwright ──── Bank Website
                    │   (Python)   │
                    └──────────────┘
```

### Components

1. **api/** — PHP API server (slim, no full Laravel framework)
   - Creates payment links with unique reference numbers
   - Receives deposit data from scraper via webhook
   - Matches deposits to payment links by reference number + amount
   - Sends webhook callbacks to API clients when payment confirmed
   - Sends Telegram notifications for key events

2. **scraper/** — Python bank scraper
   - Playwright-based headless browser automation
   - Adapter pattern: one adapter per bank (Rakuten Bank first)
   - Runs on configurable intervals (15-20 min default)
   - Reports detected deposits to the API via webhook
   - FastAPI control server for on-demand scrape triggers

3. **pay/** — Customer-facing payment pages
   - Static HTML templates rendered by the API
   - Shows bank details, amount, reference number with copy buttons
   - Status polling to show "confirmed" when payment is matched
   - Responsive, mobile-first design

4. **admin/** — Admin dashboard (Phase 3, Next.js)
   - Payment link management
   - Bank account configuration
   - Scraper monitoring
   - Transaction history

## Key Design Decisions

### From LionExpressPay (reference in `_reference/`)
- **Reference number system**: 7-digit numeric reference (1000000-9999999) prepended to depositor name for matching. Avoids full-width/half-width confusion.
- **Bank adapter pattern**: Abstract base class with login/navigate/extract/logout lifecycle. Each bank gets its own adapter.
- **Webhook sender**: Scraper sends deposits to API via authenticated webhook, not direct DB write. Clean separation of concerns.
- **HMAC authentication**: API clients authenticate with HMAC-SHA256 signatures over request body.

### New in B-CashPay (different from LionExpressPay)
- **ULID payment link IDs**: URL-safe, sortable, no auto-increment exposure.
- **Token-based payment pages**: Each payment link has a unique 32-char token for the customer URL. No encryption needed.
- **Simplified schema**: No merchant/user/role system. Just api_clients, bank_accounts, and payment_links.
- **Rakuten Bank first**: Starting with Rakuten Bank adapter instead of GMO Aozora.
- **FastAPI control server**: Exposes endpoints to trigger on-demand scrapes, check status, etc.

## Database

MySQL 8.0+ with 7 tables:
- `api_clients` — integrated EC sites
- `bank_accounts` — physical bank accounts with scraper config
- `payment_links` — individual payment requests (ULID PK)
- `deposits` — raw deposit transactions from scraper
- `scraper_tasks` — scraping run history
- `webhook_logs` — callback delivery audit trail
- `telegram_logs` — notification audit trail

Schema: `api/database/schema.sql`

## API Endpoints (Planned)

### Client API (api_key auth)
- `POST /v1/payment-links` — Create a payment link
- `GET /v1/payment-links/{id}` — Get payment link status
- `POST /v1/payment-links/{id}/cancel` — Cancel a payment link

### Payment Page
- `GET /pay/{token}` — Customer-facing payment page
- `GET /pay/{token}/status` — JSON status for polling

### Scraper Webhook (scraper token auth)
- `POST /v1/scraper/deposits` — Report detected deposits
- `POST /v1/scraper/status` — Report scraper run status

### Internal
- `GET /v1/health` — Health check

## Reference Files

`_reference/` contains source files downloaded from the LionExpressPay VPS for design reference:

- `lionexpresspay-api/` — Laravel API (EcCubeController, SelfCardDepositService, BankScraperController, etc.)
- `lion-scraper/` — Python bank scraper (runner, browser engine, GMO Aozora adapter, webhook sender)

These are READ-ONLY reference. Do not modify them.

## Development

### API (PHP)
```bash
cd api
cp .env.example .env  # edit with your values
# Import schema
mysql -u root bcashpay < database/schema.sql
# Start dev server
php -S localhost:8000 -t public/
```

### Scraper (Python)
```bash
cd scraper
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env  # edit with your values
playwright install chromium
# Run once
python -m bcashpay_scraper.runner --once
# Or start control server
python -m bcashpay_scraper.server
```

## Deployment

Target: VPS (Ubuntu 22.04 + Nginx + PHP 8.1+ + MySQL 8.0 + Python 3.11+)

- API served via Nginx + PHP-FPM
- Scraper managed by PM2 (both runner loop and FastAPI server)
- See `deploy/` for Nginx config, PM2 ecosystem, and setup script

## Security Notes

- API keys and webhook secrets must be generated with cryptographically secure random bytes
- Bank credentials in `bank_accounts.scrape_credentials_json` must be encrypted at rest
- Payment page tokens are opaque random strings (no encrypted data)
- All webhook callbacks are signed with HMAC-SHA256
- Scraper-to-API communication uses static bearer token
- No secrets in source code — all via .env files
