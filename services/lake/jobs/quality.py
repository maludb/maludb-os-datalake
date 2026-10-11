"""Slice 5's job kinds — promote and checks (contracts-quality.md, THE THIRD EXEMPLAR; lake-engine.md §4). The writer runs the engine and hands
every result to the DATABASE, which decides: dl_check_result_record() records it, opens the check's incident (or keeps it) and resolves it on a
pass, rechecks certification (lost by itself, the followers told) and the incident's trigger dispatches the Steward. This file never decides a
state — it measures, records and logs what the database did (`incident.open`, `incident.resolve`, `certification.lost` mirrored).

    promote  params {full?}           dataset = a curated dataset     → curated.<name> typed by the ACTIVE contract (+ quarantine.<name>), its
                                                                         row-level checks counted on the promoted input, its on_load checks after
    checks   params {when?, load_id?} dataset; ref_id = one check     → each enabled check of the dataset (one, those of a `when`, or all) run on
                                                                         the dataset's table, each result through dl_check_result_record()

Row-level checks (not_null, accepted_values, range, regex) are evaluated by the promotion on the rows it promotes: a BLOCKING one quarantines its
failing rows, a non-blocking one only counts them; either way the count is the check's result for that promotion (0 passes). Table-level checks
(unique, row_count, freshness, referential, schema, custom_sql) run on the dataset's table after it; `blocking` on a table-level check marks it
critical (certification counts it) — the promotion is not held (v1, recorded in contracts-quality.md "Built and proven").
"""
from __future__ import annotations

import json
import time

from psycopg.types.json import Json

from lake import checks as lake_checks
from lake import promote as lake_promote
from lake.engine import columns_of, commit, current_snapshot, qi, split_key, table_exists, table_ref

from . import frame
from .frame import JobRefused

ROW_KINDS = ("not_null", "accepted_values", "range", "regex")
TABLE_KINDS = ("unique", "row_count", "freshness", "referential", "schema", "custom_sql")
# a contract's physical type as DuckDB reports it on the table (the shape check before an incremental promotion)
DUCK_TYPE = {"TIMESTAMPTZ": "TIMESTAMP WITH TIME ZONE", "TEXT": "VARCHAR", "STRING": "VARCHAR", "INT8": "BIGINT", "FLOAT8": "DOUBLE"}


# ---- shared ----------------------------------------------------------------------------------------------------------------------------
def _dataset(pg, job: dict) -> dict:
    d = pg.execute("""SELECT id, key, zone, name, status, owner_member_id, steward_member_id, certification, dedupe_key, data_updated_at,
                             freshness_warn_after, freshness_error_after FROM datasets WHERE id = %s""", (job["dataset_id"],)).fetchone()
    if d is None or d["status"] == "retired":
        raise JobRefused("the dataset is gone")
    return d


def check_label(c: dict) -> str:
    """The name a check goes by in the quarantine's reasons and the promotion's counts: `<kind>:<column>` (a contract's own required column
    shares `not_null:<column>` — the same failure, counted once)."""
    return f"{c['kind']}:{c['column_name']}" if c.get("column_name") else str(c["kind"])


def _check_dict(c: dict) -> dict:
    """A checks row as the engine's check (lake.checks / lake.promote): the kind, the column and its params by name."""
    p = dict(c.get("params") or {})
    out = {"kind": c["kind"], "column": c.get("column_name"), "name": check_label(c), "blocking": bool(c.get("blocking"))}
    for k in ("values", "min", "max", "pattern", "max_failures", "columns", "change_pct"):
        if p.get(k) is not None:
            out[k] = p[k]
    return out


def _threshold(c: dict) -> str:
    p = dict(c.get("params") or {})
    k = c["kind"]
    if k in ROW_KINDS or k in ("unique", "referential", "custom_sql", "schema"):
        return str(int(p.get("max_failures") or 0))
    if k == "row_count":
        return json.dumps({x: p[x] for x in ("min", "max", "change_pct") if p.get(x) is not None})
    if k == "freshness":
        return str(p.get("max_age_hours"))
    return ""


