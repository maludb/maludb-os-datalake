#!/usr/bin/env bash
# The engine proof (design §10, Phase 0): re-runnable from nothing on the build host.
#   1. cpu.sh            — every pinned wheel on this CPU (x86-64-v2, no AVX)
#   2. setup_scratch.sh  — the scratch catalog databases, the two engine roles, var/engine
#   3. proof_engine.py   — the writer: sniff, raw loads, profiles, promotion, checks, transforms, time travel, rollback,
#                          inlining, maintenance, concurrency, erasure (encrypted and plaintext twin), backup and restore drill
#   4. setup_scratch.sh  — a clean scratch again
#   5. proof_query.py    — the one door: the allow-list, the views, the lock, the watchdog, memory, limits, the policy, the socket
#   6. setup_scratch.sh --drop (unless KEEP=1)
# Output: TAP lines and "N of N checks green" per proof; exit 0 only when every proof is green.
set -uo pipefail
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
PY="$HERE/services/.venv/bin/python"
if [ ! -x "$PY" ]; then
  python3 -m venv "$HERE/services/.venv" && "$PY" -m pip install -q -r "$HERE/services/requirements.txt" || exit 1
fi
[ -f "$HERE/services/.duckdb_extensions/v1.5.6/linux_amd64/ducklake.duckdb_extension" ] || \
  "$HERE/services/bin/fetch_extensions.sh" "$HERE/services/.duckdb_extensions" || exit 1
"$PY" "$HERE/tests/fixtures/make_fixtures.py" >/dev/null || exit 1
rc=0
echo "## cpu"
"$HERE/tests/engine/cpu.sh" || rc=1
for proof in proof_engine proof_query; do
  echo "## $proof"
  "$HERE/tests/engine/setup_scratch.sh" >/dev/null 2>&1 || { echo "not ok - scratch setup failed"; exit 1; }
  # shellcheck disable=SC1091
  source "$HERE/var/engine/secrets.env"
  (cd "$HERE/tests/engine" && timeout 900 "$PY" -u "$proof.py") || rc=1
done
[ "${KEEP:-0}" = "1" ] || "$HERE/tests/engine/setup_scratch.sh" --drop >/dev/null 2>&1
[ $rc -eq 0 ] && echo "# engine proof: ALL GREEN" || echo "# engine proof: FAILURES above"
exit $rc
