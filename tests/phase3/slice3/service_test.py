"""Slice 3 — the query service through its SOCKET (query-service-editor.md "Proof" 10; query-service.md §9): the request kinds against the
scratch catalog — `sql` with `dry_run` returns the datasets and the plan without running, `params` bound as literals, `saved_query` carries its
id into the audit, `cancel` (own ok, another's not_yours, unknown not_found); the forbidden forms each refused by its rule through the socket
(the service compiles the policy itself); the export stream — the writer's path: the same library on a READ-ONLY attach, the person's masks
in the Arrow batches. Run by tests/phase3/slice3/run.sh with the scratch environment; prints TAP-ish lines, exits non-zero on a failure."""
from __future__ import annotations

import json
import os
import socket
import sys
import threading
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "..", "services"))

import psycopg  # noqa: E402

from lake.engine import LakeConfig  # noqa: E402
from query import service  # noqa: E402

PREFIX = os.environ["DL_QUERY_SOCKET"]
passed = failed = 0
LONG = "SELECT sum(a.id * b.id) FROM curated.big_events a, curated.big_events b WHERE a.kind <> b.kind"


def ok(cond: bool, label: str, extra=None) -> None:
    global passed, failed
    passed += bool(cond)
    failed += not cond
    print(("  ok   " if cond else "  FAIL ") + label + ("" if cond or extra is None else f"   ({extra})"), flush=True)


def ask(req: dict, instance: int = 1, timeout: float = 60) -> dict:
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.settimeout(timeout)
    s.connect(f"{PREFIX}-{instance}.sock")
    s.sendall((json.dumps({"v": 1, **req}) + "\n").encode())
    buf = b""
    while not buf.endswith(b"\n"):
        chunk = s.recv(1 << 16)
        if not chunk:
            break
        buf += chunk
    s.close()
    return json.loads(buf or b"{}")


def pg_one(sql: str, args: tuple = ()):
    """As the application's role (it reads query_runs; the query service's role may not — it only writes its rows through the function)."""
    e = os.environ
    with psycopg.connect(host=e["DB_HOST"], port=e["DB_PORT"], dbname=e["DB_NAME"], user=e["DB_USER"], password=e["DB_PASSWORD"], autocommit=True) as c:
        return c.execute(sql, args).fetchone()


print("10. sql with dry_run: the datasets and the plan, nothing run")
a = ask({"kind": "sql", "caller_member_id": 30, "source": "screen", "sql": "SELECT region, count(*) FROM curated.sales_orders GROUP BY region", "dry_run": True})
ok(a.get("status") == "ok" and a.get("rows") == [] and a.get("datasets") == ["curated.sales_orders"] and "GROUP_BY" in (a.get("plan") or ""), "dry_run: ok, no rows, the datasets, the plan", a)
row = pg_one("SELECT status, rows_returned FROM query_runs WHERE id = %s", (a["query_run_id"],))
ok(row == ("ok", 0), "…audited ok, 0 rows")

print("   params bound as literals")
a = ask({"kind": "sql", "caller_member_id": 30, "source": "screen", "sql": "SELECT count(*) FROM curated.sales_orders WHERE region = $r", "params": {"r": "EMEA"}})
b = ask({"kind": "sql", "caller_member_id": 30, "source": "screen", "sql": "SELECT count(*) FROM curated.sales_orders WHERE region = $r", "params": {"r": "EMEA' OR '1'='1"}})
ok(a.get("rows") == [[375]] and b.get("rows") == [[0]], "a value is a literal: EMEA → 375; EMEA' OR '1'='1 → 0 (a string, never SQL)", (a.get("rows"), b.get("rows")))
c = ask({"kind": "sql", "caller_member_id": 30, "source": "screen", "sql": "SELECT count(*) FROM curated.sales_orders WHERE order_id > $n", "params": {"n": 1490}})
ok(c.get("rows") == [[10]], "a number binds as a number (order_id > 1490 → 10)")
ok(ask({"kind": "sql", "caller_member_id": 30, "sql": "SELECT 1", "params": {"x": {"a": 1}}}).get("refusal_rule") == "bad_request", "an object as a value: bad_request")

print("   saved_query: as sql, its id in the audit")
s = ask({"kind": "saved_query", "caller_member_id": 30, "source": "screen", "saved_query_id": 999999, "sql": "SELECT count(*) FROM product.monthly_revenue"})
ok(s.get("status") == "ok" and pg_one("SELECT saved_query_id FROM query_runs WHERE id = %s", (s["query_run_id"],))[0] == 999999, "the run carries saved_query_id (the service reads no saved_queries)")
ok(ask({"kind": "saved_query", "caller_member_id": 30, "sql": "SELECT 1"}).get("refusal_rule") == "bad_request", "a saved_query without its id: bad_request")

