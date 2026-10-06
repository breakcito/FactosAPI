#!/bin/sh
set -e

echo "Starting FactosAPI container..."

# Setup permissions
mkdir -p /var/www/html/storage/app/tenants \
         /var/www/html/storage/framework/cache/greenter \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Setup cron for Laravel schedule:run
echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" > /var/spool/cron/crontabs/root
chmod 0600 /var/spool/cron/crontabs/root

# Optional migrations & cache in production
if [ "$APP_ENV" = "production" ]; then
    echo "Running production optimizations..."
    php /var/www/html/artisan config:cache || true
    php /var/www/html/artisan route:cache || true
fi

# Run database migrations gracefully if enabled
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    echo "Running database migrations..."
    php /var/www/html/artisan migrate --force || echo "Migrations skipped or failed, continuing..."
fi

# Run database seeder gracefully if enabled (e.g. for dev/staging environments)
if [ "${RUN_SEEDER:-false}" = "true" ]; then
    echo "Running database seeder..."
    php /var/www/html/artisan db:seed --force || echo "Seeder failed or skipped, continuing..."
fi

echo "FactosAPI ready. Starting supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
