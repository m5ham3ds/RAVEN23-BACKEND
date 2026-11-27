#!/bin/sh
set -eu
umask 077

# Hard-bound to the disposable PostgreSQL service in GitHub Actions.
: "${RAVEN23_ALLOW_DISPOSABLE_PG_REHEARSAL:?explicit disposable-CI guard is required}"
: "${RAVEN23_REHEARSAL_ID:?a unique CI run identifier is required}"
: "${REPORT_PATH:?an evidence report path is required}"
: "${PGHOST:?}"
: "${PGPORT:?}"
: "${PGUSER:?}"
: "${PGPASSWORD:?}"
: "${PGDATABASE:?}"

[ "$RAVEN23_ALLOW_DISPOSABLE_PG_REHEARSAL" = "yes" ] || {
  echo "Refusing PostgreSQL rehearsal without the explicit disposable-CI guard." >&2
  exit 1
}
[ "$PGHOST" = "127.0.0.1" ] || {
  echo "Refusing PostgreSQL rehearsal: host must be the local CI service." >&2
  exit 1
}
[ "$PGPORT" = "5432" ] || {
  echo "Refusing PostgreSQL rehearsal: unexpected local port." >&2
  exit 1
}
[ "$PGUSER" = "raven23_test" ] || {
  echo "Refusing PostgreSQL rehearsal: unexpected disposable CI role." >&2
  exit 1
}
[ "$PGPASSWORD" = "raven23_test" ] || {
  echo "Refusing PostgreSQL rehearsal: disposable CI credential does not match." >&2
  exit 1
}
[ "$PGDATABASE" = "raven23_test" ] || {
  echo "Refusing PostgreSQL rehearsal: maintenance database must be raven23_test." >&2
  exit 1
}
case "$RAVEN23_REHEARSAL_ID" in
  ''|*[!0-9]*)
    echo "Rehearsal identifier must contain digits only." >&2
    exit 1
    ;;
esac

suffix="$(printf '%s' "$RAVEN23_REHEARSAL_ID" | cut -c1-16)"
source_db="r23_restore_src_${suffix}"
target_db="r23_restore_dst_${suffix}"
workdir="$(mktemp -d "${TMPDIR:-/tmp}/raven23-pg-restore.XXXXXX")"
RAVEN23_REHEARSAL_PASSPHRASE="$(openssl rand -hex 32)"
export RAVEN23_REHEARSAL_PASSPHRASE

