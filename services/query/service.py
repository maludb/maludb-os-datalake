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


class Prepared:
    """A caller's SQL ready to run: the locked connection with the caller's views, the SQL rewritten to name only them. The caller of
    ``prepare()`` executes ``sql`` (``run()`` fetches JSON rows; the writer's jobs stream Arrow batches) and closes ``con``."""

    def __init__(self, con, sql: str, datasets: list[str], masked_columns: list[str], timeout_s: int, row_limit: int, result_bytes: int):
        self.con, self.sql, self.datasets, self.masked_columns = con, sql, datasets, masked_columns
        self.timeout_s, self.row_limit, self.result_bytes = timeout_s, row_limit, result_bytes

    def close(self) -> None:
        try:
            self.con.close()
        except Exception:
            pass


class Control:
    """How a server cancels a running request (query-service §7 `cancel`): ``attach(con)`` is called with the connection before the SQL
    runs; ``cancel()`` interrupts it (or marks it, when it has not started — the run then never begins); ``cancelled`` says which it was."""

    def __init__(self):
        self._lock = threading.Lock()
        self._con = None
        self.cancelled = False

    def attach(self, con) -> None:
        with self._lock:
            self._con = con

    def cancel(self) -> None:
        with self._lock:
            self.cancelled = True
            if self._con is not None:
                try:
                    self._con.interrupt()
                except Exception:
                    pass


def prepare(cfg: LakeConfig, policy: dict, sql: str, *, as_of: int | None = None, params: dict | None = None,
            dsn: str | None = None, data_path: str | None = None) -> Prepared | dict:
    """Layers 1–3 and the lock (the module's steps 1–4): a refusal answers as a dict (the run's answer shape); else a Prepared."""
    out = {"status": "error", "refusal_rule": None, "message": None, "columns": [], "rows": [], "truncated": False,
           "masked_columns": [], "datasets": [], "ms": 0}
    try:
        validate(policy)
    except PolicyError as e:
        out.update(status="refused", refusal_rule="policy", message=str(e))
        return out
    lim = policy["limits"]
    tfs = _table_functions(cfg)
    # 1. refuse early — the policy's keys stand in for the view names on this first pass
    try:
        allowlist.check(sql, {k: k for k in policy["datasets"]}, table_functions=tfs, params=params)
    except allowlist.Refusal as r:
        out.update(status="refused", refusal_rule=r.rule, message=r.message, datasets=[r.dataset] if r.dataset else [])
        return out
    con = None
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
        used = set(checked.datasets)
        return Prepared(con, checked.sql, checked.datasets, [m for m in made["masked"] if m.rsplit(".", 1)[0] in used],
                        int(lim["timeout_s"]), int(lim["row_limit"]), int(lim["result_bytes"]))
    except allowlist.Refusal as r:
        out.update(status="refused", refusal_rule=r.rule, message=r.message, datasets=[r.dataset] if r.dataset else [])
    except PolicyError as e:
        out.update(status="refused", refusal_rule="policy", message=str(e))
    except duckdb.Error as e:
        msg = str(e).splitlines()[0][:300]
        out.update(status="error", refusal_rule="memory" if "Out of Memory" in msg else "engine", message=msg)
    if con is not None:
        try:
            con.close()
        except Exception:
            pass
    return out


def run(cfg: LakeConfig, policy: dict, sql: str, *, as_of: int | None = None, params: dict | None = None,
        dsn: str | None = None, data_path: str | None = None, explain: bool = False, control: Control | None = None) -> dict:
    """One read, answered as JSON-safe rows. ``explain``: the dry run (query-service §7) — the plan, no rows. ``control``: a cancel."""
    t0 = time.monotonic()
    p = prepare(cfg, policy, sql, as_of=as_of, params=params, dsn=dsn, data_path=data_path)
    if isinstance(p, dict):
        return _done(p, t0)
    out = {"status": "error", "refusal_rule": None, "message": None, "columns": [], "rows": [], "truncated": False,
           "masked_columns": p.masked_columns, "datasets": p.datasets, "ms": 0}
    timer = None
    try:
        if control is not None:
            control.attach(p.con)
            if control.cancelled:
                out.update(status="cancelled", refusal_rule="cancelled", message="cancelled before it ran")
                return _done(out, t0)
        if explain:
            plan = p.con.execute("EXPLAIN " + p.sql).fetchall()
            out["plan"] = "\n".join(str(r[1]) for r in plan)
            out["status"] = "ok"
            return _done(out, t0)
        timer = threading.Timer(p.timeout_s, p.con.interrupt)
        timer.daemon = True
        timer.start()
        cur = p.con.execute(p.sql)
        out["columns"] = [{"name": d[0], "type": str(d[1])} for d in cur.description]
        rows = cur.fetchmany(p.row_limit + 1)
        timer.cancel()
        if len(rows) > p.row_limit:
            rows = rows[: p.row_limit]
            out["truncated"] = True
        out["rows"] = [[_jsonable(v) for v in r] for r in rows]
        size = len(json.dumps(out["rows"], separators=(",", ":")))
        if size > p.result_bytes:
            keep, total = [], 2
            for r in out["rows"]:
                total += len(json.dumps(r, separators=(",", ":"))) + 1
                if total > p.result_bytes:
                    break
                keep.append(r)
            out["rows"], out["truncated"] = keep, True
            out["message"] = f"result cut at {p.result_bytes} bytes"
        out["status"] = "ok"
    except duckdb.InterruptException:
        if control is not None and control.cancelled:
            out.update(status="cancelled", refusal_rule="cancelled", message="cancelled", rows=[], columns=[])
        else:
            out.update(status="timeout", refusal_rule="timeout", message=f"stopped at {p.timeout_s} s", rows=[], columns=[])
    except duckdb.OutOfMemoryException as e:
        out.update(status="error", refusal_rule="memory", message=str(e).splitlines()[0][:300])
    except duckdb.Error as e:
        msg = str(e).splitlines()[0][:300]
        rule = "memory" if "Out of Memory" in msg else ("permission" if "Permission" in type(e).__name__ else "engine")
        out.update(status="error", refusal_rule=rule, message=msg)
    finally:
        if timer is not None:
            timer.cancel()
        p.close()
    return _done(out, t0)


def _done(out: dict, t0: float) -> dict:
    out["ms"] = int((time.monotonic() - t0) * 1000)
    return out
