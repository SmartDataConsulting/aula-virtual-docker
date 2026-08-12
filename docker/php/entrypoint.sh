#!/bin/sh
set -e

LOCK_DIR="/var/www/.docker-composer-install.lock"
WAIT_SECONDS=0

while ! mkdir "$LOCK_DIR" 2>/dev/null; do
    WAIT_SECONDS=$((WAIT_SECONDS + 1))
    if [ "$WAIT_SECONDS" -gt 300 ]; then
        echo "Timed out waiting for composer install lock" >&2
        exit 1
    fi
    sleep 1
done

cleanup() {
    rmdir "$LOCK_DIR" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

mkdir -p \
    storage/logs \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    bootstrap/cache

chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true
chmod -R a+rwX storage/logs storage/framework bootstrap/cache 2>/dev/null || true

cleanup
trap - EXIT INT TERM

exec "$@"
