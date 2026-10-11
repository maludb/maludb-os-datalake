"""Slice 2's job kinds — sniff, load, profile, rollback (uploads-loads.md; lake-engine.md §1, §3). Each reads its row, calls the Phase 0
library (sniff.sniff, load.load_raw, profile.profile, load.rollback_load), writes its results through the writer's role, records its run, logs
and answers {result} for dl_job_finish. THE EXEMPLAR: a later kind copies this file's shape (jobs/frame.py holds what they share).

    sniff    params {header_row?}      ref_id = the upload session   → upload_sessions.sniff (+ the 50-row sample the worker nulls later)
    load     params {}                 ref_id = the load             → raw.<name> or sandbox.m<me>_<name>, loads, load_rejects, the catalog
    profile  params {}                 ref_id = the load (or none)   → dataset_columns.profile, column_profiles, the classifier's proposals
    rollback params {}                 ref_id = the load             → a new snapshot without the load's rows; loads.rolled_back_*
"""
from __future__ import annotations

import decimal
import datetime as dt
import os
import shutil
import tempfile
import traceback

from psycopg.types.json import Json

from lake import load as lake_load
from lake import profile as lake_profile
from lake import sniff as lake_sniff
from lake.engine import columns_of, split_key

from . import frame
from .frame import JobRefused

MASK_AT_ONCE = 0.8              # ★3: a proposal this sure masks the column at once (classified_by rule) and still waits for a person
REJECTS_MAX = 1000              # parse rejects kept a load (the CAST rejects have their own cap in load.py)


# ---- sniff ------------------------------------------------------------------------------------------------------------------------------
def job_sniff(con, pg, job: dict) -> dict:
    cfg = frame.config()
    up = pg.execute("""SELECT u.id, u.member_id, u.filename, u.byte_size, u.status, b.storage_path, b.deleted_at
                         FROM upload_sessions u JOIN blobs b ON b.id = u.blob_id WHERE u.id = %s""", (job["ref_id"],)).fetchone()
    if up is None:
        raise JobRefused("the upload is gone")
    if up["deleted_at"] is not None:
        raise JobRefused("its landing file was purged")
    params = job.get("params") or {}
    header_row = max(1, min(50, int(params.get("header_row") or 1)))
    path = frame.landing_path(cfg, up["storage_path"])
    ext = os.path.splitext(up["filename"] or "")[1].lower()
    # the sniffer judges the format by the bytes and the extension: the landing file has no extension, so it reads a named link to it
    work_dir = tempfile.mkdtemp(prefix="sniff-", dir=cfg.temp_dir)
    try:
        named = os.path.join(work_dir, "upload" + (ext if ext in lake_sniff.CSV_EXT | lake_sniff.WORKBOOK_EXT | lake_sniff.JSON_EXT
                                                   | lake_sniff.PARQUET_EXT else ""))
        limit = int(pg.execute("SELECT dl_setting_int('sniff_max_bytes') AS n").fetchone()["n"] or 0)
        fmt = lake_sniff.detect_format(path if not ext else _link(path, named))
        if limit and int(up["byte_size"]) > limit and fmt in ("csv", "json"):
            _head_copy(path, named, limit)           # the sniffer reads the first sniff_max_bytes; the load reads everything
        elif not os.path.exists(named):
            _link(path, named)
        try:
            s = lake_sniff.sniff(named, work_dir=work_dir, header_row=header_row, delimiter=params.get("delimiter"), encoding=params.get("encoding"))
        except Exception as e:  # a file the sniffer cannot read: the person is told why, in words
            reason = frame.safe_error(e)
            pg.execute("UPDATE upload_sessions SET sniff_status = 'failed', sniff = %s WHERE id = %s", (Json({"error": reason}), up["id"]))
            frame.log(pg, job, "upload.sniff", "upload", up["id"], after={"status": "failed", "extension": ext[:10]})
            raise JobRefused(f"could not read it — {reason}")
    finally:
        shutil.rmtree(work_dir, ignore_errors=True)
    if limit and int(up["byte_size"]) > limit:
        s["warnings"].append(f"only the first {limit // 1048576} MB were read to propose the columns; the load reads it all")
    pg.execute("UPDATE upload_sessions SET sniff = %s, sniff_status = 'done' WHERE id = %s", (Json(_jsonable(s)), up["id"]))
    cols = s["columns"]
    proposals = sum(1 for c in cols if c.get("proposed_personal_kind"))
    frame.log(pg, job, "upload.sniff", "upload", up["id"], after={"format": s["format"], "columns": len(cols),
              "sheets": len(s["sheets"] or []), "proposals": proposals, "header_row": header_row})
    return {"format": s["format"], "columns": len(cols), "sheets": len(s["sheets"] or []), "rows_estimate": s["rows_estimate"]}