cleanup() {
  psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$PGDATABASE" \
    --command="DROP DATABASE IF EXISTS \"$target_db\" WITH (FORCE)" >/dev/null 2>&1 || true
  psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$PGDATABASE" \
    --command="DROP DATABASE IF EXISTS \"$source_db\" WITH (FORCE)" >/dev/null 2>&1 || true
  unset RAVEN23_REHEARSAL_PASSPHRASE
  rm -rf "$workdir"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$PGDATABASE" \
  --command="CREATE DATABASE \"$source_db\" TEMPLATE template0" >/dev/null
psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$PGDATABASE" \
  --command="CREATE DATABASE \"$target_db\" TEMPLATE template0" >/dev/null

# Deterministic synthetic rows only; no user or production records are read.
psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$source_db" <<'SQL'
CREATE TABLE migration_synthetic_accounts (
    id BIGINT PRIMARY KEY,
    source_key VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(160) NOT NULL UNIQUE,
    credential_digest CHAR(64) NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
);
CREATE TABLE migration_synthetic_media (
    account_id BIGINT NOT NULL REFERENCES migration_synthetic_accounts(id) ON DELETE RESTRICT,
    media_type VARCHAR(16) NOT NULL CHECK (media_type IN ('movie', 'episode')),
    external_id VARCHAR(32) NOT NULL,
    progress_ms BIGINT NOT NULL CHECK (progress_ms >= 0),
    updated_at_utc TIMESTAMPTZ NOT NULL,
    PRIMARY KEY (account_id, media_type, external_id)
);
CREATE TABLE migration_synthetic_events (
    event_id BIGINT PRIMARY KEY,
    account_id BIGINT NOT NULL REFERENCES migration_synthetic_accounts(id) ON DELETE RESTRICT,
    idempotency_key VARCHAR(96) NOT NULL UNIQUE,
    payload JSONB NOT NULL,
    recorded_at TIMESTAMPTZ NOT NULL
);
CREATE INDEX migration_synthetic_media_updated_idx
    ON migration_synthetic_media (account_id, updated_at_utc DESC);

INSERT INTO migration_synthetic_accounts (id, source_key, email, credential_digest, created_at) VALUES
    (1001, 'synthetic-account-1001', 'synthetic-1001@example.test', repeat('a', 64), '2026-11-01 10:00:00+00'),
    (1002, 'synthetic-account-1002', 'synthetic-1002@example.test', repeat('b', 64), '2026-11-02 10:00:00+00'),
    (1003, 'synthetic-account-1003', 'synthetic-1003@example.test', repeat('c', 64), '2026-11-03 10:00:00+00');

INSERT INTO migration_synthetic_media (account_id, media_type, external_id, progress_ms, updated_at_utc) VALUES
    (1001, 'movie', 'movie-001', 125000, '2026-11-24 08:00:00+00'),
    (1001, 'episode', 'episode-002', 25000, '2026-11-24 09:00:00+00'),
    (1002, 'movie', 'movie-003', 80000, '2026-11-24 10:00:00+00'),
    (1003, 'episode', 'episode-004', 0, '2026-11-24 11:00:00+00');

INSERT INTO migration_synthetic_events (event_id, account_id, idempotency_key, payload, recorded_at) VALUES
    (9001, 1001, 'synthetic-event-9001', '{"kind":"bookmark","synthetic":true}'::jsonb, '2026-11-24 12:00:00+00'),
    (9002, 1002, 'synthetic-event-9002', '{"kind":"watch-progress","synthetic":true}'::jsonb, '2026-11-24 12:01:00+00');
SQL

backup_file="$workdir/raven23-synthetic.dump"
encrypted_file="$workdir/raven23-synthetic.dump.enc"
restored_file="$workdir/raven23-synthetic-restored.dump"
echo "::notice::Synthetic PostgreSQL source dataset created."
pg_dump --no-owner --no-privileges --format=custom --file="$backup_file" \
  --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$source_db" || {
  echo "::error::pg_dump failed while creating the synthetic custom-format archive."
  exit 1
}
test -s "$backup_file" || {
  echo "::error::pg_dump returned without creating a non-empty archive."
  exit 1
}
echo "::notice::Synthetic PostgreSQL custom-format archive created."
source_backup_sha="$(sha256sum "$backup_file" | awk '{print $1}')"
openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -md sha256 \
  -pass env:RAVEN23_REHEARSAL_PASSPHRASE -in "$backup_file" -out "$encrypted_file" || {
  echo "::error::OpenSSL failed to encrypt the synthetic PostgreSQL archive."
  exit 1
}
test -s "$encrypted_file" || {
  echo "::error::OpenSSL did not create a non-empty encrypted archive."
  exit 1
}
encrypted_backup_sha="$(sha256sum "$encrypted_file" | awk '{print $1}')"
echo "::notice::Synthetic PostgreSQL archive encrypted."
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 \
  -pass env:RAVEN23_REHEARSAL_PASSPHRASE -in "$encrypted_file" -out "$restored_file" || {
  echo "::error::OpenSSL failed to decrypt the synthetic PostgreSQL archive."
  exit 1
}
restored_backup_sha="$(sha256sum "$restored_file" | awk '{print $1}')"
[ "$source_backup_sha" = "$restored_backup_sha" ] || {
  echo "::error::Encrypted backup round-trip checksum mismatch: source=$source_backup_sha restored=$restored_backup_sha" >&2
  exit 1
}
echo "::notice::Encrypted backup round-trip checksum matched."
pg_restore --list "$restored_file" > "$workdir/restore-list.txt" || {
  echo "::error::pg_restore could not read the encrypted/decrypted custom archive."
  exit 1
}
test -s "$workdir/restore-list.txt" || {
  echo "::error::pg_restore archive listing is empty."
  exit 1
}
pg_restore --exit-on-error --no-owner --no-privileges \
  --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$target_db" "$restored_file" || {
  echo "::error::pg_restore failed while restoring the synthetic archive into its separate target database."
  exit 1
}
echo "::notice::Synthetic backup restored into its separate target database."

