#!/usr/bin/env bash
# Runs every Phase 2 proof (docs/build-specs/sso-shell.md "Proof") on a fresh SCRATCH database (never the installed one): tests/phase2/run.sh
#   DL_APP=apache tests/phase2/run.sh   serves the app from a real Apache with the rendered deploy vhost instead of php -S
#   needs: `sudo -n -u postgres`, php, services/.venv (the engine), node with the kernel's web/node_modules (Playwright + its Chromium, for
#   the browser proof). Screenshots go to $SHOTS (default /tmp/dl-shots). Ends with one line per proof and exit status 0 only if all passed.
#   Order matters: the first-run proof needs the empty lake, the browser proof seeds it midway (tests/phase2/seed.php), the rest run on the
#   seeded lake. Ports 9001 (the app; Apache's internal vhost on 9007), 9002 (the fake kernel), 9003 (the fake MaluDB + MaluMail); the engine
#   on Unix sockets under var/run. (Phase 0's kit proof and the engine proof are separate: run them one after the other — they share the ports.)
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export DL_DEV_DB=${DL_DEV_DB:-dl_dev2} DL_DEV_ENV=${DL_DEV_ENV:-/tmp/dl-dev2.env} DL_DEV_STATE=${DL_DEV_STATE:-/tmp/dl-dev2-state} SHOTS=${SHOTS:-/tmp/dl-shots}
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
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
echo "== firstrun"; php tests/phase2/firstrun.php || status=1
echo "== browser"; node tests/phase2/browser.mjs || status=1
for p in gates engine guard vhost picker switcher; do
  echo "== $p"; php "tests/phase2/$p.php" || status=1
done
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry and maludb-os.json approvals[] match the manifest" || { echo "  FAIL the registry or approvals[] is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL PHASE 2 PROOFS PASSED" || echo "SOME PHASE 2 PROOFS FAILED"
exit $status