job_sniff.needs_lake = False         # the sniff reads the landing file with its own small DuckDB, never the lake


def _link(path: str, named: str) -> str:
    try:
        os.symlink(path, named)
    except FileExistsError:
        pass
    return named


def _head_copy(path: str, named: str, limit: int) -> None:
    """The first `limit` bytes up to the last full line (CSV, NDJSON): enough to propose columns and types for a huge file."""
    if os.path.lexists(named):
        os.unlink(named)
    with open(path, "rb") as r, open(named, "wb") as w:
        data = r.read(limit)
        cut = data.rfind(b"\n")
        w.write(data[: cut + 1] if cut > 0 else data)


# ---- load -------------------------------------------------------------------------------------------------------------------------------
def job_load(con, pg, job: dict) -> dict:
    cfg = frame.config()
    L = pg.execute("""SELECT l.*, d.key, d.zone, d.name AS dataset_name, d.evolution_mode, d.owner_member_id,
                             b.storage_path, b.deleted_at AS blob_deleted
                        FROM loads l JOIN datasets d ON d.id = l.dataset_id LEFT JOIN blobs b ON b.id = l.blob_id
                       WHERE l.id = %s""", (job["ref_id"],)).fetchone()
    if L is None:
        raise JobRefused("the load is gone")
    key, load_id, dataset_id = L["key"], int(L["id"]), int(L["dataset_id"])
    name = f"load.{L['dataset_name']}"
    out_ref = [frame.dataset_ref(key)]
    in_ref = [{"namespace": "datalake-landing", "name": (L["landing_sha256"] or "")[:12], "version": None, "facets": {}}]
    if L["landing_purged_at"] is not None or L["blob_deleted"] is not None or L["storage_path"] is None:
        _load_failed(pg, job, L, "Its landing file was purged — the load cannot be replayed")
        raise JobRefused("Its landing file was purged — the load cannot be replayed")
    run = frame.lineage_start(pg, "load", name, job, inputs=in_ref, outputs=out_ref, load_id=load_id)
    pg.execute("""UPDATE loads SET status = 'running', started_at = now(), finished_at = NULL, error = NULL, rolled_back_at = NULL, rolled_back_by = NULL
                   WHERE id = %s""", (load_id,))
    mapping = L["mapping"] or {}
    cols = mapping.get("columns") or {}
    rename = {src: c["name"] for src, c in cols.items() if not c.get("skip") and c.get("name")}
    skipped = [src for src, c in cols.items() if c.get("skip")]
    types = {c["name"]: c.get("type") for c in cols.values() if not c.get("skip") and c.get("name")} if L["zone"] == "sandbox" else None
    try:
        r = lake_load.load_raw(con, dataset_key=key, landing_path=frame.landing_path(cfg, L["storage_path"]), landing_sha256=L["landing_sha256"],
                               load_id=load_id, author=frame.author_of(pg, job), mapping=rename, evolution_mode=L["evolution_mode"],
                               read_options=dict(mapping.get("read_options") or {}), work_dir=cfg.temp_dir, types=types,
                               replace=L["mode"] == "replace", skip=skipped)
    except lake_load.LoadRefused as e:
        if e.reason == "schema_drift":
            msg = f"new columns: {', '.join(e.detail.get('new_columns') or [])} — the dataset refuses drift"
        else:
            msg = f"the file could not be loaded ({e.reason})"
        _load_failed(pg, job, L, msg[:2000], run=run, name=name, outputs=out_ref, incident=e.reason == "schema_drift")
        raise JobRefused(msg[:500])
    except JobRefused:
        raise
    except Exception as e:  # a fault: the load says so; the job is retried until max_attempts
        _load_failed(pg, job, L, frame.safe_error(e), run=run, name=name, outputs=out_ref)
        raise
    snap = int(r["snapshot_id"])
    if r["status"] == "noop":
        pg.execute("""UPDATE loads SET status = 'no_op', rows_read = 0, rows_loaded = 0, rows_rejected = 0, rows_rescued = 0, snapshot_id = %s,
                              finished_at = now() WHERE id = %s""", (snap, load_id))
        frame.log(pg, job, "load.run", "load", load_id, dataset_id=dataset_id, after={"dataset": key, "status": "no_op", "snapshot_id": snap})
        frame.lineage_end(pg, run, "load", name, job, ok=True, inputs=in_ref, outputs=[frame.dataset_ref(key, snap)],
                          facets={"outputStatistics": {"rowCount": 0}}, load_id=load_id)
        return {"status": "noop", "rows_read": 0, "rows_loaded": 0, "rows_rejected": 0, "rows_rescued": 0, "snapshot_id": snap}
    # the rejected lines and the cells that did not cast (raw text: write level only, purged with the landing window)
    rejects = r["rejects"][:REJECTS_MAX] + r.get("cast_rejects", [])
    pg.execute("DELETE FROM load_rejects WHERE load_id = %s", (load_id,))     # a replay keeps only its own
    with pg.cursor() as cur:
        cur.executemany("""INSERT INTO load_rejects (load_id, line, column_name, error_type, message, raw_line) VALUES (%s, %s, %s, %s, %s, %s)""",
                        [(load_id, x.get("line"), x.get("column"), (x.get("error") or "ERROR")[:60], frame.safe_error(x.get("message") or "")[:500] or None,
                          (x.get("raw") or "")[:2000] or None) for x in rejects])
    drift = r["drift"]
    live = [c for c, _ in _columns(con, key)]
    seen = {"columns": [{"name": c, "type": t} for c, t in _columns(con, key)], "drift": drift, "format": mapping.get("format")}
    pg.execute("""UPDATE loads SET status = 'loaded', rows_read = %s, rows_loaded = %s, rows_rejected = %s, rows_rescued = %s, snapshot_id = %s,
                          schema_seen = %s, finished_at = now() WHERE id = %s""",
               (r["rows_read"], r["rows_loaded"], r["rows_rejected"], r["rows_rescued"], snap, Json(seen), load_id))
    frame.sync_columns(con, pg, dataset_id, key, snap)
    meta = frame.refresh_dataset(con, pg, dataset_id, key, snap)
    if L["source_id"]:
        pg.execute("SELECT dl_lineage_edge(NULL, %s, %s, 'load')", (L["source_id"], dataset_id))
    changed = _schema_version(pg, dataset_id, snap, live, drift)
    frame.log(pg, job, "load.run", "load", load_id, dataset_id=dataset_id, after={
        "dataset": key, "status": "loaded", "rows_read": r["rows_read"], "rows_loaded": r["rows_loaded"], "rows_rejected": r["rows_rejected"],
        "rows_rescued": r["rows_rescued"], "cast_rejects": len(r.get("cast_rejects", [])), "snapshot_id": snap, "mode": L["mode"]})
    if changed:
        frame.log(pg, job, "drift.detect", "dataset", dataset_id, dataset_id=dataset_id, after={
            "load_id": load_id, "added": len(drift["added"]), "rescued": len(drift["rescued"]), "missing": len(drift["missing"]),
            "schema_version": changed})
        frame.notify_followers(pg, dataset_id, "schema_changed", f"{key}: its file changed shape",
                               "A load saw columns the dataset did not have (or missed some). A schema version waits for a person.",
                               "dataset", dataset_id, job.get("requested_by"), dedupe=f"schema:{dataset_id}:{changed}",
                               also=[L["owner_member_id"]] if L["owner_member_id"] else None)
    pg.execute("SELECT dl_job_enqueue('profile', %s, %s, %s::jsonb, 6::smallint)", (dataset_id, load_id, Json({"load_id": load_id})))
    if pg.execute("SELECT EXISTS (SELECT 1 FROM checks WHERE dataset_id = %s AND enabled AND run_when = 'on_load') AS x", (dataset_id,)).fetchone()["x"]:
        # slice 5: the dataset's own checks that run on every load (a curated dataset's run in its promotion instead)
        pg.execute("SELECT dl_job_enqueue('checks', %s, NULL, %s::jsonb, 5::smallint)", (dataset_id, Json({"when": "on_load", "load_id": load_id})))
    frame.lineage_end(pg, run, "load", name, job, ok=True, inputs=in_ref, outputs=[frame.dataset_ref(key, snap)],
                      facets={"outputStatistics": {"rowCount": r["rows_loaded"]}}, load_id=load_id)
    return {"status": "loaded", "rows_read": r["rows_read"], "rows_loaded": r["rows_loaded"], "rows_rejected": r["rows_rejected"],
            "rows_rescued": r["rows_rescued"], "cast_rejects": len(r.get("cast_rejects", [])), "drift": drift, "snapshot_id": snap,
            "rows_now": meta.get("rows"), "ms": r.get("ms")}


