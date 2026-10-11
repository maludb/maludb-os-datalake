"""Slice 7's lake (tests/phase3/slice7/run.sh, after lib.php's seed_slice7() made the catalog rows): REAL tables in the scratch DuckLake, written by the
writer's own library (lake.engine + lake.jobs.frame — the columns synced and the numbers refreshed as a load would), and — with --append — more rows
landing in curated.s7_orders (a new snapshot, the numbers refreshed: what an input "changing" is, for the after_inputs proof).

  curated.s7_orders      1,680 orders over the LAST 14 MONTHS (relative to today, so the metric's chart has its twelve points): order_id, region (4), product_line (3),
                         amount, ordered_on (the first of each month) — Marco owns it, everyone reads it
  curated.s7_customers   40 customers: customer_id, email (a personal email — masked below read_unmasked), city, region — the Lake admin owns it, everyone reads it
                         (so Marco, an engineer who does not own it, reads the email MASKED: the product he builds from it must arrive masked)
  curated.s7_secret      20 rows: secret_id, note — the Lake admin's alone (nobody else discovers it: a stub to an insider)
  curated.s7_incr        the output of the incremental proof — made by the transform, not here

Synthetic values only (example.invalid). Idempotent: a table already in the lake is left as it is.
    services/.venv/bin/python tests/phase3/slice7/seed.py            the tables
    services/.venv/bin/python tests/phase3/slice7/seed.py --append N  N more orders (order_id above the highest), ordered_on this month
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

REGIONS = "CASE (i // 14) % 4 WHEN 0 THEN 'EMEA' WHEN 1 THEN 'AMER' WHEN 2 THEN 'APAC' ELSE 'LATAM' END"
LINES = "CASE i % 3 WHEN 0 THEN 'hardware' WHEN 1 THEN 'software' ELSE 'services' END"
TABLES = {
    "curated.s7_orders": f"""SELECT i::BIGINT AS order_id, {REGIONS} AS region, {LINES} AS product_line,
                                    CAST((i % 50) * 10 + 5 AS DECIMAL(12,2)) AS amount,
                                    CAST(date_trunc('month', current_date) - INTERVAL (i % 14) MONTH AS DATE) AS ordered_on
                               FROM range(1, 1681) t(i)""",
    "curated.s7_customers": """SELECT i::BIGINT AS customer_id, 'customer' || i || '@example.invalid' AS email,
                                      CASE i % 3 WHEN 0 THEN 'Springfield' WHEN 1 THEN 'Shelbyville' ELSE 'Ogdenville' END AS city,
                                      CASE i % 2 WHEN 0 THEN 'EMEA' ELSE 'AMER' END AS region FROM range(1, 41) t(i)""",
    "curated.s7_secret": """SELECT i::BIGINT AS secret_id, 'a note ' || i AS note FROM range(1, 21) t(i)""",
}


def main(argv: list[str]) -> int:
    cfg = LakeConfig.from_env()
    pg = psycopg.connect(cfg.lake_dsn + " application_name=dl-slice7-seed", autocommit=True, row_factory=dict_row)
    con = writer_connection(cfg)
    if len(argv) >= 2 and argv[0] == "--append":
        n = int(argv[1])
        key = "curated.s7_orders"
        d = pg.execute("SELECT id FROM datasets WHERE key = %s", (key,)).fetchone()
        top = con.execute("SELECT max(order_id) FROM lake.curated.s7_orders").fetchone()[0]
        with commit(con, "dl:m27", "slice 7 append"):
            con.execute(f"""INSERT INTO lake.curated.s7_orders SELECT {top} + i, {REGIONS.replace('i //', '(i + 3) //')}, {LINES}, CAST(100 + i AS DECIMAL(12,2)),
                                   CAST(date_trunc('month', current_date) AS DATE) FROM range(1, {n + 1}) t(i)""")
        snap = current_snapshot(con)
        frame.sync_columns(con, pg, int(d["id"]), key, snap)
        frame.refresh_dataset(con, pg, int(d["id"]), key, snap)
        print(f"seed: {n} order(s) appended at snapshot {snap}")
        return 0
    made = []
    for key, select in TABLES.items():
        d = pg.execute("SELECT id FROM datasets WHERE key = %s", (key,)).fetchone()
        if d is None:
            print(f"seed: {key} has no catalog row — run seed_slice7() first", file=sys.stderr)
            return 1
        if not table_exists(con, key):
            zone, name = key.split(".", 1)
            with commit(con, "dl:m1", f"slice 7 seed {key}"):
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
    sys.exit(main(sys.argv[1:]))
