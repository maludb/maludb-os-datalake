"""The writer's proof (design §10, Phase 0, the engine-proof bullet) against the scratch catalog (dl_engine) and data
directory (var/engine). Run by tests/engine/run.sh after setup_scratch.sh — re-runnable from nothing."""
from __future__ import annotations

import datetime as dt
import os
import shutil
import threading
import time

from tap import ROOT, Tap

import duckdb
import psycopg

from lake import backup as bk
from lake import erase as er
from lake import maintenance as mt
from lake.checks import run_checks
from lake.engine import LakeConfig, current_snapshot, query_connection, writer_connection
from lake.load import LoadRefused, load_raw, rollback_load
from lake.profile import profile
from lake.promote import promote
from lake.sniff import sha256_file, sniff
from lake.transform import transform

T = Tap("engine")
cfg = LakeConfig.from_env()
FIX = os.path.join(ROOT, "tests", "fixtures")
LANDING = os.path.join(cfg.storage_dir, "landing")
SALT = "5f1c0a2b9d8e7f6a5b4c3d2e1f0a9b8c"
AUTHOR = "dl:member:1"


def land(name: str) -> tuple[str, str]:
    """Content-addressed landing (DocCloud's blobs shape): landing/<aa>/<bb>/<sha256>."""
    src = os.path.join(FIX, name)
    sha = sha256_file(src)
    d = os.path.join(LANDING, sha[:2], sha[2:4])
    os.makedirs(d, exist_ok=True)
    dst = os.path.join(d, sha + os.path.splitext(name)[1])
    if not os.path.exists(dst):
        shutil.copyfile(src, dst)
    return dst, sha


def snaps(con):
    return con.execute("SELECT snapshot_id, author, commit_message FROM ducklake_snapshots('lake') ORDER BY snapshot_id").fetchall()


con = writer_connection(cfg)
s0 = current_snapshot(con)
con.close()
con = writer_connection(cfg)
T.ok(current_snapshot(con) == s0, "a second connect writes nothing to the catalog (no snapshot)")
T.ok(con.execute("SELECT path FROM duckdb_databases() WHERE database_name = 'lake'").fetchone()[0].find("password") < 0,
     "the attached catalog's conninfo carries no password (passfile)")
opts = dict((r[0], r[2]) for r in con.execute("FROM ducklake_options('lake')").fetchall())
T.ok(opts.get("encrypted") == "true" and opts.get("data_inlining_row_limit") == "10", "the lake is ENCRYPTED with inlining at 10 rows",
     opts)

# ---------------------------------------------------------------- landing and sniffing
paths = {n: land(n) for n in ("customers.csv", "customers_v2.csv", "suppliers_cp1252.csv", "orders.xlsx", "events.ndjson",
                              "products.parquet")}
sn = {n: sniff(p, work_dir=cfg.temp_dir) for n, (p, _) in paths.items()}
c = sn["customers.csv"]
T.ok(c["delimiter"] == "," and len(c["columns"]) == 8, "CSV sniffed: delimiter ',' and 8 columns despite a malformed row",
     (c["delimiter"], len(c["columns"])))
types = {x["name"]: x["proposed_type"] for x in c["columns"]}
T.ok(types["customer_id"] == "VARCHAR" and types["card_number"] == "VARCHAR",
     "identifiers proposed as text (leading zeros; a 16-digit number)", types)
s = sn["suppliers_cp1252.csv"]
T.ok(s["encoding"] == "cp1252" and s["columns"][1]["sample"][0] == "Café Résumé Supplies",
     "Windows-1252 detected and decoded (é, the curly quote, €)", (s["encoding"], s["columns"][1]["sample"][:1]))
w = sn["orders.xlsx"]
summ = [x for x in w["sheets"] if x["name"] == "Summary"][0]
T.ok(len(w["sheets"]) == 2 and summ["header_rows"] == 2 and [x["name"] for x in summ["columns"]][1:3] == ["q1_units", "q1_revenue"],
     "workbook: two sheets; the merged two-row header proposed as q1_units, q1_revenue …", [x["name"] for x in summ["columns"]])
T.ok(sn["events.ndjson"]["rows_estimate"] == 3 and any("STRUCT" in x["proposed_type"] for x in sn["events.ndjson"]["columns"]),
     "NDJSON sniffed with its nested object")
T.ok(sn["products.parquet"]["rows_estimate"] == 3, "Parquet read from its own footer")

# ---------------------------------------------------------------- raw loads
p, sha = paths["customers.csv"]
r1 = load_raw(con, dataset_key="raw.customers", landing_path=p, landing_sha256=sha, load_id=101, author=AUTHOR,
              read_options=sn["customers.csv"]["read_options"], work_dir=cfg.temp_dir)