scalar() {
  result="$(psql --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$1" --command="$2")" || {
    echo "::error::PostgreSQL verification query failed for database $1." >&2
    return 1
  }
  printf '%s' "$result" | tr -d '\r'
}
assert_same() {
  name="$1"
  expected="$2"
  actual="$3"
  if [ "$expected" != "$actual" ]; then
    echo "::error::$name mismatch: source=$expected target=$actual" >&2
    return 1
  fi
}
accounts_fingerprint() {
  scalar "$1" "SELECT md5(COALESCE(string_agg(concat_ws('|', id::text, source_key, email, credential_digest, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US')), '|' ORDER BY id), '')) FROM migration_synthetic_accounts"
}
media_fingerprint() {
  scalar "$1" "SELECT md5(COALESCE(string_agg(concat_ws('|', account_id::text, media_type, external_id, progress_ms::text, to_char(updated_at_utc AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US')), '|' ORDER BY account_id, media_type, external_id), '')) FROM migration_synthetic_media"
}
event_fingerprint() {
  scalar "$1" "SELECT md5(COALESCE(string_agg(concat_ws('|', event_id::text, account_id::text, idempotency_key, payload::text, to_char(recorded_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US')), '|' ORDER BY event_id), '')) FROM migration_synthetic_events"
}
constraints_manifest() {
  scalar "$1" "SELECT COALESCE(string_agg(
    format('%I.%I|%I|type=%s|definition=%s|validated=%s|deferrable=%s|deferred=%s|local=%s|inheritance_count=%s|noinherit=%s',
      ns.nspname, tbl.relname, con.conname, con.contype,
      CASE
        WHEN con.contype = 'c' THEN '<check-expression-verified-behaviorally>'
        ELSE pg_get_constraintdef(con.oid, false)
      END,
      con.convalidated,
      con.condeferrable, con.condeferred, con.conislocal,
      con.coninhcount, con.connoinherit),
    E'\\n' ORDER BY ns.nspname, tbl.relname, con.conname), '')
  FROM pg_constraint con
  JOIN pg_class tbl ON tbl.oid = con.conrelid
  JOIN pg_namespace ns ON ns.oid = tbl.relnamespace
  WHERE ns.nspname = 'public'
    AND tbl.relname IN ('migration_synthetic_accounts', 'migration_synthetic_media', 'migration_synthetic_events')"
}
indexes_manifest() {
  scalar "$1" "SELECT COALESCE(string_agg(
    format('%I.%I|%I|%s', schemaname, tablename, indexname, indexdef),
    E'\\n' ORDER BY schemaname, tablename, indexname), '')
  FROM pg_indexes
  WHERE schemaname = 'public'
    AND tablename LIKE 'migration_synthetic_%'"
}
manifest_fingerprint() {
  printf '%s' "$1" | sha256sum | awk '{print $1}'
}
assert_manifest_same() {
  name="$1"
  expected="$2"
  actual="$3"
  if [ "$expected" != "$actual" ]; then
    echo "::error::$name manifest mismatch; source and restored definitions follow." >&2
    echo "::group::Source $name manifest"
    printf '%s\n' "$expected"
    echo "::endgroup::"
    echo "::group::Restored $name manifest"
    printf '%s\n' "$actual"
    echo "::endgroup::"
    return 1
  fi
}
check_constraint_count() {
  scalar "$1" "SELECT count(*)
  FROM pg_constraint con
  JOIN pg_class tbl ON tbl.oid = con.conrelid
  JOIN pg_namespace ns ON ns.oid = tbl.relnamespace
  WHERE ns.nspname = 'public'
    AND tbl.relname IN ('migration_synthetic_accounts', 'migration_synthetic_media', 'migration_synthetic_events')
    AND con.contype = 'c'"
}
assert_check_constraint_behavior() {
  database="$1"
  psql --no-psqlrc --set=ON_ERROR_STOP=1 \
    --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$database" <<'SQL'
DO $raven23_phase12$
DECLARE
    rejected BOOLEAN;
BEGIN
    INSERT INTO migration_synthetic_media
        (account_id, media_type, external_id, progress_ms, updated_at_utc)
    VALUES
        (1001, 'movie', 'phase12-check-valid-movie', 0, CURRENT_TIMESTAMP),
        (1001, 'episode', 'phase12-check-valid-episode', 0, CURRENT_TIMESTAMP);

    DELETE FROM migration_synthetic_media
    WHERE external_id IN ('phase12-check-valid-movie', 'phase12-check-valid-episode');

    rejected := FALSE;
    BEGIN
        INSERT INTO migration_synthetic_media
            (account_id, media_type, external_id, progress_ms, updated_at_utc)
        VALUES (1001, 'unsupported', 'phase12-check-invalid-type', 0, CURRENT_TIMESTAMP);
    EXCEPTION
        WHEN check_violation THEN rejected := TRUE;
    END;
    IF NOT rejected THEN
        RAISE EXCEPTION 'media_type CHECK constraint accepted an unsupported value';
    END IF;

    rejected := FALSE;
    BEGIN
        INSERT INTO migration_synthetic_media
            (account_id, media_type, external_id, progress_ms, updated_at_utc)
        VALUES (1001, 'movie', 'phase12-check-invalid-progress', -1, CURRENT_TIMESTAMP);
    EXCEPTION
        WHEN check_violation THEN rejected := TRUE;
    END;
    IF NOT rejected THEN
        RAISE EXCEPTION 'progress_ms CHECK constraint accepted a negative value';
    END IF;
END
$raven23_phase12$;
SQL
}

