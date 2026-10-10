"""The timing set (design §10, Phase 0): 50 million rows over 200 datasets, through the real code paths, under the design's
caps (the writer 3 GB / 4 threads; the query service 1.5 GB / 2 threads). Prints the numbers and writes var/engine/timing.json."""
from __future__ import annotations

import json
import os
import resource
import subprocess
import sys
import time

from tap import ROOT

from lake import erase as er
from lake import maintenance as mt
from lake.engine import LakeConfig, writer_connection
from lake.load import load_raw
from lake.promote import promote
from query.service import run

cfg = LakeConfig.from_env()
TOTAL = int(os.environ.get("TIMING_ROWS", "50000000"))
N = int(os.environ.get("TIMING_DATASETS", "200"))
land = os.path.join(cfg.storage_dir, "landing", "timing")
num: dict = {"rows": TOTAL, "datasets": N, "cpu": "x86-64-v2 (no AVX)", "writer_cap": "3GB / 4 threads",
             "query_cap": "1.5GB / 2 threads"}


def clock(label, fn):
    t0 = time.monotonic()
    out = fn()
    num[label] = round(time.monotonic() - t0, 2)
    print(f"{label:38} {num[label]:9.2f} s", flush=True)
    return out


clock("generate (parquet landing files)", lambda: subprocess.run(
    [sys.executable, os.path.join(ROOT, "tests", "fixtures", "gen_timing.py"), land, str(TOTAL), str(N)], check=True,
    capture_output=True))
con = writer_connection(cfg)
names = ["t_big"] + [f"t_{k:03d}" for k in range(1, N)]


def load_all():
    for i, nm in enumerate(names):
        p = os.path.join(land, nm + ".parquet")
        if nm == "t_big":
            num["load_big_25M"] = None
            t0 = time.monotonic()
        load_raw(con, dataset_key=f"raw.{nm}", landing_path=p, landing_sha256=f"timing-{nm}", load_id=1000 + i,
                 author="dl:timing", read_options={"format": "parquet"}, work_dir=cfg.temp_dir)
        if nm == "t_big":
            num["load_big_25M"] = round(time.monotonic() - t0, 2)


clock("load raw, all 200 (stream → encrypted)", load_all)
print(f"{'  of which the 25M-row table':38} {num['load_big_25M']:9.2f} s")
contract = {"columns": [{"name": "order_id", "type": "VARCHAR", "nullable": False}, {"name": "customer_id", "type": "VARCHAR"},
                        {"name": "email", "type": "VARCHAR"}, {"name": "ordered_at", "type": "TIMESTAMP"},
                        {"name": "amount", "type": "DECIMAL(12,2)"}, {"name": "region", "type": "VARCHAR"},
                        {"name": "status", "type": "VARCHAR"}, {"name": "note", "type": "VARCHAR"}], "key": ["order_id"]}


def promote_all():
    for nm in names:
        if nm == "t_big":
            t0 = time.monotonic()
        promote(con, raw_key=f"raw.{nm}", curated_key=f"curated.{nm}", contract=contract,
                checks=[{"kind": "accepted_values", "column": "status", "values": ["open", "shipped", "cancelled"]}],
                author="dl:timing")
        if nm == "t_big":
            num["promote_big_25M"] = round(time.monotonic() - t0, 2)


clock("promote raw → curated, all 200", promote_all)
print(f"{'  of which the 25M-row table':38} {num['promote_big_25M']:9.2f} s")
num["curated_rows"] = sum(con.execute(f"SELECT count(*) FROM lake.curated.{nm}").fetchone()[0] for nm in ("t_big", "t_001"))
cols = ["order_id", "customer_id", "email", "ordered_at", "amount", "region", "status", "note"]
policy = {"caller": 1, "on_behalf_of": None, "salt_hex": "0123456789abcdef0123456789abcdef",
          "limits": {"timeout_s": 120, "row_limit": 1000, "result_bytes": 5_000_000, "memory": "1.5GB", "threads": 2},
          "datasets": {f"curated.{nm}": {"level": "read", "columns": cols, "masks": {"email": "partial_email"}, "row_filter": None}
                       for nm in ("t_big", "t_001")}}
for label, sql in [
        ("query: filtered (one customer, 25M)", "SELECT order_id, amount, email FROM curated.t_big WHERE customer_id = '0001234'"),
        ("query: aggregate (region × month, 25M)", "SELECT region, date_trunc('month', ordered_at) m, sum(amount), count(*) "
                                                   "FROM curated.t_big GROUP BY 1, 2 ORDER BY 1, 2"),
        ("query: join (25M ⋈ 125k on customer)", "SELECT b.region, count(*) FROM curated.t_big b JOIN curated.t_001 s "
                                                  "USING (customer_id) GROUP BY 1 ORDER BY 1"),
        ("query: masked scan (count distinct email)", "SELECT count(DISTINCT email) FROM curated.t_big")]:
    r = clock(label, lambda: run(cfg, policy, sql))
    num[label + " status"] = r["status"]
    if r["status"] != "ok":
        print("   ", r["status"], r["refusal_rule"], r["message"])
size = sum(os.path.getsize(os.path.join(dp, f)) for dp, _, fs in os.walk(cfg.data_path) for f in fs)
num["lake_bytes"] = size
print(f"{'lake on disk (raw + curated, encrypted)':38} {size / 1e9:9.2f} GB")
clock("maintenance (flush, merge, rewrite, expire, cleanup)", lambda: mt.run(con, author="dl:timing", snapshot_keep_days=0,
                                                                            cleanup_margin_days=0))
subject = "c1234@example.invalid"
pmap = {f"{z}.{nm}": {"email": "email"} for z in ("raw", "curated") for nm in names}
targets = clock("erasure: scan 400 tables for one email", lambda: er.scan(con, identifiers=[{"kind": "email", "value": subject}],
                                                                          personal_map=pmap))
num["erasure_rows_found"] = sum(t["rows"] for t in targets)
clock("erasure: delete, rewrite, expire, clean", lambda: er.erase(con, targets=targets, author="dl:timing", request_id=9,
                                                                    lake_dsn=cfg.lake_dsn))
proof = clock("erasure: verify (decrypt + search all)", lambda: er.verify(con, needles=[subject], data_path=cfg.data_path,
                                                                          lake_dsn=cfg.lake_dsn))
num["erasure_clean"] = proof["clean"]
num["peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024)
print(f"{'erasure found / clean':38} {num['erasure_rows_found']} rows / {proof['clean']}")
print(f"{'peak RSS of this process':38} {num['peak_rss_mb']} MB")
with open(os.path.join(cfg.storage_dir, "..", "timing.json"), "w") as f:
    json.dump(num, f, indent=1)
con.close()
