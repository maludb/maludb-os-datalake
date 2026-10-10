#!/usr/bin/env bash
# Fetch the DuckDB extensions the Data Lake loads — ducklake, postgres_scanner, excel, and httpfs ONLY because DuckLake's
# ENCRYPTED writes need its OpenSSL crypto module (the writer loads it; the query service never does) — for exactly the pinned
# DuckDB version and platform, into a directory.
# deploy/ROOT_STEPS.sh calls it with a root-owned directory and then makes that directory read-only; development uses
# services/.duckdb_extensions/. At runtime every connection sets extension_directory to it, turns autoinstall and autoload
# OFF and LOADs each extension by name — nothing is ever downloaded by a running service.
#
#   services/bin/fetch_extensions.sh <dir> [python]
set -euo pipefail
DIR="${1:?usage: fetch_extensions.sh <dir> [python]}"
HERE="$(cd "$(dirname "$0")/.." && pwd)"
PY="${2:-$HERE/.venv/bin/python}"
mkdir -p "$DIR"
"$PY" - "$DIR" <<'EOF'
import sys, duckdb
d = sys.argv[1]
assert duckdb.__version__ == "1.5.6", f"pinned DuckDB is 1.5.6, found {duckdb.__version__}"
con = duckdb.connect(config={"extension_directory": d, "autoinstall_known_extensions": False,
                             "autoload_known_extensions": False})
for ext in ("ducklake", "postgres_scanner", "excel", "httpfs"):
    con.execute(f"INSTALL {ext}")
    con.execute(f"LOAD {ext}")
rows = con.execute("SELECT extension_name, extension_version, install_path FROM duckdb_extensions() "
                   "WHERE installed AND extension_name IN ('ducklake','postgres_scanner','excel','httpfs') ORDER BY 1").fetchall()
for r in rows:
    print(f"{r[0]:18} {r[1]:12} {r[2]}")
if len(rows) != 4:
    sys.exit("not every extension installed")
EOF
