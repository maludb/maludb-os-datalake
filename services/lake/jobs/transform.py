"""Slice 7's job kind — transform (transforms-products-lineage.md, "The writer's transform job"; lake-engine.md §5; THE FOURTH EXEMPLAR of the engine's pattern:
a `lake_jobs` row → the handler → a snapshot with an author and a message → the run's row → the engine fields on the output dataset).

    transform   ref_id = the transform (also params.transform_id); params {transform_run_id?}   dataset = the OUTPUT (curated, product or sandbox)
                → the transform's SQL, over its inputs as its OWNER could read them when the SQL was saved (transforms.frozen_policy — a product never
                  quietly widens what it exposes), replaces the output (mode replace) or merges the rows derived from the inputs' changes into it by the
                  output's key (mode incremental_merge — row-wise SQL only)

What the job does, in order: the transform and its output are read; the run's row (made queued by the web application or the worker) goes `running`
with the inputs' snapshots; a lineage START (job_kind transform, job_name `transform.<name>`, the SQL as the job's `sql` facet); `lake.transform.transform()`
— Phase 0's function, proven in the engine proof — runs the SQL through the allow-list and the owner's frozen views; then the output's catalog:
its columns as the engine has them (new ones added, the ones the SQL no longer makes marked removed, an output column that sqlglot's lineage shows comes
DIRECTLY from an input column inherits that column's personal kind and classification), the engine's numbers, the dataset's derived flags; the lineage edges
(one per input, with the column map; the edges of an input the SQL no longer reads pruned); the run `ok` (or `no_op`); a lineage COMPLETE; the writer's read
audited (query_runs, source `transform`, the owner the caller); a profile of the output queued. A refusal the job DECIDES (the SQL asks for an aggregate in
incremental_merge, the transform is not frozen, the output is gone) is a JobRefused: the run `failed` in words, a lineage FAIL, the owner told once a day
(dl_transform_failed_notify), never retried. A fault is the same and retried with back-off by the writer.

The database decides every state a person sees; this file measures, hands over, and records.
"""
from __future__ import annotations

import threading
import time

import duckdb
import sqlglot
from psycopg.types.json import Json
from sqlglot import exp
from sqlglot import lineage as sg_lineage

from lake import transform as lake_transform
from lake.engine import columns_of, current_snapshot, split_key, table_exists
from query import allowlist, service

from . import frame
from .frame import JobRefused
from .query_results import _audit, _frozen

CLASS_ORDER = ["public", "internal", "confidential", "restricted"]
# a DuckDB error that will say the same on a second try: the SQL or the data, not the machine — decided, never retried
DECIDED = (duckdb.BinderException, duckdb.CatalogException, duckdb.ParserException, duckdb.ConversionException, duckdb.InvalidInputException,
           duckdb.OutOfMemoryException, duckdb.InterruptException, duckdb.InvalidTypeException)
MAX_LINEAGE_COLUMNS = 200


def rowwise_problem(sql: str) -> str | None:
    """Why this SQL cannot be merged incrementally (an aggregate, GROUP BY, DISTINCT or a window needs the whole input), or None when it is row-wise."""
    tree = sqlglot.parse_one(sql, read="duckdb")
    for n in tree.walk():
        if isinstance(n, exp.AggFunc):
            return "an aggregate"
        if isinstance(n, exp.Window):
            return "a window function"
        if isinstance(n, exp.Distinct):
            return "DISTINCT"
        if isinstance(n, exp.Group):
            return "GROUP BY"
    return None


def _walk_lineage(node, direct: bool, out: list) -> None:
    expr = node.expression
    if isinstance(expr, exp.Table):
        out.append((node, direct))
        return
    inner = expr.this if isinstance(expr, exp.Alias) else expr
    while isinstance(inner, (exp.Paren, exp.Cast, exp.TryCast)):
        inner = inner.this
    step = isinstance(inner, exp.Column)
    for d in node.downstream:
        _walk_lineage(d, direct and step, out)


def column_lineage(sql: str, schema: dict, out_cols: list[str]) -> dict:
    """sqlglot's lineage of each output column to the input columns it comes from: {column: [{dataset, column, kind DIRECT|INDIRECT}]}. A column it cannot
    resolve is left out; a failure of the whole is {} (the edges are table-level always — lake-engine §5)."""
    if len(out_cols) > MAX_LINEAGE_COLUMNS:
        return {}
    result: dict = {}
    for col in out_cols:
        try:
            root = sg_lineage.lineage(col, sql, schema=schema, dialect="duckdb")
        except Exception:
            continue
        leaves: list = []
        try:
            _walk_lineage(root, True, leaves)
        except Exception:
            continue
        refs: dict = {}
        for node, direct in leaves:
            src = node.source
            if not isinstance(src, exp.Table) or not src.db or not src.name:
                continue
            key = f"{src.db}.{src.name}".lower()
            column = node.name.split(".")[-1]
            kind = "DIRECT" if direct else "INDIRECT"
            prior = refs.get((key, column))
            refs[(key, column)] = "DIRECT" if prior == "DIRECT" or (prior is None and kind == "DIRECT") else kind
        if refs:
            result[col] = [{"dataset": k, "column": c, "kind": kind} for (k, c), kind in sorted(refs.items())]
    return result


