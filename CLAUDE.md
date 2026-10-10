# Data Lake — CLAUDE.md

This repository is the **Data Lake** application of the MaluDB Business OS (`github.com/maludb/maludb-os-datalake`, **public** — the
owner, 2026-10-10; local clone and install path `/srv/apps/datalake`; catalog key `datalake`; DNS label `datalake`). The kernel is
`/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory, the agents, approvals and the token
ledger; this application owns the business's **analytical data**: datasets in four zones (landing → raw → curated → product, plus a
person's sandbox and quarantine), their catalog (owners, stewards, descriptions, columns, classifications, glossary, tags), their
trust (contracts, checks, incidents, freshness, lineage, computed certification), their protection (grants, masks, row filters, the
audit of every read, retention, erasure) and their use (the SQL editor, saved and verified queries, metrics, the ask window, exports,
the API, K7 shares). The owner's brief (2026-10-10): our own data lake, **no existing data lake platform adopted**; the data
**discoverable, trustworthy, secure and usable — not simply stored**.

## Read first
1. `docs/datalake-design.md` — the plan (Phase 0): the research (§0: what a lake must do and how lakes fail, the front ends of the
   field, the formats and engines and this server, the estate, **the kernel-rule conflict and the way through**), what it is and is
   not (§1), the actors (§2), **roles, levels and the walls** (§3), the doors (§4), the agents and what pauses (§5), the memory model
   (§6 — zones and the writer, the catalog, ingestion, trust, **the query service**, transforms and lineage, retention / erasure /
   maintenance / backups, the tables and the shared-schema decisions), the question inventory with every tool named (§7), the kernel's
   part, the API and K7 (§8), the screens (§9), the build order (§10), Extended (§11), what is owed (§12), the decisions D1–D16 (§13),
   ports and files (§14), the owner's answers (§15), the state (§16).
2. `docs/research/` — the four reports behind §0, with their sources.
3. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references — `shared-schema.md` and
   `sms-and-reads.md` above all) and `htmx-php-builder` 0.9.0 (`~/htmx-php-builder`: `new-app`, `os-application`, `php-patterns`,
   `design-system` with the record picker and the switcher, `mcp-servers`, `chat-actions`). **Read them from the clones**: Claude
   Code's registered copy of the builder plugin is the stale 0.4.1.
4. The nearest exemplars: `/srv/apps/doccloud` (uploads in parts, `blobs`, the worker, the scale rule, the kit), `/srv/apps/inventory`
   (`sources` and the fetch layer), `/srv/apps/shareholder_intelligence` (uploads tied out, rules with an evidence class),
   `/srv/apps/knowledge` (K7 reads on a schedule), `/srv/apps/spaces` (`search_index`, `exports`, the worker).

## Rules that bind here
- `htmx-php-builder` skills govern the PHP; `os-integration` governs the fit. Where the two conflict, the integration contract wins:
  **no password, no login form, no account, no model key and no model call, no shared tables, no actions server of our own, no writes
  to the directory, no writes to another application, no connection to another application's database or the kernel's.** The lake's
  extracts of the siblings' data come only through the kernel (K7 shares now, K37 feeds when built) — the owner's amendment D4.
- **One door to the data.** Every row anyone reads — a screen, the preview, the editor, a saved query, a metric, an export, an agent,
  a K7 share, the API — comes from the **query service** (`services/query/`), which compiles the caller's access with
  `dl_query_policy()` (the ONE place §3 is compiled) into per-caller views **before** it runs a word of their SQL. The web application
  cannot read the lake's files (`lake/` is 0750 to the engine's users) nor the catalog's keys and paths (the `dl_lake_*` views never
  select them). PHP reaches the engine in ONE file, `app/lake/client.php`.
- **Only the writer writes the lake.** `services/lake/` (one process, the `lake_jobs` queue) is the only holder of DML on `ducklake.*`
  and write access to `lake/`. Every job idempotent; every write a DuckLake commit with an author and a message; never a rewind of
  history — a rollback is a new snapshot.
- **The query service is a sandbox, not a convenience**: the sqlglot allow-list (one SELECT, dataset keys only, no table functions,
  no ATTACH / COPY / INSTALL / LOAD / SET / PRAGMA), DuckDB locked (`enable_external_access=false`, `allowed_directories`,
  `lock_configuration=true`, memory and thread caps), the watchdog's `interrupt()`, systemd's `IPAddressDeny=any`. DuckDB's manual:
  *treat SQL like code in Bash or Python.* Never loosen a layer to make a query work.
- **Agents read as the person who asked** in a chat turn (the intersection, K38); a duty reads as the agent; no agent holds
  `read_unmasked` by default; until K38 lands, a chat turn refuses anything above `internal` or with personal columns — fail closed.
- **Masks follow classification** — personal and confidential columns are masked below `read_unmasked` from their first load; a
  sandbox result keeps the highest classification of its inputs. **No sample row of a personal or confidential column ever reaches a
  model** — the Steward drafts from schema, profile and lineage.
- **Trust is computed**: profiles, freshness, check results, usage and lineage are written by the machine; certification is earned and
  lost by rule; a verified query loses its badge when its SQL changes. Never a hand-typed technical fact.
- **Pinned engine**: `duckdb==1.5.6` and the versions in `services/requirements.txt`; extensions vendored into a read-only directory,
  autoinstall off. **This VM's CPU is x86-64-v2 (no AVX)**: smoke-test every new wheel here (`tests/engine/cpu.sh`) before pinning it;
  no Polars unless `polars[rtcompat]`.
- **Vocabulary: "agent" means an OS AI agent**, always. A *dataset* is a table in one zone with its catalog entry (key `<zone>.<name>`);
  a *load* one batch into raw; a *promotion* raw → curated under the contract; a *transform* a SQL that makes a dataset; a *check* a
  rule; an *incident* a failure being worked; a *grant* a level for a principal; a *policy* a mask or a row filter; a *query run* one
  execution through the query service; a *metric* an aggregate defined once; an *erasure* one subject removed from every zone. Avoid
  the estate's taken names: `pipelines`, `runs`, `feeds`, `builds`, `documents`, `databases`, `reports`, `events`.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + the three build specs
  + slice specs together (Phase 1). Schema changes after that are numbered additive migrations. **Never modify a migration**, and never
  alter DuckLake's own `ducklake_*` tables.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`, SQL
  `dl_has_right()`) + the level on the dataset (`dl_level()`) + `log_activity()` + `emit_action_status()`; a create's `location` ends in
  the record id. **A row value, a profile's top values, SQL text, a filter, a credential, a token or a file path are never in a log
  payload.**
