#!/bin/sh
set -eu
umask 077

# This rehearsal is intentionally hard-bound to the disposable GitHub CI database.
: "$RAVEN23_ALLOW_DISPOSABLE_PG_REHEARSAL"
: "$RAVEN23_REHEARSAL_ID"
: "$RAVEN23_REPOSITORY_ROOT"
: "$REPORT_PATH"
: "$PGHOST"
: "$PGPORT"
: "$PGUSER"
: "$PGPASSWORD"
: "$PGDATABASE"

[ "$RAVEN23_ALLOW_DISPOSABLE_PG_REHEARSAL" = "yes" ] || {
  echo "Refusing full-schema rehearsal without the explicit disposable-CI guard." >&2
  exit 1
}
[ "$PGHOST" = "127.0.0.1" ] || {
  echo "Refusing full-schema rehearsal: host must be the local CI service." >&2
  exit 1
}
[ "$PGPORT" = "5432" ] || {
  echo "Refusing full-schema rehearsal: unexpected local port." >&2
  exit 1
}
[ "$PGUSER" = "raven23_test" ] || {
  echo "Refusing full-schema rehearsal: unexpected disposable CI role." >&2
  exit 1
}
[ "$PGPASSWORD" = "raven23_test" ] || {
  echo "Refusing full-schema rehearsal: disposable CI credential does not match." >&2
  exit 1
}
[ "$PGDATABASE" = "raven23_test" ] || {
  echo "Refusing full-schema rehearsal: database must be raven23_test." >&2
  exit 1
}
case "$RAVEN23_REHEARSAL_ID" in
  ''|*[!0-9]*)
    echo "Rehearsal identifier must contain digits only." >&2
    exit 1
    ;;
esac

migrations_dir="$RAVEN23_REPOSITORY_ROOT/database/migrations"
[ -d "$migrations_dir" ] || {
  echo "Refusing full-schema rehearsal: migrations directory is unavailable." >&2
  exit 1
}
expected_migrations="$(find "$migrations_dir" -maxdepth 1 -type f -name '*.php' | wc -l | tr -d ' ')"
[ "$expected_migrations" -gt 0 ] || {
  echo "Refusing full-schema rehearsal: no migration files were found." >&2
  exit 1
}

suffix="$(printf '%s' "$RAVEN23_REHEARSAL_ID" | cut -c1-16)"
source_db="r23_full_src_$suffix"
target_db="r23_full_dst_$suffix"
workdir="$(mktemp -d "/tmp/raven23-full-schema.XXXXXX")"
RAVEN23_REHEARSAL_PASSPHRASE="$(openssl rand -hex 32)"
export RAVEN23_REHEARSAL_PASSPHRASE

scalar() {
  result="$(psql --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$1" --command="$2")" || {
    echo "::error::PostgreSQL verification query failed for database $1." >&2
    return 1
  }
  printf '%s' "$result" | tr -d '\r'
}

cleanup() {
  psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname=postgres \
    --command="DROP DATABASE IF EXISTS \"$target_db\" WITH (FORCE)" >/dev/null 2>&1 || true
  psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname=postgres \
    --command="DROP DATABASE IF EXISTS \"$source_db\" WITH (FORCE)" >/dev/null 2>&1 || true
  unset RAVEN23_REHEARSAL_PASSPHRASE
  rm -rf "$workdir"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

actual_migrations="$(scalar "$PGDATABASE" "SELECT count(*) FROM public.migrations")"
[ "$actual_migrations" = "$expected_migrations" ] || {
  echo "::error::CI migration ledger has $actual_migrations rows but the source tree has $expected_migrations migration files." >&2
  exit 1
}

# Clone the already-migrated disposable CI database. The target starts empty.
psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname=postgres \
  --command="CREATE DATABASE \"$source_db\" WITH TEMPLATE \"$PGDATABASE\"" >/dev/null
psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname=postgres \
  --command="CREATE DATABASE \"$target_db\" TEMPLATE template0" >/dev/null

backup_file="$workdir/raven23-full-schema.dump"
encrypted_file="$workdir/raven23-full-schema.dump.enc"
restored_file="$workdir/raven23-full-schema-restored.dump"

pg_dump --no-owner --no-privileges --format=custom --file="$backup_file" \
  --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$source_db" || {
  echo "::error::pg_dump failed for the disposable full-schema CI clone."
  exit 1
}
test -s "$backup_file" || {
  echo "::error::pg_dump returned an empty full-schema archive."
  exit 1
}
source_backup_sha="$(sha256sum "$backup_file" | awk '{print $1}')"