def _input_schema(con, keys: list[str]) -> dict:
    schema: dict = {}
    for k in keys:
        z, n = split_key(k)
        schema.setdefault(z, {})[n] = {c: t for c, t in columns_of(con, k)}
    return schema


def _sync_output_columns(con, pg, out_id: int, out_key: str, snap: int) -> dict:
    """The output's columns as the engine has them (frame.sync_columns) — and the ones the SQL no longer makes marked removed at this snapshot."""
    synced = frame.sync_columns(con, pg, out_id, out_key, snap)
    live = {c.lower() for c, _ in columns_of(con, out_key)}
    gone = 0
    for r in pg.execute("SELECT id, name FROM dataset_columns WHERE dataset_id = %s AND removed_snapshot_id IS NULL", (out_id,)).fetchall():
        if r["name"].lower() not in live:
            pg.execute("UPDATE dataset_columns SET removed_snapshot_id = %s WHERE id = %s", (snap, r["id"]))
            gone += 1
    return synced | {"removed": gone}


def _inherit(pg, out_id: int, cmap: dict) -> int:
    """An output column that comes DIRECTLY from an input column keeps what that column is: its personal kind (the first) and the highest classification
    — phase1-consistency 21, and the sandbox result's rule: a mask applied when the product was built protects nobody who reads the product later
    unmasked. Never over a person's confirmation; never lowers anything."""
    inputs: dict = {}
    for dsk in {ref["dataset"] for refs in cmap.values() for ref in refs if ref["kind"] == "DIRECT"}:
        for r in pg.execute("""SELECT c.name, c.classification, c.personal_kind FROM dataset_columns c JOIN datasets d ON d.id = c.dataset_id
                                WHERE d.key = %s AND c.removed_snapshot_id IS NULL""", (dsk,)).fetchall():
            inputs[(dsk, r["name"].lower())] = r
    n = 0
    for c in pg.execute("""SELECT id, name, classification, personal_kind FROM dataset_columns
                            WHERE dataset_id = %s AND removed_snapshot_id IS NULL AND NOT is_system AND classification_confirmed_at IS NULL""", (out_id,)).fetchall():
        best, kind = c["classification"], c["personal_kind"]
        for ref in cmap.get(c["name"], cmap.get(c["name"].lower(), [])):
            if ref["kind"] != "DIRECT":
                continue
            src = inputs.get((ref["dataset"], ref["column"].lower()))
            if src is None:
                continue
            if src["classification"] in CLASS_ORDER and best in CLASS_ORDER and CLASS_ORDER.index(src["classification"]) > CLASS_ORDER.index(best):
                best = src["classification"]
            kind = kind or src["personal_kind"]
        if best != c["classification"] or kind != c["personal_kind"]:
            pg.execute("UPDATE dataset_columns SET classification = %s, personal_kind = %s, classified_by = 'rule' WHERE id = %s", (best, kind, c["id"]))
            n += 1
    return n


def _run_row(pg, job: dict, t: dict, requested_by: int | None) -> tuple[int, dict]:
    """The run's row: the one the web application or the worker queued (params.transform_run_id), set running; else made here."""
    rid = (job.get("params") or {}).get("transform_run_id")
    row = None
    if rid:
        row = pg.execute("SELECT id, status FROM transform_runs WHERE id = %s AND transform_id = %s", (rid, t["id"])).fetchone()
    if row is not None and row["status"] in ("queued", "running", "failed"):          # 'failed' = the writer's retry of the same job
        pg.execute("UPDATE transform_runs SET status = 'running', version = %s, started_at = now(), finished_at = NULL, error = NULL WHERE id = %s", (t["version"], row["id"]))
        return int(row["id"]), row
    new = pg.execute("""INSERT INTO transform_runs (transform_id, version, status, requested_by, started_at) VALUES (%s, %s, 'running', %s, now()) RETURNING id""",
                     (t["id"], t["version"], requested_by)).fetchone()
    return int(new["id"]), {"id": new["id"], "status": "running"}


