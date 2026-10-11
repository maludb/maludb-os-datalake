"""The writer's job frame — what every job kind uses, so slices 5, 7 and 8 copy one shape (lake-engine.md §1, §5; uploads-loads.md "What makes
this the exemplar"). A handler is ``job_<kind>(con, pg, job) -> dict``: ``con`` the job's own DuckDB writer connection (None for a kind that
never touches the lake — ``needs_lake = False`` on the function), ``pg`` the job's own psycopg connection (autocommit, dict rows, the
``datalake_lake`` role, ``app.member_id`` = the requester), ``job`` the claimed ``lake_jobs`` row. Its answer becomes ``lake_jobs.result``.

* ``JobRefused`` — a refusal the job DECIDED (the file is gone, the dataset refuses drift): the writer finishes the job failed at once, no
  retry. Any other exception is a fault: retried with back-off until ``max_attempts``.
* ``safe_error`` — an error's first line with every absolute path and every quoted literal taken out: never a row value, a path or SQL with
  literals in ``lake_jobs.error``, ``loads.error`` or a log line.
* ``lineage_start`` / ``lineage_end`` — one OpenLineage run per job that writes a dataset (START, then COMPLETE or FAIL; UUIDv7).
* ``log`` — the writer's activity row: source ``system``, the requester as the actor, the dataset's id (its timeline), ids and counts only.
* ``notify_followers`` — ``dl_notify`` to the dataset's followers who asked for a kind (the wall per follower is ``dl_notify``'s).
* ``refresh_dataset`` / ``sync_columns`` — the technical metadata only the writer records (db/007: a person never types it).
"""
from __future__ import annotations

import os
import re
import secrets
import time
import uuid
from functools import lru_cache

from psycopg.types.json import Json

from lake.engine import LakeConfig, columns_of, split_key


class JobRefused(Exception):
    """A refusal the job decided — finished failed at once, never retried. The message is a person's sentence (no value, no path)."""


@lru_cache(maxsize=1)
def config() -> LakeConfig:
    return LakeConfig.from_env()


def landing_path(cfg: LakeConfig, storage_path: str) -> str:
    """A blob's file: `storage_path` is relative to DL_STORAGE_DIR (landing/<aa>/<bb>/<sha256>) and must stay inside landing/."""
    base = os.path.realpath(os.path.join(cfg.storage_dir, "landing"))
    p = os.path.realpath(os.path.join(cfg.storage_dir, storage_path))
    if not p.startswith(base + os.sep):
        raise JobRefused("the landing file is not where the lake keeps them")
    return p


_PATH = re.compile(r"(?<![\w.])/(?:[\w.@%+=:,~-]+/)*[\w.@%+=:,~-]+")
_QUOTED = re.compile(r"'(?:[^']|'')*'")


def safe_error(e: BaseException | str) -> str:
    """The first line of an error, ≤ 500 characters, with paths and quoted literals replaced — a DuckDB message can quote a cell."""
    text = str(e).strip().splitlines()[0] if str(e).strip() else type(e).__name__ if isinstance(e, BaseException) else "failed"
    text = _PATH.sub("<file>", text)
    text = _QUOTED.sub("'…'", text)
    return text[:500]


def author_of(pg, job: dict) -> str:
    """The DuckLake commit's author: dl:m<member> for a person, dl:a<id> for an agent, dl:system for the worker's own jobs."""
    who = job.get("requested_by")
    if not who:
        return "dl:system"
    row = pg.execute("SELECT member_kind FROM members WHERE id = %s", (who,)).fetchone()
    return f"dl:{'a' if row and row['member_kind'] == 'agent' else 'm'}{int(who)}"


def uuid7() -> uuid.UUID:
    ms = int(time.time() * 1000)
    rnd = int.from_bytes(secrets.token_bytes(10), "big")
    value = (ms & ((1 << 48) - 1)) << 80 | 0x7 << 76 | ((rnd >> 62) & 0xFFF) << 64 | 0b10 << 62 | (rnd & ((1 << 62) - 1))
    return uuid.UUID(int=value)


def dataset_ref(key: str, version: int | None = None, facets: dict | None = None) -> dict:
    return {"namespace": "datalake", "name": key, "version": None if version is None else str(version), "facets": facets or {}}


def lineage_start(pg, kind: str, name: str, job: dict, *, inputs: list | None = None, outputs: list | None = None,
                  load_id: int | None = None, facets: dict | None = None, transform_run_id: int | None = None) -> uuid.UUID:
    """`facets` (slice 7) are OpenLineage's run/job facets added to the job's own — a transform's `sql` (the SQLJobFacet); `transform_run_id` ties the
    event to the application's transform_runs row (lineage_runs.transform_run_id)."""
    run = uuid7()
    pg.execute("""INSERT INTO lineage_runs (run_uuid, job_kind, job_name, event, inputs, outputs, facets, actor_member_id, load_id, transform_run_id)
                  VALUES (%s, %s, %s, 'START', %s, %s, %s, %s, %s, %s)""",
               (run, kind, name[:200], Json(inputs or []), Json(outputs or []), Json({"job": {"id": job["id"], "kind": job["kind"]}, **(facets or {})}),
                job.get("requested_by"), load_id, transform_run_id))
    return run