def record(pg, job: dict, d: dict, c: dict, *, value, passed: bool, rows_failed: int = 0, failing_keys: list | None = None,
           took_ms: int = 0, error: str | None = None, load_id: int | None = None, snapshot_id: int | None = None) -> int:
    """One result through the database (dl_check_result_record — db/010): it opens or keeps the incident, resolves it on a pass, rechecks
    certification. Then the writer mirrors what the database did into the activity log (ids and counts — never a failing value)."""
    before = pg.execute("SELECT certification FROM datasets WHERE id = %s", (d["id"],)).fetchone()["certification"]
    rid = pg.execute("""SELECT dl_check_result_record(%s::bigint, %s::bigint, %s::bigint, %s::numeric, %s::text, %s::boolean, %s::bigint, %s::jsonb,
                                                      %s::integer, %s::text) AS id""",
                     (c["id"], load_id, snapshot_id, value, _threshold(c), bool(passed), int(rows_failed or 0), Json(list(failing_keys or [])[:20]),
                      int(took_ms or 0), (error or None) and str(error)[:500])).fetchone()["id"]
    frame.log(pg, job, "check.result", "check", int(c["id"]), dataset_id=int(d["id"]),
              after={"result_id": rid, "kind": c["kind"], "passed": bool(passed), "value": None if value is None else float(value),
                     "rows_failed": int(rows_failed or 0), "error": bool(error)})
    opened = pg.execute("SELECT id, severity FROM incidents WHERE opened_by_result_id = %s", (rid,)).fetchone()
    if opened:
        frame.log(pg, job, "incident.open", "incident", int(opened["id"]), dataset_id=int(d["id"]),
                  after={"check_id": int(c["id"]), "severity": opened["severity"], "result_id": rid, "by": "the database"})
    resolved = pg.execute("SELECT id FROM incidents WHERE resolved_by_result_id = %s", (rid,)).fetchone()
    if resolved:
        frame.log(pg, job, "incident.resolve", "incident", int(resolved["id"]), dataset_id=int(d["id"]),
                  after={"check_id": int(c["id"]), "result_id": rid, "by": "a passing run"})
    after = pg.execute("SELECT certification, certification_lost_reason FROM datasets WHERE id = %s", (d["id"],)).fetchone()
    if before != "none" and after["certification"] == "none":
        frame.log(pg, job, "certification.lost", "dataset", int(d["id"]), dataset_id=int(d["id"]),
                  after={"was": before, "missing": (after["certification_lost_reason"] or "")[:300], "by": "the database"})
    return int(rid)


def _salt_or_none() -> str | None:
    try:
        from .query_results import _salt
        return _salt()
    except Exception:
        return None


def _key_columns(pg, d: dict, live: set[str]) -> tuple[list[str], set[str]]:
    """The dataset's key (its contract's) for failing keys, and which of those are personal (hashed with the salt)."""
    key = [k for k in (d.get("dedupe_key") or []) if k in live]
    personal = {r["name"] for r in pg.execute(
        "SELECT name FROM dataset_columns WHERE dataset_id = %s AND removed_snapshot_id IS NULL AND personal_kind IS NOT NULL", (d["id"],)).fetchall()}
    return key, personal


def run_table_checks(con, pg, job: dict, d: dict, checks: list[dict], *, load_id: int | None = None) -> list[dict]:
    """Table-level checks (and row-level ones run as a whole-table count) on the dataset's table — each result through record()."""
    key = d["key"]
    if not checks:
        return []
    have = table_exists(con, key)
    live = {c for c, _ in columns_of(con, key)} if have else set()
    snap = current_snapshot(con) if have else None
    key_cols, personal = _key_columns(pg, d, live)
    salt = _salt_or_none()
    if salt is None:
        key_cols = [k for k in key_cols if k not in personal]          # no salt to hash with: a personal key is never listed
    out = []
    for c in checks:
        t0 = time.monotonic()
        try:
            r = _one_check(con, pg, d, c, have=have, live=live, key_cols=key_cols, personal=personal, salt=salt or "")
        except JobRefused as e:
            r = {"value": None, "passed": False, "rows_failed": 0, "failing_keys": [], "error": str(e)}
        except Exception as e:  # the check could not run: "Could not run", in words without a value
            r = {"value": None, "passed": False, "rows_failed": 0, "failing_keys": [], "error": frame.safe_error(e)}
        r["took_ms"] = int((time.monotonic() - t0) * 1000)
        rid = record(pg, job, d, c, value=r["value"], passed=r["passed"], rows_failed=r.get("rows_failed", 0), failing_keys=r.get("failing_keys"),
                     took_ms=r["took_ms"], error=r.get("error"), load_id=load_id, snapshot_id=snap)
        out.append({"check_id": int(c["id"]), "result_id": rid, "kind": c["kind"], "passed": bool(r["passed"]), "error": bool(r.get("error"))})
    return out


