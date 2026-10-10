"""Table-level checks (design §6.4; ODCS's library metrics with Great Expectations' and Soda's names mapped onto them —
nothing installed). Each result: {check, kind, value, threshold, passed, rows_failed, failing_keys}. ``failing_keys`` holds
at most 20 KEYS, never values, and a personal key is hashed with the installation salt before it leaves this function.

Kinds: not_null · unique · accepted_values · range · regex · row_count (min/max, or ``change_pct`` against ``previous``) ·
freshness (``max_age_hours`` against a timestamp column or ``_dl_loaded_at``) · referential (values exist in another
dataset's key) · custom_sql (a SELECT returning failing rows — the caller passes it through the allow-list first and gives
the rewritten SQL that names ``lake.<zone>.<name>``).
"""
from __future__ import annotations

import hashlib
import time

import duckdb

from .engine import qi, table_ref
from .promote import _check_expr, _lit

MAX_KEYS = 20


def _keys(con, ref: str, where: str, key_cols: list[str], personal: set[str], salt_hex: str) -> list:
    if not key_cols:
        return []
    rows = con.execute(f"SELECT {', '.join(qi(k) for k in key_cols)} FROM {ref} WHERE {where} LIMIT {MAX_KEYS}").fetchall()
    out = []
    for r in rows:
        vals = []
        for k, v in zip(key_cols, r):
            if k in personal and v is not None:
                v = "h:" + hashlib.sha256((salt_hex + str(v)).encode()).hexdigest()[:16]
            vals.append(None if v is None else str(v))
        out.append(vals[0] if len(vals) == 1 else vals)
    return out


def run_checks(con: duckdb.DuckDBPyConnection, key: str, checks: list[dict], *, key_columns: list[str] | None = None,
               personal: set[str] | None = None, salt_hex: str = "", alias: str = "lake") -> list[dict]:
    ref = table_ref(key, alias)
    personal = set(personal or ())
    key_columns = list(key_columns or [])
    results = []
    total = con.execute(f"SELECT count(*) FROM {ref}").fetchone()[0]
    for chk in checks:
        t0 = time.monotonic()
        k = chk["kind"]
        label = chk.get("name") or (f"{k}:{chk['column']}" if chk.get("column") else k)
        r = {"check": label, "kind": k, "value": None, "threshold": None, "passed": None, "rows_failed": 0, "failing_keys": []}
        if k in ("not_null", "accepted_values", "range", "regex"):
            fail = _check_expr(chk, qi(chk["column"]))
            n = con.execute(f"SELECT count(*) FROM {ref} WHERE {fail}").fetchone()[0]
            r.update(value=n, threshold=chk.get("max_failures", 0), passed=n <= chk.get("max_failures", 0), rows_failed=n,
                     failing_keys=_keys(con, ref, fail, key_columns, personal, salt_hex) if n else [])
        elif k == "unique":
            cols = chk.get("columns") or [chk["column"]]
            grp = ", ".join(qi(c) for c in cols)
            n = con.execute(f"SELECT coalesce(sum(c), 0) FROM (SELECT count(*) c FROM {ref} GROUP BY {grp} HAVING count(*) > 1)").fetchone()[0]
            r.update(value=int(n), threshold=0, passed=n == 0, rows_failed=int(n))
        elif k == "row_count":
            r["value"] = total
            ok = True
            if chk.get("min") is not None:
                ok = ok and total >= chk["min"]
            if chk.get("max") is not None:
                ok = ok and total <= chk["max"]
            if chk.get("change_pct") is not None and chk.get("previous"):
                ch = abs(total - chk["previous"]) * 100.0 / chk["previous"]
                r["change_pct"] = round(ch, 2)
                ok = ok and ch <= chk["change_pct"]
            r.update(threshold={x: chk.get(x) for x in ("min", "max", "change_pct") if chk.get(x) is not None}, passed=ok)
        elif k == "freshness":
            col = chk.get("column") or "_dl_loaded_at"
            age = con.execute(f"SELECT epoch(now() - max({qi(col)})) / 3600.0 FROM {ref}").fetchone()[0]
            r.update(value=None if age is None else round(age, 3), threshold=chk["max_age_hours"],
                     passed=age is not None and age <= chk["max_age_hours"])
        elif k == "referential":
            other = table_ref(chk["references"], alias)
            fail = (f"{qi(chk['column'])} IS NOT NULL AND {qi(chk['column'])} NOT IN "
                    f"(SELECT {qi(chk['references_column'])} FROM {other} WHERE {qi(chk['references_column'])} IS NOT NULL)")
            n = con.execute(f"SELECT count(*) FROM {ref} WHERE {fail}").fetchone()[0]
            r.update(value=n, threshold=0, passed=n == 0, rows_failed=n,
                     failing_keys=_keys(con, ref, fail, key_columns, personal, salt_hex) if n else [])
        elif k == "custom_sql":
            n = con.execute(f"SELECT count(*) FROM ({chk['rewritten_sql']}) f").fetchone()[0]
            r.update(value=n, threshold=0, passed=n == 0, rows_failed=n)
        else:
            raise ValueError(f"unknown check kind {k}")
        r["took_ms"] = int((time.monotonic() - t0) * 1000)
        results.append(r)
    return results


__all__ = ["run_checks", "_lit"]
