"""The query service's proof — the one door (design §6.5, D6, D7): the allow-list refusing each forbidden form by its rule,
the caller's views (columns, masks, row filters, as-of), the lock, the watchdog, the memory cap, the limits, the policy
refused when it cannot be honoured exactly, the query role unable to write, and the socket prototype."""
from __future__ import annotations

import datetime as dt
import os
import threading
import time

from tap import Tap

import duckdb
import psycopg

from lake.engine import LakeConfig, current_snapshot, query_connection, writer_connection
from lake.load import load_raw
from lake.promote import promote
from lake.sniff import sha256_file
from query import allowlist
from query.policy import create_views
from query.server import ask, serve
from query.service import run

T = Tap("query")
cfg = LakeConfig.from_env()
SALT = "a1b2c3d4e5f60718293a4b5c6d7e8f90"
AUTHOR = "dl:member:1"

# ---------------------------------------------------------------- a small lake to query
w = writer_connection(cfg)
tmp = cfg.temp_dir
with open(os.path.join(tmp, "people.csv"), "w") as f:
    f.write("customer_id,name,email,card_number,city,birth_date,department_id,balance\n")
    for i in range(1, 31):
        f.write(f"{i:05d},Person {i},person{i}@example.invalid,4111111111111111,{'North' if i % 2 else 'South'},"
                f"19{60 + i % 30}-0{1 + i % 9}-1{i % 9},{1 + i % 3},{i * 10}\n")
p = os.path.join(tmp, "people.csv")
load_raw(w, dataset_key="raw.people", landing_path=p, landing_sha256=sha256_file(p), load_id=1, author=AUTHOR,
         read_options={"delim": ",", "header": True}, work_dir=tmp)
contract = {"columns": [{"name": "customer_id", "type": "VARCHAR"}, {"name": "name", "type": "VARCHAR"},
                        {"name": "email", "type": "VARCHAR"}, {"name": "card_number", "type": "VARCHAR"},
                        {"name": "city", "type": "VARCHAR"}, {"name": "birth_date", "type": "DATE"},
                        {"name": "department_id", "type": "INTEGER"}, {"name": "balance", "type": "DECIMAL(12,2)"}],
            "key": ["customer_id"]}
promote(w, raw_key="raw.people", curated_key="curated.people", contract=contract, checks=[], author=AUTHOR)
snap_30 = current_snapshot(w)
with open(os.path.join(tmp, "people2.csv"), "w") as f:
    f.write("customer_id,name,email,card_number,city,birth_date,department_id,balance\n")
    for i in range(31, 41):
        f.write(f"{i:05d},Person {i},person{i}@example.invalid,5555555555554444,North,1980-01-01,1,5\n")
p2 = os.path.join(tmp, "people2.csv")
load_raw(w, dataset_key="raw.people", landing_path=p2, landing_sha256=sha256_file(p2), load_id=2, author=AUTHOR,
         read_options={"delim": ",", "header": True}, work_dir=tmp)
promote(w, raw_key="raw.people", curated_key="curated.people", contract=contract, checks=[], author=AUTHOR, full=True)
w.execute("CREATE TABLE lake.curated.big AS SELECT i AS x, 'payload-' || i || '-abcdefghijklmnopqrstuvwxyz' AS payload "
          "FROM range(200000) t(i)")
w.execute("CREATE TABLE lake.curated.small AS SELECT i AS y, 'p' || i AS payload FROM range(200) t(i)")
w.execute("CREATE TABLE lake.curated.orders AS SELECT '000' || lpad((1 + i % 40)::VARCHAR, 2, '0') AS customer_id, i AS amount "
          "FROM range(1, 101) t(i)")
w.close()

LIM = {"timeout_s": 10, "row_limit": 1000, "result_bytes": 5_000_000, "memory": "512MB", "threads": 2}


def pol(datasets: dict, **lim) -> dict:
    return {"caller": 7, "on_behalf_of": None, "salt_hex": SALT, "limits": {**LIM, **lim}, "datasets": datasets}


