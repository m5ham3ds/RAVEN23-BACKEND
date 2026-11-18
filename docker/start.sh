#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is required. Generate it once and set it as a stable Render environment variable." >&2
  exit 1
fi

if [ "${DB_CONNECTION:-pgsql}" != "pgsql" ]; then
  echo "This deployment is configured for PostgreSQL. Set DB_CONNECTION=pgsql." >&2
  exit 1
fi

php artisan config:clear
php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