source_accounts="$(scalar "$source_db" 'SELECT count(*) FROM migration_synthetic_accounts')"
target_accounts="$(scalar "$target_db" 'SELECT count(*) FROM migration_synthetic_accounts')"
source_media="$(scalar "$source_db" 'SELECT count(*) FROM migration_synthetic_media')"
target_media="$(scalar "$target_db" 'SELECT count(*) FROM migration_synthetic_media')"
source_events="$(scalar "$source_db" 'SELECT count(*) FROM migration_synthetic_events')"
target_events="$(scalar "$target_db" 'SELECT count(*) FROM migration_synthetic_events')"
assert_same "Synthetic accounts row count" "3" "$source_accounts"
assert_same "Restored accounts row count" "$source_accounts" "$target_accounts"
assert_same "Synthetic media row count" "4" "$source_media"
assert_same "Restored media row count" "$source_media" "$target_media"
assert_same "Synthetic event row count" "2" "$source_events"
assert_same "Restored event row count" "$source_events" "$target_events"
echo "::notice::Synthetic account, media and event row counts matched."

source_accounts_hash="$(accounts_fingerprint "$source_db")"
target_accounts_hash="$(accounts_fingerprint "$target_db")"
source_media_hash="$(media_fingerprint "$source_db")"
target_media_hash="$(media_fingerprint "$target_db")"
source_events_hash="$(event_fingerprint "$source_db")"
target_events_hash="$(event_fingerprint "$target_db")"
source_schema_manifest="$(constraints_manifest "$source_db")"
target_schema_manifest="$(constraints_manifest "$target_db")"
source_index_manifest="$(indexes_manifest "$source_db")"
target_index_manifest="$(indexes_manifest "$target_db")"
source_schema_hash="$(manifest_fingerprint "$source_schema_manifest")"
target_schema_hash="$(manifest_fingerprint "$target_schema_manifest")"
source_index_hash="$(manifest_fingerprint "$source_index_manifest")"
target_index_hash="$(manifest_fingerprint "$target_index_manifest")"
source_constraints_count="$(printf '%s\n' "$source_schema_manifest" | awk 'NF { count++ } END { print count + 0 }')"
target_constraints_count="$(printf '%s\n' "$target_schema_manifest" | awk 'NF { count++ } END { print count + 0 }')"
source_check_constraints_count="$(check_constraint_count "$source_db")"
target_check_constraints_count="$(check_constraint_count "$target_db")"