PEOPLE_ALL = ["customer_id", "name", "email", "card_number", "city", "birth_date", "department_id", "balance"]
masked_people = {"level": "read", "columns": ["customer_id", "name", "email", "card_number", "city", "birth_date", "department_id",
                                              "balance"],
                 "masks": {"email": "partial_email", "card_number": "hash", "name": "partial", "birth_date": "year_only"},
                 "row_filter": None}
base = pol({"curated.people": masked_people,
            "curated.orders": {"level": "read", "columns": ["customer_id", "amount"], "masks": {"customer_id": "hash"},
                               "row_filter": None},
            "curated.big": {"level": "read", "columns": ["x", "payload"], "masks": {}, "row_filter": None},
            "curated.small": {"level": "read", "columns": ["y", "payload"], "masks": {}, "row_filter": None}})

# ---------------------------------------------------------------- layer 1: the allow-list, every forbidden form
forbidden = [
    ("SELECT * FROM read_csv('/etc/passwd')", "function"),
    ("SELECT * FROM read_parquet('/srv/x/*.parquet')", "function"),
    ("SELECT * FROM glob('/*')", "function"),
    ("SELECT * FROM query_table('curated.people')", "function"),
    ("SELECT * FROM duckdb_databases()", "function"),
    ("SELECT * FROM ducklake_list_files('lk', 'people', schema => 'curated')", "function"),
    ("SELECT getenv('HOME')", "function"),
    ("SELECT current_setting('allowed_directories')", "function"),
    ("SELECT * FROM curated.people, LATERAL (SELECT * FROM read_text('/etc/passwd'))", "function"),
    ("SET enable_external_access = true", "select_only"),
    ("PRAGMA database_list", "select_only"),
    ("ATTACH '/tmp/x.db' AS x", "select_only"),
    ("COPY (SELECT 1) TO '/tmp/x.csv'", "select_only"),
    ("INSTALL httpfs", "select_only"),
    ("LOAD httpfs", "select_only"),
    ("CALL ducklake_list_files('lk', 'people')", "select_only"),
    ("CREATE TABLE x AS SELECT 1", "select_only"),
    ("INSERT INTO curated.people VALUES (1)", "select_only"),
    ("DELETE FROM curated.people", "select_only"),
    ("DROP TABLE curated.people", "select_only"),
    ("DESCRIBE curated.people", "select_only"),
    ("SUMMARIZE curated.people", "select_only"),
    ("EXPORT DATABASE '/tmp/x'", "parse"),
    ("SELECT 1; SELECT 2", "one_statement"),
    ("SELECT * FROM lake.curated.people", "catalog_reference"),
    ("SELECT * FROM 'people.parquet'", "unknown_table"),
    ("SELECT * FROM information_schema.tables", "unknown_table"),
    ("SELECT * FROM dlv_curated__people", "unknown_table"),
    ("SELECT * FROM curated.people AT (VERSION => 1)", "time_travel"),
    ("SELECT * FROM raw.people", "not_granted"),
    ("SELECT * FROM curated.people WHERE city = $city", "unbound_parameter"),
    ("SELECT " + " + ".join(["1"] * 3000), "too_complex"),
]
for sql, rule in forbidden:
    r = run(cfg, base, sql)
    T.ok(r["status"] == "refused" and r["refusal_rule"] == rule, f"refused [{rule}]: {sql[:60]}", (r["status"], r["refusal_rule"], r["message"]))
r = run(cfg, base, "SELECT * FROM raw.people")
T.ok(r["datasets"] == ["raw.people"] and "request access" in r["message"], "a 'not granted' refusal names the dataset for Request access")

# ---------------------------------------------------------------- layer 2: the caller's views
r = run(cfg, base, "SELECT customer_id, name, email, card_number, birth_date FROM curated.people ORDER BY customer_id LIMIT 2")
row = r["rows"][0]
T.ok(r["status"] == "ok" and row[2] == "p***@example.invalid" and row[1] == "P******1" and row[3].startswith("h:")
     and row[4] == "1961-01-01", "masks applied: partial_email, partial, hash, year_only", row)