def _one_check(con, pg, d: dict, c: dict, *, have: bool, live: set[str], key_cols: list[str], personal: set[str], salt: str) -> dict:
    k = c["kind"]
    p = dict(c.get("params") or {})
    if k == "freshness":                                              # the worker's measure: hours since "data updated" (lake-engine §4)
        hours = p.get("max_age_hours")
        if hours is None and d.get("freshness_warn_after") is not None:
            hours = d["freshness_warn_after"].total_seconds() / 3600.0
        if hours is None:
            raise JobRefused("no freshness expectation is set")
        upd = d.get("data_updated_at")
        age = None if upd is None else pg.execute("SELECT extract(epoch FROM now() - %s::timestamptz) / 3600.0 AS h", (upd,)).fetchone()["h"]
        return {"value": None if age is None else round(float(age), 3), "passed": age is not None and float(age) <= float(hours)}
    if k == "schema":                                                 # no pending breaking schema change, here or on its raw input
        ids = [d["id"]] + [r["from_dataset_id"] for r in pg.execute(
            "SELECT from_dataset_id FROM lineage_edges WHERE to_dataset_id = %s AND via = 'promote' AND from_dataset_id IS NOT NULL", (d["id"],)).fetchall()]
        n = int(pg.execute("SELECT count(*) AS n FROM schema_versions WHERE dataset_id = ANY(%s) AND decision = 'pending' AND breaking", (ids,)).fetchone()["n"])
        return {"value": n, "passed": n == 0, "rows_failed": 0}
    if not have:
        raise JobRefused(f"{d['key']} has no data yet")
    if c.get("column_name") and c["column_name"] not in live:
        raise JobRefused(f"{d['key']} has no column {c['column_name']}")
    chk = _check_dict(c)
    if k == "row_count" and p.get("change_pct") is not None:
        prev = pg.execute("""SELECT value FROM check_results WHERE check_id = %s AND error IS NULL AND value IS NOT NULL
                              ORDER BY ran_at DESC, id DESC LIMIT 1""", (c["id"],)).fetchone()
        if prev is not None and prev["value"]:
            chk["previous"] = int(prev["value"])
    if k == "referential":
        other = str(p.get("dataset_key") or "")
        col = str(p.get("column") or "")
        if not other or not col:
            raise JobRefused("a referential check names the dataset and the column it refers to")
        if not table_exists(con, other):
            raise JobRefused(f"{other} has no data yet")
        if col not in {x for x, _ in columns_of(con, other)}:
            raise JobRefused(f"{other} has no column {col}")
        chk["references"], chk["references_column"] = other, col
    if k == "custom_sql":
        chk["rewritten_sql"] = _custom_sql(con, d["key"], str(p.get("sql") or ""))
    r = lake_checks.run_checks(con, d["key"], [chk], key_columns=key_cols, personal=personal, salt_hex=salt)[0]
    value = r["value"]
    if isinstance(value, dict):
        value = None
    return {"value": value, "passed": bool(r["passed"]), "rows_failed": int(r.get("rows_failed") or 0), "failing_keys": r.get("failing_keys") or []}


def _custom_sql(con, key: str, sql: str) -> str:
    """A custom check's SQL through the query service's allow-list against the dataset's OWN key only (lake-engine §4): the SQL may read
    nothing else, call no table function, write nothing. Rewritten onto a temporary view of the dataset."""
    from query import allowlist
    zone, name = split_key(key)
    con.execute(f"CREATE OR REPLACE TEMP VIEW _dl_check_self AS SELECT * FROM {table_ref(key)}")
    try:
        checked = allowlist.check(sql, {key: "_dl_check_self"}, table_functions=allowlist.table_function_names(con))
    except allowlist.Refusal as r:
        raise JobRefused(f"the custom SQL was refused: {r.message}")
    return checked.sql


