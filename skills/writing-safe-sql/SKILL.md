---
name: writing-safe-sql
description: The SQL the query service accepts — one SELECT over dataset keys, no files or table functions, the limits, as-of, masked columns and joins on hashed keys. Load before query_run.
---
# Writing SQL the query service accepts

- **One statement, a SELECT** (or `WITH … SELECT`). Nothing else runs: no `COPY`, `ATTACH`, `INSTALL`, `LOAD`, `SET`, `PRAGMA`, `CREATE`.
- **Name datasets by key**: `FROM curated.sales_orders o JOIN product.monthly_revenue r ON …`. Never a path, a URL or a table function
  (`read_csv`, `read_parquet`, `glob` …) — the service refuses them by name.
- **As of**: pass the `as_of` argument (a snapshot or a time) to `query_run`; do not write `AT (VERSION …)` yourself.
- **Limits**: a chat turn's query stops at 60 s and returns at most the display cap (1,000 rows); aggregate in SQL rather than
  fetching rows to count them. A truncated result says so — say so too.
- **Masks**: a masked column returns its mask (`j***@example.com`, `***`, a keyed hash, a year). A hashed key (`member_id`,
  `ip_address`) still **joins** to the same hash in another dataset — join on it, never try to reverse it.
- **Row filters**: you may see fewer rows than the dataset holds — the person's filter applies. Do not "correct" a total for it; say
  the total is for what they may see.
- **A refusal names its rule** (e.g. "you may not read `curated.payroll` — request access"); read it to the person and offer the request.
- Prefer `date_trunc`, `sum`, `count(DISTINCT …)`, `GROUP BY`; quote nothing a person typed into a literal you were not given.