def _fail(pg, job: dict, t: dict, out_id: int, run_id: int, run_uuid, words: str, inputs: list, caller: int) -> None:
    words = frame.safe_error(words)[:500]
    pg.execute("UPDATE transform_runs SET status = 'failed', error = %s, finished_at = now() WHERE id = %s", (words[:2000], run_id))
    pg.execute("UPDATE transforms SET last_run_id = %s WHERE id = %s", (run_id, t["id"]))
    if run_uuid is not None:
        frame.lineage_end(pg, run_uuid, "transform", f"transform.{t['name']}", {**job, "requested_by": caller}, ok=False, inputs=inputs, outputs=[frame.dataset_ref(_out_key(pg, out_id))],
                          facets={"errorMessage": {"message": words}}, transform_run_id=run_id)
    frame.log(pg, job, "transform.run_done", "transform", int(t["id"]), dataset_id=out_id,
              after={"transform_run_id": run_id, "status": "failed", "rows_written": 0, "output_snapshot": None, "error": words[:200]})
    pg.execute("SELECT dl_transform_failed_notify(%s, %s)", (t["id"], words))


def _out_key(pg, out_id: int) -> str:
    return pg.execute("SELECT key FROM datasets WHERE id = %s", (out_id,)).fetchone()["key"]


def _author(pg, who: int | None) -> str:
    if not who:
        return "dl:system"
    return frame.author_of(pg, {"requested_by": who})


