"""Slice 4's lake (tests/phase3/slice4/run.sh, after slice 3's seed and seed_slice4()'s catalog rows): two more REAL tables in the scratch
DuckLake, written by the writer's own library, the columns synced as a load would:

  curated.products   12 rows: product_id, name — internal; Marco owns it; Ann (external) held a grant that EXPIRED yesterday
  raw.crm_deals      300 rows: deal_id, buyer_email (personal email — set by the classifier's rule, not yet confirmed), contact (proposed phone
                     at 0.6 — a proposal only), amount, department_id (7 Finance / 8 Sales) — Marco owns it; no grant: a stub to insiders

Synthetic values only (example.invalid). Idempotent.
    services/.venv/bin/python tests/phase3/slice4/seed.py
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
    "curated.products": """SELECT 'P-' || lpad(i::VARCHAR, 3, '0') AS product_id, 'SMOKE product ' || i AS name FROM range(1, 13) t(i)""",
    "raw.crm_deals": """SELECT 'D-' || lpad(i::VARCHAR, 4, '0') AS deal_id, 'buyer' || i || '@example.invalid' AS buyer_email,
                               'call ' || (i % 7) AS contact, CAST(i * 10 + 0.5 AS DECIMAL(12,2)) AS amount,
                               CASE WHEN i % 2 = 0 THEN 8 ELSE 7 END::BIGINT AS department_id FROM range(1, 301) t(i)""",
}


def main() -> int:
    cfg = LakeConfig.from_env()
    pg = psycopg.connect(cfg.lake_dsn + " application_name=dl-slice4-seed", autocommit=True, row_factory=dict_row)
    con = writer_connection(cfg)
    made = []
    for key, select in TABLES.items():
        d = pg.execute("SELECT id FROM datasets WHERE key = %s", (key,)).fetchone()
        if d is None:
            print(f"seed: {key} has no catalog row — run seed_slice4() first", file=sys.stderr)
            return 1
        if not table_exists(con, key):
            zone, name = key.split(".", 1)
            with commit(con, "dl:m1", f"slice 4 seed {key}"):
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
