#!/usr/bin/env bash
# Exit on error
set -o errexit

echo "=== Running Render Build for Laravel ==="

# Install dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# Cache config and routes
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Run database migrations and seed
php artisan migrate --force --seed || echo "Database migration will run on startup"

echo "=== Build Complete ==="
