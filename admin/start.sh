#!/bin/bash
set -e
cd "$(dirname "$0")/.."

ADMIN_DIR="$(pwd)/admin"
API_DIR="$(pwd)/api"
DB_FILE="${API_DIR}/database/bcashpay.sqlite"

echo "=== B-CashPay Local Dev ==="

# 1. Install admin dependencies if needed
if [ ! -d "${ADMIN_DIR}/vendor" ]; then
    echo "Installing Composer dependencies..."
    cd "${ADMIN_DIR}"
    composer install --no-interaction --quiet
    cd ..
fi

# 2. Copy .env if not present
if [ ! -f "${ADMIN_DIR}/.env" ]; then
    cp "${ADMIN_DIR}/.env.example" "${ADMIN_DIR}/.env"
    echo "Created admin/.env from .env.example"
fi
if [ ! -f "${API_DIR}/.env" ]; then
    cp "${API_DIR}/.env.example" "${API_DIR}/.env"
    echo "Created api/.env from .env.example"
fi

# 3. Migrate + seed if DB doesn't exist
if [ ! -f "${DB_FILE}" ]; then
    echo "Initializing database..."
    php "${ADMIN_DIR}/database/migrate.php"
    php "${ADMIN_DIR}/database/seed.php"
else
    echo "Database exists: ${DB_FILE}"
fi

# 4. Start API server in background (port 8000)
php -S localhost:8000 -t "${API_DIR}/public" > /tmp/bcashpay-api.log 2>&1 &
API_PID=$!
echo "API started on http://localhost:8000 (pid=${API_PID})"

# 5. Start Admin server (port 8001, foreground)
echo ""
echo "=================================="
echo "  Admin: http://localhost:8001"
echo "  Login: admin / admin123"
echo "=================================="
echo ""
echo "Press Ctrl+C to stop."
echo ""

trap "echo ''; echo 'Stopping...'; kill ${API_PID} 2>/dev/null; exit 0" INT TERM

php -S localhost:8001 -t "${ADMIN_DIR}/public"
