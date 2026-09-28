#!/bin/sh
set -e

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Configure it in the Compose environment file."
    exit 1
fi

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
if [ "${DB_CONNECTION:-}" = "sqlite" ]; then
    if [ "${DB_DATABASE:-}" != "/var/www/html/storage/app/private/database.sqlite" ]; then
        echo "SQLite requires DB_DATABASE=/var/www/html/storage/app/private/database.sqlite"
        exit 1
    fi
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

php artisan storage:link --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

chown -R www-data:www-data storage bootstrap/cache
exec "$@"