def _columns(con, key: str) -> list[tuple[str, str]]:
    return columns_of(con, key)


def _load_failed(pg, job: dict, L: dict, msg: str, *, run=None, name: str = "", outputs: list | None = None, incident: bool = False) -> None:
    load_id, dataset_id = int(L["id"]), int(L["dataset_id"])
    pg.execute("UPDATE loads SET status = 'failed', error = %s, finished_at = now() WHERE id = %s", (msg[:2000], load_id))
    frame.log(pg, job, "load.fail", "load", load_id, dataset_id=dataset_id, after={"dataset": L["key"], "error": msg[:200]})
    if run is not None:
        frame.lineage_end(pg, run, "load", name, job, ok=False, outputs=outputs, facets={"errorMessage": {"message": msg[:500]}}, load_id=load_id)
    try:                                              # the load's own failure is recorded above; telling people must never hide it
        if incident:
            pg.execute("SELECT dl_incident_open_load(%s, %s, %s)", (dataset_id, load_id, f"A load into {L['key']} was refused: {msg}"[:200]))
        frame.notify_followers(pg, dataset_id, "load_failed", f"A load into {L['key']} failed", msg[:300], "load", load_id, job.get("requested_by"),
                               dedupe=f"load_failed:{load_id}:{job['attempts']}", also=[job.get("requested_by")])
    except Exception:
        traceback.print_exc()


