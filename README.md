# B-CashPay

Bank transfer payment gateway for Japanese e-commerce. Accepts bank transfers (furikomi) with automatic deposit detection and matching via bank scraping.

## Quick Start

See [CLAUDE.md](./CLAUDE.md) for full architecture documentation.

```bash
# API
cd api && cp .env.example .env && php -S localhost:8000 -t public/

# Scraper
cd scraper && python3 -m venv venv && source venv/bin/activate
pip install -r requirements.txt && playwright install chromium
cp .env.example .env
python -m bcashpay_scraper.runner --once
```

## Components

| Directory | Language | Purpose |
|-----------|----------|---------|
| `api/` | PHP | REST API + payment page rendering |
| `scraper/` | Python | Bank transaction scraper (Playwright) |
| `pay/` | HTML/CSS/JS | Payment page assets |
| `admin/` | Next.js | Admin dashboard (Phase 3) |
| `deploy/` | Shell/Nginx | Deployment configuration |

## License

Proprietary. All rights reserved.
