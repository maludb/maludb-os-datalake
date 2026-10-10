"""Transforms — a named SQL that makes a curated, product or sandbox dataset from others (design §6.6).

The SQL is the caller's (an engineer's or, in a sandbox, an analyst's), so it passes the SAME allow-list as a query, and it
reads its inputs through the SAME per-caller views (the policy is the transform owner's read level frozen at save time) —
a product never quietly widens what it exposes. Two modes:

* ``replace`` — the output is the SQL's whole result, in one commit (DELETE + INSERT when the columns are unchanged; a
  changed column set is a new table version, reported as ``schema_changed``).
* ``incremental_merge`` — each input view reads only the rows inserted or updated since that input's last snapshot (the
  change feed), and the result is MERGEd into the output by its key. Correct only for ROW-WISE SQL (projections, filters,
  joins to a dimension); aggregates and windows over a whole input must use ``replace`` (FINDINGS §9).
Returns {status, rows_written, mode, schema_changed, inputs: {key: snapshot}, snapshot_id}.
"""
from __future__ import annotations

import time

import duckdb

from query import allowlist
from query.policy import create_views, view_name

from .engine import columns_of, commit, current_snapshot, qi, split_key, table_exists, table_ref


def _change_view(con, alias: str, key: str, since: int, until: int, policy_ds: dict, salt_hex: str) -> str:
    """A view over the rows inserted or updated in ``key`` after ``since`` (masked exactly as its full view)."""
    from query.policy import mask_expr
    zone, name = split_key(key)
    types = dict(columns_of(con, key, alias))
    proj = []
    for c in policy_ds["columns"]:
        m = (policy_ds.get("masks") or {}).get(c)
        proj.append(f"{mask_expr(c, types[c], m, salt_hex)} AS {qi(c)}" if m else qi(c))
    where = f" AND ({policy_ds['row_filter']})" if policy_ds.get("row_filter") else ""
    vn = view_name(key)
    con.execute(f"CREATE OR REPLACE TEMP VIEW {qi(vn)} AS SELECT {', '.join(proj)} FROM "
                f"ducklake_table_changes('{alias}', '{zone}', '{name}', {since + 1}, {until}) "
                f"WHERE change_type IN ('insert', 'update_postimage'){where}")
    return vn


def transform(con: duckdb.DuckDBPyConnection, *, output_key: str, sql: str, policy: dict, author: str, name: str,
              version: int = 1, mode: str = "replace", key_columns: list[str] | None = None,
              since: dict[str, int] | None = None, table_functions: set[str] | None = None, alias: str = "lake") -> dict:
    t0 = time.monotonic()
    oz, _ = split_key(output_key)
    if oz not in ("curated", "product", "sandbox"):
        raise ValueError("a transform writes curated, product or sandbox")
    if mode not in ("replace", "incremental_merge"):
        raise ValueError("mode is replace or incremental_merge")
    out_ref = table_ref(output_key, alias)
    now = current_snapshot(con, alias)
    made = create_views(con, alias, policy)
    granted = dict(made["granted"])
    incremental = mode == "incremental_merge" and table_exists(con, output_key, alias) and since
    if incremental:
        if not key_columns:
            raise ValueError("incremental_merge needs the output's key")
        for k, snap in since.items():
            if k in policy["datasets"] and snap < now:
                granted[k] = _change_view(con, alias, k, snap, now, policy["datasets"][k], policy["salt_hex"])
    checked = allowlist.check(sql, granted, table_functions=table_functions or set())
    with commit(con, author, f"transform {name} v{version} → {output_key}", alias):
        con.execute("DROP TABLE IF EXISTS _dl_tx")
        con.execute(f"CREATE TEMP TABLE _dl_tx AS {checked.sql}")
        new_cols = [(r[0], r[1]) for r in con.execute(
            "SELECT column_name, data_type FROM duckdb_columns() WHERE database_name = 'temp' AND table_name = '_dl_tx' "
            "ORDER BY column_index").fetchall()]
        rows = con.execute("SELECT count(*) FROM _dl_tx").fetchone()[0]
        schema_changed = False
        if not table_exists(con, output_key, alias):
            con.execute(f"CREATE TABLE {out_ref} ({', '.join(f'{qi(c)} {t}' for c, t in new_cols)})")
        elif columns_of(con, output_key, alias) != new_cols:
            schema_changed = True
            con.execute(f"DROP TABLE {out_ref}")
            con.execute(f"CREATE TABLE {out_ref} ({', '.join(f'{qi(c)} {t}' for c, t in new_cols)})")
        names = ", ".join(qi(c) for c, _ in new_cols)
        if incremental and not schema_changed:
            cond = " AND ".join(f"t.{qi(k)} IS NOT DISTINCT FROM s.{qi(k)}" for k in key_columns)
            con.execute(f"""MERGE INTO {out_ref} AS t USING _dl_tx AS s ON {cond}
                WHEN MATCHED THEN UPDATE SET {', '.join(f'{qi(c)} = s.{qi(c)}' for c, _ in new_cols)}
                WHEN NOT MATCHED THEN INSERT ({names}) VALUES ({', '.join('s.' + qi(c) for c, _ in new_cols)})""")
        else:
            if not schema_changed:
                con.execute(f"DELETE FROM {out_ref}")
            con.execute(f"INSERT INTO {out_ref} ({names}) SELECT {names} FROM _dl_tx")
        con.execute("DROP TABLE IF EXISTS _dl_tx")
    return {"status": "ran", "mode": "incremental_merge" if incremental else "replace", "rows_written": int(rows),
            "schema_changed": schema_changed, "inputs": {k: now for k in checked.datasets},
            "datasets_read": checked.datasets, "snapshot_id": current_snapshot(con, alias),
            "ms": int((time.monotonic() - t0) * 1000)}