def _schema_version(pg, dataset_id: int, snap: int, live: list[str], drift: dict) -> int | None:
    """Version 1 on the first load (decision none); a drift (a column added, rescued or missing) is the next version, PENDING a person (R50)."""
    last = pg.execute("SELECT max(version) AS v FROM schema_versions WHERE dataset_id = %s", (dataset_id,)).fetchone()["v"]
    cols = [{"name": c} for c in live if not c.startswith("_dl_")]
    if last is None:
        pg.execute("""INSERT INTO schema_versions (dataset_id, version, snapshot_id, columns, changes, breaking, detected_by, decision)
                      VALUES (%s, 1, %s, %s, '[]'::jsonb, false, 'load', 'none')""", (dataset_id, snap, Json(cols)))
        if not (drift["added"] or drift["rescued"] or drift["missing"]):
            return None
        last = 1
    changes = ([{"column": c, "kind": "added"} for c in drift["added"]] + [{"column": c, "kind": "added", "to": "_dl_rescued"} for c in drift["rescued"]]
               + [{"column": c, "kind": "removed"} for c in drift["missing"]])
    if not changes:
        return None
    v = int(last) + 1
    pg.execute("""INSERT INTO schema_versions (dataset_id, version, snapshot_id, columns, changes, breaking, detected_by, decision)
                  VALUES (%s, %s, %s, %s, %s, %s, 'load', 'pending')""", (dataset_id, v, snap, Json(cols), Json(changes), bool(drift["missing"])))
    return v


# ---- profile ----------------------------------------------------------------------------------------------------------------------------
def job_profile(con, pg, job: dict) -> dict:
    d = pg.execute("SELECT id, key FROM datasets WHERE id = %s AND status <> 'retired'", (job["dataset_id"],)).fetchone()
    if d is None:
        raise JobRefused("the dataset is gone")
    key, dataset_id = d["key"], int(d["id"])
    if frame.table_id(pg, key) is None:
        raise JobRefused(f"{key} has no data yet")
    load_id = (job.get("params") or {}).get("load_id") or job.get("ref_id")
    cols = {r["name"]: r for r in pg.execute(
        """SELECT id, name, personal_kind, classification, classification_confirmed_at, is_system FROM dataset_columns
            WHERE dataset_id = %s AND removed_snapshot_id IS NULL""", (dataset_id,)).fetchall()}
    # a column a person or a rule marked personal, or a confidential / restricted one: its min, max and top values are masked in the profile
    masked = {n: (c["personal_kind"] or "other") for n, c in cols.items()
              if c["personal_kind"] or c["classification"] in ("confidential", "restricted")}
    p = lake_profile.profile(con, key, personal=masked, classify=True)
    snap = int(p["snapshot_id"])
    proposals = masked_now = 0
    for name, entry in p["columns"].items():
        c = cols.get(name)
        if c is None or c["is_system"]:
            continue
        stored = {k: _plain(v) for k, v in entry.items() if k not in ("classifier", "personal_kind")}
        if c["classification"] == "restricted":
            stored.update(min=None, max=None, top=[], withheld=True)
        stored["classifier"] = entry.get("classifier")
        pg.execute("UPDATE dataset_columns SET profile = %s, profiled_at = now() WHERE id = %s", (Json(stored), c["id"]))
        pg.execute("INSERT INTO column_profiles (dataset_id, column_name, snapshot_id, load_id, profile) VALUES (%s, %s, %s, %s, %s)",
                   (dataset_id, name, snap, load_id, Json(stored)))
        if c["classification_confirmed_at"] is not None:
            continue                                   # a person decided this column: the classifier never overrides them
        prop = entry.get("classifier")
        if prop:
            proposals += 1
            at_once = prop["confidence"] >= MASK_AT_ONCE and c["personal_kind"] is None
            masked_now += 1 if at_once else 0
            pg.execute(f"""UPDATE dataset_columns SET proposed_personal_kind = %s, proposed_confidence = %s
                                  {", personal_kind = %s, classified_by = 'rule'" if at_once else ''} WHERE id = %s""",
                       (prop["kind"], round(float(prop["confidence"]), 3), *([prop["kind"]] if at_once else []), c["id"]))
        else:
            pg.execute("UPDATE dataset_columns SET proposed_personal_kind = NULL, proposed_confidence = NULL WHERE id = %s AND personal_kind IS NULL",
                       (c["id"],))
    frame.log(pg, job, "load.profile", "dataset", dataset_id, dataset_id=dataset_id,
              after={"load_id": load_id, "columns": len(p["columns"]), "proposals": proposals, "masked_at_once": masked_now, "snapshot_id": snap})
    return {"snapshot_id": snap, "columns": len(p["columns"]), "proposals": proposals, "masked_at_once": masked_now}


