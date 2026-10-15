#!/bin/sh
set -e

PORT=${PORT:-8080}

echo "=== Maxun Backend Starting for Render ==="
echo "Port: ${PORT}"

# Optimizations
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Run migrations and seed data
echo "Running PostgreSQL database migrations & seed..."
php artisan migrate --force --seed || echo "Notice: Database connection will be retried on demand."

# Start Laravel server with multi-worker CLI server
export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-4}
echo "Starting PHP server on 0.0.0.0:${PORT}..."
exec php artisan serve --host=0.0.0.0 --port="${PORT}"