T.ok(set(r["masked_columns"]) == {"curated.people.email", "curated.people.card_number", "curated.people.name",
                                  "curated.people.birth_date"}, "the answer names every masked column", r["masked_columns"])
j = run(cfg, base, "SELECT count(*) FROM curated.orders o JOIN (SELECT customer_id FROM curated.orders) x USING (customer_id)")
T.ok(j["status"] == "ok" and j["rows"][0][0] > 100, "a hashed key is stable, so joins on it still work")
h1 = run(cfg, base, "SELECT card_number FROM curated.people WHERE customer_id = '00001'")["rows"][0][0]
h2 = run(cfg, base, "SELECT card_number FROM curated.people WHERE customer_id = '00003'")["rows"][0][0]
T.ok(h1 == h2 and h1.startswith("h:"), "the same value hashes the same across requests (keyed by the installation salt)")
r = run(cfg, base, "SELECT phone FROM curated.people")
T.ok(r["status"] == "error" and r["refusal_rule"] == "engine", "a column outside the policy does not exist for the caller")
hidden = pol({"curated.people": {"level": "read", "columns": ["customer_id", "city"], "masks": {}, "row_filter": None}})
r = run(cfg, hidden, "SELECT * FROM curated.people LIMIT 1")
T.ok([c["name"] for c in r["columns"]] == ["customer_id", "city"], "SELECT * shows only the policy's columns", r["columns"])
filt = pol({"curated.people": {**masked_people, "row_filter": "department_id IN (1, 2) AND city = 'North'"}})
r = run(cfg, filt, "SELECT count(*), min(department_id), max(department_id), min(city), max(city) FROM curated.people")
T.ok(r["status"] == "ok" and r["rows"][0][1] >= 1 and r["rows"][0][2] <= 2 and r["rows"][0][3] == r["rows"][0][4] == "North",
     "the row filter applies to every query, aggregates included", r["rows"])
unm = pol({"curated.people": {"level": "read_unmasked", "columns": PEOPLE_ALL, "masks": {}, "row_filter": None}})
r = run(cfg, unm, "SELECT email FROM curated.people WHERE customer_id = '00001'")
T.ok(r["rows"] == [["person1@example.invalid"]] and r["masked_columns"] == [], "read_unmasked reads values as stored")
r_now = run(cfg, base, "SELECT count(*) FROM curated.people")
r_then = run(cfg, base, "SELECT count(*) FROM curated.people", as_of=snap_30)
T.ok(r_now["rows"] == [[40]] and r_then["rows"] == [[30]], "as_of reads the dataset at an earlier snapshot (time travel, by parameter)",
     (r_now["rows"], r_then))
r = run(cfg, base, "SELECT count(*) FROM curated.big", as_of=snap_30)
T.ok(r["status"] == "refused" and r["refusal_rule"] == "absent_as_of", "a dataset that did not exist at the as-of snapshot is "
     "refused by name, the others still answer", r["message"])
r = run(cfg, base, "SELECT count(*) FROM curated.people WHERE city = $city", params={"city": "South"})
T.ok(r["status"] == "ok" and r["rows"] == [[15]], "a named parameter is bound as a literal", r)
r = run(cfg, base, "SELECT p.city, count(*) FROM curated.people p GROUP BY 1 ORDER BY 1")
T.ok(r["status"] == "ok" and len(r["rows"]) == 2, "aliases and GROUP BY survive the rewrite")
r = run(cfg, base, "WITH n AS (SELECT * FROM curated.people WHERE city = 'North') SELECT count(*) FROM n")
T.ok(r["status"] == "ok" and r["rows"] == [[25]], "a CTE over a dataset is allowed", r["rows"])