def _plain(v):
    """A profile value as JSON (dates and decimals as text)."""
    if isinstance(v, (dt.date, dt.datetime, dt.time)):
        return v.isoformat()
    if isinstance(v, decimal.Decimal):
        return str(v)
    if isinstance(v, list):
        return [_plain(x) for x in v]
    if isinstance(v, dict):
        return {k: _plain(x) for k, x in v.items()}
    if isinstance(v, float) and (v != v or v in (float("inf"), float("-inf"))):
        return None
    return v


def _jsonable(v):
    return _plain(v)


# ---- rollback ---------------------------------------------------------------------------------------------------------------------------
def job_rollback(con, pg, job: dict) -> dict:
    L = pg.execute("""SELECT l.id, l.dataset_id, l.status, d.key, d.name AS dataset_name FROM loads l JOIN datasets d ON d.id = l.dataset_id
                       WHERE l.id = %s""", (job["ref_id"],)).fetchone()
    if L is None:
        raise JobRefused("the load is gone")
    if L["status"] == "rolled_back":
        return {"removed": 0, "status": "already rolled back"}
    if L["status"] not in ("loaded", "no_op", "failed"):
        raise JobRefused(f"a {L['status']} load cannot be rolled back")
    key, load_id, dataset_id = L["key"], int(L["id"]), int(L["dataset_id"])
    # the rows carry their load's provenance downstream too (a promotion keeps _dl_load_id): the curated twins and their quarantine
    keys = [key]
    for r in pg.execute("""SELECT d.key FROM lineage_edges e JOIN datasets d ON d.id = e.to_dataset_id
                            WHERE e.from_dataset_id = %s AND e.via = 'promote'""", (dataset_id,)).fetchall():
        keys += [r["key"], "quarantine." + split_key(r["key"])[1]]
    name = f"rollback.{L['dataset_name']}"
    run = frame.lineage_start(pg, "rollback", name, job, inputs=[frame.dataset_ref(key)], outputs=[frame.dataset_ref(k) for k in keys], load_id=load_id)
    try:
        r = lake_load.rollback_load(con, load_id=load_id, dataset_keys=keys, author=frame.author_of(pg, job))
    except Exception as e:
        frame.lineage_end(pg, run, "rollback", name, job, ok=False, facets={"errorMessage": {"message": frame.safe_error(e)}}, load_id=load_id)
        raise
    snap = int(r["snapshot_id"])
    pg.execute("""UPDATE loads SET status = 'rolled_back', rolled_back_at = now(), rolled_back_by = %s, rollback_snapshot_id = %s WHERE id = %s""",
               (job.get("requested_by"), snap, load_id))
    for k in keys:
        row = pg.execute("SELECT id FROM datasets WHERE key = %s", (k,)).fetchone()
        if row:
            frame.refresh_dataset(con, pg, int(row["id"]), k, snap)
    removed = sum(r["removed"].values())
    frame.log(pg, job, "load.rollback", "load", load_id, dataset_id=dataset_id,
              after={"phase": "done", "dataset": key, "removed": removed, "snapshot_id": snap})
    frame.lineage_end(pg, run, "rollback", name, job, ok=True, outputs=[frame.dataset_ref(k, snap) for k in keys],
                      facets={"outputStatistics": {"rowCount": -removed}}, load_id=load_id)
    return {"removed": removed, "by_dataset": r["removed"], "snapshot_id": snap}
