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

if [ -z "${DB_URL:-}" ]; then
  echo "DB_URL is required for application traffic." >&2
  exit 1
fi

if [ -z "${DB_MIGRATION_URL:-}" ]; then
  echo "DB_MIGRATION_URL is required. Use the direct (non-pooled) Neon connection string for migrations." >&2
  exit 1
fi

php artisan config:clear
DB_URL="$DB_MIGRATION_URL" php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
