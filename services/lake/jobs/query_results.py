"""The writer's jobs that write FROM A PERSON'S QUERY (slice 3 — query-service-editor.md, THE SECOND EXEMPLAR; query-service.md §1, §6, §7):
``sandbox_save`` (a result kept as the person's sandbox dataset) and ``export`` (a result as a CSV, XLSX or Parquet file to download).

THE PATTERN later jobs copy (transforms in slice 7): the person's policy was compiled by the database AT THE REQUEST and frozen in the
job's params (PHP: ``dl_query_policy(member, on_behalf_of, keys, 'transform' | 'export')`` — never at the job's run, so a grant revoked
meanwhile cannot widen nor a grant made meanwhile narrow what they asked for); here the writer adds the masking salt and runs the SAME
library as the query service (``query.service.prepare``: the allow-list, the person's views with masks and row filters, the lock) on a
READ-ONLY attach of its own — the person's SQL never runs on the writer's read-write connection: its rows stream as Arrow batches into
the lake (sandbox_save) or into a file (export). The run is audited like any read (``dl_query_run_log``, source ``transform`` /
``export``), the output's classification floor is the highest of its inputs, the result is the person's.

    job_sandbox_save(con, pg, job) — params {sql, params, as_of, policy, keys}; dataset_id = the person's sandbox dataset (PHP made the row)
    job_export(con, pg, job)       — op `make` (ref_id = the export): params {sql, params, as_of, policy, keys, format};
                                     op `sweep` (no ref): unlinks every file under exports/ whose row is gone (deleted by its owner) or expired
                                     — PHP never deletes there (lake-engine §6: one process owns the files' lifecycle)
"""
from __future__ import annotations

import csv
import datetime as dt
import decimal
import hashlib
import json
import os
import re
import threading
import time

from psycopg.types.json import Json

from lake.engine import commit, current_snapshot, split_key, table_exists, table_ref
from lake.jobs import frame
from lake.jobs.frame import JobRefused
from query import service

XLSX_MAX_ROWS = 1_048_575            # Excel's sheet: 1,048,576 rows, one of them the header
BATCH = 10_000
EXPORT_EXT = {"csv": "csv", "xlsx": "xlsx", "parquet": "parquet"}
_SALT: str | None = None


def _salt() -> str:
    """The installation's masking salt (DL_MASK_SALT_FILE — root:datalake 0440: the writer's and the query service's, never www-data's)."""
    global _SALT
    if _SALT is None:
        path = os.environ.get("DL_MASK_SALT_FILE", "")
        if not path:
            raise RuntimeError("DL_MASK_SALT_FILE is not set")
        with open(path, "r", encoding="ascii") as f:
            salt = f.read().strip().lower()
        if not re.fullmatch(r"[0-9a-f]{32,128}", salt):
            raise RuntimeError("the masking salt is not 32–128 hex characters")
        _SALT = salt
    return _SALT


def _frozen(params: dict) -> dict:
    """The policy frozen at the request, made runnable: the salt added, temp_mb → temp (as the service's identity does)."""
    p = dict(params.get("policy") or {})
    if not p.get("datasets") and not p.get("refused"):
        raise JobRefused("the request carries no policy")
    lim = dict(p.get("limits") or {})
    if lim.get("temp_mb") is not None:
        lim["temp"] = f"{int(lim['temp_mb'])}MB"
    p["limits"] = lim
    p["salt_hex"] = _salt()
    return p


def _refused_by_policy(policy: dict, keys: list[str]) -> None:
    """A key the frozen policy refused (the person lost the right between the request and the run is NOT this — the policy is frozen;
    this is a key the request named that was never readable): the job refuses in the policy's words."""
    for k in keys:
        r = (policy.get("refused") or {}).get(k)
        if r is not None:
            raise JobRefused(r.get("message") or f"you may not read {k}")
        if k not in (policy.get("datasets") or {}):
            raise JobRefused(f"you may not read {k}")


