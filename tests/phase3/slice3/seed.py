"""Slice 3's lake (tests/phase3/slice3/run.sh, after slice 1's seed_catalog() made the catalog rows): REAL tables in the scratch DuckLake for
the catalog's datasets, written by the writer's own library (lake.engine + lake.jobs.frame — the columns synced and the numbers refreshed as a
load would), and the grants the slice's proofs read through:

  curated.sales_orders   1,500 orders: order_id, customer_email (personal email — masked below read_unmasked), region, margin (confidential),
                         department_id (7 Finance / 8 Sales), amount, ordered_on — everyone reads it (seed_lake's grant); Marco owns it
  curated.payroll        20 rows: member_name (personal name), salary (restricted) — the owner's; the Expert reads it (seed_lake); Sales may
                         DISCOVER it (a `discover` grant → discover_only for Priya, Dana, Lee)
  product.monthly_revenue 24 rows: month, region, revenue — everyone reads it
  curated.region_sales   400 rows: region, department_id, amount — Sales reads ONLY its department's rows (a grant with the row filter
                         `department_id = ANY({departments})`); Marco owns it
  curated.twin_a/_b      10 rows each, the same words — search ranks the one queried above the other (the usage rollup)
  curated.big_events     120,000 rows: id, kind, at — everyone reads it (the truncation at 1,000, the export's 100,000, a long cross join)

The catalog rows and grants are tests/phase3/slice3/lib.php's seed_slice3() (as postgres); this file makes the tables. Synthetic values only
(example.invalid). Idempotent: a table already in the lake is left as it is.
    services/.venv/bin/python tests/phase3/slice3/seed.py
"""
from __future__ import annotations

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "..", "services"))

import psycopg  # noqa: E402
from psycopg.rows import dict_row  # noqa: E402

from lake.engine import LakeConfig, commit, current_snapshot, table_exists, writer_connection  # noqa: E402
from lake.jobs import frame  # noqa: E402

TABLES = {
    "curated.sales_orders": """SELECT i::BIGINT AS order_id, 'buyer' || (i % 900) || '@example.invalid' AS customer_email,
                                      CASE i % 4 WHEN 0 THEN 'EMEA' WHEN 1 THEN 'AMER' WHEN 2 THEN 'APAC' ELSE 'LATAM' END AS region,
                                      CAST((i % 400) / 10.0 + 0.1 AS DECIMAL(12,2)) AS margin, i::BIGINT AS _dl_load_id,
                                      CASE WHEN i % 2 = 0 THEN 8 ELSE 7 END::BIGINT AS department_id,
                                      CAST(i % 1000 + 0.5 AS DECIMAL(12,2)) AS amount, DATE '2026-01-01' + CAST(i % 270 AS INTEGER) AS ordered_on
                                 FROM range(1, 1501) t(i)""",
    "curated.payroll": """SELECT 'Person ' || i AS member_name, CAST(40000 + i * 1000 AS DECIMAL(12,2)) AS salary FROM range(1, 21) t(i)""",
    "product.monthly_revenue": """SELECT DATE '2025-01-01' + INTERVAL (i // 2) MONTH AS month, CASE WHEN i % 2 = 0 THEN 'EMEA' ELSE 'AMER' END AS region,
                                         CAST(10000 + i * 250 AS DECIMAL(14,2)) AS revenue FROM range(0, 24) t(i)""",
    "curated.region_sales": """SELECT CASE i % 3 WHEN 0 THEN 'North' WHEN 1 THEN 'South' ELSE 'East' END AS region,
                                      CASE WHEN i % 2 = 0 THEN 8 ELSE 7 END::BIGINT AS department_id, CAST(i AS DECIMAL(12,2)) AS amount
                                 FROM range(1, 401) t(i)""",
    "curated.twin_a": """SELECT i::BIGINT AS id, 'event' AS what FROM range(1, 11) t(i)""",
    "curated.twin_b": """SELECT i::BIGINT AS id, 'event' AS what FROM range(1, 11) t(i)""",
    "curated.big_events": """SELECT i::BIGINT AS id, CASE i % 5 WHEN 0 THEN 'view' WHEN 1 THEN 'click' WHEN 2 THEN 'buy' WHEN 3 THEN 'share' ELSE 'save' END AS kind,
                                    TIMESTAMP '2026-01-01 00:00:00' + INTERVAL (i) SECOND AS at FROM range(1, 120001) t(i)""",
}

def main() -> int:
    cfg = LakeConfig.from_env()
    pg = psycopg.connect(cfg.lake_dsn + " application_name=dl-slice3-seed", autocommit=True, row_factory=dict_row)
    con = writer_connection(cfg)
    made = []
    for key, select in TABLES.items():
        d = pg.execute("SELECT id FROM datasets WHERE key = %s", (key,)).fetchone()
        if d is None:
            print(f"seed: {key} has no catalog row — run seed_catalog() first", file=sys.stderr)
            return 1
        if not table_exists(con, key):
            zone, name = key.split(".", 1)
            with commit(con, "dl:m1", f"slice 3 seed {key}"):
                con.execute(f'CREATE TABLE lake."{zone}"."{name}" AS {select}')
            made.append(key)
        snap = current_snapshot(con)
        frame.sync_columns(con, pg, int(d["id"]), key, snap)
        frame.refresh_dataset(con, pg, int(d["id"]), key, snap)
    con.close()
    pg.close()
    print(f"seed: {len(made)} table(s) made: {', '.join(made) or 'none'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