def _checks_of(pg, dataset_id: int, *, when: str | None = None, one: int | None = None, kinds: tuple | None = None) -> list[dict]:
    sql = "SELECT id, dataset_id, column_name, kind, params, severity, blocking, run_when FROM checks WHERE dataset_id = %s AND enabled"
    args: list = [dataset_id]
    if one is not None:
        sql += " AND id = %s"
        args.append(one)
    if when is not None:
        sql += " AND run_when = %s"
        args.append(when)
    if kinds is not None:
        sql += " AND kind = ANY(%s)"
        args.append(list(kinds))
    return list(pg.execute(sql + " ORDER BY id", tuple(args)).fetchall())


# ---- promote ----------------------------------------------------------------------------------------------------------------------------
def _contract(pg, dataset_id: int) -> dict | None:
    return pg.execute("SELECT id, version, schema, key, ordering_column FROM contracts WHERE dataset_id = %s AND status = 'active'", (dataset_id,)).fetchone()


def _source(e: dict) -> tuple[str, str]:
    """A schema element's `source` — `{raw key}.{column}` (divergence 1) — as (raw key, column)."""
    s = str(e.get("source") or "")
    parts = s.split(".")
    if len(parts) != 3 or parts[0] != "raw":
        raise JobRefused(f"the contract's column {e.get('name')} names no raw column ({s or 'no source'})")
    return f"{parts[0]}.{parts[1]}", parts[2]


def _duck(t: str) -> str:
    t = (t or "VARCHAR").strip().upper()
    return DUCK_TYPE.get(t, t).replace(", ", ",")


