"""The query service — the ONE door to the lake's rows (design §6.5). One request = one fresh in-memory DuckDB:

1. the allow-list's first pass happens before any connection (a refusal costs nothing);
2. the lake is attached READ_ONLY through the query role under an unguessable alias;
3. the caller's views are created from the policy (masks, columns, row filters, the as-of snapshot);
4. THEN the connection is locked — ``allowed_directories`` = the lake's data path only, ``enable_external_access=false``,
   ``lock_configuration=true`` (memory, threads and spill already capped at connect);
5. THEN the caller's SQL — rewritten to name only the views — runs under a watchdog that calls ``interrupt()`` at the
   timeout; rows are fetched up to the limit + 1 (→ ``truncated``) and capped by bytes.

The answer: {status ok|refused|timeout|error, refusal_rule, message, columns, rows, truncated, masked_columns, datasets, ms}.
Every answer is what ``query_runs`` records (the caller's process writes the audit row; this library returns its fields).
"""
from __future__ import annotations

import datetime as dt
import decimal
import json
import threading
import time
import uuid

import duckdb

from lake.engine import LakeConfig, query_connection
from query import allowlist
from query.policy import PolicyError, create_views, validate

_TABLE_FUNCTIONS: set[str] | None = None


def _table_functions(cfg: LakeConfig) -> set[str]:
    global _TABLE_FUNCTIONS
    if _TABLE_FUNCTIONS is None:
        con = duckdb.connect(config={"extension_directory": cfg.extension_dir, "autoinstall_known_extensions": False,
                                     "autoload_known_extensions": False})
        for e in ("ducklake", "postgres_scanner", "excel", "httpfs"):
            try:
                con.execute(f"LOAD {e}")
            except Exception:
                pass
        _TABLE_FUNCTIONS = allowlist.table_function_names(con)
        con.close()
    return _TABLE_FUNCTIONS


def _jsonable(v):
    if v is None or isinstance(v, (bool, int, float, str)):
        return v
    if isinstance(v, decimal.Decimal):
        return str(v)
    if isinstance(v, (dt.date, dt.datetime, dt.time)):
        return v.isoformat()
    if isinstance(v, (bytes, bytearray)):
        return "\\x" + bytes(v).hex()
    if isinstance(v, uuid.UUID):
        return str(v)
    if isinstance(v, (list, tuple)):
        return [_jsonable(x) for x in v]
    if isinstance(v, dict):
        return {str(k): _jsonable(x) for k, x in v.items()}
    return str(v)


def run(cfg: LakeConfig, policy: dict, sql: str, *, as_of: int | None = None, params: dict | None = None,
        dsn: str | None = None, data_path: str | None = None) -> dict:
    t0 = time.monotonic()
    out = {"status": "error", "refusal_rule": None, "message": None, "columns": [], "rows": [], "truncated": False,
           "masked_columns": [], "datasets": [], "ms": 0}
    try:
        validate(policy)
    except PolicyError as e:
        out.update(status="refused", refusal_rule="policy", message=str(e))
        return _done(out, t0)
    lim = policy["limits"]
    tfs = _table_functions(cfg)
    # 1. refuse early — the policy's keys stand in for the view names on this first pass
    try:
        allowlist.check(sql, {k: k for k in policy["datasets"]}, table_functions=tfs, params=params)
    except allowlist.Refusal as r:
        out.update(status="refused", refusal_rule=r.rule, message=r.message, datasets=[r.dataset] if r.dataset else [])
        return _done(out, t0)
    con = None
    timer = None
    try:
        con, alias = query_connection(cfg, memory=str(lim.get("memory", "1.5GB")), threads=int(lim.get("threads", 2)),
                                      temp_max=str(lim.get("temp", "2GB")), dsn=dsn, data_path=data_path)
        made = create_views(con, alias, policy, as_of=as_of)
        try:
            checked = allowlist.check(sql, made["granted"], table_functions=tfs, params=params)
        except allowlist.Refusal as r:
            if r.rule == "not_granted" and r.dataset in made["absent_as_of"]:
                raise allowlist.Refusal("absent_as_of", f"{r.dataset} did not exist at snapshot {as_of}.", r.dataset)
            raise
        lake_dir = (data_path or cfg.data_path).rstrip("/") + "/"
        con.execute(f"SET allowed_directories = ['{lake_dir}']")
        con.execute("SET enable_external_access = false")
        con.execute("SET lock_configuration = true")
        out["datasets"] = checked.datasets
        used = {k for k in checked.datasets}
        out["masked_columns"] = [m for m in made["masked"] if m.rsplit(".", 1)[0] in used]
        timer = threading.Timer(lim["timeout_s"], con.interrupt)
        timer.daemon = True
        timer.start()
        cur = con.execute(checked.sql)
        out["columns"] = [{"name": d[0], "type": str(d[1])} for d in cur.description]
        rows = cur.fetchmany(lim["row_limit"] + 1)
        timer.cancel()
        if len(rows) > lim["row_limit"]:
            rows = rows[: lim["row_limit"]]
            out["truncated"] = True
        out["rows"] = [[_jsonable(v) for v in r] for r in rows]
        size = len(json.dumps(out["rows"], separators=(",", ":")))
        if size > lim["result_bytes"]:
            keep, total = [], 2
            for r in out["rows"]:
                total += len(json.dumps(r, separators=(",", ":"))) + 1
                if total > lim["result_bytes"]:
                    break
                keep.append(r)
            out["rows"], out["truncated"] = keep, True
            out["message"] = f"result cut at {lim['result_bytes']} bytes"
        out["status"] = "ok"
    except allowlist.Refusal as r:
        out.update(status="refused", refusal_rule=r.rule, message=r.message)
    except PolicyError as e:
        out.update(status="refused", refusal_rule="policy", message=str(e))
    except duckdb.InterruptException:
        out.update(status="timeout", refusal_rule="timeout", message=f"stopped at {lim['timeout_s']} s")
    except duckdb.OutOfMemoryException as e:
        out.update(status="error", refusal_rule="memory", message=str(e).splitlines()[0][:300])
    except duckdb.Error as e:
        msg = str(e).splitlines()[0][:300]
        rule = "memory" if "Out of Memory" in msg else ("permission" if "Permission" in type(e).__name__ else "engine")
        out.update(status="error", refusal_rule=rule, message=msg)
    finally:
        if timer is not None:
            timer.cancel()
        if con is not None:
            try:
                con.close()
            except Exception:
                pass
    return _done(out, t0)


def _done(out: dict, t0: float) -> dict:
    out["ms"] = int((time.monotonic() - t0) * 1000)
    return out
