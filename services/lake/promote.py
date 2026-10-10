"""Promotion raw → curated, typed by the dataset's contract (design §6.4).

A contract: {"columns": [{"name", "type", "source"?, "nullable"?}], "key": [names], "ordering": "_dl_loaded_at"|column}.
Row-level checks (blocking ones quarantine): [{"kind": not_null|accepted_values|range|regex, "column", "values"|"min"/"max"|
"pattern", "blocking": bool, "name"?}].

For the raw rows that arrived since ``since_snapshot`` (the change feed — ``ducklake_table_insertions``) or every raw row
(``full=True``), in ONE commit:
  cast each contract column with TRY_CAST (a value that does not cast is a row-level failure ``cast:<column>``, never a
  crash) → run the row checks → rows failing a BLOCKING check go to ``quarantine.<name>`` with ``_dl_reason`` (idempotent:
  a row already quarantined from the same load and row is not quarantined twice) → the rest are deduplicated by the key
  (latest wins by the ordering, then load, then row) and MERGEd into ``curated.<name>``, carrying ``_dl_load_id``,
  ``_dl_row`` and ``_dl_loaded_at`` so a rollback or an erasure can follow a row.
Returns {rows_in, rows_promoted, rows_quarantined, warnings (non-blocking failures by check), raw_snapshot (to remember as
the next since), snapshot_id}.
"""
from __future__ import annotations

import time

import duckdb

from .engine import columns_of, commit, current_snapshot, qi, split_key, table_exists, table_ref

PROV = ("_dl_load_id", "_dl_row", "_dl_loaded_at")


def _lit(v) -> str:
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "TRUE" if v else "FALSE"
    if isinstance(v, (int, float)):
        return repr(v)
    return "'" + str(v).replace("'", "''") + "'"


def _check_expr(chk: dict, typed: str) -> str:
    """SQL that is TRUE when the row FAILS the check."""
    k = chk["kind"]
    if k == "not_null":
        return f"{typed} IS NULL"
    if k == "accepted_values":
        return f"{typed} IS NOT NULL AND {typed} NOT IN ({', '.join(_lit(v) for v in chk['values'])})"
    if k == "range":
        parts = []
        if chk.get("min") is not None:
            parts.append(f"{typed} < {_lit(chk['min'])}")
        if chk.get("max") is not None:
            parts.append(f"{typed} > {_lit(chk['max'])}")
        return f"{typed} IS NOT NULL AND ({' OR '.join(parts) or 'false'})"
    if k == "regex":
        return f"{typed} IS NOT NULL AND NOT regexp_full_match(CAST({typed} AS VARCHAR), {_lit(chk['pattern'])})"
    raise ValueError(f"not a row-level check: {k}")