def job_transform(con, pg, job: dict) -> dict:
    params = job.get("params") or {}
    tid = job.get("ref_id") or params.get("transform_id")
    t = pg.execute("""SELECT id, name, output_dataset_id, sql, input_dataset_ids, mode, version, owner_member_id, frozen_policy, enabled
                        FROM transforms WHERE id = %s""", (tid,)).fetchone()
    if t is None:
        raise JobRefused("the transform is gone")
    out = pg.execute("SELECT id, key, zone, name, status, dedupe_key FROM datasets WHERE id = %s", (t["output_dataset_id"],)).fetchone()
    if out is None or out["status"] == "retired":
        raise JobRefused("the output dataset is gone")
    out_id, out_key = int(out["id"]), out["key"]
    name = f"transform.{t['name']}"
    requested_by = job.get("requested_by")
    caller = int(requested_by or t["owner_member_id"])                  # the audit's caller and the commit's author: who asked, else the owner (lake-engine §5)
    keys = [r["key"] for r in pg.execute("SELECT key FROM datasets WHERE id = ANY(%s) ORDER BY id", (list(t["input_dataset_ids"] or []),)).fetchall()]
    run_id, _ = _run_row(pg, job, t, requested_by)
    snap0 = current_snapshot(con)
    input_snapshots = {k: snap0 for k in keys}
    pg.execute("UPDATE transform_runs SET input_snapshots = %s WHERE id = %s", (Json(input_snapshots), run_id))
    in_refs = [frame.dataset_ref(k, snap0) for k in keys]
    run_uuid = frame.lineage_start(pg, "transform", name, {**job, "requested_by": caller}, inputs=in_refs, outputs=[frame.dataset_ref(out_key)],
                                   facets={"sql": {"query": t["sql"]}}, transform_run_id=run_id)
    pg.execute("UPDATE transforms SET last_run_id = %s WHERE id = %s", (run_id, t["id"]))
    try:
        if t["frozen_policy"] is None:
            raise JobRefused("not_frozen — this transform's SQL changed and its owner's level was not frozen again: open it, check the SQL and save it")
        policy = _frozen({"policy": t["frozen_policy"]})
        missing = [k for k in keys if k not in (policy.get("datasets") or {})]
        if missing:
            raise JobRefused("not_frozen — the frozen level does not cover " + ", ".join(missing) + ": save the transform again")
        mode = t["mode"]
        key_columns = [c for c in (out["dedupe_key"] or []) if c]
        if mode == "incremental_merge":
            problem = rowwise_problem(t["sql"])
            if problem:
                raise JobRefused(f"incremental_needs_rowwise — incremental_merge needs row-wise SQL; this has {problem}: use replace")
            if not key_columns:
                raise JobRefused(f"incremental_needs_key — incremental_merge merges by the output's key, and {out_key} has none: set its key, or use replace")
        since = None
        if mode == "incremental_merge":
            last = pg.execute("""SELECT input_snapshots FROM transform_runs WHERE transform_id = %s AND status IN ('ok', 'no_op') AND version = %s AND id <> %s
                                  ORDER BY id DESC LIMIT 1""", (t["id"], t["version"], run_id)).fetchone()
            since = {k: int(v) for k, v in (last["input_snapshots"] or {}).items()} if last else None
        timer = threading.Timer(int(policy["limits"]["timeout_s"]), con.interrupt)
        timer.daemon = True
        timer.start()
        t0 = time.monotonic()
        try:
            r = lake_transform.transform(con, output_key=out_key, sql=t["sql"], policy=policy, author=_author(pg, caller), name=t["name"], version=int(t["version"]),
                                         mode=mode, key_columns=key_columns or None, since=since, table_functions=service._table_functions(frame.config()))
        finally:
            timer.cancel()
    except JobRefused as e:
        _fail(pg, job, t, out_id, run_id, run_uuid, str(e), in_refs, caller)
        raise
    except allowlist.Refusal as e:                                   # the SQL asks for what the allow-list forbids: decided
        _fail(pg, job, t, out_id, run_id, run_uuid, e.message, in_refs, caller)
        raise JobRefused(e.message)
    except ValueError as e:
        _fail(pg, job, t, out_id, run_id, run_uuid, str(e), in_refs, caller)
        raise JobRefused(frame.safe_error(e))
    except DECIDED as e:
        words = f"stopped after {policy['limits']['timeout_s']} s — narrow the SQL" if isinstance(e, duckdb.InterruptException) else frame.safe_error(e)
        _audit(pg, {**job, "requested_by": caller}, {"policy": policy, "keys": keys, "sql": t["sql"]}, "transform",
               {"status": "timeout" if isinstance(e, duckdb.InterruptException) else "error", "refusal_rule": "engine", "message": words, "datasets": keys}, int((time.monotonic() - t0) * 1000))
        _fail(pg, job, t, out_id, run_id, run_uuid, words, in_refs, caller)
        raise JobRefused(words)
    except Exception as e:
        _fail(pg, job, t, out_id, run_id, run_uuid, frame.safe_error(e), in_refs, caller)
        raise
    snap = int(r["snapshot_id"])
    rows = int(r["rows_written"])
    ran_incremental = r["mode"] == "incremental_merge"
    status = "no_op" if ran_incremental and rows == 0 and not r["schema_changed"] else "ok"
    inputs_seen = {k: int(v) for k, v in (r.get("inputs") or {}).items()} or input_snapshots
    synced = _sync_output_columns(con, pg, out_id, out_key, snap)
    out_cols = [c for c, _ in columns_of(con, out_key) if not c.startswith("_dl_")]
    cmap = column_lineage(t["sql"], _input_schema(con, keys), out_cols)
    inherited = _inherit(pg, out_id, cmap)
    refreshed = frame.refresh_dataset(con, pg, out_id, out_key, snap, data_changed=status == "ok")
    pg.execute("SELECT dl_dataset_derive(%s)", (out_id,))
    for r_in in pg.execute("SELECT id, key FROM datasets WHERE id = ANY(%s) ORDER BY id", (list(t["input_dataset_ids"] or []),)).fetchall():
        per_input = {col: [x for x in refs if x["dataset"] == r_in["key"]] for col, refs in cmap.items()}
        per_input = {col: refs for col, refs in per_input.items() if refs}
        pg.execute("SELECT dl_lineage_edge(%s, NULL, %s, 'transform', %s, %s::jsonb)", (r_in["id"], out_id, t["id"], Json(per_input)))
    pruned = pg.execute("SELECT dl_lineage_edge_prune(%s, %s) AS n", (t["id"], list(t["input_dataset_ids"] or []))).fetchone()["n"]
    qr = _audit(pg, {**job, "requested_by": caller}, {"policy": policy, "keys": keys, "sql": t["sql"], "agent_run_id": None}, "transform",
                {"status": "ok", "datasets": r.get("datasets_read") or keys, "rows": rows}, int(r.get("ms") or 0))
    pg.execute("""UPDATE transform_runs SET status = %s, input_snapshots = %s, output_snapshot = %s, rows_written = %s, finished_at = now(), error = NULL WHERE id = %s""",
               (status, Json(inputs_seen), snap, rows, run_id))
    pg.execute("UPDATE transforms SET last_run_id = %s WHERE id = %s", (run_id, t["id"]))
    frame.lineage_end(pg, run_uuid, "transform", name, {**job, "requested_by": caller}, ok=True, inputs=[frame.dataset_ref(k, inputs_seen.get(k)) for k in keys],
                      outputs=[frame.dataset_ref(out_key, snap)], facets={"outputStatistics": {"rowCount": rows}}, transform_run_id=run_id)
    frame.log(pg, job, "transform.run_done", "transform", int(t["id"]), dataset_id=out_id, query_run_id=qr,
              after={"transform_run_id": run_id, "status": status, "rows_written": rows, "output_snapshot": snap})
    if status == "ok":
        pg.execute("SELECT dl_job_enqueue('profile', %s, NULL, %s::jsonb, 6::smallint)", (out_id, Json({"after": "transform"})))
    return {"status": "no_op" if status == "no_op" else "ran", "mode": r["mode"], "rows_written": rows, "snapshot_id": snap, "transform_run_id": run_id,
            "schema_changed": bool(r["schema_changed"]), "columns": synced.get("columns"), "columns_removed": synced.get("removed"), "inherited_columns": inherited,
            "edges_pruned": int(pruned or 0), "rows_now": refreshed.get("rows"), "query_run_id": qr, "ms": r.get("ms")}
