#!/usr/bin/env bash
# The timing set: 50 million rows over 200 datasets through the real code paths under the design's caps. Takes ~10–30 min and
# ~5 GB of scratch disk; nothing else may run heavy meanwhile. The numbers land in var/timing.json; the scratch is dropped.
#   TIMING_ROWS=5000000 TIMING_DATASETS=20 tests/engine/timing.sh   (a quick run)
set -uo pipefail
HERE="$(cd "$(dirname "$0")/../.." && pwd)"
PY="$HERE/services/.venv/bin/python"
"$HERE/tests/engine/setup_scratch.sh" >/dev/null 2>&1 || exit 1
# shellcheck disable=SC1091
source "$HERE/var/engine/secrets.env"
(cd "$HERE/tests/engine" && "$PY" -u timing.py); rc=$?
[ "${KEEP:-0}" = "1" ] || "$HERE/tests/engine/setup_scratch.sh" --drop >/dev/null 2>&1
exit $rc
