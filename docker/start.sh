#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is required. Generate it once and set it as a stable Render environment variable." >&2
  exit 1
fi

# The Laravel skeleton defaults to SQLite. RAVEN23 must always use Neon PostgreSQL.
# Export this explicitly so both migrations and long-running HTTP requests use pgsql.
export DB_CONNECTION=pgsql

if [ -z "${DB_URL:-}" ]; then
  echo "DB_URL is required for pooled application traffic." >&2
  exit 1
fi

if [ -z "${DB_MIGRATION_URL:-}" ]; then
  echo "DB_MIGRATION_URL is required. Use the direct (non-pooled) Neon URI for migrations." >&2
  exit 1
fi

php artisan config:clear
DB_URL="$DB_MIGRATION_URL" php artisan migrate --force

# Fail startup rather than silently serving requests against a local SQLite database.
php artisan db:show --database=pgsql >/dev/null

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
