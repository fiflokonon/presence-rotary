#!/bin/sh
set -e

if [ ! -f database/data/database.sqlite ]; then
    touch database/data/database.sqlite
fi

php artisan migrate --force
php artisan db:seed
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