print("   cancel: own ok, another's not_yours, unknown not_found")
res: dict = {}
t = threading.Thread(target=lambda: res.update(ask({"kind": "sql", "caller_member_id": 30, "source": "screen", "sql": LONG, "request_id": "svc-long"}, 1)))
t.start()
time.sleep(1.2)
ok(ask({"kind": "cancel", "caller_member_id": 27, "request_id": "svc-long"}, 1).get("refusal_rule") == "not_yours", "another caller's cancel: not_yours")
ok(ask({"kind": "cancel", "caller_member_id": 30, "request_id": "svc-long"}, 2).get("refusal_rule") == "not_found", "the other instance is not running it: not_found")
t0 = time.monotonic()
ok(ask({"kind": "cancel", "caller_member_id": 30, "request_id": "svc-long"}, 1).get("cancelled") is True, "her own: cancelled")
t.join(10)
ok(res.get("status") == "cancelled" and time.monotonic() - t0 < 2.0, f"…the run ended cancelled in {time.monotonic() - t0:.2f} s", res.get("status"))
ok(ask({"kind": "cancel", "caller_member_id": 30}).get("refusal_rule") == "bad_request", "a cancel naming no request: bad_request")

print("   the forbidden forms through the socket (the service compiles the policy itself)")
forms = [
    ("SELECT * FROM read_csv('/etc/passwd')", "function"), ("SELECT * FROM read_parquet('/srv/x/*.parquet')", "function"), ("SELECT * FROM glob('/*')", "function"),
    ("SELECT * FROM query_table('curated.sales_orders')", "function"), ("SELECT * FROM duckdb_databases()", "function"),
    ("SELECT * FROM ducklake_list_files('lk', 'sales_orders', schema => 'curated')", "function"), ("SELECT getenv('HOME')", "function"),
    ("SELECT current_setting('allowed_directories')", "function"), ("SELECT * FROM curated.sales_orders, LATERAL (SELECT * FROM read_text('/etc/passwd'))", "function"),
    ("SET enable_external_access = true", "select_only"), ("PRAGMA database_list", "select_only"), ("ATTACH '/tmp/x.db' AS x", "select_only"),
    ("COPY (SELECT 1) TO '/tmp/x.csv'", "select_only"), ("INSTALL httpfs", "select_only"), ("LOAD httpfs", "select_only"),
    ("CALL ducklake_list_files('lk', 'sales_orders')", "select_only"), ("CREATE TABLE x AS SELECT 1", "select_only"), ("INSERT INTO curated.sales_orders VALUES (1)", "select_only"),
    ("DELETE FROM curated.sales_orders", "select_only"), ("DROP TABLE curated.sales_orders", "select_only"), ("DESCRIBE curated.sales_orders", "select_only"),
    ("SUMMARIZE curated.sales_orders", "select_only"), ("EXPORT DATABASE '/tmp/x'", "parse"), ("SELECT 1; SELECT 2", "one_statement"),
    ("SELECT * FROM lake.curated.sales_orders", "catalog_reference"), ("SELECT * FROM 'people.parquet'", "unknown_table"),
    ("SELECT * FROM information_schema.tables", "unknown_table"), ("SELECT * FROM dlv_curated__sales_orders", "unknown_table"),
    ("SELECT * FROM curated.sales_orders AT (VERSION => 1)", "time_travel"), ("SELECT * FROM curated.hr_reviews", "not_readable"),
    ("SELECT * FROM curated.sales_orders WHERE region = $city", "unbound_parameter"), ("SELECT " + " + ".join(["1"] * 3000), "too_complex"),
]
bad = [(sql[:50], r) for sql, r in forms if ask({"kind": "sql", "caller_member_id": 30, "source": "screen", "sql": sql}).get("refusal_rule") != r]
ok(not bad, f"{len(forms)} forbidden forms, each answered by its rule through the socket", bad)

print("   the export stream (the writer's path): the same library, READ-ONLY, the person's masks in the batches")
cfg = LakeConfig.from_env()
pol = pg_one("SELECT dl_query_policy(30, NULL, ARRAY['curated.sales_orders'], 'export', NULL)")[0]
pol = dict(pol)
lim = dict(pol["limits"])
lim["temp"] = f"{int(lim['temp_mb'])}MB"
pol["limits"] = lim
pol["salt_hex"] = open(os.environ["DL_MASK_SALT_FILE"]).read().strip()
prep = service.prepare(cfg, pol, "SELECT order_id, customer_email FROM curated.sales_orders ORDER BY order_id", dsn=cfg.lake_dsn)
ok(not isinstance(prep, dict) and prep.row_limit == 100000 and prep.timeout_s == 300, "an export's limits: 100,000 rows, 300 s", prep if isinstance(prep, dict) else None)
cur = prep.con.execute(prep.sql)
reader = cur.to_arrow_reader(500) if hasattr(cur, "to_arrow_reader") else cur.fetch_record_batch(500)
batches = list(reader)
emails = batches[0].column(1).to_pylist()
ok(len(batches) == 3 and sum(b.num_rows for b in batches) == 1500 and emails[0] == "b***@example.invalid", "1,500 rows in Arrow batches of 500, the email masked in them")
refused = 0
for stmt in ("COPY (SELECT 1) TO '/tmp/dl-slice3-escape.csv'", "SET enable_external_access = true", "SET allowed_directories = ['/']"):
    try:
        prep.con.execute(stmt)
    except Exception:
        refused += 1
ok(refused == 3 and not os.path.exists("/tmp/dl-slice3-escape.csv"), "the prepared connection is locked: no file written, no setting changed")
prep.close()

print(f"{'all ' + str(passed) + ' passed' if failed == 0 else str(failed) + ' FAILED (' + str(passed) + ' passed)'}")
sys.exit(1 if failed else 0)
