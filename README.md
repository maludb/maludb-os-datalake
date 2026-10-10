# Data Lake

The MaluDB Business OS's data lake, with a built-in lakehouse: every dataset a business keeps for analysis — files people upload,
files and databases read on a schedule, extracts of the other applications' data through the kernel — landed as received, made
typed, checked and owned, and served to people and agents through one locked-down query service. A catalog to find it, quality
checks, incidents and lineage to trust it, classifications, masks, row filters and an audit of every read to protect it, and a SQL
editor, saved and verified queries, metrics and an ask-me-anything window to use it. Built on open formats and embeddable libraries
(DuckLake on PostgreSQL, Parquet, DuckDB) — no data platform adopted.

An application of the kernel [`maludb-os-core`](https://github.com/maludb/maludb-os-core), built with
[`maludb-os-htmx-php-guidelines`](https://github.com/maludb/maludb-os-htmx-php-guidelines) and fitted by
[`maludb-os-integration`](https://github.com/maludb/maludb-os-integration). Installed by the kernel's installer at
`/srv/apps/datalake`, served as `datalake.<domain>`, signed in by the kernel, reached by its agents through MCP.

- The plan: `docs/datalake-design.md` (planned and approved 2026-10-10). The research behind it:
  `docs/research/`.
- The rules for working here: `CLAUDE.md`.
- Catalog key `datalake`; ports 8193 (Apache, internal), 8847 (records MCP), 8848 (activity MCP).
- On request, not a default install.

**State (2026-10-10):** planned and APPROVED (D1–D16 and four open questions, every recommendation — `docs/datalake-design.md` §15); Phase 0 in progress.