def _audit(pg, job: dict, params: dict, source: str, out: dict, ms: int) -> int:
    """The query_runs row of the job's read (query-service §6: the writer audits the reads it makes for a person)."""
    policy = params.get("policy") or {}
    used = set(out.get("datasets") or params.get("keys") or [])
    masks = {k: d["masks"] for k, d in (policy.get("datasets") or {}).items() if k in used and d.get("masks")}
    filters = {k: d["row_filter"] for k, d in (policy.get("datasets") or {}).items() if k in used and d.get("row_filter")}
    sql = params.get("sql") or ""
    row = pg.execute("SELECT dl_query_run_log(%s::jsonb) AS id", (Json({
        "caller": job.get("requested_by"), "on_behalf_of": policy.get("on_behalf_of"), "agent_run_id": params.get("agent_run_id"),
        "request_id": f"job:{job['id']}", "source": source, "saved_query_id": params.get("saved_query_id"),
        "sql_sha256": hashlib.sha256(sql.encode()).hexdigest(), "sql": sql, "as_of": None if params.get("as_of") is None else str(params["as_of"]),
        "datasets": sorted(used), "masked_columns": masks, "row_filters": filters, "rows_returned": int(out.get("rows") or 0),
        "truncated": bool(out.get("truncated")), "bytes_returned": int(out.get("bytes") or 0), "ms": ms,
        "status": out.get("status", "error"), "refusal_rule": out.get("refusal_rule"),
        "error": out.get("message") if out.get("status") in ("error", "timeout") else None, "worker": "writer",
    }),)).fetchone()
    return int(row["id"])


def _prepared(params: dict, policy: dict):
    """The person's read, ready: the same library as the query service on a READ-ONLY attach (the writer's own role), or a refusal."""
    cfg = frame.config()
    as_of = params.get("as_of")
    as_of = int(as_of) if isinstance(as_of, (int, str)) and str(as_of).isdigit() else None
    return service.prepare(cfg, policy, params.get("sql") or "", as_of=as_of, params=params.get("params") or {}, dsn=cfg.lake_dsn)


def _arrow_reader(cur):
    """The result as an Arrow RecordBatchReader of BATCH rows (DuckDB 1.5: to_arrow_reader; fetch_record_batch before it)."""
    return cur.to_arrow_reader(BATCH) if hasattr(cur, "to_arrow_reader") else cur.fetch_record_batch(BATCH)


def _watch(prep) -> threading.Timer:
    t = threading.Timer(prep.timeout_s, prep.con.interrupt)
    t.daemon = True
    t.start()
    return t


