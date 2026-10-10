"""Keeping the lake healthy — the writer's nightly pass and on demand (design §6.7, R58–R59), in DuckLake's own functions and
in this order (each step's input is the previous step's output):

1. ``ducklake_flush_inlined_data``   — inlined small batches (rows living in the catalog) → Parquet files
2. ``ducklake_merge_adjacent_files``  — small files merged toward ``target_file_size`` (default 128 MB)
3. ``ducklake_rewrite_data_files``    — files carrying many deleted rows rewritten without them (``delete_threshold``)
4. ``ducklake_expire_snapshots``      — snapshots older than the time-travel window forgotten (``older_than``)
5. ``ducklake_cleanup_old_files``     — files no snapshot references any more, older than the safety margin, deleted
6. ``ducklake_delete_orphaned_files`` — files on disk the catalog never knew (a crashed write) deleted after the margin

Never run while a backup holds ``backup_lock`` (the catalog dump and the file copy must agree — DuckLake's guide).
"""
from __future__ import annotations

import datetime as dt
import time

import duckdb

from .engine import commit, current_snapshot


def run(con: duckdb.DuckDBPyConnection, *, author: str, snapshot_keep_days: float = 30, cleanup_margin_days: float = 3,
        target_file_size: int = 128 * 1024 * 1024, delete_threshold: float = 0.1, alias: str = "lake",
        now: dt.datetime | None = None) -> dict:
    t_all = time.monotonic()
    now = now or dt.datetime.now(dt.timezone.utc)
    steps = {}

    def step(name, sql, params=None):
        t0 = time.monotonic()
        rows = con.execute(sql, params or []).fetchall()
        steps[name] = {"rows": len(rows), "ms": int((time.monotonic() - t0) * 1000)}
        return rows

    with commit(con, author, "maintenance: flush inlined data", alias):
        step("flush_inlined", f"SELECT * FROM ducklake_flush_inlined_data('{alias}')")
    step("merge_adjacent", f"SELECT * FROM ducklake_merge_adjacent_files('{alias}', max_file_size => ?)", [target_file_size])
    step("rewrite_deletes", f"SELECT * FROM ducklake_rewrite_data_files('{alias}', delete_threshold => ?)", [delete_threshold])
    keep = now - dt.timedelta(days=snapshot_keep_days)
    step("expire_snapshots", f"SELECT * FROM ducklake_expire_snapshots('{alias}', older_than => ?)", [keep])
    margin = now - dt.timedelta(days=cleanup_margin_days)
    step("cleanup_old_files", f"SELECT * FROM ducklake_cleanup_old_files('{alias}', older_than => ?)", [margin])
    step("delete_orphans", f"SELECT * FROM ducklake_delete_orphaned_files('{alias}', older_than => ?)", [margin])
    return {"status": "done", "steps": steps, "snapshot_id": current_snapshot(con, alias),
            "ms": int((time.monotonic() - t_all) * 1000)}


def file_census(con: duckdb.DuckDBPyConnection, alias: str = "lake") -> dict:
    """Live files per table (for storage_daily and the proofs)."""
    rows = con.execute(f"SELECT table_name, file_count, file_size_bytes, delete_file_count FROM ducklake_table_info('{alias}')").fetchall()
    return {r[0]: {"files": int(r[1]), "bytes": int(r[2]), "delete_files": int(r[3])} for r in rows}
