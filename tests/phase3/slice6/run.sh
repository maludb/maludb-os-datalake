#!/usr/bin/env bash
# Slice 6 (sources and pulls — docs/build-specs/sources-pulls.md "Proof"; replicates slices 1 and 2) — every proof on a fresh SCRATCH database (dl_dev6; never the
# installed one) with the ENGINE real: the writer and the query service run as their units run them, DuckLake on the scratch catalog, the data directory under /tmp:
#   tests/phase3/slice6/run.sh                the app under php -S with tests/dev_router.php
#   DL_APP=apache tests/phase3/slice6/run.sh  the app under a real Apache serving the rendered deploy vhost (internal port 9007)
# Beside the Phase 2 servers it starts the FIXTURES SERVER on :9004 (fixtures_server.php — a site the http_file connector fetches from: a CSV with an ETag and a
# 304, a robots-blocked path, a 403 wall, a workbook) and makes an "outside" PostgreSQL database dl_dev6_outside (a role of its own, reached as 127.0.0.2 — the
# guard refuses `localhost`). The K7 shares are answered by the fake kernel on :9002 (extended with /api/v1/apps/read.php; the spec's second fake kernel on :9005 is
# not needed — the application's kernel URL is already wired to :9002). The SFTP connector is proven through its transport seam with a directory fixture (no sshd).
# A pull runs in a child PHP process as the worker runs it. Screenshots in $SHOTS (/tmp/dl-shots/slice6). Ends with one line per proof; exit 0 only if all
# passed. Run slices 5, 4, 3, 2 and 1 and Phase 2's suites after this one.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DL_DEV_DB=${DL_DEV_DB:-dl_dev6} DL_DEV_ENV=${DL_DEV_ENV:-/tmp/dl-dev6.env} DL_DEV_STATE=${DL_DEV_STATE:-/tmp/dl-dev6-state} SHOTS=${SHOTS:-/tmp/dl-shots/slice6}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
"$ROOT/tests/phase2/servers.sh" stop >/dev/null 2>&1 || true
for port in 9001 9002 9003 9004 9007; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
mkdir -p "$SHOTS" "$DL_DEV_STATE"
OUTSIDE=${DL_OUTSIDE_DB:-dl_dev6_outside}; OUTROLE=dl6_outside_reader
psqlpg() { sudo -n -u postgres psql -X -q -v ON_ERROR_STOP=1 "$@"; }
cleanup() {
  [ -f "$DL_DEV_STATE/fx.pid" ] && kill "$(cat "$DL_DEV_STATE/fx.pid")" 2>/dev/null;
  "$ROOT/tests/phase2/servers.sh" stop
  psqlpg -c "DROP DATABASE IF EXISTS $OUTSIDE" -c "DROP ROLE IF EXISTS $OUTROLE" >/dev/null 2>&1
}
DL_DEV_ENGINE=1 "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
cd "$ROOT"
echo "== the schema (db/proof/phase0_proof.sql on the scratch database, rolled back)"
out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DL_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
if echo "$summary" | grep -qE ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; echo "$out" | grep -E '^ *FAIL|ERROR' | head -5; status=1; fi
# the outside database: a table of a hundred orders, read by a role of its own (reached as 127.0.0.2 — never `localhost`, which the guard refuses)
OUTPW=$(openssl rand -hex 12)
psqlpg -c "DROP DATABASE IF EXISTS $OUTSIDE" -c "DROP ROLE IF EXISTS $OUTROLE" >/dev/null 2>&1
psqlpg -c "CREATE ROLE $OUTROLE LOGIN PASSWORD '$OUTPW'" -c "CREATE DATABASE $OUTSIDE" || { echo "cannot make the outside database"; exit 1; }
psqlpg -d "$OUTSIDE" <<SQL || { echo "cannot fill the outside database"; exit 1; }
CREATE TABLE public.orders (id integer PRIMARY KEY, customer text NOT NULL, amount numeric(12,2) NOT NULL, note text, updated_at timestamptz NOT NULL);
INSERT INTO public.orders SELECT g, 'Customer ' || g, (g * 3.10)::numeric(12,2), CASE WHEN g % 10 = 0 THEN NULL ELSE 'note ' || g END, timestamptz '2026-09-01 00:00:00+00' + (g || ' minutes')::interval FROM generate_series(1, 120) g;
GRANT USAGE ON SCHEMA public TO $OUTROLE;
GRANT SELECT ON public.orders TO $OUTROLE;
SQL
echo "$OUTPW" > "$DL_DEV_STATE/outside.pw"
export DL6_OUTSIDE_DB="$OUTSIDE" DL6_OUTSIDE_USER="$OUTROLE" DL6_OUTSIDE_PW="$OUTPW" DL_PG_PAGE_ROWS=50
export FX_DIR="$DL_DEV_STATE/fx" FX_STATE="$DL_DEV_STATE/fx.json" FX_LOG="$DL_DEV_STATE/fx.log"
mkdir -p "$FX_DIR"; : > "$FX_LOG"; echo '{}' > "$FX_STATE"
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; cleanup; exit 1; }
# (after servers.sh: its start/stop kills every *.pid in the state directory)
php -S 127.0.0.1:9004 tests/phase3/slice6/fixtures_server.php >"$DL_DEV_STATE/fixtures.log" 2>&1 & echo $! > "$DL_DEV_STATE/fx.pid"
trap cleanup EXIT
set -a; . "$DL_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DL_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DL_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$DL_DEV_STATE/malumail.log"
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:9004/robots.txt && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
php -r 'require "tests/phase3/slice6/lib.php"; seed_slice6(); echo "  ok   seeded\n";' || { echo "  FAIL the seed"; exit 1; }
[ "${UP_ONLY:-0}" = 1 ] && { trap - EXIT; echo "up (UP_ONLY): servers left running; stop them with tests/phase2/servers.sh stop and kill the :9004 fixtures server"; exit 0; }       # while a proof is being written
for p in ${PROOFS:-sources pulls postgres k7 json}; do
  echo "== $p"; php "tests/phase3/slice6/$p.php" || status=1
done
[ "${NOBROWSER:-0}" = 1 ] || { echo "== browser"; node tests/phase3/slice6/browser.mjs || status=1; }
log="$DL_DEV_STATE/app.log"; [ -f "$DL_DEV_STATE/apache/apache.err" ] && log="$log $DL_DEV_STATE/apache/apache.err"
if cat $log 2>/dev/null | grep -qE 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error'; then echo "  FAIL the server log holds a PHP warning or error:"; cat $log | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error' | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
if grep -qa 'Traceback' "$DL_DEV_STATE"/writer.log "$DL_DEV_STATE"/query-*.log 2>/dev/null; then echo "  FAIL the engine's logs hold a traceback:"; grep -a -A3 'Traceback' "$DL_DEV_STATE"/writer.log "$DL_DEV_STATE"/query-*.log | head -8; status=1; else echo "  ok   no traceback in the writer's or the query service's logs"; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 6 PROOFS PASSED" || echo "SOME SLICE 6 PROOFS FAILED"
exit $status