def job_promote(con, pg, job: dict) -> dict:
    d = _dataset(pg, job)
    if d["zone"] != "curated":
        raise JobRefused("promotion is raw → curated: only a curated dataset is promoted")
    k = _contract(pg, int(d["id"]))
    if k is None:
        raise JobRefused("Publish a contract first — promotion types by it")
    schema = list(k["schema"] or [])
    raws = {_source(e)[0] for e in schema}
    if len(raws) != 1:
        raise JobRefused("A curated dataset promotes from one raw dataset — two inputs are a transform")
    raw_key = raws.pop()
    raw = pg.execute("SELECT id, key FROM datasets WHERE key = %s AND status <> 'retired'", (raw_key,)).fetchone()
    if raw is None:
        raise JobRefused(f"{raw_key} is gone")
    if frame.table_id(pg, raw_key) is None:
        raise JobRefused(f"{raw_key} has no data yet")
    key, cur_id = d["key"], int(d["id"])
    contract = {"key": list(k["key"] or []), "ordering": k["ordering_column"] or None,
                "columns": [{"name": e["name"], "source": _source(e)[1], "type": _duck(e.get("physical_type")),
                             "nullable": not bool(e.get("required"))} for e in schema]}
    if not contract["key"]:
        raise JobRefused("the contract names no key — promotion deduplicates by it")
    full = bool((job.get("params") or {}).get("full"))
    rebuilt_for_shape = False
    since = None
    if not full:
        last = pg.execute("""SELECT result FROM lake_jobs WHERE kind = 'promote' AND dataset_id = %s AND status = 'done' AND result ? 'raw_snapshot'
                              ORDER BY finished_at DESC, id DESC LIMIT 1""", (cur_id,)).fetchone()
        since = int(last["result"]["raw_snapshot"]) if last and last["result"].get("raw_snapshot") is not None else None
    # the curated table's shape against the contract: an added column is added (old rows NULL until a rebuild); a removed or retyped one
    # (a new major version) rebuilds the table whole — never a MERGE into a shape the contract no longer has
    author = frame.author_of(pg, job)
    if table_exists(con, key):
        live = {c: t for c, t in columns_of(con, key) if not c.startswith("_dl_")}
        want = {c["name"]: c["type"] for c in contract["columns"]}
        removed = [c for c in live if c not in want]
        retyped = [c for c in want if c in live and live[c].replace(", ", ",").upper() != want[c].upper()]
        added = [c for c in want if c not in live]
        if removed or retyped:
            with commit(con, author, f"drop {key} — contract {k['version']} changed its shape"):
                con.execute(f"DROP TABLE {table_ref(key)}")
            full, rebuilt_for_shape = True, True
        elif added:
            with commit(con, author, f"columns added to {key} by contract {k['version']}"):
                for c in added:
                    con.execute(f"ALTER TABLE {table_ref(key)} ADD COLUMN {qi(c)} {want[c]}")
    q_key = "quarantine." + d["name"]
    if table_exists(con, q_key):
        q_live = {c for c, _ in columns_of(con, q_key)}
        q_missing = sorted({c["source"] for c in contract["columns"]} - q_live)
        if q_missing:
            with commit(con, author, f"columns added to {q_key} by contract {k['version']}"):
                for c in q_missing:
                    con.execute(f"ALTER TABLE {table_ref(q_key)} ADD COLUMN {qi(c)} VARCHAR")
    names = {c["name"] for c in contract["columns"]}
    # the row-level checks a promotion evaluates on its rows: every BLOCKING one (it gates the rows) and the non-blocking ones that run on
    # every load; a non-blocking row check kept for the hour or the day waits for its schedule (job_checks over the table)
    row_checks = [c for c in _checks_of(pg, cur_id, kinds=ROW_KINDS) if c["column_name"] in names and (c["blocking"] or c["run_when"] == "on_load")]
    name = f"promote.{d['name']}"
    in_ref, out_ref = [frame.dataset_ref(raw_key)], [frame.dataset_ref(key)]
    run = frame.lineage_start(pg, "promote", name, job, inputs=in_ref, outputs=out_ref)
    try:
        r = lake_promote.promote(con, raw_key=raw_key, curated_key=key, contract=contract, checks=[_check_dict(c) for c in row_checks],
                                 author=author, since_snapshot=since, full=full)
    except ValueError as e:                                       # the contract reads a column raw does not have (any more): decided
        msg = frame.safe_error(e)
        frame.lineage_end(pg, run, "promote", name, job, ok=False, inputs=in_ref, outputs=out_ref, facets={"errorMessage": {"message": msg}})
        frame.log(pg, job, "promote.fail", "dataset", cur_id, dataset_id=cur_id, after={"dataset": key, "error": msg[:200]})
        raise JobRefused(msg)
    except Exception as e:
        frame.lineage_end(pg, run, "promote", name, job, ok=False, inputs=in_ref, outputs=out_ref,
                          facets={"errorMessage": {"message": frame.safe_error(e)}})
        frame.log(pg, job, "promote.fail", "dataset", cur_id, dataset_id=cur_id, after={"dataset": key, "error": frame.safe_error(e)[:200]})
        raise
    snap = int(r["snapshot_id"])
    changed = r["status"] == "promoted" and (r["rows_promoted"] > 0 or r["rows_quarantined"] > 0 or full)
    frame.sync_columns(con, pg, cur_id, key, snap)
    meta = frame.refresh_dataset(con, pg, cur_id, key, snap, data_changed=r["status"] == "promoted" and (r["rows_promoted"] > 0 or full))
    _apply_contract(pg, cur_id, schema, raw["id"])
    pg.execute("SELECT dl_lineage_edge(%s, NULL, %s, 'promote')", (raw["id"], cur_id))
    deduped = max(0, int(r["rows_in"]) - int(r["rows_quarantined"]) - int(r["rows_promoted"])) if r["status"] == "promoted" else 0
    kind_word = "promote.rebuild" if full else "promote.run"
    frame.log(pg, job, kind_word, "dataset", cur_id, dataset_id=cur_id, after={
        "dataset": key, "from": raw_key, "contract": k["version"], "status": r["status"], "rows_in": r["rows_in"], "rows_promoted": r["rows_promoted"],
        "rows_quarantined": r["rows_quarantined"], "rows_deduped": deduped, "snapshot_id": snap, "full": full, "rebuilt_for_shape": rebuilt_for_shape})
    # the row-level checks' results: their count on the rows this promotion read (nothing new to read → no new result)
    results = []
    if r["status"] == "promoted":
        for c in row_checks:
            n = int((r["reasons"] if c["blocking"] else r["warnings"]).get(check_label(c), 0))
            rid = record(pg, job, d, c, value=n, passed=n <= int((c["params"] or {}).get("max_failures") or 0), rows_failed=n, snapshot_id=snap)
            results.append({"check_id": int(c["id"]), "result_id": rid, "passed": n == 0})
    # then the table-level checks that run on every load
    results += run_table_checks(con, pg, job, d, _checks_of(pg, cur_id, when="on_load", kinds=TABLE_KINDS))
    frame.lineage_end(pg, run, "promote", name, job, ok=True, inputs=[frame.dataset_ref(raw_key, r["raw_snapshot"])],
                      outputs=[frame.dataset_ref(key, snap)], facets={"outputStatistics": {"rowCount": r["rows_promoted"]},
                      "quarantine": {"rows": r["rows_quarantined"]}})
    if changed:
        pg.execute("SELECT dl_job_enqueue('profile', %s, NULL, %s::jsonb, 6::smallint)", (cur_id, Json({"after": "promote"})))
    return {"status": r["status"], "rows_in": r["rows_in"], "rows_promoted": r["rows_promoted"], "rows_quarantined": r["rows_quarantined"],
            "rows_deduped": deduped, "warnings": r["warnings"], "reasons": r["reasons"], "raw_snapshot": r["raw_snapshot"], "snapshot_id": snap,
            "contract": k["version"], "full": full, "rebuilt_for_shape": rebuilt_for_shape, "since": since, "rows_now": meta.get("rows"),
            "checks": len(results), "checks_failed": sum(1 for x in results if not x["passed"]), "ms": r.get("ms")}


