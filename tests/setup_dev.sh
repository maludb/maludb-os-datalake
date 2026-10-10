#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $DL_DEV_DB, default dl_dev0)
# 1. drops and creates the scratch database; 2. applies db/[0-9]*.sql in order as postgres (the same files the installer's provision
#    applies — db/001–005 alone are enough for the kit's proof; DL_DEV_UPTO=005 stops there while the schema's later files are being
#    written); 3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env); else gives them a
#    scratch password (the kernel's installer sets fresh ones at `apply`); the engine's two roles (datalake_lake, datalake_query) are
#    made NOLOGIN by db/006 and need nothing here — the engine proof (tests/engine/) gives them what it needs; 4. makes a scratch data
#    directory ($DL_DEV_STORAGE, default /tmp/<db>-storage: landing/ lake/ exports/ tmp/ backup/); 5. writes the proofs' environment to
#    $DL_DEV_ENV (default /tmp/dl-dev0.env, mode 600) — app/bootstrap.php reads that file instead of config/.env when DL_DEV_ENV is set
#    (never in production). Nothing here is committed or installed. Ports 9001–9005 (9006–9007 are the engine proof's).
# With DL_DEV_ENGINE=1 (Phase 2 onward — the proofs that run the writer and the query service): 6. records the applied files in the
#    provision script's ledger and runs deploy/os-provision.sh against the scratch database exactly as the installer would (DL_ENV_FILE =
#    the scratch environment, DL_PASS_DIR = var/pass-<db>): the engine's two roles get LOGIN and a password in a passfile (never in a
#    DSN), schema ducklake is made and granted; 7. DuckLake's first attach (services/bin/ducklake_init.py, as you — the provision script
#    skips it when not root); 8. the provision script again, which now applies db/engine/*.sql. Refused when the application is installed
#    here (config/.env names DL_LAKE_DB_DSN): the engine's roles are the installation's then.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${DL_DEV_DB:-dl_dev0}
ENVF=${DL_DEV_ENV:-/tmp/dl-dev0.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
UPTO=${DL_DEV_UPTO:-999}
for f in "$ROOT"/db/[0-9]*.sql; do n=$(basename "$f" | cut -c1-3); [ "$((10#$n))" -le "$((10#$UPTO))" ] 2>/dev/null || continue; $PSQL -d "$DB" -f "$f" >/dev/null || { echo "FAILED: $f"; exit 1; }; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in datalake_rw datalake_records_ro datalake_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
STORE=${DL_DEV_STORAGE:-/tmp/${DB}-storage}          # the data directory (ROOT_STEPS step 3): a scratch one, emptied
rm -rf "$STORE"; mkdir -p "$STORE"/{landing,lake,exports,tmp,backup}
RUNDIR=$ROOT/var/run                                  # the query service's sockets (the prefix DL_QUERY_SOCKET; /run/datalake in production)
PASSDIR=$ROOT/var/pass-$DB                            # the engine's passfiles and the masking salt (/etc/datalake in production)
mkdir -p "$RUNDIR"; rm -rf "$PASSDIR"; mkdir -p "$PASSDIR"; chmod 700 "$PASSDIR"
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Data Lake"
APP_KEY=datalake
APP_URL=http://127.0.0.1:9001
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=datalake_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=datalake_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=datalake_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=9001
MCP_RECORDS_PORT=9004
MCP_ACTIVITY_PORT=9005
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:9002
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:9003
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
MALUMAIL_API_URL=http://127.0.0.1:9003
MALUMAIL_API_KEY=dev-malumail-$(openssl rand -hex 8)
MAIL_FROM=datalake@example.invalid
MAIL_FROM_NAME="Data Lake"
DL_STORAGE_DIR=$STORE
DL_BACKUP_DIR=$STORE/backup
DL_QUERY_SOCKET=$RUNDIR/${DB}-query
DL_QUERY_WORKERS=${DL_DEV_QUERY_WORKERS:-2}
DL_WRITER_MEMORY=1GB
DL_QUERY_MEMORY=
DL_EXTENSION_DIR=$ROOT/services/.duckdb_extensions
DL_LAKE_DB_DSN=
DL_QUERY_DB_DSN=
DL_MASK_SALT_FILE=$PASSDIR/mask_salt
ENV
( umask 077; head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' > "$PASSDIR/mask_salt" )
if [ "${DL_DEV_ENGINE:-0}" = 1 ]; then
  if [ -f "$LIVEENV" ] && [ -n "$(lv DL_LAKE_DB_DSN)" ]; then echo "REFUSED: the application is installed here (config/.env names DL_LAKE_DB_DSN) — the engine's roles are the installation's"; exit 2; fi
  $PSQL -d "$DB" -c "CREATE TABLE IF NOT EXISTS public.schema_migrations (file text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now()); REVOKE ALL ON public.schema_migrations FROM PUBLIC;"
  for f in "$ROOT"/db/[0-9]*.sql; do n=$(basename "$f" | cut -c1-3); [ "$((10#$n))" -le "$((10#$UPTO))" ] 2>/dev/null || continue; $PSQL -d "$DB" -c "INSERT INTO public.schema_migrations (file) VALUES ('$(basename "$f")') ON CONFLICT DO NOTHING"; done
  prov() { DB_NAME=$DB APP_DIR=$ROOT DL_ENV_FILE=$ENVF DL_PASS_DIR=$PASSDIR bash "$ROOT/deploy/os-provision.sh" >/dev/null; }
  prov || { echo "FAILED: deploy/os-provision.sh (the engine's roles)"; exit 1; }
  ( set -a; . "$ENVF"; set +a; "$ROOT/services/.venv/bin/python" "$ROOT/services/bin/ducklake_init.py" >/dev/null ) || { echo "FAILED: DuckLake's first attach"; exit 1; }
  prov || { echo "FAILED: deploy/os-provision.sh (the engine views)"; exit 1; }
fi
echo "scratch database $DB ready; environment in $ENVF"