# ---------------------------------------------------------------- the policy is refused when it cannot be honoured
bad_policies = [
    ("an unknown column", {"curated.people": {"level": "read", "columns": ["nope"], "masks": {}, "row_filter": None}}),
    ("a row filter with a subquery", {"curated.people": {**masked_people, "row_filter": "city IN (SELECT 'x')"}}),
    ("a row filter with a function", {"curated.people": {**masked_people, "row_filter": "getenv('HOME') = city"}}),
    ("a row filter naming another table", {"curated.people": {**masked_people, "row_filter": "o.city = 'x'"}}),
    ("masks under read_unmasked", {"curated.people": {**masked_people, "level": "read_unmasked"}}),
    ("an unknown mask", {"curated.people": {**masked_people, "masks": {"email": "rot13"}}}),
]
for label, ds in bad_policies:
    r = run(cfg, pol(ds), "SELECT count(*) FROM curated.people")
    T.ok(r["status"] == "refused" and r["refusal_rule"] == "policy", f"policy refused — fail closed: {label}", r["message"])
r = run(cfg, {**base, "salt_hex": "short"}, "SELECT 1 FROM curated.people")
T.ok(r["status"] == "refused" and r["refusal_rule"] == "policy", "a policy without a proper salt is refused")

# ---------------------------------------------------------------- the salt never leaves
outs = []
for q in ["SELECT * FROM curated.people LIMIT 3", "SELECT card_number::INT FROM curated.people",
          "SELECT sql FROM duckdb_views()", "SELECT email FROM curated.people WHERE email = 1"]:
    outs.append(repr(run(cfg, base, q)))
T.ok(not any(SALT in o for o in outs), "the masking salt appears in no answer and no error")

# ---------------------------------------------------------------- layer 3: the locked connection itself
con, alias = query_connection(cfg)
made = create_views(con, alias, base)
con.execute(f"SET allowed_directories = ['{cfg.data_path}']")
con.execute("SET enable_external_access = false")
con.execute("SET lock_configuration = true")
try:
    con.execute("SELECT * FROM read_csv('/etc/passwd')").fetchall()
    T.ok(False, "locked: read_csv('/etc/passwd') refused by the engine even if the allow-list failed")
except duckdb.Error as e:
    T.ok("Permission" in type(e).__name__ or "disabled" in str(e), "locked: read_csv('/etc/passwd') refused by the engine even if the allow-list failed", str(e)[:120])
for stmt in ("SET enable_external_access = true", "SET memory_limit = '50GB'", "SET allowed_directories = ['/']"):
    try:
        con.execute(stmt)
        T.ok(False, f"locked: {stmt} refused")
    except duckdb.Error as e:
        T.ok("locked" in str(e), f"locked: {stmt} refused", str(e)[:100])
try:
    con.execute("ATTACH '/tmp/dl_engine_attack.duckdb' AS a")
    T.ok(False, "locked: attaching another database refused")
except duckdb.Error as e:
    T.ok(True, "locked: attaching another database refused")
lake_file = next(os.path.join(dp, f) for dp, _, fs in os.walk(os.path.join(cfg.data_path, "curated", "people")) for f in fs)
try:
    con.execute("SELECT * FROM read_parquet(?)", [lake_file]).fetchall()
    T.ok(False, "locked: a lake file read directly yields nothing — it is encrypted and the key is in the catalog")
except duckdb.Error as e:
    T.ok("encrypt" in str(e).lower(), "locked: a lake file read directly yields nothing — it is encrypted and the key is in the catalog",
         str(e)[:120])
T.ok(alias.startswith("lk_") and len(alias) == 15 and con.execute(f'SELECT count(*) FROM {alias}."curated"."people"').fetchone()[0] == 40,
     "documented: the locked connection still reaches the attached lake by its alias — the alias is random per request and "
     "layer 1 refuses every catalog-qualified name (catalog_reference)")
con.close()

# ---------------------------------------------------------------- the watchdog, memory, limits
t0 = time.monotonic()
r = run(cfg, pol(base["datasets"], timeout_s=1),
        "SELECT count(*) FROM curated.big a, curated.big b WHERE a.x + b.x = -1")