openssl enc -aes-256-cbc -salt -pbkdf2 -iter 200000 -md sha256 \
  -pass env:RAVEN23_REHEARSAL_PASSPHRASE -in "$backup_file" -out "$encrypted_file" || {
  echo "::error::OpenSSL failed to encrypt the disposable full-schema archive."
  exit 1
}
test -s "$encrypted_file" || {
  echo "::error::OpenSSL did not create a non-empty full-schema encrypted archive."
  exit 1
}
encrypted_backup_sha="$(sha256sum "$encrypted_file" | awk '{print $1}')"

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -md sha256 \
  -pass env:RAVEN23_REHEARSAL_PASSPHRASE -in "$encrypted_file" -out "$restored_file" || {
  echo "::error::OpenSSL failed to decrypt the disposable full-schema archive."
  exit 1
}
restored_backup_sha="$(sha256sum "$restored_file" | awk '{print $1}')"
[ "$source_backup_sha" = "$restored_backup_sha" ] || {
  echo "::error::Full-schema encrypted archive round-trip checksum mismatch." >&2
  exit 1
}
pg_restore --list "$restored_file" > "$workdir/restore-list.txt" || {
  echo "::error::pg_restore could not inspect the full-schema archive."
  exit 1
}
test -s "$workdir/restore-list.txt" || {
  echo "::error::Full-schema archive listing is empty."
  exit 1
}
pg_restore --exit-on-error --no-owner --no-privileges \
  --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$target_db" "$restored_file" || {
  echo "::error::Full-schema archive restore failed."
  exit 1
}

schema_manifest() {
  psql --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$1" <<'SQL'
SELECT 'column|' || json_build_array(
    table_name, ordinal_position, column_name, data_type, udt_name,
    is_nullable, column_default, character_maximum_length,
    numeric_precision, numeric_scale, datetime_precision,
    is_identity, identity_generation, is_generated, generation_expression
)::text
FROM information_schema.columns
WHERE table_schema = 'public'
UNION ALL
SELECT 'constraint|' || json_build_array(
    ns.nspname, tbl.relname, con.conname, con.contype,
    pg_get_constraintdef(con.oid, false), con.convalidated,
    con.condeferrable, con.condeferred
)::text
FROM pg_constraint con
JOIN pg_class tbl ON tbl.oid = con.conrelid
JOIN pg_namespace ns ON ns.oid = tbl.relnamespace
WHERE ns.nspname = 'public'
UNION ALL
SELECT 'index|' || json_build_array(schemaname, tablename, indexname, indexdef)::text
FROM pg_indexes
WHERE schemaname = 'public'
UNION ALL
SELECT 'sequence|' || json_build_array(
    schemaname, sequencename, data_type, start_value,
    min_value, max_value, increment_by, cycle, cache_size
)::text
FROM pg_sequences
WHERE schemaname = 'public'
UNION ALL
SELECT 'trigger|' || json_build_array(
    ns.nspname, tbl.relname, trg.tgname,
    pg_get_triggerdef(trg.oid, false), trg.tgenabled
)::text
FROM pg_trigger trg
JOIN pg_class tbl ON tbl.oid = trg.tgrelid
JOIN pg_namespace ns ON ns.oid = tbl.relnamespace
WHERE ns.nspname = 'public' AND NOT trg.tgisinternal
UNION ALL
SELECT 'view|' || json_build_array(schemaname, viewname, definition)::text
FROM pg_views
WHERE schemaname = 'public'
ORDER BY 1;
SQL
}