# ---------------------------------------------------------------------------------------------------------------------------------------
def job_sandbox_save(con, pg, job: dict) -> dict:
    params = job.get("params") or {}
    d = pg.execute("SELECT id, key, zone, owner_member_id, status FROM datasets WHERE id = %s", (job["dataset_id"],)).fetchone()
    if d is None or d["status"] == "retired":
        raise JobRefused("the sandbox dataset is gone")
    if d["zone"] != "sandbox" or d["owner_member_id"] != job.get("requested_by"):
        raise JobRefused("a result is saved only into its owner's sandbox")
    key, dataset_id = d["key"], int(d["id"])
    keys = list(params.get("keys") or [])
    policy = _frozen(params)
    _refused_by_policy(policy, keys)
    inputs = [frame.dataset_ref(k) for k in keys]
    run = frame.lineage_start(pg, "sandbox_save", key, job, inputs=inputs, outputs=[frame.dataset_ref(key)])
    t0 = time.monotonic()
    prep = _prepared(params, policy)
    if isinstance(prep, dict):                        # refused by the allow-list or the policy's own check
        _audit(pg, job, params, "transform", {**prep, "rows": 0}, int((time.monotonic() - t0) * 1000))
        frame.lineage_end(pg, run, "sandbox_save", key, job, ok=False, inputs=inputs, outputs=[frame.dataset_ref(key)],
                          facets={"errorMessage": {"message": prep.get("message")}})
        raise JobRefused(prep.get("message") or "the query was refused")
    timer = _watch(prep)
    zone, name = split_key(key)
    try:
        reader = _arrow_reader(prep.con.execute(prep.sql))
        con.register("dl_person_result", reader)
        with commit(con, frame.author_of(pg, job), f"sandbox_save {job['id']}"):
            if table_exists(con, key):
                con.execute(f"DROP TABLE {table_ref(key)}")
            con.execute(f"CREATE TABLE {table_ref(key)} AS SELECT * FROM dl_person_result")
        con.unregister("dl_person_result")
        timer.cancel()
    except Exception as e:
        timer.cancel()
        status = "timeout" if "INTERRUPT" in str(e).upper() else "error"
        _audit(pg, job, params, "transform", {"status": status, "refusal_rule": status if status == "timeout" else "engine",
                                              "message": frame.safe_error(e), "datasets": prep.datasets}, int((time.monotonic() - t0) * 1000))
        frame.lineage_end(pg, run, "sandbox_save", key, job, ok=False, inputs=inputs, outputs=[frame.dataset_ref(key)],
                          facets={"errorMessage": {"message": frame.safe_error(e)}})
        if status == "timeout":
            raise JobRefused(f"stopped after {prep.timeout_s} s — narrow the query")
        raise
    finally:
        prep.close()
    snap = current_snapshot(con)
    rows = int(con.execute(f"SELECT count(*) FROM {table_ref(key)}").fetchone()[0])
    qr = _audit(pg, job, params, "transform", {"status": "ok", "datasets": prep.datasets, "rows": rows}, int((time.monotonic() - t0) * 1000))
    frame.sync_columns(con, pg, dataset_id, key, snap)
    inherited = _inherit_columns(pg, dataset_id, keys)
    refreshed = frame.refresh_dataset(con, pg, dataset_id, key, snap)
    floor = _floor(policy, keys)
    pg.execute("UPDATE datasets SET classification_floor = %s WHERE id = %s AND classification_floor IS DISTINCT FROM %s", (floor, dataset_id, floor))
    pg.execute("SELECT dl_dataset_derive(%s)", (dataset_id,))
    from lake.jobs.ingest import job_profile          # slice 2's profile, in this same job: the columns' profiles and ★3's classifier
    prof = job_profile(con, pg, {**job, "ref_id": None, "params": {}})
    frame.lineage_end(pg, run, "sandbox_save", key, job, ok=True, inputs=inputs, outputs=[frame.dataset_ref(key, snap)],
                      facets={"outputStatistics": {"rowCount": rows}})
    frame.log(pg, job, "sandbox.save", "dataset", dataset_id, dataset_id=dataset_id, query_run_id=qr,
              after={"key": key, "done": True, "rows": rows, "inputs": keys, "masked_columns": len(prep.masked_columns),
                     "classification_floor": floor, "inherited_columns": inherited, "snapshot_id": snap, "job_id": job["id"]})
    return {"rows": rows, "snapshot_id": snap, "query_run_id": qr, "classification_floor": floor, "masked_columns": len(prep.masked_columns),
            "inherited_columns": inherited, "bytes": refreshed.get("bytes"), "profiled_columns": prof.get("columns")}


def _floor(policy: dict, keys: list[str]) -> str:
    order = ["public", "internal", "confidential", "restricted"]
    best = 0
    for k in keys:
        c = ((policy.get("datasets") or {}).get(k) or {}).get("classification") or "internal"
        best = max(best, order.index(c) if c in order else 1)
    return order[best]


def _inherit_columns(pg, dataset_id: int, keys: list[str]) -> int:
    """An output column named as an input column keeps that column's classification and personal kind (the higher, the first personal) —
    phase1-consistency 21's rule, conservative: a mask applied when it was saved protects nobody who reads the dataset later unmasked."""
    if not keys:
        return 0
    rows = pg.execute("""SELECT c.name, c.classification, c.personal_kind FROM dataset_columns c JOIN datasets d ON d.id = c.dataset_id
                          WHERE d.key = ANY(%s) AND c.removed_snapshot_id IS NULL AND NOT c.is_system""", (keys,)).fetchall()
    order = ["public", "internal", "confidential", "restricted"]
    best: dict[str, list] = {}                           # name → [classification, personal kind]
    for r in rows:
        cls = r["classification"] if r["classification"] in order else "internal"
        b = best.setdefault(r["name"].lower(), [cls, None])
        if order.index(cls) > order.index(b[0]):
            b[0] = cls
        b[1] = b[1] or r["personal_kind"]
    n = 0
    for c in pg.execute("SELECT id, name FROM dataset_columns WHERE dataset_id = %s AND removed_snapshot_id IS NULL AND NOT is_system",
                        (dataset_id,)).fetchall():
        b = best.get(c["name"].lower())
        if b is None or (b[0] in ("public", "internal") and b[1] is None):
            continue
        pg.execute("""UPDATE dataset_columns SET classification = %s, personal_kind = COALESCE(personal_kind, %s), classified_by = 'rule'
                       WHERE id = %s AND classification_confirmed_at IS NULL""", (b[0], b[1], c["id"]))
        n += 1
    return n


