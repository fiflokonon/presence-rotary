#!/bin/sh
set -e

mkdir -p database/data/tenants

if [ ! -f database/data/central.sqlite ]; then
    touch database/data/central.sqlite
fi

php artisan migrate --database=central --path=database/migrations/central --force

for tenant_db in database/data/tenants/*.sqlite; do
    [ -e "$tenant_db" ] || continue
    DB_DATABASE="$tenant_db" php artisan migrate --database=sqlite --force
done

php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