sequence_state_manifest() {
  database="$1"
  queries_file="$workdir/$(basename "$2").queries"

  psql --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$database" \
    --command="SELECT format('SELECT %L || ''|'' || last_value::text || ''|'' || is_called::text FROM %I.%I;', schemaname || '.' || sequencename, schemaname, sequencename) FROM pg_sequences WHERE schemaname = 'public' ORDER BY schemaname, sequencename" \
    > "$queries_file" || {
      echo "::error::Unable to enumerate PostgreSQL sequence state in $database." >&2
      return 1
    }

  psql --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
    --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$database" \
    --file="$queries_file" || {
      echo "::error::Unable to read last_value/is_called for PostgreSQL sequences in $database." >&2
      return 1
    }
}

table_names() {
  scalar "$1" "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
}

write_table_data_manifest() {
  database="$1"
  output="$2"
  : > "$output"
  names_file="$workdir/$(basename "$output").tables"
  table_names "$database" > "$names_file"
  while IFS= read -r table; do
    [ -n "$table" ] || continue
    case "$table" in
      *[!a-zA-Z0-9_]*)
        echo "::error::Unexpected public table name; refusing dynamic verification query." >&2
        return 1
        ;;
    esac
    row_count="$(scalar "$database" "SELECT count(*) FROM public.\"$table\"")"
    rows_file="$workdir/$(basename "$output").$table.jsonl"
    psql --no-psqlrc --set=ON_ERROR_STOP=1 --tuples-only --no-align --quiet \
      --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$database" \
      --command="SELECT row_to_json(row_value)::text FROM public.\"$table\" AS row_value ORDER BY row_to_json(row_value)::text" \
      > "$rows_file" || {
      echo "::error::Unable to fingerprint rows in public.$table." >&2
      return 1
    }
    row_sha="$(sha256sum "$rows_file" | awk '{print $1}')"
    printf '%s|%s|%s\n' "$table" "$row_count" "$row_sha" >> "$output"
  done < "$names_file"
}

schema_manifest "$source_db" > "$workdir/source-schema.jsonl"
schema_manifest "$target_db" > "$workdir/target-schema.jsonl"
if ! cmp -s "$workdir/source-schema.jsonl" "$workdir/target-schema.jsonl"; then
  echo "::error::Full-schema restore changed one or more column, constraint, index, sequence-definition, trigger or view definitions."
  diff -u "$workdir/source-schema.jsonl" "$workdir/target-schema.jsonl" || true
  exit 1
fi

sequence_state_manifest "$source_db" > "$workdir/source-sequence-state.txt"
sequence_state_manifest "$target_db" > "$workdir/target-sequence-state.txt"
if ! cmp -s "$workdir/source-sequence-state.txt" "$workdir/target-sequence-state.txt"; then
  echo "::error::Full-schema restore changed one or more sequence last_value/is_called states."
  diff -u "$workdir/source-sequence-state.txt" "$workdir/target-sequence-state.txt" || true
  exit 1
fi
sequence_count="$(wc -l < "$workdir/source-sequence-state.txt" | tr -d ' ')"
[ "$sequence_count" -gt 0 ] || {
  echo "::error::No public PostgreSQL sequences were verified; refusing a false-positive sequence-state check." >&2
  exit 1
}

table_names "$source_db" > "$workdir/source-tables.txt"
table_names "$target_db" > "$workdir/target-tables.txt"
if ! cmp -s "$workdir/source-tables.txt" "$workdir/target-tables.txt"; then
  echo "::error::Source and restored target public table inventories differ."
  diff -u "$workdir/source-tables.txt" "$workdir/target-tables.txt" || true
  exit 1
