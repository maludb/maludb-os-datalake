#!/usr/bin/env bash
# Provision or upgrade the Data Lake's database beside the Business OS kernel (maludb-os.json → database.provision). The kernel's
# installer (bin/app_install.php) creates the database (owner DB_RW_ROLE) and the three roles, then runs THIS INSTEAD OF its own
# db/*.sql pass — as root, with DB_NAME, DB_RW_ROLE, DB_RECORDS_ROLE, DB_ACTIVITY_ROLE, APP_DIR, APP_KEY, APP_NAME, TENANT in the
# environment — on a fresh database and again on every later apply. Idempotent, in this order:
#   1. every db/[0-9]*.sql in order as postgres, each recorded once in public.schema_migrations (the cidery's ledger, 2026-10-04) —
#      a file already recorded is never run again; a new file is how an upgrade arrives;
#   2. the engine's two roles (made NOLOGIN by db/006 because later migrations grant to them): LOGIN with a password generated ONCE and
#      kept in config/.env as DL_LAKE_DB_DSN / DL_QUERY_DB_DSN (an existing password is kept; never echoed, never on a command line);
#      the schema ducklake owned by datalake_lake; datalake_query SELECT on ducklake.* (and on every future table, default privileges);
#      NOTHING on ducklake for the three installer roles — the catalog holds file paths and per-file encryption keys (design §6.8);
#   3. DuckLake's first attach (services/bin/ducklake_init.py, the engine's) as the system user datalake-writer, when the venv and the
#      user exist (deploy/ROOT_STEPS.sh steps 2 and 4) — else skipped with a note, and the next apply does it;
#   4. every db/engine/*.sql in order (views over DuckLake's catalog for the application — never selecting a key or a path), in the
#      same ledger, once the catalog's tables exist — else skipped with a note.
# For a scratch proof as a non-root developer: DL_ENV_FILE names the environment file to read and write (default
# $APP_DIR/config/.env); `sudo -n -u postgres` stands in for runuser; step 3 is skipped (it needs root).
set -euo pipefail
: "${DB_NAME:?DB_NAME}" "${APP_DIR:?APP_DIR}"
RW=${DB_RW_ROLE:-datalake_rw}
REC=${DB_RECORDS_ROLE:-datalake_records_ro}
ACT=${DB_ACTIVITY_ROLE:-datalake_activity_ro}
ENVF=${DL_ENV_FILE:-$APP_DIR/config/.env}
if [ "$(id -u)" = 0 ]; then AS_PG="runuser -u postgres --"; else AS_PG="sudo -n -u postgres"; fi
PSQL="$AS_PG psql -v ON_ERROR_STOP=1 -q -d $DB_NAME"
cd "$APP_DIR"

# one key of the environment file (quotes stripped), or empty
envval() { [ -f "$ENVF" ] && sed -n "s/^$1=//p" "$ENVF" | tail -1 | sed -E "s/^([\"'])(.*)\\1\$/\\2/" || true; }
# set one key in the environment file IN PLACE (owner, group and mode kept: the file is rewritten through its own inode)
envset() {
    local k=$1 v=$2 tmp
    tmp=$(mktemp)
    if [ -f "$ENVF" ]; then grep -v "^$k=" "$ENVF" > "$tmp" || true; fi
    printf '%s=%s\n' "$k" "$v" >> "$tmp"
    if [ -f "$ENVF" ]; then cat "$tmp" > "$ENVF"; else install -m 640 "$tmp" "$ENVF"; fi
    rm -f "$tmp"
}

echo "== 1. the schema (each db/[0-9]*.sql once, as postgres; ledger public.schema_migrations)"
$PSQL -c "CREATE TABLE IF NOT EXISTS public.schema_migrations (file text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now()); REVOKE ALL ON public.schema_migrations FROM PUBLIC;"
if [ "$($PSQL -At -c "select count(*) from public.schema_migrations")" = 0 ] && [ "$($PSQL -At -c "select to_regclass('public.members') is not null")" = t ]; then
    echo "STOP: $DB_NAME carries the schema but no ledger (migrated by hand or by a proof): record what it holds —"
    echo "      INSERT INTO public.schema_migrations (file) VALUES ('001_roles_and_identity.sql'), … — then run this again." >&2
    exit 3
fi
for f in $(ls db/[0-9]*.sql | sort); do
    b=$(basename "$f")
    if [ "$($PSQL -At -c "select count(*) from public.schema_migrations where file = '$b'")" = 1 ]; then continue; fi
    echo "   $b"
    $PSQL -f "$f" >/dev/null
    $PSQL -c "insert into public.schema_migrations (file) values ('$b')"
done

echo "== 2. the engine's roles and the schema ducklake"
for r in datalake_lake datalake_query; do
    if [ "$($PSQL -At -c "select count(*) from pg_roles where rolname = '$r'")" != 1 ]; then
        echo "STOP: role $r is missing — db/006 makes it; a migration did not run" >&2; exit 3
    fi