- **This repository is public**: no tenant data, no secret, no real file ever committed; fixtures are synthetic; `config/.env` is
  gitignored.
- The kernel's rules copied here: activity `source` is `web` for the UI, `api` for a key, `agent` under a run token, `cron` / `system`
  for the worker, the writer and the query service; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see; the
  shared-schema rule — survey the siblings before any `CREATE TABLE`, reuse verbatim, extend by appending, record every table as read /
  reuse / new (design §6.8).
- Commit after every finished step on `main` in the repository's message style; never push unless the owner asks.

## State
2026-10-10 — planned; the four open questions answered as recommended (§15: `/srv/lake` on a dedicated volume, the CPU type `host`,
the GL / Inventory / CRM reads first, people feeds pseudonymised); **D1–D16 APPROVED as recommended** the same day. **PHASE 0 COMPLETE** the same day: K36 (kernel db/179); the kit (83 checks); the schema db/001–017 (158 checks, `tests/phase0/schema.sh`); the engine (`services/`, 157 checks, `tests/engine/run.sh`; the 50-million-row timings; `tests/engine/FINDINGS.md`, design §6.9); `maludb-os.json`, the two agents, nine skills, `deploy/`; the installer's plan clean. **PHASE 1 WRITTEN** the same day (80 screens, 105 actions, 69 + 6 tools, 3 build specs + 11 slice specs, db/018; `tests/phase1/check_specs.sh` 61 green; the schema proof 181; `docs/phase1-consistency.md` lists the decisions) — awaiting the owner's approval before Phase 2. Ports 8193 / 8847 /
8848 and scratch 9001–9007 reserved; K36 (the kernel's catalog row) follows the owner's go.
