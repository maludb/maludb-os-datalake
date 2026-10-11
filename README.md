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

**State (2026-10-11):** planned and APPROVED (D1–D16 and four open questions, every recommendation — `docs/datalake-design.md` §15); PHASE 0 COMPLETE — the kit (83 checks), the schema (158), the engine on DuckLake + DuckDB with its sandboxed query service (157; 50 million rows timed on this host); Phase 1 WRITTEN (80 screens, 105 actions, 75 tools, 14 specs — `docs/phase1-consistency.md`), APPROVED by the owner; Phase 2 the shell built and proven (322 / 327 checks); slice 1 the catalog built (229 checks); slice 2 uploads and loads built (262 checks); slice 3 the query service and editor built (220 checks); slice 4 access and policies built (332 checks); slice 5 contracts and quality built (310 checks) — the handoff point; slice 6 sources and pulls built by a worker (428 checks); slice 7 transforms, products, metrics and lineage built by a worker (349 checks under both modes, db/026); slices 8–10 next.