el = time.monotonic() - t0
T.ok(r["status"] == "timeout" and el < 4, f"the watchdog interrupts a runaway query at its timeout (1 s; stopped after {el:.2f} s)", r)
r = run(cfg, pol(base["datasets"], memory="100MB"),
        "SELECT len(list(a.payload || b.payload)) FROM curated.big a, curated.small b")
T.ok(r["status"] == "error" and r["refusal_rule"] == "memory", "a query over its memory cap fails cleanly (Out of Memory)", r["message"])
r = run(cfg, base, "SELECT count(*) FROM curated.people")
T.ok(r["status"] == "ok", "… and the next request runs normally")
r = run(cfg, pol(base["datasets"], row_limit=5), "SELECT x FROM curated.big")
T.ok(r["status"] == "ok" and len(r["rows"]) == 5 and r["truncated"], "row_limit: 5 rows shown and truncated flagged")
r = run(cfg, pol(base["datasets"], result_bytes=2000), "SELECT payload FROM curated.big LIMIT 500")
T.ok(r["status"] == "ok" and r["truncated"] and len(str(r["rows"])) < 2200, "result_bytes: the answer cut at its byte cap", len(str(r["rows"])))

# ---------------------------------------------------------------- the query role cannot write
con, alias = query_connection(cfg)
try:
    con.execute(f'INSERT INTO {alias}."curated"."people" (customer_id) VALUES (\'x\')')
    T.ok(False, "the query connection is READ_ONLY")
except duckdb.Error as e:
    T.ok("read-only" in str(e), "the query connection is READ_ONLY", str(e)[:100])
con.close()
with psycopg.connect(os.environ["DL_QUERY_DB_DSN"], autocommit=True) as pg:
    try:
        pg.execute("INSERT INTO ducklake.ducklake_snapshot (snapshot_id) VALUES (999999)")
        T.ok(False, "the query role cannot write the catalog in PostgreSQL")
    except psycopg.errors.InsufficientPrivilege:
        T.ok(True, "the query role cannot write the catalog in PostgreSQL")
    try:
        pg.execute("CREATE TABLE ducklake.x (i int)")
        T.ok(False, "the query role cannot create in the catalog schema")
    except psycopg.errors.InsufficientPrivilege:
        T.ok(True, "the query role cannot create in the catalog schema")
    keys = pg.execute("SELECT count(*) FROM ducklake.ducklake_data_file WHERE encryption_key IS NOT NULL").fetchone()[0]
    T.ok(keys > 0, "documented: the query role reads the files' keys (it must decrypt) — so only the query service ever holds "
                   "that role; the web application's role must never SELECT ducklake.*")

# ---------------------------------------------------------------- the socket prototype
sock = os.path.join(cfg.storage_dir, "tmp", "q.sock")
srv = serve(sock, workers=2, cfg=cfg)
th = threading.Thread(target=srv.serve_forever, daemon=True)
th.start()
a = ask(sock, {"policy": base, "sql": "SELECT count(*) FROM curated.people"})
T.ok(a["status"] == "ok" and a["rows"] == [[40]], "the socket answers a request")
results = []
ths = [threading.Thread(target=lambda: results.append(ask(sock, {"policy": base, "sql": "SELECT count(*) FROM curated.big"})))
       for _ in range(4)]
[t.start() for t in ths]
[t.join() for t in ths]
T.ok(len(results) == 4 and all(x["status"] == "ok" and x["rows"] == [[200000]] for x in results),
     "four concurrent requests through the socket, bounded by the worker slots, all answer")
a = ask(sock, {"policy": base, "sql": "SELECT * FROM read_csv('/etc/passwd')"})
T.ok(a["status"] == "refused" and a["refusal_rule"] == "function", "the socket refuses as the library does")
srv.shutdown()
srv.server_close()
os.unlink(sock) if os.path.exists(sock) else None
raise SystemExit(T.done())
