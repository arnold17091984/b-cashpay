// B-Pay PM2 Ecosystem Configuration
// Manages the Python scraper processes

module.exports = {
  apps: [
    {
      // Bank scraper runner — continuous loop checking for banks due for scraping
      name: 'bcashpay-scraper',
      cwd: '/opt/bcashpay/scraper',
      script: '/opt/bcashpay/scraper/venv/bin/python',
      args: '-m bcashpay_scraper.runner',
      interpreter: 'none',
      env: {
        PYTHONUNBUFFERED: '1',
      },
      // Restart on crash with exponential backoff
      autorestart: true,
      max_restarts: 20,
      restart_delay: 5000,
      exp_backoff_restart_delay: 100,
      // Logging
      log_file: '/var/log/bcashpay/scraper-combined.log',
      error_file: '/var/log/bcashpay/scraper-error.log',
      out_file: '/var/log/bcashpay/scraper-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      // Memory limit (restart if exceeded)
      max_memory_restart: '512M',
    },
    {
      // FastAPI control server for on-demand scrape triggers
      name: 'bcashpay-scraper-api',
      cwd: '/opt/bcashpay/scraper',
      script: '/opt/bcashpay/scraper/venv/bin/python',
      args: '-m bcashpay_scraper.server',
      interpreter: 'none',
      env: {
        PYTHONUNBUFFERED: '1',
      },
      autorestart: true,
      max_restarts: 10,
      restart_delay: 3000,
      log_file: '/var/log/bcashpay/scraper-api-combined.log',
      error_file: '/var/log/bcashpay/scraper-api-error.log',
      out_file: '/var/log/bcashpay/scraper-api-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      max_memory_restart: '256M',
    },
  ],
};
