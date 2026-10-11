#!/usr/bin/env bash
# Slice 2 (uploads, loads and the sandbox — THE FIRST EXEMPLAR, docs/build-specs/uploads-loads.md "Proof") — every proof on a fresh SCRATCH
# database (dl_dev2s; never the installed one) with the ENGINE real: the writer and the query service run as their units run them, DuckLake on
# the scratch catalog, the data directory under /tmp:
#   tests/phase3/slice2/run.sh                the app under php -S with tests/dev_router.php
#   DL_APP=apache tests/phase3/slice2/run.sh  the app under a real Apache serving the rendered deploy vhost (internal port 9007)
# The Phase 2 servers (tests/phase2/servers.sh: the app :9001, the fake kernel :9002, the fake MaluDB + MaluMail :9003, the writer, the query
# service on Unix sockets). jobs_test.py runs the four job kinds in-process while the writer service is paused. Screenshots in $SHOTS
# (/tmp/dl-shots/slice2). Ends with one line per proof; exit 0 only if all passed. Run slices 1 and Phase 2's suites after this one.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DL_DEV_DB=${DL_DEV_DB:-dl_dev2s} DL_DEV_ENV=${DL_DEV_ENV:-/tmp/dl-dev2s.env} DL_DEV_STATE=${DL_DEV_STATE:-/tmp/dl-dev2s-state} SHOTS=${SHOTS:-/tmp/dl-shots/slice2}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
for port in 9001 9002 9003 9007; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
mkdir -p "$SHOTS"
rm -rf "$DL_DEV_STATE/fixtures"
DL_DEV_ENGINE=1 "$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
status=0
cd "$ROOT"
echo "== the schema (db/proof/phase0_proof.sql on the scratch database, rolled back)"
out=$(sudo -n -u postgres psql -v ON_ERROR_STOP=1 -d "$DL_DEV_DB" -f db/proof/phase0_proof.sql 2>&1); summary=$(echo "$out" | grep -E '[0-9]+ ok, [0-9]+ failed' | head -1 | sed 's/^ *//')
if echo "$summary" | grep -qE ' 0 failed'; then echo "  ok   schema proof: $summary"; else echo "  FAIL schema proof: $summary"; echo "$out" | grep -E '^ *FAIL|ERROR' | head -5; status=1; fi
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$DL_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$DL_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$DL_DEV_STATE/maludb.log" FAKE_MALUMAIL_LOG="$DL_DEV_STATE/malumail.log"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
echo "== jobs (the writer's four kinds in-process — the service paused)"
wpid=$(cat "$DL_DEV_STATE/writer.pid"); kill "$wpid" 2>/dev/null; for i in $(seq 1 50); do kill -0 "$wpid" 2>/dev/null || break; sleep 0.1; done
services/.venv/bin/python tests/phase3/slice2/jobs_test.py || status=1
services/.venv/bin/python services/lake/writer_main.py >>"$DL_DEV_STATE/writer.log" 2>&1 & echo $! > "$DL_DEV_STATE/writer.pid"
sleep 1
for p in upload map loads drift rejects preview json; do
  echo "== $p"; php "tests/phase3/slice2/$p.php" || status=1
done
echo "== browser"; node tests/phase3/slice2/browser.mjs || status=1
log="$DL_DEV_STATE/app.log"; [ -f "$DL_DEV_STATE/apache/apache.err" ] && log="$log $DL_DEV_STATE/apache/apache.err"
if cat $log 2>/dev/null | grep -qE 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error'; then echo "  FAIL the server log holds a PHP warning or error:"; cat $log | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error' | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
if grep -qa 'Traceback' "$DL_DEV_STATE"/writer.log "$DL_DEV_STATE"/query-*.log 2>/dev/null; then echo "  FAIL the engine's logs hold a traceback:"; grep -a -A3 'Traceback' "$DL_DEV_STATE"/writer.log "$DL_DEV_STATE"/query-*.log | head -8; status=1; else echo "  ok   no traceback in the writer's or the query service's logs"; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 2 PROOFS PASSED" || echo "SOME SLICE 2 PROOFS FAILED"
exit $status