def lineage_end(pg, run: uuid.UUID, kind: str, name: str, job: dict, *, ok: bool, inputs: list | None = None, outputs: list | None = None,
                facets: dict | None = None, load_id: int | None = None, transform_run_id: int | None = None) -> None:
    f = dict(facets or {})
    pg.execute("""INSERT INTO lineage_runs (run_uuid, job_kind, job_name, event, inputs, outputs, facets, actor_member_id, load_id, transform_run_id)
                  VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
               (run, kind, name[:200], "COMPLETE" if ok else "FAIL", Json(inputs or []), Json(outputs or []), Json(f),
                job.get("requested_by"), load_id, transform_run_id))


def log(pg, job: dict, action: str, entity_type: str | None, entity_id: int | None, *, dataset_id: int | None = None,
        after: dict | None = None, query_run_id: int | None = None) -> None:
    """The writer's activity row (memory.md §2): ids, keys, counts — never a value, SQL or a path."""
    pg.execute("""INSERT INTO activity_log (actor_member_id, source, action, route, entity_type, entity_id, after, request_id, dataset_id, query_run_id)
                  VALUES (%s, 'system', %s, %s, %s, %s, %s, %s, %s, %s)""",
               (job.get("requested_by"), action, f"JOB {job['kind']} {job['id']}", entity_type, entity_id,
                Json(after) if after is not None else None, f"job:{job['id']}", dataset_id, query_run_id))


def notify_followers(pg, dataset_id: int, kind: str, title: str, body: str, record_type: str, record_id: int, actor: int | None,
                     dedupe: str | None = None, also: list[int] | None = None) -> int:
    """dl_notify to every follower who asked to hear `kind` (and to `also` — the requester, the owner); dl_notify checks each one's wall."""
    who = [r["member_id"] for r in pg.execute(
        "SELECT member_id FROM dataset_follows WHERE dataset_id = %s AND %s = ANY (what)", (dataset_id, kind)).fetchall()]
    for m in also or []:
        if m and m not in who:
            who.append(m)
    sent = 0
    for m in who:
        if m == actor and kind != "load_failed":
            continue
        r = pg.execute("SELECT dl_notify(%s, %s, %s, %s, %s, %s, %s, %s, %s) AS id",
                       (m, kind, title[:200], body, record_type, record_id, dataset_id, actor, f"{dedupe}:{m}" if dedupe else None)).fetchone()
        sent += 1 if r and r["id"] else 0
    return sent


def table_id(pg, key: str) -> int | None:
    zone, name = split_key(key)
    row = pg.execute("""SELECT t.table_id FROM ducklake.ducklake_table t JOIN ducklake.ducklake_schema s ON s.schema_id = t.schema_id
                         WHERE s.schema_name = %s AND t.table_name = %s AND t.end_snapshot IS NULL AND s.end_snapshot IS NULL""",
                     (zone, name)).fetchone()
    return int(row["table_id"]) if row else None


def refresh_dataset(con, pg, dataset_id: int, key: str, snapshot_id: int | None, *, data_changed: bool = True) -> dict:
    """The engine's numbers onto the catalog row (rows, bytes, files, the last snapshot, "data updated") — the writer's alone (db/007)."""
    zone, name = split_key(key)
    tid = table_id(pg, key)
    if tid is None:
        return {}
    info = con.execute("SELECT file_count, file_size_bytes FROM ducklake_table_info('lake') WHERE table_id = ?", [tid]).fetchone()
    rows = int(con.execute(f'SELECT count(*) FROM lake."{zone}"."{name}"').fetchone()[0])
    files, size = (int(info[0] or 0), int(info[1] or 0)) if info else (0, 0)
    pg.execute(f"""UPDATE datasets SET row_count = %s, byte_size = %s, file_count = %s, last_snapshot_id = %s, ducklake_schema = %s,
                          ducklake_table_id = %s{', data_updated_at = now()' if data_changed else ''} WHERE id = %s""",
               (rows, size, files, snapshot_id, zone, tid, dataset_id))
    return {"rows": rows, "bytes": size, "files": files}


LOGICAL = (("BIGINT", "integer"), ("INTEGER", "integer"), ("HUGEINT", "integer"), ("SMALLINT", "integer"), ("DECIMAL", "number"),
           ("DOUBLE", "number"), ("FLOAT", "number"), ("BOOLEAN", "boolean"), ("DATE", "date"), ("TIMESTAMP", "timestamp"),
           ("VARCHAR", "string"), ("STRUCT", "object"), ("MAP", "object"), ("JSON", "object"))


def logical_of(physical: str) -> str | None:
    t = (physical or "").upper()
    if t.endswith("[]"):
        return "array"
    for prefix, logical in LOGICAL:
        if t.startswith(prefix):
            return logical
    return None


def sync_columns(con, pg, dataset_id: int, key: str, snapshot_id: int | None) -> dict:
    """The dataset's live columns as the engine has them: a new column gets its row (first_snapshot_id), a known one its type and place.
    A person's description, classification and personal kind are never touched here; a raw column never disappears (drift reports it)."""
    live = columns_of(con, key)
    have = {r["name"].lower(): r for r in pg.execute(
        "SELECT id, name, physical_type, logical_type, ordinal FROM dataset_columns WHERE dataset_id = %s AND removed_snapshot_id IS NULL",
        (dataset_id,)).fetchall()}
    added = 0
    for ordinal, (name, typ) in enumerate(live):
        system = name.startswith("_dl_")
        row = have.get(name.lower())
        if row is None:
            pg.execute("""INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, logical_type, is_system, first_snapshot_id)
                          VALUES (%s, %s, %s, %s, %s, %s, %s)""", (dataset_id, name, ordinal, typ, logical_of(typ), system, snapshot_id))
            added += 1
        elif row["physical_type"] != typ or row["ordinal"] != ordinal or (row["logical_type"] is None and logical_of(typ)):
            pg.execute("""UPDATE dataset_columns SET physical_type = %s, ordinal = %s, is_system = %s,
                                 logical_type = COALESCE(logical_type, %s), first_snapshot_id = COALESCE(first_snapshot_id, %s) WHERE id = %s""",
                       (typ, ordinal, system, logical_of(typ), snapshot_id, row["id"]))
    return {"columns": len(live), "added": added}