fi

write_table_data_manifest "$source_db" "$workdir/source-table-data.txt"
write_table_data_manifest "$target_db" "$workdir/target-table-data.txt"
if ! cmp -s "$workdir/source-table-data.txt" "$workdir/target-table-data.txt"; then
  echo "::error::Full-schema restore row counts or per-table content fingerprints differ."
  diff -u "$workdir/source-table-data.txt" "$workdir/target-table-data.txt" || true
  exit 1
fi

restored_migrations="$(scalar "$target_db" "SELECT count(*) FROM public.migrations")"
[ "$restored_migrations" = "$expected_migrations" ] || {
  echo "::error::Restored migration ledger count does not match the source migration files." >&2
  exit 1
}
table_count="$(wc -l < "$workdir/source-tables.txt" | tr -d ' ')"
schema_sha="$(sha256sum "$workdir/source-schema.jsonl" | awk '{print $1}')"
data_manifest_sha="$(sha256sum "$workdir/source-table-data.txt" | awk '{print $1}')"
sequence_state_sha="$(sha256sum "$workdir/source-sequence-state.txt" | awk '{print $1}')"

# Remove disposable databases before emitting the evidence artifact.
psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$PGDATABASE" \
  --command="DROP DATABASE \"$target_db\" WITH (FORCE)" >/dev/null
psql --no-psqlrc --set=ON_ERROR_STOP=1 --username="$PGUSER" --host="$PGHOST" --port="$PGPORT" --dbname="$PGDATABASE" \
  --command="DROP DATABASE \"$source_db\" WITH (FORCE)" >/dev/null

mkdir -p "$(dirname "$REPORT_PATH")"
cat > "$REPORT_PATH" <<EOF
{
  "schemaVersion": 2,
  "kind": "raven23.phase12.full-schema-postgresql-backup-restore",
  "result": "passed",
  "rehearsalId": "$RAVEN23_REHEARSAL_ID",
  "timestampUtc": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "source": {
    "databaseClass": "disposable GitHub Actions PostgreSQL integration database",
    "migrationFiles": $expected_migrations,
    "migrationLedgerRows": $actual_migrations,
    "publicTables": $table_count
  },
  "backup": {
    "format": "PostgreSQL custom archive",
    "encryption": "AES-256-CBC with PBKDF2-HMAC-SHA256",
    "pbkdf2Iterations": 200000,
    "encryptedSha256": "$encrypted_backup_sha",
    "decryptedPayloadSha256": "$source_backup_sha",
    "roundTripChecksumMatched": true
  },
  "restore": {
    "targetWasSeparateDisposableDatabase": true,
    "migrationLedgerRows": $restored_migrations,
    "publicTables": $table_count,
    "schemaManifestSha256": "$schema_sha",
    "tableDataManifestSha256": "$data_manifest_sha",
    "sequenceStateManifestSha256": "$sequence_state_sha",
    "sequencesVerified": $sequence_count,
    "sequenceCurrentValuesAndIsCalledMatched": true,
    "tableInventoriesMatched": true,
    "columnsConstraintsIndexesSequencesTriggersViewsMatched": true,
    "perTableRowCountsAndContentFingerprintsMatched": true
  },
  "safety": {
    "ciDatabaseOnly": true,
    "productionDatabaseUsed": false,
    "productionSecretsUsed": false,
    "productionDataRead": false,
    "cutoverPerformed": false,
    "temporarySourceAndTargetDatabasesDropped": true,
    "rowValuesIncludedInArtifact": false
  }
}
EOF

test -s "$REPORT_PATH"
chmod 0644 "$REPORT_PATH"
echo "PASS: full disposable PostgreSQL schema/data backup restored; migration ledger, table inventory, schema definitions, sequence state, row counts and per-table fingerprints matched."