assert_same "Accounts content fingerprint" "$source_accounts_hash" "$target_accounts_hash"
assert_same "Media content fingerprint" "$source_media_hash" "$target_media_hash"
assert_same "Events content fingerprint" "$source_events_hash" "$target_events_hash"
assert_same "Synthetic database constraint count" "10" "$source_constraints_count"
assert_same "Restored database constraint count" "$source_constraints_count" "$target_constraints_count"
assert_same "Synthetic CHECK constraint count" "2" "$source_check_constraints_count"
assert_same "Restored CHECK constraint count" "$source_check_constraints_count" "$target_check_constraints_count"
assert_manifest_same "Database constraints" "$source_schema_manifest" "$target_schema_manifest"
assert_same "Database constraints fingerprint" "$source_schema_hash" "$target_schema_hash"
assert_check_constraint_behavior "$source_db"
echo "::notice::Source CHECK constraints rejected invalid synthetic values and accepted movie/episode values."
assert_check_constraint_behavior "$target_db"
echo "::notice::Restored CHECK constraints rejected invalid synthetic values and accepted movie/episode values."
assert_manifest_same "Database indexes" "$source_index_manifest" "$target_index_manifest"
assert_same "Database indexes fingerprint" "$source_index_hash" "$target_index_hash"
echo "::notice::Synthetic row content, all 10 constraints (including both behavioral CHECK probes) and indexes matched after restore."

mkdir -p "$(dirname "$REPORT_PATH")"
cat > "$REPORT_PATH" <<EOF
{
  "schemaVersion": 1,
  "kind": "raven23.phase12.synthetic-postgresql-backup-restore",
  "result": "passed",
  "rehearsalId": "$RAVEN23_REHEARSAL_ID",
  "timestampUtc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "backup": {
    "format": "PostgreSQL custom archive",
    "encryption": "AES-256-CBC with PBKDF2-HMAC-SHA256",
    "pbkdf2Iterations": 200000,
    "sha256": "$encrypted_backup_sha",
    "decryptedPayloadSha256": "$source_backup_sha",
    "roundTripChecksumMatched": true
  },
  "restore": {
    "targetWasSeparateDisposableDatabase": true,
    "accountsRows": $target_accounts,
    "mediaRows": $target_media,
    "eventRows": $target_events,
    "accountRowsFingerprint": "$target_accounts_hash",
    "mediaRowsFingerprint": "$target_media_hash",
    "eventRowsFingerprint": "$target_events_hash",
    "constraintsFingerprint": "$target_schema_hash",
    "indexesFingerprint": "$target_index_hash",
    "rowsAndSchemaMatchedSource": true
  },
  "safety": {
    "syntheticDataOnly": true,
    "productionDatabaseUsed": false,
    "productionSecretsUsed": false,
    "productionDataRead": false,
    "cutoverPerformed": false,
    "sourceAndTargetDatabasesDroppedAfterRehearsal": true
  }
}
EOF

test -s "$REPORT_PATH"
echo "PASS: encrypted PostgreSQL backup restored into a separate disposable database; 3 account, 4 media and 2 event rows plus constraints/indexes match."
