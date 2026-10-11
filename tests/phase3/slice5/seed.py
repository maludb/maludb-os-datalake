"""Slice 5's own table (tests/phase3/slice5/run.sh, after seed_slice5() registered it): curated.statuses — every status an order may have, the
referential check's target — written by the writer's own library as a load would (the columns synced, the numbers refreshed). Idempotent.
    services/.venv/bin/python tests/phase3/slice5/seed.py
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


def main() -> int:
    cfg = LakeConfig.from_env()
    pg = psycopg.connect(cfg.lake_dsn + " application_name=dl-slice5-seed", autocommit=True, row_factory=dict_row)
    con = writer_connection(cfg)
    key = "curated.statuses"
    d = pg.execute("SELECT id FROM datasets WHERE key = %s", (key,)).fetchone()
    if d is None:
        print("seed: curated.statuses has no catalog row", file=sys.stderr)
        return 1
    if not table_exists(con, key):
        with commit(con, "dl:m27", "seed curated.statuses"):
            con.execute("""CREATE TABLE lake.curated.statuses AS SELECT * FROM (VALUES ('open', 'Open'), ('shipped', 'Shipped'), ('cancelled', 'Cancelled'),
                           ('returned', 'Returned')) t(status, label)""")
    snap = current_snapshot(con)
    frame.sync_columns(con, pg, int(d["id"]), key, snap)
    frame.refresh_dataset(con, pg, int(d["id"]), key, snap)
    print("seed: curated.statuses ready")
    return 0


if __name__ == "__main__":
    sys.exit(main())
