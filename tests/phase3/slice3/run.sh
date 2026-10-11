#!/usr/bin/env bash
# Slice 3 (the query service, the SQL editor, saved queries and exports — THE SECOND EXEMPLAR, docs/build-specs/query-service-editor.md "Proof";
# query-service.md §9) — every proof on a fresh SCRATCH database (dl_dev3; never the installed one) with the ENGINE real: the writer and the
# query service run as their units run them (two instances on their sockets), DuckLake on the scratch catalog, the data directory under /tmp:
#   tests/phase3/slice3/run.sh                the app under php -S with tests/dev_router.php
#   DL_APP=apache tests/phase3/slice3/run.sh  the app under a real Apache serving the rendered deploy vhost (internal port 9007)
# The slice's lake (tests/phase3/slice3/seed.py through lib.php's seed_slice3()): orders with a personal email, payroll, a department-filtered
# table, 120,000 events, two twins — real tables written by the writer's library. Screenshots in $SHOTS (/tmp/dl-shots/slice3). Ends with one
# line per proof; exit 0 only if all passed. Run slices 2 and 1 and Phase 2's suites after this one.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DL_DEV_DB=${DL_DEV_DB:-dl_dev3} DL_DEV_ENV=${DL_DEV_ENV:-/tmp/dl-dev3.env} DL_DEV_STATE=${DL_DEV_STATE:-/tmp/dl-dev3-state} SHOTS=${SHOTS:-/tmp/dl-shots/slice3}
[ -e "$ROOT/config/.env" ] && grep -q '^DB_PASSWORD=.' "$ROOT/config/.env" && { echo "ABORT: the application looks installed (config/.env has DB_PASSWORD); the proofs are for a scratch clone"; exit 2; }
for port in 9001 9002 9003 9007; do (exec 3<>/dev/tcp/127.0.0.1/$port) 2>/dev/null && { echo "ABORT: port $port is taken"; exit 2; }; done
mkdir -p "$SHOTS"
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
echo "== the slice's lake (seed.py: real tables through the writer's library)"
php -r 'require "tests/phase3/slice3/lib.php"; seed_slice3(); echo "  ok   seeded\n";' || { echo "  FAIL the seed"; exit 1; }
for p in editor refusals saved verify sandbox exports audit json; do
  echo "== $p"; php "tests/phase3/slice3/$p.php" || status=1
done
echo "== service (the socket: the request kinds, the forbidden forms, the export stream)"; services/.venv/bin/python -W ignore::DeprecationWarning tests/phase3/slice3/service_test.py || status=1
echo "== browser"; node tests/phase3/slice3/browser.mjs || status=1
log="$DL_DEV_STATE/app.log"; [ -f "$DL_DEV_STATE/apache/apache.err" ] && log="$log $DL_DEV_STATE/apache/apache.err"
if cat $log 2>/dev/null | grep -qE 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error'; then echo "  FAIL the server log holds a PHP warning or error:"; cat $log | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error' | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
if grep -qa 'Traceback' "$DL_DEV_STATE"/writer.log "$DL_DEV_STATE"/query-*.log 2>/dev/null; then echo "  FAIL the engine's logs hold a traceback:"; grep -a -A3 'Traceback' "$DL_DEV_STATE"/writer.log "$DL_DEV_STATE"/query-*.log | head -8; status=1; else echo "  ok   no traceback in the writer's or the query service's logs"; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 3 PROOFS PASSED" || echo "SOME SLICE 3 PROOFS FAILED"
exit $status
