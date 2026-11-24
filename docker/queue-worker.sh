#!/bin/sh
set -eu

connection="${QUEUE_CONNECTION:-database}"
queues="${QUEUE_WORKER_QUEUES:-mail,push,default}"
sleep_seconds="${QUEUE_SLEEP:-3}"
tries="${QUEUE_TRIES:-5}"
timeout="${QUEUE_TIMEOUT:-45}"
max_time="${QUEUE_MAX_TIME:-3600}"
memory="${QUEUE_MEMORY:-128}"
retry_after="${DB_QUEUE_RETRY_AFTER:-90}"

require_positive_integer() {
    name="$1"
    value="$2"
    case "$value" in
        ''|*[!0-9]*|0)
            echo "$name must be a positive integer." >&2
            exit 64
            ;;
    esac
}

require_positive_integer QUEUE_SLEEP "$sleep_seconds"
require_positive_integer QUEUE_TRIES "$tries"
require_positive_integer QUEUE_TIMEOUT "$timeout"
require_positive_integer QUEUE_MAX_TIME "$max_time"
require_positive_integer QUEUE_MEMORY "$memory"
require_positive_integer DB_QUEUE_RETRY_AFTER "$retry_after"

if [ -z "$queues" ]; then
    echo "QUEUE_WORKER_QUEUES must not be empty." >&2
    exit 64
fi

push_enabled="${RAVEN23_PUSH_ENABLED:-false}"
case "$push_enabled" in
    1|true|TRUE|True|yes|YES|Yes|on|ON|On)
        case ",$queues," in
            *,push,*) ;;
            *)
                echo "RAVEN23_PUSH_ENABLED=true requires QUEUE_WORKER_QUEUES to include push." >&2
                exit 64
                ;;
        esac
        ;;
esac

if [ "$connection" = "sync" ] || [ "$connection" = "null" ]; then
    echo "A long-lived queue worker requires an asynchronous queue connection." >&2
    exit 64
fi

if [ "$connection" = "database" ] && [ "$timeout" -ge "$retry_after" ]; then
    echo "QUEUE_TIMEOUT must be lower than DB_QUEUE_RETRY_AFTER to prevent duplicate concurrent execution." >&2
    exit 64
fi

exec php artisan queue:work "$connection" \
    --queue="$queues" \
    --sleep="$sleep_seconds" \
    --tries="$tries" \
    --timeout="$timeout" \
    --max-time="$max_time" \
    --memory="$memory"
