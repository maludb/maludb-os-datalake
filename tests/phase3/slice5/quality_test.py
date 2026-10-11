"""Slice 5 proof 8 — the writer's two kinds in-process (contracts-quality.md "Proof" 8; lake-engine §4): job_promote and job_checks against the
scratch catalog — the results through dl_check_result_record() (an incident opened by the database, resolved on a pass), the lineage runs, the
quarantine, idempotence, a personal key hashed in a result's failing keys, the decided refusals (no contract, no table) never retried. Run by
tests/phase3/slice5/run.sh while the writer SERVICE is stopped (writer_main.run_one claims the next claimable job, so nothing else may be queued).

    services/.venv/bin/python tests/phase3/slice5/quality_test.py
"""
from __future__ import annotations

import json
import os
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
sys.path.insert(0, os.path.join(ROOT, "services"))

from lake import writer_main  # noqa: E402
from lake.engine import LakeConfig, commit, current_snapshot, table_exists, writer_connection  # noqa: E402

PASSES = FAILS = 0


def ok(cond: bool, label: str) -> None:
    global PASSES, FAILS
    PASSES, FAILS = (PASSES + 1, FAILS) if cond else (PASSES, FAILS + 1)
    print(("  ok   " if cond else "  FAIL ") + label, flush=True)


def pg(sql: str) -> list[str]:
    out = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-X", "-q", "-At", "-v", "ON_ERROR_STOP=1", "-d", os.environ["DB_NAME"]],
                         input=sql, capture_output=True, text=True)
    if out.returncode != 0:
        print("psql: " + out.stderr.strip(), file=sys.stderr)
    return [line for line in out.stdout.splitlines() if line != ""]


def pg1(sql: str) -> str | None:
    rows = pg(sql)
    return rows[0] if rows else None


def enqueue(kind: str, dataset: str, ref: str = "NULL", params: str = "{}") -> int:
    return int(pg1(f"INSERT INTO lake_jobs (kind, dataset_id, ref_id, params, requested_by) VALUES ('{kind}', {dataset}, {ref}, '{params}'::jsonb, 27) RETURNING id"))


def run(job_id: int) -> dict:
    r = dict(writer_main.run_one(job_id))
    r["result"] = r["result"] if isinstance(r["result"], dict) else (json.loads(r["result"]) if r["result"] else {})
    return r


ok(pg1("SELECT count(*) FROM lake_jobs WHERE status IN ('queued', 'running')") == "0", "nothing else is queued (the writer service is stopped for this proof)")

# the proof's own lake: raw.qt_people (all text + provenance, as a load writes it) — the key is a PERSONAL email
cfg = LakeConfig.from_env()
con = writer_connection(cfg)
if not table_exists(con, "raw.qt_people"):
    with commit(con, "dl:m27", "quality_test raw.qt_people"):
        con.execute("""CREATE TABLE lake.raw.qt_people AS SELECT * FROM (VALUES
            ('a@example.invalid', '10', '2026-01-01', 1::BIGINT, 1::BIGINT), ('b@example.invalid', '', '2026-01-02', 1, 2),
            ('c@example.invalid', 'x', '2026-01-03', 1, 3), ('a@example.invalid', '12', '2026-01-04', 1, 4))
            t(email, score, joined, _dl_load_id, _dl_row)""")
        con.execute("ALTER TABLE lake.raw.qt_people ADD COLUMN _dl_loaded_at TIMESTAMP WITH TIME ZONE")
        con.execute("UPDATE lake.raw.qt_people SET _dl_loaded_at = now()")
con.close()
raw = pg1("""INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('raw.qt_people', 'raw', 'qt_people', 'QT people', 27, 'active')
             ON CONFLICT (key) DO UPDATE SET title = EXCLUDED.title RETURNING id""")
cur = pg1("""INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('curated.qt_people', 'curated', 'qt_people', 'QT people', 27, 'active')
             ON CONFLICT (key) DO UPDATE SET title = EXCLUDED.title RETURNING id""")
