#!/usr/bin/env bash
# Slice 1 (the catalog, the CRUD exemplar — docs/build-specs/catalog.md "Proof") — every proof on a fresh SCRATCH database (dl_dev1; never
# the installed one):
#   tests/phase3/slice1/run.sh                the app under php -S with tests/dev_router.php
#   DL_APP=apache tests/phase3/slice1/run.sh  the app under a real Apache serving the rendered deploy vhost (internal port 9007)
# The Phase 2 servers (tests/phase2/servers.sh: the app :9001, the fake kernel :9002, the fake MaluDB + MaluMail :9003, the writer and the
# query service on Unix sockets) on the engine-provisioned scratch database (DL_DEV_ENGINE=1). Screenshots in $SHOTS (/tmp/dl-shots/slice1).
# Ends with one line per proof; exit 0 only if all passed. Phase 2's suite (tests/phase2/run.sh) is separate: run it after this one.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export DL_DEV_DB=${DL_DEV_DB:-dl_dev1} DL_DEV_ENV=${DL_DEV_ENV:-/tmp/dl-dev1.env} DL_DEV_STATE=${DL_DEV_STATE:-/tmp/dl-dev1-state} SHOTS=${SHOTS:-/tmp/dl-shots/slice1}
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
for p in catalog columns tags glossary walls json; do
  echo "== $p"; php "tests/phase3/slice1/$p.php" || status=1
done
echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1
log="$DL_DEV_STATE/app.log"; [ -f "$DL_DEV_STATE/apache/apache.err" ] && log="$log $DL_DEV_STATE/apache/apache.err"
if cat $log 2>/dev/null | grep -qE 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error'; then echo "  FAIL the server log holds a PHP warning or error:"; cat $log | grep -E 'PHP (Warning|Notice|Deprecated|Fatal)|Uncaught|json error' | head -5; status=1; else echo "  ok   no PHP warning, notice or uncaught error in the server log"; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
