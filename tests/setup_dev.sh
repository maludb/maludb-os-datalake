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
DL_QUERY_SOCKET=/tmp/${DB}-query.sock
DL_QUERY_WORKERS=1
DL_WRITER_MEMORY=
DL_QUERY_MEMORY=
DL_EXTENSION_DIR=
DL_LAKE_DB_DSN=
DL_QUERY_DB_DSN=
DL_MASK_SALT_FILE=
ENV
echo "scratch database $DB ready; environment in $ENVF"
