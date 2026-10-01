#!/bin/sh
set -e

mkdir -p \
    storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
fi

chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

exec "$@"
