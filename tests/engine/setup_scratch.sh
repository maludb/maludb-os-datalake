#!/usr/bin/env bash
# The engine proof's scratch world, re-runnable from nothing: the scratch catalog database dl_engine (and its restore-drill
# twin dl_engine_restore), the two engine roles as the design names them for production (§14: datalake_lake owns schema
# `ducklake`; datalake_query only SELECTs it) under scratch names dl_eng_lake / dl_eng_query, their passwords in libpq
# passfiles under var/engine (gitignored), and the data directory var/engine/lake. Nothing else on the host is touched.
#
#   tests/engine/setup_scratch.sh          create (drop first if present)
#   tests/engine/setup_scratch.sh --drop   drop everything it made
set -euo pipefail
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
VAR="$HERE/var/engine"
PSQL=(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q -X)

drop() {
  for db in dl_engine dl_engine_restore; do
    "${PSQL[@]}" -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$db' AND pid <> pg_backend_pid()" >/dev/null
    "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS $db" 2>/dev/null
  done
  for r in dl_eng_query dl_eng_lake; do
    "${PSQL[@]}" -d postgres -c "DROP ROLE IF EXISTS $r" 2>/dev/null
  done
  rm -rf "$VAR"
}

if [ "${1:-}" = "--drop" ]; then drop; echo "scratch dropped"; exit 0; fi
drop
mkdir -p "$VAR"/{lake,lake_plain,landing,tmp,backup,restore}
chmod 700 "$VAR"
LAKE_PW=$(openssl rand -hex 24); QUERY_PW=$(openssl rand -hex 24)
"${PSQL[@]}" -d postgres -c "CREATE ROLE dl_eng_lake  LOGIN PASSWORD '$LAKE_PW'"
"${PSQL[@]}" -d postgres -c "CREATE ROLE dl_eng_query LOGIN PASSWORD '$QUERY_PW'"
for db in dl_engine dl_engine_restore; do
  "${PSQL[@]}" -d postgres -c "CREATE DATABASE $db"
  # The lake's catalog: DuckLake creates its tables here at first attach, and the writer creates inlined-data tables as it
  # goes — so the query role's SELECT must also be a DEFAULT privilege of the owner. The writer also needs TEMPORARY on the
  # database: DuckLake updates catalog rows through a temporary table (FINDINGS §2).
  "${PSQL[@]}" -d "$db" \
    -c "REVOKE ALL ON DATABASE $db FROM PUBLIC" \
    -c "GRANT CONNECT ON DATABASE $db TO dl_eng_lake, dl_eng_query" \
    -c "GRANT TEMPORARY ON DATABASE $db TO dl_eng_lake" \
    -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC" \
    -c "CREATE SCHEMA ducklake AUTHORIZATION dl_eng_lake" \
    -c "GRANT USAGE ON SCHEMA ducklake TO dl_eng_query" \
    -c "ALTER DEFAULT PRIVILEGES FOR ROLE dl_eng_lake IN SCHEMA ducklake GRANT SELECT ON TABLES TO dl_eng_query" \
    -c "CREATE SCHEMA ducklake_plain AUTHORIZATION dl_eng_lake"
  # ducklake_plain: an UNENCRYPTED, uncompressed twin lake used only by the erasure proof, where a byte search of every file
  # on disk means something (an encrypted file never contains its plaintext).
done
# Passwords never appear in a connection string: duckdb_databases() shows an attached catalog's whole connection string, so
# each engine role reads its password from its own libpq passfile (production: one per service user, mode 0600).
: > "$VAR/lake.pgpass"; : > "$VAR/query.pgpass"
for db in dl_engine dl_engine_restore; do
  echo "127.0.0.1:5432:$db:dl_eng_lake:$LAKE_PW"   >> "$VAR/lake.pgpass"
  echo "127.0.0.1:5432:$db:dl_eng_query:$QUERY_PW" >> "$VAR/query.pgpass"
done
chmod 600 "$VAR/lake.pgpass" "$VAR/query.pgpass"
{
  echo "export DL_LAKE_DB_DSN='dbname=dl_engine host=127.0.0.1 port=5432 user=dl_eng_lake passfile=$VAR/lake.pgpass'"
  echo "export DL_QUERY_DB_DSN='dbname=dl_engine host=127.0.0.1 port=5432 user=dl_eng_query passfile=$VAR/query.pgpass'"
  echo "export DL_RESTORE_DB_DSN='dbname=dl_engine_restore host=127.0.0.1 port=5432 user=dl_eng_lake passfile=$VAR/lake.pgpass'"
  echo "export DL_RESTORE_QUERY_DSN='dbname=dl_engine_restore host=127.0.0.1 port=5432 user=dl_eng_query passfile=$VAR/query.pgpass'"
  echo "export DL_STORAGE_DIR='$VAR'"
  echo "export DL_EXTENSION_DIR='$HERE/services/.duckdb_extensions'"
} > "$VAR/secrets.env"
chmod 600 "$VAR/secrets.env"
echo "scratch ready: $VAR"
