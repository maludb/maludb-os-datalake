"""Profiles — computed by the writer on every load (design §6.2, R5, R14): per column null %, distinct (approximate, as
DuckDB's SUMMARIZE gives it), min, max, mean, standard deviation, text length range, the top 10 values, and the classifier's
proposal. **A column the classifier or a person marks personal never has a raw value in its profile**: its min, max and top
values are masked the way the query service masks them (email → ``a***@example.com``, anything else → ``A****e``).
Profiles are stored by snapshot (``column_profiles``) so drift is a comparison of two rows.
"""
from __future__ import annotations

import re

import duckdb

from .classify import classify_column
from .engine import columns_of, current_snapshot, qi, table_ref

TOP_N = 10
SAMPLE_FOR_CLASSIFIER = 1000


def mask_text(v, kind: str | None) -> str | None:
    if v is None:
        return None
    s = str(v)
    if kind == "email" or re.match(r"^[^@\s]+@", s):
        return re.sub(r"^(.)[^@]*", r"\1***", s)
    if len(s) <= 2:
        return "**"
    return s[0] + "*" * min(len(s) - 2, 6) + s[-1]


def profile(con: duckdb.DuckDBPyConnection, key: str, *, personal: dict[str, str] | None = None, alias: str = "lake",
            classify: bool = True) -> dict:
    """``personal`` = column → personal kind already confirmed (masked whatever the classifier says)."""
    personal = dict(personal or {})
    ref = table_ref(key, alias)
    cols = [(c, t) for c, t in columns_of(con, key, alias) if not c.startswith("_dl_")]
    rows = int(con.execute(f"SELECT count(*) FROM {ref}").fetchone()[0])
    out = {"dataset": key, "snapshot_id": current_snapshot(con, alias), "row_count": rows, "columns": {}}
    if not cols:
        return out
    summ = {r[0]: r for r in con.execute(
        f"SELECT column_name, column_type, min, max, approx_unique, avg, std, null_percentage "
        f"FROM (SUMMARIZE SELECT {', '.join(qi(c) for c, _ in cols)} FROM {ref})").fetchall()}
    for c, t in cols:
        s = summ.get(c)
        proposal = None
        if classify:
            sample = [r[0] for r in con.execute(
                f"SELECT {qi(c)}::VARCHAR FROM {ref} WHERE {qi(c)} IS NOT NULL USING SAMPLE {SAMPLE_FOR_CLASSIFIER} ROWS").fetchall()]
            proposal = classify_column(c, sample)
        kind = personal.get(c) or (proposal or {}).get("kind")
        top = con.execute(f"SELECT {qi(c)}::VARCHAR AS v, count(*) AS n FROM {ref} WHERE {qi(c)} IS NOT NULL "
                          f"GROUP BY 1 ORDER BY 2 DESC, 1 LIMIT {TOP_N}").fetchall()
        lens = con.execute(f"SELECT min(length({qi(c)}::VARCHAR)), max(length({qi(c)}::VARCHAR)) FROM {ref}").fetchone()
        null_pct = float(s[7]) if s and s[7] is not None else None
        distinct = int(s[4]) if s and s[4] is not None else None
        entry = {
            "type": t,
            "null_pct": round(null_pct, 3) if null_pct is not None else None,
            "distinct_approx": distinct,
            "distinct_pct": round(100.0 * distinct / rows, 3) if distinct is not None and rows else None,
            "min": s[2] if s else None,
            "max": s[3] if s else None,
            "mean": s[5] if s else None,
            "stddev": s[6] if s else None,
            "length_min": lens[0],
            "length_max": lens[1],
            "top": [{"value": v, "count": int(n)} for v, n in top],
            "personal_kind": kind,
            "classifier": proposal,
        }
        if kind:
            entry["min"] = mask_text(entry["min"], kind)
            entry["max"] = mask_text(entry["max"], kind)
            entry["top"] = [{"value": mask_text(x["value"], kind), "count": x["count"]} for x in entry["top"]]
            entry["masked"] = True
        out["columns"][c] = entry
    return out