T.ok(r1["status"] == "loaded" and r1["rows_loaded"] == 7 and r1["rows_rejected"] == 1, "CSV: 7 rows loaded, 1 rejected",
     (r1["rows_loaded"], r1["rows_rejected"]))
rj = r1["rejects"][0] if r1["rejects"] else {}
T.ok(rj.get("line") == 5 and rj.get("error") == "TOO MANY COLUMNS" and "Bad Row" in (rj.get("raw") or ""),
     "the reject kept with its line, error and raw text (not lost)", rj)
cols = con.execute("SELECT column_name, data_type FROM duckdb_columns() WHERE database_name='lake' AND schema_name='raw' "
                   "AND table_name='customers' ORDER BY column_index").fetchall()
T.ok(all(t == "VARCHAR" for c_, t in cols if not c_.startswith("_dl_") or c_ in ("_dl_landing_sha256", "_dl_rescued")),
     "raw is all text")
T.ok([c_ for c_, _ in cols][-5:] == ["_dl_load_id", "_dl_row", "_dl_loaded_at", "_dl_landing_sha256", "_dl_rescued"],
     "every raw row carries its provenance columns")
T.ok(con.execute("SELECT count(*) FROM lake.raw.customers WHERE customer_id = '00042' AND _dl_landing_sha256 = ?", [sha]).fetchone()[0] == 2,
     "leading zeros kept ('00042'), the landing sha on the row")
p, sha = paths["suppliers_cp1252.csv"]
load_raw(con, dataset_key="raw.suppliers", landing_path=p, landing_sha256=sha, load_id=102, author=AUTHOR,
         read_options=sn["suppliers_cp1252.csv"]["read_options"], work_dir=cfg.temp_dir)
T.ok(con.execute("SELECT name, note FROM lake.raw.suppliers ORDER BY _dl_row").fetchall()
     == [("Café Résumé Supplies", "It’s fine"), ("Naïve Widgets", "€ price list")],
     "Windows-1252 text stored as the right characters")
p, sha = paths["orders.xlsx"]
r = load_raw(con, dataset_key="raw.orders", landing_path=p, landing_sha256=sha, load_id=103, author=AUTHOR,
             read_options={"format": "workbook", "sheet": "Orders"}, work_dir=cfg.temp_dir)
T.ok(r["rows_loaded"] == 4 and con.execute("SELECT list(_dl_row ORDER BY _dl_row) FROM lake.raw.orders").fetchone()[0] == [2, 3, 4, 5],
     "workbook sheet loaded; _dl_row is the sheet's own row number")
r = load_raw(con, dataset_key="raw.region_summary", landing_path=p, landing_sha256=sha + ":Summary", load_id=104, author=AUTHOR,
             read_options={"format": "workbook", "sheet": "Summary"}, work_dir=cfg.temp_dir)
T.ok(con.execute("SELECT q1_units, q2_revenue FROM lake.raw.region_summary WHERE region = 'North'").fetchone() == ("10", "1250"),
     "the merged-header sheet loads under its joined names")
p, sha = paths["events.ndjson"]
load_raw(con, dataset_key="raw.events", landing_path=p, landing_sha256=sha, load_id=105, author=AUTHOR,
         read_options={"format": "json"}, work_dir=cfg.temp_dir)
T.ok(con.execute("SELECT meta FROM lake.raw.events WHERE event_id = '2'").fetchone()[0] == '{"ip":"198.51.100.7","rows":120}'
     or '"198.51.100.7"' in con.execute("SELECT meta FROM lake.raw.events WHERE event_id = '2'").fetchone()[0],
     "NDJSON's nested object kept as JSON text")
p, sha = paths["products.parquet"]
load_raw(con, dataset_key="raw.products", landing_path=p, landing_sha256=sha, load_id=106, author=AUTHOR,
         read_options={"format": "parquet"}, work_dir=cfg.temp_dir)
T.ok(con.execute("SELECT list_price FROM lake.raw.products WHERE sku='SKU-002'").fetchone()[0] == "24.5", "Parquet loaded as text")
p, sha = paths["customers.csv"]
before = con.execute("SELECT count(*) FROM lake.raw.customers").fetchone()[0]
s_before = current_snapshot(con)
again = load_raw(con, dataset_key="raw.customers", landing_path=p, landing_sha256=sha, load_id=107, author=AUTHOR,
                 read_options=sn["customers.csv"]["read_options"], work_dir=cfg.temp_dir)
T.ok(again["status"] == "noop" and again["reason"] == "already_loaded"
     and con.execute("SELECT count(*) FROM lake.raw.customers").fetchone()[0] == before and current_snapshot(con) == s_before,
     "the same file again is a no-op that says so (no rows, no snapshot)")