def _apply_contract(pg, dataset_id: int, schema: list[dict], raw_id: int) -> None:
    """What the contract says of each curated column onto the catalog: the key, required, the description where none is written yet, and a
    personal kind or classification carried from the contract (itself prefilled from the raw column) — never over a person's confirmation."""
    for e in schema:
        row = pg.execute("""SELECT id, description, personal_kind, classification, classification_confirmed_at FROM dataset_columns
                             WHERE dataset_id = %s AND name = %s AND removed_snapshot_id IS NULL""", (dataset_id, e["name"])).fetchone()
        if row is None:
            continue
        pg.execute("""UPDATE dataset_columns SET is_key = %s, nullable = %s, critical = critical OR %s,
                             logical_type = COALESCE(%s, logical_type), description = COALESCE(description, %s) WHERE id = %s""",
                   (bool(e.get("primary_key")), not bool(e.get("required")), bool(e.get("critical")), e.get("logical_type") or None,
                    (e.get("description") or None), row["id"]))
        if row["classification_confirmed_at"] is not None:
            continue
        pk = e.get("personal_kind") or None
        if pk and row["personal_kind"] is None:
            pg.execute("UPDATE dataset_columns SET personal_kind = %s, classified_by = 'rule' WHERE id = %s", (pk, row["id"]))
        cl = e.get("classification")
        if cl in ("confidential", "restricted") and row["classification"] in ("public", "internal") or (cl == "restricted" and row["classification"] == "confidential"):
            pg.execute("UPDATE dataset_columns SET classification = %s WHERE id = %s", (cl, row["id"]))


# ---- checks -----------------------------------------------------------------------------------------------------------------------------
def job_checks(con, pg, job: dict) -> dict:
    d = _dataset(pg, job)
    params = job.get("params") or {}
    one = job.get("ref_id") or params.get("check")
    when = params.get("when") if params.get("when") in ("on_load", "hourly", "daily") else None
    checks = _checks_of(pg, int(d["id"]), one=int(one) if one else None, when=None if one else when)
    if one and not checks:
        raise JobRefused("that check is gone or turned off")
    load_id = params.get("load_id")
    # a row-level check run here counts the table as it stands (a person's "run now", a schedule); a promotion counts its own input
    results = run_table_checks(con, pg, job, d, checks, load_id=load_id)
    frame.log(pg, job, "check.run", "dataset", int(d["id"]), dataset_id=int(d["id"]),
              after={"dataset": d["key"], "checks": len(results), "failed": sum(1 for x in results if not x["passed"]), "when": when or ("one" if one else "all")})
    return {"dataset": d["key"], "checks": len(results), "failed": sum(1 for x in results if not x["passed"]),
            "errors": sum(1 for x in results if x["error"]), "results": [x["result_id"] for x in results]}