def promote(con: duckdb.DuckDBPyConnection, *, raw_key: str, curated_key: str, contract: dict, checks: list[dict] | None,
            author: str, since_snapshot: int | None = None, full: bool = False, alias: str = "lake") -> dict:
    t0 = time.monotonic()
    rz, rname = split_key(raw_key)
    cz, cname = split_key(curated_key)
    if rz != "raw" or cz != "curated":
        raise ValueError("promotion is raw → curated")
    raw_ref, cur_ref = table_ref(raw_key, alias), table_ref(curated_key, alias)
    q_ref = f'{alias}."quarantine".{qi(cname)}'
    raw_cols = {c for c, _ in columns_of(con, raw_key, alias)}
    key = list(contract["key"])
    ordering = contract.get("ordering") or "_dl_loaded_at"
    cols = contract["columns"]
    for c in cols:
        src = c.get("source", c["name"])
        if src not in raw_cols:
            raise ValueError(f"contract column {c['name']} reads {src}, which raw.{rname} does not have")
    raw_snap = current_snapshot(con, alias)
    if full or since_snapshot is None:
        source_sql = f"SELECT * FROM {raw_ref}"
    elif since_snapshot >= raw_snap:
        return {"status": "nothing_new", "rows_in": 0, "rows_promoted": 0, "rows_quarantined": 0, "warnings": {},
                "raw_snapshot": raw_snap, "snapshot_id": raw_snap, "ms": int((time.monotonic() - t0) * 1000)}
    else:
        source_sql = (f"SELECT * FROM ducklake_table_insertions('{alias}', '{rz}', '{rname}', {int(since_snapshot) + 1}, "
                      f"{raw_snap})")
    typed_cols, reason_parts, warn_parts = [], [], []
    for c in cols:
        src, name, typ = c.get("source", c["name"]), c["name"], c["type"]
        if typ.upper() in ("VARCHAR", "TEXT", "STRING"):
            typed = f"{qi(src)}"
        else:
            typed = f"TRY_CAST(trim({qi(src)}) AS {typ})"
            reason_parts.append(f"CASE WHEN {qi(src)} IS NOT NULL AND trim({qi(src)}) <> '' AND {typed} IS NULL "
                                f"THEN 'cast:{name}' END")
        typed_cols.append((name, typ, typed, src))
        if not c.get("nullable", True):
            reason_parts.append(f"CASE WHEN {typed} IS NULL THEN 'not_null:{name}' END")
    typed_of = {n: t for n, _, t, _ in typed_cols}
    for chk in checks or []:
        expr = _check_expr(chk, typed_of[chk["column"]])
        label = chk.get("name") or f"{chk['kind']}:{chk['column']}"
        if chk.get("blocking", True):
            reason_parts.append(f"CASE WHEN {expr} THEN '{label}' END")
        else:
            warn_parts.append((label, expr))
    reasons = (f"list_filter([{', '.join(reason_parts)}], x -> x IS NOT NULL)" if reason_parts else "[]::VARCHAR[]")
    con.execute("DROP TABLE IF EXISTS _dl_promote")
    con.execute(f"""CREATE TEMP TABLE _dl_promote AS
        SELECT {', '.join(f'{t} AS {qi("t__" + n)}' for n, _, t, _ in typed_cols)},
               {', '.join(sorted({qi(s) for _, _, _, s in typed_cols}))},
               _dl_load_id, _dl_row, _dl_loaded_at, {reasons} AS _reasons
        FROM ({source_sql}) s""")
    rows_in = con.execute("SELECT count(*) FROM _dl_promote").fetchone()[0]
    warnings = {}
    for label, expr in warn_parts:
        e = expr
        for n in typed_of:
            e = e.replace(typed_of[n], qi("t__" + n))
        warnings[label] = int(con.execute(f"SELECT count(*) FROM _dl_promote WHERE {e}").fetchone()[0])
    order = f'{qi("t__" + ordering) if ordering in typed_of else qi(ordering)} DESC NULLS LAST, _dl_load_id DESC, _dl_row DESC'
    with commit(con, author, f"promote {raw_key} → {curated_key}" + (" (full)" if full else ""), alias):
        if not table_exists(con, curated_key, alias):
            ddl = ", ".join(f"{qi(n)} {t}" for n, t, _, _ in typed_cols)
            con.execute(f'CREATE TABLE {cur_ref} ({ddl}, "_dl_load_id" BIGINT, "_dl_row" BIGINT, '
                        f'"_dl_loaded_at" TIMESTAMP WITH TIME ZONE)')
        if not table_exists(con, f"quarantine.{cname}", alias):
            srcs = sorted({s for _, _, _, s in typed_cols})
            con.execute(f'CREATE TABLE {q_ref} ({", ".join(f"{qi(s)} VARCHAR" for s in srcs)}, "_dl_load_id" BIGINT, '
                        f'"_dl_row" BIGINT, "_dl_loaded_at" TIMESTAMP WITH TIME ZONE, "_dl_reason" VARCHAR, '
                        f'"_dl_quarantined_at" TIMESTAMP WITH TIME ZONE)')
        if full:
            con.execute(f"DELETE FROM {cur_ref}")
            con.execute(f"DELETE FROM {q_ref}")
        srcs = sorted({s for _, _, _, s in typed_cols})
        con.execute(f"""INSERT INTO {q_ref} ({', '.join(qi(s) for s in srcs)}, _dl_load_id, _dl_row, _dl_loaded_at, _dl_reason,
                                             _dl_quarantined_at)
            SELECT {', '.join('p.' + qi(s) for s in srcs)}, p._dl_load_id, p._dl_row, p._dl_loaded_at,
                   array_to_string(p._reasons, ', '), now()
            FROM _dl_promote p
            WHERE len(p._reasons) > 0
              AND NOT EXISTS (SELECT 1 FROM {q_ref} q WHERE q._dl_load_id = p._dl_load_id AND q._dl_row = p._dl_row)""")
        quarantined = con.execute("SELECT count(*) FROM _dl_promote WHERE len(_reasons) > 0").fetchone()[0]
        keycond = " AND ".join(f"t.{qi(k)} IS NOT DISTINCT FROM s.{qi(k)}" for k in key)
        names = [n for n, _, _, _ in typed_cols] + list(PROV)
        sel = ", ".join([f'{qi("t__" + n)} AS {qi(n)}' for n, _, _, _ in typed_cols] + list(PROV))
        oc = qi(ordering)
        deduped = (f"SELECT {sel} FROM _dl_promote WHERE len(_reasons) = 0 QUALIFY row_number() OVER "
                   f"(PARTITION BY {', '.join(qi('t__' + k) for k in key)} ORDER BY {order}) = 1")
        empty = con.execute(f"SELECT 1 FROM {cur_ref} LIMIT 1").fetchone() is None
        if empty:  # the first promotion or a full rebuild: nothing to match — a plain INSERT, no MERGE
            promoted = con.execute(f"INSERT INTO {cur_ref} ({', '.join(qi(n) for n in names)}) {deduped}").fetchone()[0]
        else:      # MERGE answers the rows it inserted or updated: every deduplicated row of this input
            promoted = con.execute(f"""MERGE INTO {cur_ref} AS t USING ({deduped}) AS s ON {keycond}
                WHEN MATCHED AND (s.{oc} >= t.{oc} OR t.{oc} IS NULL) THEN UPDATE SET {', '.join(f'{qi(n)} = s.{qi(n)}' for n in names)}
                WHEN NOT MATCHED THEN INSERT ({', '.join(qi(n) for n in names)}) VALUES ({', '.join('s.' + qi(n) for n in names)})"""
                                   ).fetchone()[0]
    con.execute("DROP TABLE IF EXISTS _dl_promote")
    return {"status": "promoted", "rows_in": int(rows_in), "rows_promoted": int(promoted), "rows_quarantined": int(quarantined),
            "warnings": warnings, "raw_snapshot": raw_snap, "snapshot_id": current_snapshot(con, alias),
            "ms": int((time.monotonic() - t0) * 1000)}
