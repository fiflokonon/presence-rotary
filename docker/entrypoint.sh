#!/bin/sh
set -e

if [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
