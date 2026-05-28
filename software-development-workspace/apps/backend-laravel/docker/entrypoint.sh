#!/bin/sh
set -e

# Wait for PostgreSQL to be ready
echo "Waiting for database..."
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USERNAME}" 2>/dev/null; do
    sleep 2
done
echo "Database ready."

# Run migrations
php artisan migrate --force

# Ensure storage is writable by PHP-FPM (www-data) — 775 is sufficient; Dockerfile sets www-data ownership
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Create public/storage symlink so uploaded files are served (idempotent)
php artisan storage:link --force 2>/dev/null || true

# Cache config/routes for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Create supervisor log dir
mkdir -p /var/log/supervisor

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
