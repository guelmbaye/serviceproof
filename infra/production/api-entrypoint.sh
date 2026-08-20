#!/bin/sh
set -e

# Deliberately does NOT run migrations.
#
# The development image migrates on every boot, which is convenient on a
# laptop and dangerous on a server: a restart during an incident would apply
# a half-finished migration, and two replicas would race each other. Schema
# changes belong in the deploy script, run once, deliberately.

cd /var/www/html

if [ -z "${APP_KEY}" ]; then
    echo "FATAL: APP_KEY is not set. Generate one with:" >&2
    echo "  docker compose run --rm api php artisan key:generate --show" >&2
    exit 1
fi

# Rebuilt at every start because the values come from the environment, not
# from a file baked into the image.
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
