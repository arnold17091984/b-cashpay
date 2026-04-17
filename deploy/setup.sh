#!/bin/bash
# B-CashPay Server Setup Script
# Target: Ubuntu 22.04 VPS with Nginx, PHP 8.1+, MySQL 8.0, Python 3.11+
#
# Usage:
#   sudo bash setup.sh
#
# This script:
#   1. Creates the bcashpay system user
#   2. Sets up directory structure
#   3. Installs PHP dependencies (Composer)
#   4. Sets up Python virtual environment
#   5. Installs Playwright + Chromium
#   6. Creates the MySQL database
#   7. Links Nginx config
#   8. Sets up PM2 processes
#   9. Creates log directories

set -euo pipefail

INSTALL_DIR="/opt/bcashpay"
LOG_DIR="/var/log/bcashpay"
DB_NAME="bcashpay"
DB_USER="bcashpay"

echo "=== B-CashPay Server Setup ==="

# 1. Create system user (if not exists)
if ! id -u bcashpay &>/dev/null; then
    useradd --system --home-dir "$INSTALL_DIR" --shell /bin/bash bcashpay
    echo "Created bcashpay user"
fi

# 2. Create directories
mkdir -p "$INSTALL_DIR"/{api,scraper,pay}
mkdir -p "$LOG_DIR"
mkdir -p "$INSTALL_DIR/scraper/screenshots"
mkdir -p "$INSTALL_DIR/scraper/sessions"
chown -R bcashpay:bcashpay "$INSTALL_DIR" "$LOG_DIR"
echo "Directories created"

# 3. Deploy application files
# (Assume files are already copied to $INSTALL_DIR)

# 4. PHP setup
if [ -f "$INSTALL_DIR/api/composer.json" ]; then
    cd "$INSTALL_DIR/api"
    sudo -u bcashpay composer install --no-dev --optimize-autoloader
    echo "PHP dependencies installed"
fi

# 5. Python virtual environment
cd "$INSTALL_DIR/scraper"
sudo -u bcashpay python3 -m venv venv
sudo -u bcashpay ./venv/bin/pip install --upgrade pip
sudo -u bcashpay ./venv/bin/pip install -r requirements.txt
echo "Python dependencies installed"

# 6. Install Playwright + Chromium
sudo -u bcashpay ./venv/bin/playwright install chromium
sudo -u bcashpay ./venv/bin/playwright install-deps chromium
echo "Playwright + Chromium installed"

# 7. Database setup
echo "Creating database (enter MySQL root password if prompted)..."
mysql -u root -p <<EOF
CREATE DATABASE IF NOT EXISTS $DB_NAME
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
CREATE USER IF NOT EXISTS '${DB_USER}_scraper'@'localhost' IDENTIFIED BY 'CHANGE_ME_SCRAPER_PASSWORD';
GRANT SELECT ON $DB_NAME.bank_accounts TO '${DB_USER}_scraper'@'localhost';
GRANT SELECT ON $DB_NAME.scraper_tasks TO '${DB_USER}_scraper'@'localhost';
FLUSH PRIVILEGES;
EOF
mysql -u "$DB_USER" -p "$DB_NAME" < "$INSTALL_DIR/api/database/schema.sql"
echo "Database created and schema imported"

# 8. Nginx config
ln -sf "$INSTALL_DIR/deploy/nginx/bcashpay.conf" /etc/nginx/sites-enabled/bcashpay.conf
nginx -t && systemctl reload nginx
echo "Nginx configured"

# 9. .env files
if [ ! -f "$INSTALL_DIR/api/.env" ]; then
    cp "$INSTALL_DIR/api/.env.example" "$INSTALL_DIR/api/.env"
    echo "IMPORTANT: Edit $INSTALL_DIR/api/.env with your actual values!"
fi
if [ ! -f "$INSTALL_DIR/scraper/.env" ]; then
    cp "$INSTALL_DIR/scraper/.env.example" "$INSTALL_DIR/scraper/.env"
    echo "IMPORTANT: Edit $INSTALL_DIR/scraper/.env with your actual values!"
fi
chown bcashpay:bcashpay "$INSTALL_DIR"/api/.env "$INSTALL_DIR"/scraper/.env
chmod 600 "$INSTALL_DIR"/api/.env "$INSTALL_DIR"/scraper/.env

# 10. PM2 setup
pm2 start "$INSTALL_DIR/deploy/pm2/ecosystem.config.js"
pm2 save
echo "PM2 processes started"

echo ""
echo "=== Setup Complete ==="
echo "Next steps:"
echo "  1. Edit $INSTALL_DIR/api/.env with real database credentials"
echo "  2. Edit $INSTALL_DIR/scraper/.env with API token and DB credentials"
echo "  3. Add bank account(s) to the bank_accounts table"
echo "  4. Add API client(s) to the api_clients table"
echo "  5. Set up SSL with: certbot --nginx -d api.bcashpay.com -d pay.bcashpay.com"
echo "  6. Test: curl https://api.bcashpay.com/api/v1/health"
