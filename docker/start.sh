#!/bin/sh
set -eu

APP_KEY_VALUE="$(printenv APP_KEY 2>/dev/null || true)"
if [ -z "$APP_KEY_VALUE" ]; then
  echo "APP_KEY is required. Generate it once and set it as a stable Render environment variable." >&2
  exit 1
fi

# RAVEN23 uses a local file cache; Neon is reserved for application data.
export CACHE_STORE=file
if [ "$(php -r '$c = require "config/cache.php"; echo $c["default"] ?? "";')" != "file" ]; then
  echo "RAVEN23 cache store must be file-backed; refusing to start with a database cache." >&2
  exit 1
fi

# RAVEN23 uses Neon PostgreSQL: pooled URL for API traffic and direct URL for migrations.
export APP_ENV=production
export APP_DEBUG=false
export LOG_CHANNEL=stderr
export DB_CONNECTION=pgsql

DB_URL_VALUE="$(printenv DB_URL 2>/dev/null || true)"
if [ -z "$DB_URL_VALUE" ]; then
  echo "DB_URL is required for pooled application traffic." >&2
  exit 1
fi

DB_MIGRATION_URL_VALUE="$(printenv DB_MIGRATION_URL 2>/dev/null || true)"
if [ -z "$DB_MIGRATION_URL_VALUE" ]; then
  echo "DB_MIGRATION_URL is required. Use the direct (non-pooled) Neon URI for migrations." >&2
  exit 1
fi

# Prevent successful migrations on a different Neon branch/database than the API reads.
php /usr/local/bin/raven23-check-database-target.php

php artisan config:clear
DB_URL="$DB_MIGRATION_URL_VALUE" php artisan migrate --force

# Verify the exact pooled DB_URL used by HTTP requests before exposing the service.
php artisan config:clear
php /usr/local/bin/raven23-verify-auth-schema.php

PORT_VALUE="$(printenv PORT 2>/dev/null || echo 10000)"
case "$PORT_VALUE" in
  ''|*[!0-9]*)
    echo "PORT must be a numeric TCP port." >&2
    exit 1
    ;;
esac

# Run PHP's HTTP server directly. Do not use "php artisan serve" in production:
# Laravel's dev server may filter custom environment variables before spawning
# the child HTTP process when a .env file exists, which drops DB_URL and OAuth
# secrets even though the startup/migration process can read them.
exec php -S "0.0.0.0:${PORT_VALUE}" -t public /usr/local/bin/raven23-router.php