raw_snap_1 = current_snapshot(con)
p2, sha2 = paths["customers_v2.csv"]
T.raises(lambda: load_raw(con, dataset_key="raw.customers", landing_path=p2, landing_sha256=sha2, load_id=108, author=AUTHOR,
                          evolution_mode="fail", read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir),
         LoadRefused, "evolution 'fail': a new column refuses the load", lambda e: e.reason == "schema_drift")
T.ok(con.execute("SELECT count(*) FROM lake.raw.customers").fetchone()[0] == before, "… and nothing was loaded")
rv2 = load_raw(con, dataset_key="raw.customers", landing_path=p2, landing_sha256=sha2, load_id=109, author=AUTHOR,
               evolution_mode="rescue", read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
T.ok(rv2["drift"]["rescued"] == ["loyalty_tier"] and rv2["rows_rescued"] == 2
     and con.execute("SELECT _dl_rescued FROM lake.raw.customers WHERE customer_id='00050'").fetchone()[0] == '{"loyalty_tier":"gold"}',
     "evolution 'rescue' (the default): the new field kept in _dl_rescued, reported as drift")
ra = load_raw(con, dataset_key="raw.customers_wide", landing_path=paths["customers.csv"][0], landing_sha256=paths["customers.csv"][1],
              load_id=110, author=AUTHOR, read_options=sn["customers.csv"]["read_options"], work_dir=cfg.temp_dir)
ra = load_raw(con, dataset_key="raw.customers_wide", landing_path=p2, landing_sha256=sha2, load_id=111, author=AUTHOR,
              evolution_mode="add", read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
T.ok(ra["drift"]["added"] == ["loyalty_tier"] and con.execute(
    "SELECT count(*) FROM lake.raw.customers_wide WHERE loyalty_tier IS NOT NULL").fetchone()[0] == 2,
     "evolution 'add': the new column added to raw, reported")
msgs = [m for _, a, m in snaps(con) if m and m.startswith("load ")]
T.ok(len(msgs) >= 8 and all(a == AUTHOR for _, a, m in snaps(con) if m and m.startswith("load ")),
     "every load is a DuckLake commit with its author and message", msgs[:3])

# ---------------------------------------------------------------- profile and the classifier
prof = profile(con, "raw.customers")
pc = prof["columns"]
T.ok(prof["row_count"] == 9 and pc["email"]["null_pct"] == 0, "profile: row count and null %", (prof["row_count"], pc["email"]["null_pct"]))
T.ok((pc["email"]["classifier"] or {}).get("kind") == "email" and (pc["card_number"]["classifier"] or {}).get("kind") == "payment_card"
     and (pc["phone"]["classifier"] or {}).get("kind") == "phone",
     "the classifier proposes email, payment_card (Luhn) and phone",
     {k: (v["classifier"] or {}).get("kind") for k, v in pc.items()})
T.ok(pc["name"]["classifier"] and pc["name"]["classifier"]["kind"] == "name" and pc["city"]["classifier"] is None,
     "a name column proposed by its name; a city is not personal")
tops = [x["value"] for x in pc["email"]["top"]]
T.ok(all(v.startswith(tuple("abcdefgh")) and "***@" in v for v in tops) and "ada.example" not in str(pc["email"]),
     "a personal column's top values, min and max are masked in the profile", tops[:3])
T.ok(pc["balance"]["masked"] if "masked" in pc["balance"] else pc["balance"]["top"][0]["value"] in ("0", "120.50", "130.00", "15", "abc", "9.99", "75.25", "44.00", "5.00"),
     "a column that is not personal keeps its values in the profile")

# ---------------------------------------------------------------- promotion
contract = {"columns": [
    {"name": "customer_id", "type": "VARCHAR", "nullable": False},
    {"name": "name", "type": "VARCHAR"},
    {"name": "email", "type": "VARCHAR", "nullable": False},
    {"name": "card_number", "type": "VARCHAR"},
    {"name": "city", "type": "VARCHAR"},
    {"name": "signed_up", "type": "DATE"},
    {"name": "balance", "type": "DECIMAL(12,2)"}], "key": ["customer_id"]}
row_checks = [{"kind": "regex", "column": "email", "pattern": r"[^@\s]+@[^@\s]+\.[a-z]+", "blocking": True},
              {"kind": "range", "column": "balance", "min": 0, "max": 100, "blocking": False, "name": "balance_under_100"}]
pr = promote(con, raw_key="raw.customers", curated_key="curated.customers", contract=contract, checks=row_checks, author=AUTHOR,
             since_snapshot=None)
T.ok(pr["rows_in"] == 9 and pr["rows_quarantined"] == 1, "promotion typed 9 raw rows; 1 quarantined", pr)
reason = con.execute("SELECT _dl_reason FROM lake.quarantine.customers").fetchone()[0]
T.ok("cast:signed_up" in reason and "cast:balance" in reason, "the quarantined row carries its reasons", reason)
T.ok(con.execute("SELECT email, balance FROM lake.curated.customers WHERE customer_id = '00042'").fetchall()
     == [("ada.new@example.invalid", __import__("decimal").Decimal("130.00"))],
     "deduplicated by key, latest wins (the second 00042 row)")
T.ok(con.execute("SELECT data_type FROM duckdb_columns() WHERE database_name='lake' AND schema_name='curated' AND "
                 "table_name='customers' AND column_name='signed_up'").fetchone()[0] == "DATE", "curated is typed by the contract")
T.ok(pr["warnings"].get("balance_under_100") == 2, "a non-blocking check counts its failures without quarantining",
     pr["warnings"])
cur_count = con.execute("SELECT count(*) FROM lake.curated.customers").fetchone()[0]
T.ok(cur_count == 7, "curated: 7 customers (9 raw − 1 duplicate − 1 quarantined)", cur_count)
q_count = con.execute("SELECT count(*) FROM lake.quarantine.customers").fetchone()[0]
pr2 = promote(con, raw_key="raw.customers", curated_key="curated.customers", contract=contract, checks=row_checks, author=AUTHOR,
              since_snapshot=None)
T.ok(con.execute("SELECT count(*) FROM lake.curated.customers").fetchone()[0] == cur_count
     and con.execute("SELECT count(*) FROM lake.quarantine.customers").fetchone()[0] == q_count,
     "promotion is idempotent: the same input twice changes nothing (no duplicate quarantine)")
promoted_at = pr["raw_snapshot"]
# a new raw load → incremental promotion reads only the change feed
open(os.path.join(cfg.temp_dir, "c3.csv"), "w").write(
    "customer_id,name,email,phone,card_number,city,signed_up,balance\n"
    + "".join(f"0{i:04d},Person {i},p{i}@example.invalid,+1-555-01{i % 100:02d},4111111111111111,Somewhere,2026-06-01,{i}\n"
              for i in range(200, 240)))
p3 = os.path.join(cfg.temp_dir, "c3.csv")
r3 = load_raw(con, dataset_key="raw.customers", landing_path=p3, landing_sha256=sha256_file(p3), load_id=112, author=AUTHOR,
              read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
pi = promote(con, raw_key="raw.customers", curated_key="curated.customers", contract=contract, checks=row_checks, author=AUTHOR,
             since_snapshot=promoted_at)
T.ok(pi["rows_in"] == 40 and con.execute("SELECT count(*) FROM lake.curated.customers").fetchone()[0] == cur_count + 40,
     "incremental promotion reads only the rows since the last promoted snapshot (the change feed)", pi["rows_in"])
pf = promote(con, raw_key="raw.customers", curated_key="curated.customers", contract=contract, checks=row_checks, author=AUTHOR,
             full=True)
T.ok(con.execute("SELECT count(*) FROM lake.curated.customers").fetchone()[0] == cur_count + 40 and pf["rows_in"] == 49,
     "a full rebuild from raw gives the same curated table")

# ---------------------------------------------------------------- checks (table level)
res = {x["check"]: x for x in run_checks(con, "curated.customers", [
    {"kind": "not_null", "column": "email"}, {"kind": "unique", "column": "customer_id"},
    {"kind": "row_count", "min": 10}, {"kind": "freshness", "max_age_hours": 1},
    {"kind": "range", "column": "balance", "max": 100, "name": "balance_cap"},
    {"kind": "referential", "column": "customer_id", "references": "raw.customers", "references_column": "customer_id"}],
    key_columns=["email"], personal={"email"}, salt_hex=SALT)}
T.ok(res["not_null:email"]["passed"] and res["unique:customer_id"]["passed"] and res["row_count"]["passed"] and res["freshness"]["passed"]
     and res["referential:customer_id"]["passed"], "table checks: not_null, unique, row_count, freshness, referential pass")
over = con.execute("SELECT count(*) FROM lake.curated.customers WHERE balance > 100").fetchone()[0]
T.ok(not res["balance_cap"]["passed"] and res["balance_cap"]["rows_failed"] == over and len(res["balance_cap"]["failing_keys"]) == 20
     and all(k.startswith("h:") for k in res["balance_cap"]["failing_keys"]),
     "a failing check lists at most 20 failing KEYS, hashed because the key is personal — never values", res["balance_cap"]["check"])

# ---------------------------------------------------------------- transforms
pol_owner = {"caller": 1, "on_behalf_of": None, "salt_hex": SALT,
             "limits": {"timeout_s": 60, "row_limit": 1000, "result_bytes": 1 << 20},
             "datasets": {"curated.customers": {"level": "read", "columns": ["customer_id", "email", "city", "balance"],
                                                "masks": {"email": "hash"}, "row_filter": None}}}
tr = transform(con, output_key="product.balance_by_city", name="balance_by_city", author=AUTHOR, policy=pol_owner,
               sql="SELECT city, count(*) AS customers, sum(balance) AS balance FROM curated.customers GROUP BY city")
T.ok(tr["status"] == "ran" and con.execute("SELECT customers FROM lake.product.balance_by_city WHERE city = 'Somewhere'").fetchone()[0] == 40,
     "a replace transform builds a product from a curated dataset")
tr = transform(con, output_key="product.customer_emails", name="customer_emails", author=AUTHOR, policy=pol_owner,
               sql="SELECT customer_id, email, city FROM curated.customers", mode="incremental_merge", key_columns=["customer_id"])
T.ok(con.execute("SELECT count(*) FROM lake.product.customer_emails WHERE email LIKE 'h:%'").fetchone()[0] == cur_count + 40,
     "a transform reads through the owner's views: the masked email arrives hashed — a product never widens what it exposes")
since = tr["inputs"]
open(os.path.join(cfg.temp_dir, "c4.csv"), "w").write(
    "customer_id,name,email,phone,card_number,city,signed_up,balance\n"
    + "".join(f"0{i:04d},Person {i},p{i}@example.invalid,+1-555-0100,4111111111111111,Elsewhere,2026-06-02,1\n" for i in range(300, 305)))
p4 = os.path.join(cfg.temp_dir, "c4.csv")
load_raw(con, dataset_key="raw.customers", landing_path=p4, landing_sha256=sha256_file(p4), load_id=113, author=AUTHOR,
         read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
promote(con, raw_key="raw.customers", curated_key="curated.customers", contract=contract, checks=row_checks, author=AUTHOR,
        since_snapshot=pf["raw_snapshot"])
tr2 = transform(con, output_key="product.customer_emails", name="customer_emails", version=1, author=AUTHOR, policy=pol_owner,
                sql="SELECT customer_id, email, city FROM curated.customers", mode="incremental_merge", key_columns=["customer_id"],
                since=since)
T.ok(tr2["mode"] == "incremental_merge" and tr2["rows_written"] == 5
     and con.execute("SELECT count(*) FROM lake.product.customer_emails").fetchone()[0] == cur_count + 45,
     "an incremental transform reads only the inputs' changes and MERGEs them by key", tr2)
T.raises(lambda: transform(con, output_key="product.bad", name="bad", author=AUTHOR, policy=pol_owner,
                           sql="SELECT * FROM read_csv('/etc/passwd')"),
         Exception, "a transform's SQL passes the same allow-list (read_csv refused)", lambda e: "read_csv" in str(e))

# ---------------------------------------------------------------- time travel and rollback
snap_before_v2 = None
for sid, _, m in snaps(con):
    if m == "load 109 into raw.customers":
        snap_before_v2 = sid - 1
T.ok(con.execute(f"SELECT count(*) FROM lake.raw.customers AT (VERSION => {snap_before_v2})").fetchone()[0] == 7,
     "time travel: raw as of the snapshot before load 109 has 7 rows")
n_cur = con.execute("SELECT count(*) FROM lake.curated.customers").fetchone()[0]
s_rb = current_snapshot(con)
rb = rollback_load(con, load_id=109, dataset_keys=["raw.customers", "curated.customers", "quarantine.customers"], author=AUTHOR)
T.ok(rb["removed"] == {"raw.customers": 2, "curated.customers": 2, "quarantine.customers": 0} and rb["snapshot_id"] > s_rb,
     "a load rolled back from raw and curated as a NEW snapshot", rb)
T.ok(con.execute(f"SELECT count(*) FROM lake.raw.customers AT (VERSION => {s_rb}) WHERE _dl_load_id = 109").fetchone()[0] == 2
     and con.execute("SELECT count(*) FROM lake.raw.customers WHERE _dl_load_id = 109").fetchone()[0] == 0,
     "history is not rewound: the rows are still there as of the snapshot before the rollback")

# ---------------------------------------------------------------- inlining
open(os.path.join(cfg.temp_dir, "tiny.csv"), "w").write("k,v\na,1\nb,2\nc,3\n")
pt = os.path.join(cfg.temp_dir, "tiny.csv")
load_raw(con, dataset_key="raw.tiny", landing_path=pt, landing_sha256=sha256_file(pt), load_id=114, author=AUTHOR,
         read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
info = {r[0]: r[1] for r in con.execute("SELECT table_name, file_count FROM ducklake_table_info('lake')").fetchall()}
with psycopg.connect(cfg.lake_dsn) as pg:
    inl = pg.execute("SELECT count(*) FROM information_schema.tables WHERE table_schema='ducklake' AND table_name LIKE 'ducklake_inlined_data_%'").fetchone()[0]
T.ok(info.get("tiny") == 0 and inl >= 1, "a 3-row load is INLINED: no Parquet file, the rows in the catalog", (info.get("tiny"), inl))

# ---------------------------------------------------------------- maintenance
for i in range(12):   # twelve small appends (> the inlining limit) → twelve small files
    open(os.path.join(cfg.temp_dir, f"s{i}.csv"), "w").write("k,v\n" + "".join(f"k{i}_{j},{j}\n" for j in range(20)))
    ps = os.path.join(cfg.temp_dir, f"s{i}.csv")
    load_raw(con, dataset_key="raw.smallfiles", landing_path=ps, landing_sha256=sha256_file(ps), load_id=200 + i, author=AUTHOR,
             read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
files_before = con.execute("SELECT file_count FROM ducklake_table_info('lake') WHERE table_name='smallfiles'").fetchone()[0]
junk = os.path.join(cfg.data_path, "raw", "smallfiles", "ducklake-00000000-junk-orphan.parquet")
open(junk, "wb").write(b"not a parquet file")
past = dt.datetime.now(dt.timezone.utc) - dt.timedelta(days=4)
os.utime(junk, (past.timestamp(), past.timestamp()))
rows_before = con.execute("SELECT count(*) FROM lake.raw.smallfiles").fetchone()[0]
m = mt.run(con, author=AUTHOR, snapshot_keep_days=0, cleanup_margin_days=0, target_file_size=128 * 1024 * 1024,
           now=dt.datetime.now(dt.timezone.utc) + dt.timedelta(seconds=2))
files_after = con.execute("SELECT file_count FROM ducklake_table_info('lake') WHERE table_name='smallfiles'").fetchone()[0]
T.ok(files_before == 12 and files_after == 1, "merge_adjacent_files: twelve small files → one", (files_before, files_after))
T.ok(con.execute("SELECT count(*) FROM lake.raw.smallfiles").fetchone()[0] == rows_before, "… with every row kept")
info = {r[0]: r[1] for r in con.execute("SELECT table_name, file_count FROM ducklake_table_info('lake')").fetchall()}
T.ok(info.get("tiny") == 1 and con.execute("SELECT count(*) FROM lake.raw.tiny").fetchone()[0] == 3,
     "flush_inlined_data: the inlined rows now in a Parquet file, unchanged")
T.ok(not os.path.exists(junk), "delete_orphaned_files removed a file the catalog never knew")
listed = {os.path.realpath(r[0]) for t in ("customers", "smallfiles") for r in
          con.execute(f"SELECT data_file FROM ducklake_list_files('lake', '{t}', schema => 'raw')").fetchall()}
on_disk = {os.path.realpath(os.path.join(dp, f)) for dp, _, fs in os.walk(os.path.join(cfg.data_path, "raw", "smallfiles")) for f in fs}
T.ok(on_disk <= listed, "expire + cleanup: no file of an expired snapshot remains on disk for the compacted table",
     sorted(on_disk - listed)[:3])
T.ok(set(m["steps"]) == {"flush_inlined", "merge_adjacent", "rewrite_deletes", "expire_snapshots", "cleanup_old_files", "delete_orphans"},
     "maintenance ran its six steps in order", m["steps"])

# ---------------------------------------------------------------- concurrency: a reader while the writer commits
qcon, qalias = query_connection(cfg)
n0 = qcon.execute(f'SELECT count(*) FROM {qalias}."raw"."smallfiles"').fetchone()[0]
open(os.path.join(cfg.temp_dir, "more.csv"), "w").write("k,v\n" + "".join(f"m{j},{j}\n" for j in range(50)))
pm = os.path.join(cfg.temp_dir, "more.csv")
load_raw(con, dataset_key="raw.smallfiles", landing_path=pm, landing_sha256=sha256_file(pm), load_id=300, author=AUTHOR,
         read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
n1 = qcon.execute(f'SELECT count(*) FROM {qalias}."raw"."smallfiles"').fetchone()[0]
T.ok(n1 == n0 + 50, "a READ_ONLY reader sees the writer's commit on its next statement", (n0, n1))
qcon.close()
errs = []


def writer_thread(i):
    try:
        c2 = writer_connection(cfg)
        p_ = os.path.join(cfg.temp_dir, f"cc{i}.csv")
        open(p_, "w").write("k,v\n" + "".join(f"t{i}_{j},{j}\n" for j in range(30)))
        load_raw(c2, dataset_key="raw.smallfiles", landing_path=p_, landing_sha256=sha256_file(p_), load_id=400 + i, author=AUTHOR,
                 read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir)
        c2.close()
    except Exception as e:  # noqa
        errs.append(f"{type(e).__name__}: {str(e)[:160]}")


ths = [threading.Thread(target=writer_thread, args=(i,)) for i in range(3)]
[t.start() for t in ths]
[t.join() for t in ths]
T.ok(not errs and con.execute("SELECT count(*) FROM lake.raw.smallfiles WHERE _dl_load_id BETWEEN 400 AND 402").fetchone()[0] == 90,
     "three writers appending to one table at once all commit (DuckLake retries non-conflicting commits)", errs[:2])

# ---------------------------------------------------------------- erasure (the encrypted lake)
subject = ["ada.example@example.invalid", "ada.new@example.invalid"]
pmap = {"raw.customers": {"email": "email"}, "curated.customers": {"email": "email"}, "quarantine.customers": {"email": "email"},
        "raw.events": {"member": "email"}, "raw.customers_wide": {"email": "email"}}
targets = er.scan(con, identifiers=[{"kind": "email", "value": v} for v in subject], personal_map=pmap,
                  follow=[{"from": "curated.customers", "key": "customer_id",
                           "to": [{"dataset": "product.customer_emails", "column": "customer_id"}]}])
found = {(t["dataset"], t["column"]): t["rows"] for t in targets}
T.ok(found.get(("raw.customers", "email")) == 2 and found.get(("curated.customers", "email")) == 1
     and found.get(("raw.events", "member")) == 1 and found.get(("product.customer_emails", "customer_id")) == 1
     and found.get(("raw.customers_wide", "email")) == 2,
     "the scan REPORTS every dataset holding the subject — by personal column and, through lineage, by key", found)
pre = er.verify(con, needles=subject + ["00042"], data_path=cfg.data_path, lake_dsn=cfg.lake_dsn)
T.ok(not pre["clean"] and pre["rows_found"], "before erasure the proof finds the subject (the proof can see it)")
T.ok(any(x["table"] == "ducklake_file_column_stats" for x in pre["inlined_rows_found"]),
     "… including in the catalog's column statistics — a file's min email IS the subject's address, in plain text",
     pre["inlined_rows_found"])
others_before = con.execute("SELECT count(*) FROM lake.curated.customers WHERE customer_id <> '00042'").fetchone()[0]
landing_of_subject = [paths["customers.csv"][0], paths["events.ndjson"][0]]
ers = er.erase(con, targets=targets, author=AUTHOR, request_id=1, lake_dsn=cfg.lake_dsn, landing_paths=landing_of_subject)
T.ok(ers["deleted"].get("raw.customers") == 2 and ers["deleted"].get("product.customer_emails") == 1 and ers["files_rewritten"] >= 1,
     "erasure deleted in every zone and rewrote the affected files", ers)
miss = er.verify(con, needles=subject + ["00042"], data_path=cfg.data_path, lake_dsn=cfg.lake_dsn, landing_paths=landing_of_subject)
T.ok([r["table"] for r in miss["rows_found"]] and {r["table"] for r in miss["rows_found"]} == {"raw.orders"}
     and miss["files_with_match"] and {f["file"].split("/")[1] for f in miss["files_with_match"]} == {"orders"},
     "the proof is independent of the scan: it catches the subject's ORDERS (keyed by customer_id) the scan was not told to follow",
     (miss["rows_found"][:2], miss["files_with_match"][:2]))
targets2 = er.scan(con, identifiers=[{"kind": "email", "value": v} for v in subject], personal_map={},
                   follow=[]) + [{"dataset": "raw.orders", "column": "customer_id", "kind": "key", "values": ["00042"],
                                  "rows": con.execute("SELECT count(*) FROM lake.raw.orders WHERE customer_id = '00042'").fetchone()[0]}]
er.erase(con, targets=targets2, author=AUTHOR, request_id=1, lake_dsn=cfg.lake_dsn, landing_paths=[paths["orders.xlsx"][0]])
landing_of_subject.append(paths["orders.xlsx"][0])
proof = er.verify(con, needles=subject + ["00042"], data_path=cfg.data_path, lake_dsn=cfg.lake_dsn, landing_paths=landing_of_subject)
T.ok(proof["rows_found"] == [], "no row of the subject at ANY remaining snapshot of any table", proof["rows_found"][:3])
T.ok(proof["files_with_match"] == [] and proof["files_checked"] > 0,
     f"every data file on disk ({proof['files_checked']}), decrypted with its key, holds no trace", proof["files_with_match"][:3])
T.ok(proof["files_not_in_catalog"] == [], "no file on disk outside the catalog (an old file would hold the old rows)",
     proof["files_not_in_catalog"][:3])
T.ok(proof["inlined_rows_found"] == [], "no inlined row and no column statistic (min/max) in the catalog holds the subject",
     proof["inlined_rows_found"])
T.ok(proof["landing_remaining"] == [] and proof["clean"], "the landing files of the affected loads are purged; the proof is clean")
T.ok(con.execute("SELECT count(*) FROM lake.curated.customers WHERE customer_id <> '00042'").fetchone()[0] == others_before,
     "everyone else is still there")

# ---------------------------------------------------------------- erasure, byte-level, on the plaintext twin
pcon = writer_connection(cfg, alias="plain", data_path=os.path.join(cfg.storage_dir, "lake_plain") + "/", encrypted=False,
                         metadata_schema="ducklake_plain")
pcon.execute("CALL ducklake_set_option('plain', 'parquet_compression', 'uncompressed')")
pcon.execute("CALL ducklake_set_option('plain', 'data_inlining_row_limit', 0)")
pp, psha = land("customers.csv")
shutil.copyfile(os.path.join(FIX, "customers.csv"), pp) if not os.path.exists(pp) else None
load_raw(pcon, dataset_key="raw.customers", landing_path=pp, landing_sha256=psha, load_id=1, author=AUTHOR,
         read_options=sn["customers.csv"]["read_options"], work_dir=cfg.temp_dir, alias="plain")
for i in range(3):
    p_ = os.path.join(cfg.temp_dir, f"pl{i}.csv")
    open(p_, "w").write("customer_id,name,email,phone,card_number,city,signed_up,balance\n"
                        + "".join(f"9{i}{j:03d},X {j},x{i}_{j}@example.invalid,+1-555-0100,4111111111111111,Q,2026-01-01,1\n" for j in range(30)))
    load_raw(pcon, dataset_key="raw.customers", landing_path=p_, landing_sha256=sha256_file(p_), load_id=10 + i, author=AUTHOR,
             read_options={"delim": ",", "header": True}, work_dir=cfg.temp_dir, alias="plain")


def plain_hits():
    hits = []
    for dp, _, fs in os.walk(os.path.join(cfg.storage_dir, "lake_plain")):
        for f in fs:
            b = open(os.path.join(dp, f), "rb").read()
            if b"ada.example@example.invalid" in b:
                hits.append(f)
    return hits


T.ok(len(plain_hits()) >= 1, "plaintext twin: the subject's bytes are findable in the files before erasure (the search works)")
pt_ = er.scan(pcon, identifiers=[{"kind": "email", "value": "ada.example@example.invalid"}],
              personal_map={"raw.customers": {"email": "email"}}, alias="plain")
er.erase(pcon, targets=pt_, author=AUTHOR, request_id=2, lake_dsn=cfg.lake_dsn, alias="plain")
T.ok(plain_hits() == [], "plaintext twin: after erasure NO file on disk contains the subject's bytes")
T.ok(pcon.execute("SELECT count(*) FROM plain.raw.customers").fetchone()[0] == 7 + 90 - 1,
     "plaintext twin: only the subject's row went")
pcon.close()

# ---------------------------------------------------------------- backup and the restore drill
queries = ["SELECT count(*), sum(balance) FROM lake.curated.customers",
           "SELECT city, customers FROM lake.product.balance_by_city ORDER BY city",
           "SELECT count(*) FROM lake.raw.smallfiles"]
live = [con.execute(q).fetchall() for q in queries]
bdir = os.path.join(cfg.storage_dir, "backup", "b1")
b = bk.backup(cfg, bdir, con=con)
T.ok(b["status"] == "done" and os.path.exists(os.path.join(bdir, "catalog.dump")) and b["files"] > 0,
     "backup: the catalog dumped FIRST, then the files, with a manifest of sha256s", b)
rd = bk.restore_drill(cfg, bdir, restore_dsn=os.environ["DL_RESTORE_DB_DSN"],
                      restore_data_dir=os.path.join(cfg.storage_dir, "restore"), queries=queries)
T.ok(rd["status"] == "restored" and rd["snapshot_id"] == b["snapshot_id"], "restore drill: the catalog restored at the dump's snapshot",
     (rd.get("snapshot_id"), b["snapshot_id"]))
T.ok(rd["answers"] == live, "restore drill: the fixed queries answer exactly as the live lake", (rd.get("answers"), live))
victim = next(os.path.join(dp, f) for dp, _, fs in os.walk(os.path.join(bdir, "lake")) for f in fs)
open(victim, "ab").write(b"x")
T.ok(bk.restore_drill(cfg, bdir, restore_dsn=os.environ["DL_RESTORE_DB_DSN"],
                      restore_data_dir=os.path.join(cfg.storage_dir, "restore2"), queries=queries)["status"] == "corrupt",
     "a backup whose file no longer matches its manifest is refused as corrupt")

con.close()
raise SystemExit(T.done())