# ---------------------------------------------------------------------------------------------------------------------------------------
def job_export(con, pg, job: dict) -> dict:
    params = job.get("params") or {}
    if params.get("op") == "sweep":
        return _sweep(pg, job)
    x = pg.execute("SELECT id, format, created_by, status, dataset_id FROM exports WHERE id = %s", (job.get("ref_id"),)).fetchone()
    if x is None:
        raise JobRefused("the export is gone")
    if x["created_by"] != job.get("requested_by"):
        raise JobRefused("an export is made for the person who asked")
    fmt = x["format"]
    if fmt not in EXPORT_EXT:
        raise JobRefused(f"{fmt} is not an export format here")
    pg.execute("UPDATE exports SET status = 'running' WHERE id = %s", (x["id"],))
    keys = list(params.get("keys") or [])
    try:
        policy = _frozen(params)
        _refused_by_policy(policy, keys)
    except JobRefused as e:
        pg.execute("UPDATE exports SET status = 'failed', log = %s, finished_at = now() WHERE id = %s", (str(e)[:500], x["id"]))
        raise
    t0 = time.monotonic()
    prep = _prepared(params, policy)
    if isinstance(prep, dict):
        _audit(pg, job, params, "export", {**prep, "rows": 0}, int((time.monotonic() - t0) * 1000))
        pg.execute("UPDATE exports SET status = 'failed', log = %s, finished_at = now() WHERE id = %s", ((prep.get("message") or "refused")[:500], x["id"]))
        raise JobRefused(prep.get("message") or "the query was refused")
    cfg = frame.config()
    folder = os.path.join(cfg.storage_dir, "exports")
    os.makedirs(folder, exist_ok=True)
    final = os.path.join(folder, f"{x['id']}.{EXPORT_EXT[fmt]}")
    part = final + ".part"
    cap = prep.row_limit if fmt != "xlsx" else min(prep.row_limit, XLSX_MAX_ROWS)
    timer = _watch(prep)
    written = 0
    truncated = False
    try:
        reader = _arrow_reader(prep.con.execute(prep.sql))
        names = [f.name for f in reader.schema]
        sink = _sink(fmt, part, reader.schema, names)
        try:
            for batch in reader:
                room = cap - written
                if room <= 0:
                    truncated = batch.num_rows > 0 or truncated
                    if truncated:
                        break
                    continue
                if batch.num_rows > room:
                    batch = batch.slice(0, room)
                    truncated = True
                sink.write(batch)
                written += batch.num_rows
                if truncated:
                    break
        finally:
            sink.close()
        timer.cancel()
    except Exception as e:
        timer.cancel()
        prep.close()
        try:
            os.unlink(part)
        except FileNotFoundError:
            pass
        status = "timeout" if "INTERRUPT" in str(e).upper() else "error"
        msg = f"stopped after {prep.timeout_s} s — narrow the query" if status == "timeout" else frame.safe_error(e)
        _audit(pg, job, params, "export", {"status": status, "refusal_rule": status if status == "timeout" else "engine", "message": msg,
                                           "datasets": prep.datasets}, int((time.monotonic() - t0) * 1000))
        pg.execute("UPDATE exports SET status = 'failed', log = %s, finished_at = now() WHERE id = %s", (msg[:500], x["id"]))
        if status == "timeout":
            raise JobRefused(msg)
        raise
    prep.close()
    os.replace(part, final)
    size = os.path.getsize(final)
    masked = bool(prep.masked_columns)
    qr = _audit(pg, job, params, "export", {"status": "ok", "datasets": prep.datasets, "rows": written, "truncated": truncated, "bytes": size},
                int((time.monotonic() - t0) * 1000))
    pg.execute("""UPDATE exports SET status = 'done', storage_path = %s, byte_size = %s, row_count = %s, item_count = %s, truncated = %s, masked = %s,
                         query_run_id = %s, sql_sha256 = %s, finished_at = now(), log = NULL WHERE id = %s""",
               (f"exports/{x['id']}.{EXPORT_EXT[fmt]}", size, written, written, truncated, masked, qr,
                hashlib.sha256((params.get("sql") or "").encode()).hexdigest(), x["id"]))
    title = f"Your {fmt.upper()} export is ready"
    body = f"{written:,} rows{' (cut at the download limit)' if truncated else ''}{'; some columns are masked for you' if masked else ''}."
    pg.execute("SELECT dl_notify(%s, 'export_ready', %s, %s, 'export', %s, %s, NULL, %s)",
               (x["created_by"], title, body, x["id"], x["dataset_id"], f"export:{x['id']}"))
    frame.log(pg, job, "export.ready", "export", int(x["id"]), dataset_id=x["dataset_id"], query_run_id=qr,
              after={"format": fmt, "rows": written, "truncated": truncated, "masked": masked, "bytes": size})
    return {"export_id": x["id"], "rows": written, "truncated": truncated, "masked": masked, "bytes": size, "query_run_id": qr}


