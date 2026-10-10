"""The timing set (design §10: 50 million rows over 200 datasets) — SYNTHETIC, generated, never committed: one 25-million-row
order table and 199 tables sharing the other 25 million rows, written as Parquet landing files by DuckDB.

    services/.venv/bin/python tests/fixtures/gen_timing.py <outdir> [total_rows] [datasets]
"""
from __future__ import annotations

import os
import sys
import time

import duckdb

OUT = sys.argv[1]
TOTAL = int(sys.argv[2]) if len(sys.argv) > 2 else 50_000_000
N = int(sys.argv[3]) if len(sys.argv) > 3 else 200
BIG = TOTAL // 2
os.makedirs(OUT, exist_ok=True)
con = duckdb.connect(config={"memory_limit": "3GB", "threads": 4})
SELECT = """SELECT 'SO-' || ({off} + i) AS order_id,
       lpad(((({off} + i) * 7) % 2000000)::VARCHAR, 7, '0') AS customer_id,
       'c' || ((({off} + i) * 7) % 2000000) || '@example.invalid' AS email,
       (TIMESTAMP '2025-01-01' + to_seconds((({off} + i) * 13) % 31536000))::VARCHAR AS ordered_at,
       ((({off} + i) * 7919) % 100000 / 100.0)::VARCHAR AS amount,
       ['North', 'South', 'East', 'West'][(({off} + i) % 4) + 1] AS region,
       ['open', 'shipped', 'cancelled'][(({off} + i) % 3) + 1] AS status,
       'note ' || (({off} + i) % 97) AS note
FROM range({n}) t(i)"""
t0 = time.monotonic()
con.execute(f"COPY ({SELECT.format(off=0, n=BIG)}) TO '{OUT}/t_big.parquet' (FORMAT parquet, COMPRESSION zstd, ROW_GROUP_SIZE 1000000)")
rest, small = TOTAL - BIG, N - 1
base, extra = divmod(rest, small)
off = BIG
for k in range(1, N):
    n = base + (1 if k <= extra else 0)
    con.execute(f"COPY ({SELECT.format(off=off, n=n)}) TO '{OUT}/t_{k:03d}.parquet' (FORMAT parquet, COMPRESSION zstd)")
    off += n
print(f"generated {off} rows in {N} files in {time.monotonic() - t0:.1f} s")
