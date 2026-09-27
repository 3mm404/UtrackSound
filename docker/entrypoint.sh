#!/bin/sh
set -e

if [ ! -f .env ]; then
    echo "Missing .env file. Copy .env.example and configure production secrets."
    exit 1
fi

php artisan storage:link --force || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