schema = json.dumps([{"name": "email", "physical_type": "VARCHAR", "required": True, "primary_key": True, "source": "raw.qt_people.email", "personal_kind": "email"},
                     {"name": "score", "physical_type": "INTEGER", "source": "raw.qt_people.score"},
                     {"name": "joined", "physical_type": "DATE", "source": "raw.qt_people.joined"}])
pg(f"""INSERT INTO contracts (dataset_id, version, status, schema, key, ordering_column, published_at)
       VALUES ({cur}, '1.0.0', 'active', '{schema}'::jsonb, '{{email}}', 'joined', now()) ON CONFLICT DO NOTHING;
       UPDATE datasets SET contract_version = '1.0.0', dedupe_key = '{{email}}', ordering_column = 'joined' WHERE id = {cur};""")
nn = pg1(f"INSERT INTO checks (dataset_id, column_name, kind, dimension, severity, blocking, source) VALUES ({cur}, 'score', 'not_null', 'completeness', 'error', true, 'person') RETURNING id")
rc = pg1(f"""INSERT INTO checks (dataset_id, kind, params, dimension, severity, source) VALUES ({cur}, 'row_count', '{{"min": 5}}', 'completeness', 'warn', 'person') RETURNING id""")
rng = pg1(f"""INSERT INTO checks (dataset_id, column_name, kind, params, dimension, severity, source, run_when)
              VALUES ({cur}, 'score', 'range', '{{"max": 100}}', 'accuracy', 'warn', 'person', 'daily') RETURNING id""")

print("promote")
j = run(enqueue("promote", cur))
r = j["result"]
ok(j["status"] == "done" and r.get("rows_in") == 4 and r.get("rows_promoted") == 1 and r.get("rows_quarantined") == 2 and r.get("rows_deduped") == 1,
   f"job_promote: 4 in — 1 promoted, 2 held back (an empty score by the blocking not_null, 'x' that is no integer), 1 duplicate — {dict((k, r.get(k)) for k in ('rows_in', 'rows_promoted', 'rows_quarantined', 'rows_deduped'))}")
ok(r.get("reasons") == {"not_null:score": 2, "cast:score": 1}, f"the reasons: cast:score for 'x', not_null:score for it and the empty one (a value that did not type is null) — {r.get('reasons')}")
ok(pg1(f"SELECT passed::text || '|' || value || '|' || (opened.id IS NOT NULL) FROM check_results cr LEFT JOIN incidents opened ON opened.opened_by_result_id = cr.id WHERE cr.check_id = {nn} ORDER BY cr.id DESC LIMIT 1")
   == "false|2|true", "the blocking not_null's result on the rows it read: failed, two — the database opened its incident")
ok(pg1(f"SELECT passed::text || '|' || value FROM check_results WHERE check_id = {rc} ORDER BY id DESC LIMIT 1") == "false|1",
   "the on_load row count ran after the promotion: one row against a minimum of five — failed")
ok(pg1(f"SELECT count(*) FROM check_results WHERE check_id = {rng}") == "0", "the daily range did not run (not on_load)")
ok(pg(f"SELECT event FROM lineage_runs WHERE job_name = 'promote.qt_people' ORDER BY id") == ["START", "COMPLETE"], "lineage: START then COMPLETE")
ok(pg1(f"SELECT count(*) FROM lake_jobs WHERE kind = 'profile' AND dataset_id = {cur} AND status = 'queued'") == "1", "a profile of the curated table queued")
pg(f"DELETE FROM lake_jobs WHERE kind = 'profile' AND dataset_id = {cur} AND status = 'queued'")
ok(pg1(f"SELECT personal_kind FROM dataset_columns WHERE dataset_id = {cur} AND name = 'email'") == "email", "the contract's personal kind reaches the curated column")