done
HOST=$(envval DB_HOST); HOST=${HOST:-127.0.0.1}
PORT=$(envval DB_PORT); PORT=${PORT:-5432}
# The two connection strings carry a libpq PASSFILE, never a password (tests/engine/FINDINGS.md §2: duckdb_databases() prints an
# attached catalog's conninfo verbatim; the engine refuses a DSN with `password=`). One passfile per service user, 0600, owned by it:
# /etc/datalake/lake.pgpass (datalake-writer), /etc/datalake/query.pgpass (datalake-query); a scratch run (not root) keeps them under
# var/pass/. An existing passfile's password is kept; a new one is generated; the password reaches psql on its standard input.
if [ "$(id -u)" = 0 ]; then PASSDIR=${DL_PASS_DIR:-/etc/datalake}; else PASSDIR=${DL_PASS_DIR:-$APP_DIR/var/pass}; fi
install -d -m 0755 "$PASSDIR"
for spec in "DL_LAKE_DB_DSN:datalake_lake:lake:datalake-writer" "DL_QUERY_DB_DSN:datalake_query:query:datalake-query"; do
    IFS=: read -r key role short svc <<EOS
$spec
EOS
    pf="$PASSDIR/$short.pgpass"
    pw=""
    if [ -r "$pf" ]; then pw=$(head -1 "$pf" | cut -d: -f5-); fi
    if [ -z "$pw" ]; then pw=$(openssl rand -hex 24); fi
    printf "ALTER ROLE %s LOGIN PASSWORD '%s';\n" "$role" "$pw" | $AS_PG psql -v ON_ERROR_STOP=1 -q -d "$DB_NAME" >/dev/null
    ( umask 077; printf '%s:%s:%s:%s:%s\n' "$HOST" "$PORT" "$DB_NAME" "$role" "$pw" > "$pf.new" && mv "$pf.new" "$pf" )
    if [ "$(id -u)" = 0 ] && id -u "$svc" >/dev/null 2>&1; then chown "$svc" "$pf"; fi
    chmod 0600 "$pf"
    want="host=$HOST port=$PORT dbname=$DB_NAME user=$role passfile=$pf"
    dsn=$(envval "$key")
    if [ "$dsn" != "$want" ]; then envset "$key" "$want"; echo "   $key written to $(basename "$ENVF") (a passfile — no password in the file)"; else echo "   $key kept"; fi
done
$PSQL <<SQL
CREATE SCHEMA IF NOT EXISTS ducklake AUTHORIZATION datalake_lake;
REVOKE ALL ON SCHEMA ducklake FROM PUBLIC;
REVOKE ALL ON SCHEMA ducklake FROM $RW, $REC, $ACT;
GRANT USAGE ON SCHEMA ducklake TO datalake_query;
GRANT SELECT ON ALL TABLES IN SCHEMA ducklake TO datalake_query;
ALTER DEFAULT PRIVILEGES FOR ROLE datalake_lake IN SCHEMA ducklake GRANT SELECT ON TABLES TO datalake_query;
-- tests/engine/FINDINGS.md §2: DuckLake updates catalog rows through a temporary table; a DuckLake connection can sit idle in transaction
GRANT TEMPORARY ON DATABASE $DB_NAME TO datalake_lake;
ALTER ROLE datalake_lake SET idle_in_transaction_session_timeout = '10min';
ALTER ROLE datalake_query SET idle_in_transaction_session_timeout = '2min';
SQL
echo "   ducklake owned by datalake_lake; datalake_query reads it; $RW, $REC and $ACT hold nothing on it"

echo "== 3. DuckLake's first attach (services/bin/ducklake_init.py as datalake-writer)"
PY=$APP_DIR/services/.venv/bin/python
if [ "$(id -u)" != 0 ]; then
    echo "   skipped: not root (a scratch proof) — the engine proof attaches DuckLake itself"
elif [ ! -x "$PY" ] || [ ! -f "$APP_DIR/services/bin/ducklake_init.py" ]; then
    echo "   skipped: services/.venv is not built yet (deploy/ROOT_STEPS.sh step 4) — the next apply attaches"
elif ! id -u datalake-writer >/dev/null 2>&1; then
    echo "   skipped: the system user datalake-writer does not exist yet (deploy/ROOT_STEPS.sh step 2) — the next apply attaches"
else
    # the engine's keys reach the script through its environment, read here from the file (never on a command line)
    while IFS= read -r line; do
        case "$line" in DB_HOST=*|DB_PORT=*|DB_NAME=*|DL_*=*) k=${line%%=*}; v=${line#*=}; v=$(printf '%s' "$v" | sed -E "s/^([\"'])(.*)\\1\$/\\2/"); export "$k=$v";; esac
    done < "$ENVF"
    export DB_NAME
    runuser -u datalake-writer -- "$PY" "$APP_DIR/services/bin/ducklake_init.py"
fi

echo "== 4. the views over DuckLake's catalog (each db/engine/*.sql once)"
if [ "$($PSQL -At -c "select to_regclass('ducklake.ducklake_snapshot') is not null")" != t ]; then
    echo "   skipped: DuckLake's catalog tables do not exist yet (step 3) — the next apply creates the views"
else
    for f in $(ls db/engine/*.sql 2>/dev/null | sort); do
        b=engine/$(basename "$f")
        if [ "$($PSQL -At -c "select count(*) from public.schema_migrations where file = '$b'")" = 1 ]; then continue; fi
        echo "   $b"
        $PSQL -f "$f" >/dev/null
        $PSQL -c "insert into public.schema_migrations (file) values ('$b')"
    done
fi
echo "== done: $DB_NAME"
