"""DuckLake's first attach — run by deploy/os-provision.sh (step 3) as the system user datalake-writer, on every apply (idempotent).

The first attach creates DuckLake's catalog tables in schema `ducklake` (owned by datalake_lake, made by the provision script), records
the data path and ENCRYPTED in ducklake_metadata, sets the inlining limit, and creates the zones (raw, curated, product, sandbox,
quarantine). A later run finds all of it and changes nothing. Configuration from the environment (DL_LAKE_DB_DSN with a passfile,
DL_QUERY_DB_DSN, DL_STORAGE_DIR, DL_EXTENSION_DIR) — the same LakeConfig the writer service uses.
"""
from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))

from lake.engine import LakeConfig, ZONES, writer_connection, current_snapshot  # noqa: E402


def main() -> int:
    cfg = LakeConfig.from_env()
    os.makedirs(cfg.data_path, exist_ok=True)
    con = writer_connection(cfg)
    try:
        have = sorted(r[0] for r in con.execute(
            "SELECT schema_name FROM duckdb_schemas() WHERE database_name = 'lake' AND schema_name = ANY(?)", [list(ZONES)]).fetchall())
        print(f"ducklake: catalog attached at snapshot {current_snapshot(con)}; zones {', '.join(have)}; data path {cfg.data_path}")
    finally:
        con.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