print("idempotence")
j2 = run(enqueue("promote", cur))
ok(j2["status"] == "done" and j2["result"].get("status") == "nothing_new" and j2["result"].get("since") == r.get("raw_snapshot"),
   "again with nothing new: nothing_new, from the last promotion's raw snapshot — no result, no commit")
j3 = run(enqueue("promote", cur, params='{"full": true}'))
ok(j3["result"].get("rows_promoted") == 1 and j3["result"].get("rows_quarantined") == 2 and j3["result"].get("full") is True, "rebuilt whole: the same answer")
ok(pg1(f"SELECT count(*) FROM incidents WHERE check_id = {nn}") == "1", "a second failure keeps the one incident")

print("checks")
j4 = run(enqueue("checks", cur, params='{"when": "all"}'))
ok(j4["status"] == "done" and j4["result"].get("checks") == 3, f"job_checks: every enabled check — {j4['result']}")
ok(pg1(f"SELECT passed::text FROM check_results WHERE check_id = {rng} ORDER BY id DESC LIMIT 1") == "true", "the range on the table as it stands: passed (12 ≤ 100 — the later row of a@ won)")
pg(f"""UPDATE checks SET params = '{{"max": 5}}' WHERE id = {rng}""")
j5 = run(enqueue("checks", cur, ref=rng))
keys = json.loads(pg1(f"SELECT failing_keys FROM check_results WHERE check_id = {rng} ORDER BY id DESC LIMIT 1") or "[]")
ok(j5["result"].get("checks") == 1 and len(keys) == 1 and str(keys[0]).startswith("h:") and "example" not in json.dumps(keys),
   f"one check run alone: failed — its failing KEY is personal (the email), so hashed with the salt: {keys}")
pg(f"""UPDATE checks SET params = '{{"max": 100}}' WHERE id = {rng}""")
j6 = run(enqueue("checks", cur, ref=rng))
inc = pg1(f"SELECT status || '|' || (resolved_by_result_id IS NOT NULL) FROM incidents WHERE check_id = {rng} ORDER BY id DESC LIMIT 1")
ok(inc == "resolved|true", "passing again: the database resolved its incident, citing the result")
ok(pg1(f"SELECT count(*) FROM activity_log WHERE action = 'incident.resolve' AND source = 'system' AND dataset_id = {cur}") == "2"
   and pg1(f"SELECT count(*) FROM activity_log WHERE action = 'incident.open' AND source = 'system' AND dataset_id = {cur}") == "3",
   "the writer mirrors what the database did: incident.open (3 — not_null, row_count, range), incident.resolve (2)")

print("refusals")
nc = pg1("""INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('curated.qt_none', 'curated', 'qt_none', 'QT none', 27, 'active')
            ON CONFLICT (key) DO UPDATE SET title = EXCLUDED.title RETURNING id""")
j7 = run(enqueue("promote", nc))
ok(j7["status"] == "failed" and j7["error"] == "Publish a contract first — promotion types by it" and pg1(f"SELECT attempts FROM lake_jobs WHERE id = {j7['id']}") == "1",
   "a promotion with no contract: refused in words, failed at once, never retried")
pg(f"INSERT INTO checks (dataset_id, kind, column_name, dimension, source) VALUES ({nc}, 'not_null', 'x', 'completeness', 'person')")
j8 = run(enqueue("checks", nc, params='{"when": "all"}'))
ok(j8["status"] == "done" and pg1(f"SELECT error FROM check_results WHERE dataset_id = {nc} ORDER BY id DESC LIMIT 1") == "curated.qt_none has no data yet",
   "a check on a table that is not there: the result says it could not run (in words) — the job itself is done")
j9 = run(enqueue("promote", raw))
ok(j9["status"] == "failed" and "only a curated dataset is promoted" in (j9["error"] or ""), "promoting a raw dataset: refused")
pg("DELETE FROM lake_jobs WHERE status = 'queued'")
print(f"all {PASSES} passed" if FAILS == 0 else f"{FAILS} FAILED ({PASSES} passed)")
sys.exit(1 if FAILS else 0)