job_export.needs_lake = False             # its own READ-ONLY attach (prepare) — never the writer's read-write connection


def _cell(v):
    if v is None:
        return ""
    if isinstance(v, (dt.datetime, dt.date, dt.time)):
        return v.isoformat()
    if isinstance(v, (bytes, bytearray)):
        return "\\x" + bytes(v).hex()
    if isinstance(v, (dict, list, tuple)):
        return json.dumps(v, default=str)
    return v


class _CsvSink:
    def __init__(self, path: str, names: list[str]):
        self.f = open(path, "w", newline="", encoding="utf-8")
        self.w = csv.writer(self.f)
        self.w.writerow(names)

    def write(self, batch) -> None:
        cols = [c.to_pylist() for c in batch.columns]
        for i in range(batch.num_rows):
            self.w.writerow([_cell(c[i]) for c in cols])

    def close(self) -> None:
        self.f.close()


class _XlsxSink:
    def __init__(self, path: str, names: list[str]):
        from openpyxl import Workbook
        self.path = path
        self.wb = Workbook(write_only=True)
        self.ws = self.wb.create_sheet("result")
        self.ws.append(names)

    def write(self, batch) -> None:
        cols = [c.to_pylist() for c in batch.columns]
        for i in range(batch.num_rows):
            row = []
            for c in cols:
                v = c[i]
                if isinstance(v, decimal.Decimal):
                    v = float(v)
                elif isinstance(v, dt.datetime) and v.tzinfo is not None:
                    v = v.replace(tzinfo=None)               # Excel has no time zones: the value as stored (UTC)
                elif isinstance(v, (dict, list, tuple, bytes, bytearray, dt.time)):
                    v = _cell(v)
                row.append(v)
            self.ws.append(row)

    def close(self) -> None:
        self.wb.save(self.path)


class _ParquetSink:
    def __init__(self, path: str, schema):
        import pyarrow.parquet as pq
        self.w = pq.ParquetWriter(path, schema)

    def write(self, batch) -> None:
        self.w.write_batch(batch)

    def close(self) -> None:
        self.w.close()


def _sink(fmt: str, path: str, schema, names: list[str]):
    if fmt == "csv":
        return _CsvSink(path, names)
    if fmt == "xlsx":
        return _XlsxSink(path, names)
    return _ParquetSink(path, schema)


def _sweep(pg, job: dict) -> dict:
    """exports/ kept in step with the rows: a file whose row was deleted by its owner, or whose row has expired, is unlinked; an expired row
    keeps its status and loses its file (storage_path NULL, `export.expire` logged). A file being written (.part) is left alone."""
    folder = os.path.join(frame.config().storage_dir, "exports")
    unlinked = expired = 0
    rows = {int(r["id"]): r for r in pg.execute("SELECT id, storage_path, expires_at < now() AS expired, created_by FROM exports").fetchall()}
    try:
        names = os.listdir(folder)
    except FileNotFoundError:
        names = []
    for n in names:
        m = re.fullmatch(r"(\d+)\.(csv|xlsx|parquet)", n)
        if m is None:
            continue
        r = rows.get(int(m.group(1)))
        if r is None or r["expired"]:
            try:
                os.unlink(os.path.join(folder, n))
                unlinked += 1
            except FileNotFoundError:
                pass
    for r in rows.values():
        if r["expired"] and r["storage_path"] is not None:
            pg.execute("UPDATE exports SET storage_path = NULL WHERE id = %s", (r["id"],))
            frame.log(pg, {**job, "requested_by": r["created_by"]}, "export.expire", "export", r["id"], after={"unlinked": True})
            expired += 1
    return {"unlinked": unlinked, "expired": expired}
