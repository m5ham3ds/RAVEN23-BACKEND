#!/bin/sh
set -eu

: "${PORT:=10000}"
: "${APP_ENV:=production}"
: "${APP_DEBUG:=false}"
: "${LOG_CHANNEL:=stderr}"

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required; provide a stable key through the runtime environment." >&2
    exit 1
fi

case "$PORT" in
    ''|*[!0-9]*)
        echo "PORT must be a numeric TCP port." >&2
        exit 1
        ;;
esac

if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be between 1024 and 65535 for the non-root runtime." >&2
    exit 1
fi

export PORT APP_ENV APP_DEBUG LOG_CHANNEL

# No database checks, writes, or migrations occur here. Database migrations
# are an explicit one-off release task, not part of web process startup.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec frankenphp run --config /etc/frankenphp/Caddyfile
