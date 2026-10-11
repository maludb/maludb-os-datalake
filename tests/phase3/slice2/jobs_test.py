"""Slice 2 proof 11 — the writer's four job kinds against the scratch catalog, in-process (uploads-loads.md "Proof" 11; lake-engine §9):
each kind's result, its rows, its lineage runs, its idempotence, the job finished — and the frame (a decided refusal is never retried, an
unbuilt kind names its slice, an error never carries a path or a quoted value). Run by tests/phase3/slice2/run.sh while the writer SERVICE is
stopped (writer_main.run_one claims the next claimable job, so nothing else may be queued), with the scratch environment exported.

    services/.venv/bin/python tests/phase3/slice2/jobs_test.py
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
sys.path.insert(0, os.path.join(ROOT, "services"))

from lake import writer_main  # noqa: E402
from lake.jobs import frame  # noqa: E402

PASSES = FAILS = 0


def ok(cond: bool, label: str) -> None:
    global PASSES, FAILS
    if cond:
        PASSES += 1
    else:
        FAILS += 1
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


def enqueue(kind: str, dataset: str, ref: str, params: str = "{}") -> int:
    return int(pg1(f"INSERT INTO lake_jobs (kind, dataset_id, ref_id, params, requested_by) VALUES ('{kind}', {dataset}, {ref}, '{params}'::jsonb, 26) RETURNING id"))


def run(job_id: int) -> dict:
    row = writer_main.run_one(job_id)
    r = dict(row)
    r["result"] = r["result"] if isinstance(r["result"], dict) else (json.loads(r["result"]) if r["result"] else {})
    return r


ok(pg1("SELECT count(*) FROM lake_jobs WHERE status IN ('queued', 'running')") == "0", "nothing else is queued (the writer service is stopped for this proof)")
store = os.environ["DL_STORAGE_DIR"]
src = os.path.join(ROOT, "tests", "fixtures", "customers.csv")
sha = hashlib.sha256(open(src, "rb").read()).hexdigest()
rel = f"landing/{sha[:2]}/{sha[2:4]}/{sha}"
os.makedirs(os.path.join(store, os.path.dirname(rel)), exist_ok=True)
shutil.copyfile(src, os.path.join(store, rel))
blob = pg1(f"INSERT INTO blobs (sha256, byte_size, storage_path) VALUES ('{sha}', {os.path.getsize(src)}, '{rel}') "
           f"ON CONFLICT (sha256) DO UPDATE SET deleted_at = NULL RETURNING id")
up = pg1(f"INSERT INTO upload_sessions (member_id, filename, byte_size, part_bytes, parts_total, parts_done, status, blob_id, sniff_status) "
         f"VALUES (26, 'customers.csv', {os.path.getsize(src)}, 8388608, 1, 1, 'done', {blob}, 'queued') RETURNING id")

print("sniff")
j = run(enqueue("sniff", "NULL", up))
ok(j["status"] == "done" and j["result"].get("format") == "csv" and j["result"].get("columns") == 8, f"job_sniff: done — {j['result']}")
sn = json.loads(pg1(f"SELECT sniff FROM upload_sessions WHERE id = {up}") or "{}")
ok(pg1(f"SELECT sniff_status FROM upload_sessions WHERE id = {up}") == "done" and len(sn.get("sample", {}).get("", {}).get("rows", [])) == 7,
   "upload_sessions.sniff written with the 7-row sample (the uploader's only)")
ok([c.get("proposed_personal_kind") for c in sn["columns"]][2:5] == ["email", "phone", "payment_card"] and sn["columns"][2]["type"] == "VARCHAR",
   "each column's screen type and the classifier's proposal (email, phone, payment_card)")
ok(pg1(f"SELECT count(*) FROM activity_log WHERE action = 'upload.sniff' AND entity_id = {up} AND source = 'system' AND actor_member_id = 26") == "1",
   "upload.sniff logged by the writer (source system, the requester)")
j = run(enqueue("sniff", "NULL", "999999"))
ok(j["status"] == "failed" and j["error"] == "the upload is gone" and int(pg1(f"SELECT attempts FROM lake_jobs WHERE id = {j['id']}")) == 1,
   "a sniff of an upload that is not there: JobRefused — failed at once, one attempt, never retried")

print("load")
ds = pg1("INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('sandbox.m26_jobs_proof', 'sandbox', 'm26_jobs_proof', 'Jobs proof', 26, 'active') "
         "ON CONFLICT (key) DO UPDATE SET title = EXCLUDED.title RETURNING id")
mapping = json.dumps({"format": "csv", "read_options": dict(sn["read_options"], format="csv"),
                      "columns": {c["source_name"]: {"name": c["name"], "type": "DECIMAL(18,2)" if c["name"] == "balance" else "VARCHAR", "skip": False} for c in sn["columns"]}})
load = pg1(f"INSERT INTO loads (dataset_id, upload_session_id, blob_id, landing_sha256, idempotency_key, mapping, requested_by) "
           f"VALUES ({ds}, {up}, {blob}, '{sha}', '{ds}:{sha}', $m${mapping}$m$::jsonb, 26) RETURNING id")
j = run(enqueue("load", ds, load))
r = j["result"]
ok(j["status"] == "done" and r.get("status") == "loaded" and r.get("rows_loaded") == 7 and r.get("rows_rejected") == 1 and r.get("cast_rejects") == 1,
   f"job_load: loaded 7, refused 1 line, 1 cell left empty — {dict((k, r.get(k)) for k in ('status', 'rows_read', 'rows_loaded', 'rows_rejected', 'cast_rejects', 'snapshot_id'))}")
ok("abc" not in json.dumps(r) and "ada" not in json.dumps(r), "its result carries counts and names — no value of the file")
ok(pg1(f"SELECT status || '/' || rows_loaded || '/' || (snapshot_id IS NOT NULL) FROM loads WHERE id = {load}") == "loaded/7/true", "the loads row: loaded, its counts, its snapshot")
ok(pg1(f"SELECT physical_type FROM dataset_columns WHERE dataset_id = {ds} AND name = 'balance'") == "DECIMAL(18,2)"
   and pg1(f"SELECT count(*) FROM dataset_columns WHERE dataset_id = {ds} AND is_system") == "5", "the columns synced: balance typed, the five provenance columns")
ok(pg(f"SELECT event FROM lineage_runs WHERE load_id = {load} ORDER BY id") == ["START", "COMPLETE"], "lineage_runs: START then COMPLETE")
ok(pg1(f"SELECT count(*) FROM load_rejects WHERE load_id = {load}") == "2", "load_rejects: the refused line and the CAST cell")
prof = pg1(f"SELECT id FROM lake_jobs WHERE kind = 'profile' AND ref_id = {load} AND status = 'queued'")
ok(prof is not None, "a profile job queued after the load")

print("profile")
j = run(int(prof))
ok(j["status"] == "done" and j["result"].get("columns") == 8, f"job_profile: done — {j['result']}")
ok(pg1(f"SELECT count(*) FROM column_profiles WHERE dataset_id = {ds} AND load_id = {load}") == "8"
   and pg1(f"SELECT profile->>'masked' FROM dataset_columns WHERE dataset_id = {ds} AND name = 'email'") == "true",
   "a profile per column; the email column's masked (the classifier proposed it — nobody decided it here)")
ok(pg1(f"SELECT personal_kind || '/' || classified_by FROM dataset_columns WHERE dataset_id = {ds} AND name = 'email'") == "email/rule",
   "★3: email proposed at ≥ 0.8 — masked at once by the rule")

print("idempotence")
pg(f"UPDATE loads SET status = 'queued' WHERE id = {load}")
j = run(enqueue("load", ds, load))
ok(j["status"] == "done" and j["result"].get("status") == "noop" and pg1(f"SELECT status FROM loads WHERE id = {load}") == "no_op",
   "the same load again: noop — already loaded (the engine's backstop under the idempotency key)")
ok(pg1(f"SELECT count(*) FROM lake_jobs WHERE kind = 'profile' AND ref_id = {load} AND status = 'queued'") == "0", "…and no new profile")

print("rollback")
before = int(pg1(f"SELECT snapshot_id FROM loads WHERE id = {load}"))
j = run(enqueue("rollback", ds, load))
ok(j["status"] == "done" and j["result"].get("removed") == 7 and int(j["result"].get("snapshot_id", 0)) > before, f"job_rollback: 7 rows removed in a new snapshot — {j['result']}")
ok(pg1(f"SELECT status || '/' || rolled_back_by || '/' || (rollback_snapshot_id IS NOT NULL) FROM loads WHERE id = {load}") == "rolled_back/26/true", "the load: rolled back, by whom, the snapshot")
ok(pg1(f"SELECT row_count FROM datasets WHERE id = {ds}") == "0", "the catalog: 0 rows now")
j = run(enqueue("rollback", ds, load))
ok(j["status"] == "done" and j["result"].get("removed") == 0, "rolled back again: nothing to do (idempotent)")

print("the frame")
j = run(enqueue("promote", "NULL", "NULL"))
ok(j["status"] == "failed" and j["error"] == "not built until slice 5", "a kind not built yet: failed at once, naming its slice (promote — export is slice 3's, built)")
ok(frame.safe_error("IO Error: No files found that match the pattern \"/srv/lake/landing/ab/cd/x\" near 'ada@example.invalid'")
   == "IO Error: No files found that match the pattern \"<file>\" near '…'", "safe_error: no path, no quoted value")
ok(frame.uuid7().version == 7, "lineage run ids are UUIDv7")
ok({"load", "profile", "rollback", "sniff"} <= set(writer_main.DISPATCH) and getattr(writer_main.DISPATCH["sniff"], "needs_lake", True) is False,
   "DISPATCH holds slice 2's four kinds (later slices add theirs); the sniff never opens the lake")

pg(f"DELETE FROM lake_jobs WHERE status = 'queued'")
print(f"all {PASSES} passed" if FAILS == 0 else f"{FAILS} FAILED ({PASSES} passed)")
sys.exit(1 if FAILS else 0)
