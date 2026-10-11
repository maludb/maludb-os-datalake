# Data Lake — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-10 · the business's **own data lake with a built-in lakehouse**: every dataset the business wants to keep and analyse — files
people upload, files and APIs read on a schedule, extracts of the sibling applications' data through the kernel, outside databases
the business owns — landed **as received**, made **typed, checked and owned**, and served to people and agents through **one
locked-down query service**: a catalog to find it, quality and lineage to trust it, policies to protect it, a SQL editor, saved and
verified queries, metrics and an ask-me-anything window to use it. The owner's brief (2026-10-10): *"build our own data lake
infrastructure and application without adopting an existing data lake platform. The data stored must be discoverable, trustworthy,
secure, and usable — not simply storing large amounts of it"*, with a built-in lakehouse front end, by the process every
`maludb-os-` application follows. Its own repository `/srv/apps/datalake` (origin `github.com/maludb/maludb-os-datalake`,
**public** — the owner, 2026-10-10), served as **`datalake.<domain>`** (the owner, 2026-10-10), fitted to `maludb-os-integration`
0.9.0 and built with `htmx-php-builder` 0.9.0. The nearest plans and exemplars: DocCloud (uploads in parts, content-addressed blobs,
the scale rule, a worker), Inventory (the `sources` model, the polite client), Shareholder Intelligence (uploads tied out against
control totals, rules with an evidence class), Knowledge (the extractor, K7 reads on a schedule).

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. Phase 0's first half is
> this document and the owner's answers (§13, recorded in §15); the schema in `db/`, the engine proof, `maludb-os.json`, `os/`,
> `skills/` and `deploy/` complete it. Phase 1 adds `docs/datalake-mcp-tool-surface.md`, `docs/datalake-action-manifest.md`,
> `docs/build-specs/lake-engine.md`, `docs/build-specs/query-service.md`, `docs/build-specs/access-and-policies.md` and the slice
> specs. **No feature PHP is written until the owner approves them together.** The four research reports behind §0 are in
> `docs/research/` with their sources.

## 0. The research — what a lake must do, what the field shows, and what the estate gives

### 0.1 What a lake is, and how lakes fail (`docs/research/01-requirements.md`)

A **lakehouse** is "low-cost and directly-accessible storage that also provides traditional analytical DBMS management and
performance features such as ACID transactions, data versioning, auditing, indexing, caching, and query optimization" (Armbrust et
al., CIDR 2021). The paper's point that matters here: **a transactional metadata layer over the files is what makes a lake** — and
it names that layer as the place for quality enforcement, access control and audit. Every one of the owner's four words lives there.

**How lakes fail** — the twelve documented ways, each answered by a rule of this plan:

| # | Failure | This plan's answer |
|---|---|---|
| 1 | Ingest first, metadata never — the swamp (Gartner 2014; Hai et al.) | **No data lands without a catalog entry**; technical metadata harvested on every load; descriptions drafted by an agent and accepted by a person (§6.2) |
| 2 | No owner; a central team becomes the bottleneck (Dehghani) | every dataset has an owner, a steward and a department; certification needs them (§6.2) |
| 3 | Unknown quality → shadow spreadsheets (Stonebraker) | checks on every load, quarantine, incidents, freshness, computed certification (§6.4) |
| 4 | Two tiers drift (lake + warehouse) | one store, four zones, the query service the only reader (§6.1) |
| 5 | "Just a bunch of files" — partial writes, small files, nobody compacts | an ACID table format (DuckLake), inlining, scheduled maintenance (§6.1, §6.7) |
| 6 | Security that can be bypassed | **one enforcement point**; the files unreadable to the web application; masks and filters applied by views the caller never sees past (§3, §6.5) |
| 7 | Data that cannot be deleted | an **erasure pipeline** with a proof report; landing kept only as long as needed (§6.7) |
| 8 | Unbounded retention, audit logs included (this server's pgaudit disk-fill of 2026-10-05) | retention per dataset and zone; the query audit with its own window (§6.7) |
| 9 | Snapshots mistaken for backups; a catalog restored out of step with its files | catalog first, then files, never during maintenance; a restore drill (§6.7) |
| 10 | Hyperscale tooling at SMB scale | one server, one embedded engine, no cluster, no partitions under a terabyte (§0.3) |
| 11 | One metric, many definitions; text-to-SQL's plausible wrong numbers | metrics defined once; verified queries; the expert uses them before writing SQL (§6.6) |
| 12 | Agents with excessive agency | agents query **as the person who asked**, read-only, rate-limited, every query audited; exports and grants pause (§5) |

The research's consolidated list — **R1–R71**, each MUST / SHOULD / LATER for an SMB-scale lake — is in
`docs/research/01-requirements.md`; §10 maps every MUST to a slice.

### 0.2 The field — what the front ends do (`docs/research/02-frontends.md`)

Surveyed screen by screen: Databricks (Catalog Explorer, SQL editor, Genie), Snowflake (Snowsight, Horizon, CoWork), Dremio,
Starburst Galaxy, Google's Knowledge Catalog (ex-Dataplex), Microsoft Fabric / Purview, AWS SageMaker Unified Studio (ex-DataZone),
OpenMetadata, DataHub, Amundsen (archived 2026-09-29), Apache Atlas, Unity Catalog OSS, CKAN, Marquez, Superset, Metabase, Hue,
DuckDB UI / MotherDuck.

- **They converge on one dataset page**: About · Columns · Preview · Profile · Quality · Lineage · Usage · Access · History.
  *Kept*, as tabs (§9).
- **The catalog is filled by the machine, not by people**: profiles computed on load, usage from the query log, lineage from what
  ran, freshness from commits, ranking by usage (Amundsen's — no curation at all). *Kept.*
- **AI drafts, a person accepts**; AI text marked until reviewed. *Kept* — the Data Steward proposes, people accept (§5).
- **Trust is computed, not typed**: health from checks, certification that requires an owner, a description and passing checks
  (Collate), verification that **drops on edit** (Metabase). *Kept.*
- **Honest about access**: search shows only what the caller may see; a preview says outright "you may not preview this"; lineage
  greys out what the caller may not see. *Kept.*
- **Show limits**: the default LIMIT, the display cap, a truncation notice, download tiers; "data updated" separate from
  "metadata updated" (CKAN). *Kept.*
- **The AI assistants run as the asker** — Genie, Snowflake CoWork, Metabot, BigQuery and Fabric agents all execute with the
  end user's permissions; the exceptions (OpenMetadata's bot token, MotherDuck's shared key) are the anti-pattern. They "cite" by
  showing the interpretation, the SQL, the verified query and the sources. *Kept* — and it needs a kernel item (K38, §12).
- **Phones**: Superset's mobile mode, Genie's and CoWork's apps cover chat, dashboards, approvals and incidents; **no product offers
  a SQL editor on a phone**. *Kept*: the reading screens at 375 px, the editor and the policy editors desk-only (§9).
- *Avoided*: the ghost-town catalog (required metadata forms at creation), empty tabs, sticky manual badges, **sample rows sent to a
  model by default** (Hue), AI that writes without approval, security enforced only in the application (Metabase's native-SQL
  bypass of its own row security).

### 0.3 Building our own — formats and libraries, not a platform (`docs/research/03-foundations.md`)

The owner's rule separates a **platform** (Databricks, Snowflake, Dremio, Starburst, a hosted lakehouse, a Spark or Trino cluster
product, a Hadoop distribution) — never adopted — from open **formats, specifications and embeddable libraries**, which we may use.

| Candidate | What it is | Verdict |
|---|---|---|
| **DuckLake 1.0** (2026-04-13; MIT; DuckDB Foundation) | A table format whose **whole catalog is ordinary SQL tables in PostgreSQL** (26 `ducklake_*` tables; every object row carries a snapshot range, so the state at any snapshot is a `WHERE`), data as Parquet; multi-table ACID commits in one PostgreSQL transaction; time travel (`AT (VERSION => n)`); a change feed (`table_changes`); **data inlining** (small inserts kept in the catalog until flushed — the small-files cure for an SMB's trickle of writes); per-file encryption keys in the catalog; `METADATA_SCHEMA` puts the catalog in a schema of our own database; a second implementation exists (DataFusion) and Iceberg import/export is documented — the exits | **Recommended (D2)**: our catalog is a PostgreSQL schema the PHP front end reads with plain SQL; one `pg_dump` + the data directory is the backup |
| **DuckDB 1.5.6** (2026-09-28; MIT) | An embedded vectorised SQL engine (a library) over Parquet, CSV, JSON, XLSX and DuckLake; spills to disk | **Recommended**, pinned; extensions vendored into a read-only directory, autoinstall off; 2.0 only after its first patch releases |
| Apache Iceberg v2/v3 via PyIceberg `SqlCatalog` | The widest-supported format; the catalog is one pointer row per table in PostgreSQL, everything else JSON/Avro files | the alternative: PHP cannot browse its metadata cheaply, no multi-table atomicity without a REST catalog server |
| PostgreSQL-native (partitioned tables, `pg_partman`) | One engine, native grants and RLS | the minimal alternative: fine under ~50–100 GB, an order of magnitude slower on scans, competes with every application on the shared cluster, no time travel |
| pg_lake, pg_duckdb, pg_mooncake | an analytical engine **inside** the shared PostgreSQL cluster | **rejected**: `shared_preload_libraries` + a restart of the cluster every application shares; none packaged for noble; pg_mooncake stale since its company's acquisition |
| Delta Lake, Hudi | JSON-log and JVM-first formats | rejected: Spark-first, no PostgreSQL catalog |
| MinIO, any object-storage server | an S3 API on one host | rejected: unnecessary on one server (local paths work for every candidate); MinIO in maintenance mode since 2025-12-03 and archived in 2026 |

**Metadata standards implemented natively, as tables with an export** (vocabularies copied, nothing installed): **OpenLineage**
(run / job / dataset, facets — a DuckLake snapshot id is the `version` facet), the **Open Data Contract Standard** (ODCS v3.2.0,
Bitol: fundamentals, schema with `classification` and `criticalDataElement`, quality rules with the library metrics `nullValues`,
`missingValues`, `invalidValues`, `duplicateValues`, `rowCount` and operators `mustBe…`, SLA `freshness`, `retention`,
`timeToDetect`), **DCAT 3** and **schema.org/Dataset** (export, Extended), Great Expectations' and Soda's check names mapped onto
ODCS's.

**Query safety** — DuckDB's own manual: *"Treat SQL in DuckDB like code in Bash or Python"*; its settings "are not a substitute for
proper sandboxing". So four layers (§6.5): an **allow-list of the query's syntax tree** (sqlglot, MIT), **per-person views**
(masks and row filters), **DuckDB locked down** (`enable_external_access=false`, `allowed_directories`, autoload off,
`lock_configuration=true`, memory and thread caps), and a **process sandbox** (systemd: `MemoryMax`, `CPUQuota`,
`ProtectSystem=strict`, `ReadOnlyPaths`, `IPAddressDeny=any`). DuckDB 1.5.6 has no query timeout: a watchdog's `interrupt()` —
tested on this host, a runaway query cancelled at 1.0 s; `read_csv('/etc/passwd')` refused under the lockdown; `SET` after the lock
refused.

**This server** (read 2026-10-10): 12 vCPU, 15 GiB RAM shared with ~16 applications, PostgreSQL and MaluDB; one 522 GB volume
(458 GB free) under `/`, `/srv` and `/var`; PostgreSQL 17.11 with `pgaudit` and `pg_stat_statements` preloaded (nothing to add);
PHP 8.3 with `upload_max_filesize` 2M / `post_max_size` 8M (so uploads go in parts, DocCloud's way); no DuckDB, PyArrow or Polars
anywhere yet. **The virtual CPU is x86-64-v2 — no AVX/AVX2**: DuckDB 1.5.6 and PyArrow 26 run correctly (tested: 20 M rows built
in 7.8 s, a 1,000-group aggregate in 0.51 s); the default Polars 2.0 runtime **crashes** (`polars[rtcompat]` runs). Every wheel is
smoke-tested here before it is pinned (Phase 0), and the owner may switch the VM's CPU type to `host` / `x86-64-v3` (open question b).

### 0.4 What the estate gives (the survey of the siblings' `db/*.sql` and manifests, 2026-10-10 — `docs/research/04-estate.md`)

| Need | The estate has | Used here as |
|---|---|---|
| The directory, sign-on, roles, tokens, the activity log | the kit (Sales CRM / DocCloud `db/001`–`005`) | **reuse** verbatim |
| A place read from under a policy, its pulls, its credential | **Inventory `sources` / `source_pulls` / `source_credentials`** (canonical; Shareholder Intelligence copied verbatim): connector, settings, schedule, rate, user-agent, back-off, pause; libsodium-sealed credentials with last4; the fetch layer (`Connector.php`, the one outbound client `http.php`, `pulls.php`) | **reuse** verbatim; `connector` widened with the lake's connectors (§6.3); the fetch layer ported as code |
| Files uploaded in parts, stored once | **DocCloud `upload_sessions` / `upload_parts` / `blobs`** (canonical "file store": 8 MB resumable parts, sha256-addressed `blobs/<aa>/<bb>/<sha>`, a reference count) | **reuse** for the landing zone |
| A file's text | Knowledge's extractor (DocCloud copied it) | **not used in v1** — the lake reads tabular files with DuckDB; unstructured files are Extended (§11) |
| A saved column mapping for a recurring upload | `import_templates` — **two divergent canonicals**: Shareholder Intelligence `db/014` (connector, mapping, sample, unique name) and Sales CRM `db/012` (dedupe policy, shared, owner) | **reuse SI's** (closer: a connector and a mapping); the divergence recorded for the catalogue |
| A cross-entity search index | **Spaces `search_index`** (canonical; reused by DocCloud, CRM, SI) | **reuse** + append the dataset columns |
| Groups | **DocCloud `groups` / `group_members`** (canonical, the estate's first) | **reuse** — "Finance analysts" as a principal of a grant |
| Tags | Help Desk `tags` + DocCloud's appended columns; **Sales CRM `record_tags`** (the polymorphic shape) | **reuse** both |
| Notes, notifications, dispatches, settings, exports, worker passes | GL `notes`; Spaces' member-only `notifications` / `_prefs` / `_outbox`; GL `agent_dispatches`; the `app_settings` skeleton; Spaces `exports` (+ `params`), `worker_passes` | **reuse** |
| Rules that raise a thing a person disposes of | SI `signal_rules` / `signals` with an evidence class | compared: the family of `checks` / `incidents` (§6.4) — a different concept, recorded as a sibling |
| Datasets, columns, lineage, contracts, checks, policies, queries | **nothing** — no `datasets`, `catalog`, `lineage`, `schemas` or `columns` table anywhere in the estate | **new canonical** (§6.8) |

**Names to avoid** (taken by a different concept): `sources` *as a document* (Knowledge's — Inventory's is the one copied),
`documents` (DocCloud), `databases` / `database_views` (Spaces' Notion databases), **`pipelines`** (Sales CRM's sales pipelines; the
kernel's too), `reports` (SI's approved pack), `events` (SI, Reservations), **`runs`** (ProcessCore's production runs), `feeds` and
`builds` (Knowledge), `watches` (Inventory), `jobs` (nothing yet — but generic; the lake says `lake_jobs`).

Free prefix: **`dl_`**. Ports after Shareholder Intelligence's 8192/8845/8846: **8193 / 8847 / 8848**, scratch **9001–9007** (nothing
listens on any of them). The kernel's category checks already admit **`database`** (db/172, db/175), so the catalog row (K36)
widens nothing.

### 0.5 What the kernel's rules say about a lake — the conflict, and the way through

A lake **keeps copies**. The contract says the opposite twice:

- `shared-schema.md` §2: data another application owns is *"read, never copied"* — hold *"at most the provider's id and the display
  fields you need"*, refreshed from a K7 read.
- `os-integration` SKILL: the only crossings are MaluDB episodes, MCP, the hand-off token and the kernel's endpoints — **never a
  connection to another application's database**.

And the one crossing, **K7**, is built for small reads: one synchronous MCP call per request, **an answer capped at 256 kB**, a
timeout of at most 60 s, **no paging at the kernel** (paging exists only inside a few shares: Sales CRM's `accounts_index` /
`contacts_index` / `deals_won`, DocCloud's `documents_index`, Inventory's `sales_closed`), every call logged `application.read`, and
**a share about people (`people: true`) readable by HR alone**. The 39 shares declared today are period summaries and indexes.

**The way through** (D4 — the owner's to approve):

1. **An amendment to the rule, by name**: *a lake extract is not a copy of a table.* The lake keeps **dated, read-only extracts** of
   data another application owns, in its own format, each row carrying its provenance (provider, share or feed, pull, extracted at);
   the provider stays the system of record; the lake never writes back and never serves an extract as the provider's current
   truth (the dataset page says "as of"); the provider's erasure reaches the lake (an erasure request names the subject in every
   zone, §6.7). Recorded in `shared-schema.md` as the lake's exception, in the same change that adds the lake's canonical tables.
2. **In version 1, through K7 as it is**: a `k7` connector reads the paged shares that exist (by `since` and page), on a schedule,
   under an approved connection — the siblings' summaries become datasets with lineage back to their share.
3. **For bulk, a kernel door — K37 dataset feeds** (§12): a provider declares `feeds[]` in `maludb-os.json` (a named dataset, its
   columns with types and classifications, its key, its watermark column, whether it names people), a super-admin approves each
   feed for the lake, and the lake pulls it in pages by cursor; the bytes go from the provider to the lake over loopback under a
   short-lived token the kernel mints, as DocCloud's `document_link` does for a file. Each provider then owes its first feeds.
4. **Never**: a read-only role on a sibling's database, a `postgres` connector pointed at one of the tenant's own databases (the
   connector refuses the tenant's database names and the local socket), the kernel's database.

## 1. What the Data Lake is, and is not

The Data Lake **owns the business's analytical data** — every dataset it decides to keep for analysis, where it came from, what it
means, who owns it, how good it is, who may see it, what was made from it — and **the one door to it**.

- **A dataset is the record.** It has a key (`curated.sales_orders`), a zone, a title, a description, an owner, a steward, a
  department, columns with business names and classifications, a source, loads, snapshots, checks and their results, incidents,
  lineage, grants, a retention, usage. **No data lands without one.**
- **Four zones, one store** (§6.1): **landing** (the bytes exactly as received, stored once), **raw** (a table as received — every
  column text, every row with its provenance, append-only, replayable), **curated** (typed by the dataset's contract, deduplicated,
  checked; failures quarantined with a reason), **product** (owned, certified datasets and metrics people and agents use by default);
  beside them a person's **sandbox** (what they saved from the editor, theirs alone, never certified).
- **One door.** People, agents, sibling applications and keys read data **only through the query service**: a pool of sandboxed
  DuckDB workers that see each caller's datasets as views with that caller's masks and row filters applied. The web application
  cannot read the files; nobody holds a path.
- **Trust is computed.** Profiles, freshness, check results, lineage and usage are recorded by the machine on every load and every
  query; "certified" is earned by an owner, a description, checks and freshness, and **drops by itself** when they stop holding.
- **Usable in three ways**: find and read (search, the dataset page, preview); ask (the expert, which uses metrics and verified
  queries before it writes SQL, and shows the SQL); work (the SQL editor, saved and verified queries, transforms that make new
  datasets, exports, the API and K7 shares).
- **Nothing of identity lives here.** The kernel signs people in; an outside system holds a person's key.
- **Full MCP coverage** is a requirement (`maludb-os-integration`, `mcp-and-api.md`).
- **The easy path is three steps**: drop a spreadsheet on *Upload*, confirm the columns, and query it — the catalog entry, the
  profile, the preview and the first checks exist by then.

Not in version 1 (§11): streaming and log-based change capture (scheduled pulls and micro-batches cover an SMB); notebooks;
dashboards beyond saved queries as tiles; a column-level lineage graph for non-SQL code; learned anomaly detection; unstructured
files (PDFs and images in "volumes"); sharing outside the business (Delta-Sharing-like links); BI tools over a wire protocol
(PostgreSQL wire / Flight SQL); a DCAT export; a lake shared between two installations; Presidio's NER for free text; branching.

## 2. Who uses it (the actors, in the OS's words)

"Agent" always means an OS AI agent. A **member** is a kernel member mirrored here (an external member is flagged); a **dataset** a
table in one zone with its catalog entry; a **column** one field of it; a **source** a place data is read from; a **load** one
batch landed into a raw dataset; a **promotion** raw → curated under the contract; a **transform** a named SQL that makes a curated or
product dataset from others; a **check** a rule over a dataset; an **incident** a check's failure being worked; a **grant** a level on
a dataset (or a zone, or a tag) for a principal; a **policy** a mask by classification or a row filter; a **query run** one
execution through the query service; a **saved query** a named SQL, **verified** when a steward vouched for it; a **metric** a named
aggregate defined once; an **erasure** the removal of one subject from every zone.

| Actor | Who | How they reach the application |
|---|---|---|
| **Member** | Everyone inside the business holding Member: the catalog (what classification allows), preview and query what they are granted, run saved queries and metrics, ask the expert, request access, upload a file into their sandbox, follow a dataset | The launcher → `datalake.<domain>`; the phone (375 px first: search, a dataset's About / Columns / Quality, incidents, approvals, ask); the command bar |
| **Analyst** | Members who work with data: the SQL editor, saving queries, saving a result as a sandbox dataset, sandbox transforms, proposing a dataset for promotion | The desk |
| **Data engineer** | Builds the lake: sources and credentials, loads, contracts and promotion, transforms into curated and product, checks, incidents, descriptions and tags, the glossary, metrics, verifying queries | The build and quality areas |
| **Owner / steward of a dataset** | A row on the dataset, not a role: the owner answers for it and decides access requests to it; the steward keeps its description, checks and certification | The dataset page; the access inbox; the steward inbox |
| **Lake admin** | Grants beyond a dataset's owner, classifications and masking policies, row filters, retention, **erasure**, settings, the query service's limits, maintenance and backups, the audit of every query, keys and tokens. A super-admin holds it. Sees every catalog entry; **reads restricted data only through a logged break-glass** (`lake.admin_read`) | The admin area |
| **An agent** | The shipped **expert** (the command bar and the ask window) and **Data Steward** (a weekday duty), and any hired agent granted a role: reads what it is granted — **and in a person's chat turn, only what that person may also read** (§5, K38) | Records MCP (run token) · Actions MCP (relayed) · a chat turn · a duty |
| **An outside system** | Holds a person's key: lists the catalog, runs saved queries and metrics, reads products, uploads a file — as that person, rate-limited, logged under the key's label | `/api/v1/*` (§8) |
| **A sibling application** | A provider the lake reads (K7 shares now, K37 feeds later); a consumer of the lake's shares (a product's rows, a metric's value) granted to it as an `application` principal | K7 over an approved connection |
| **The kernel** | Sign-on, the directory, agents, approvals, the command bar (chat endpoint), run facts (K38), the siblings' reads (K7, K37), texts (K6), the ledger of the agents' model calls | — |
| **System** | The **writer** (the only process that writes the lake: loads, promotions, transforms, profiles, checks, maintenance, erasure, exports), the **query service**, the worker (schedules, freshness, the outbox, retention, dispatch retries) | `source = 'cron'` / `'system'`, every row logged |

Tenancy: one installation, one record database `<tenant>_datalake` holding **both** the application's tables and the lake's catalog
(schema `ducklake`), one data directory, **no memory database of its own** (D2) — the application's activity goes to the tenant's
one MaluDB as every sibling's does.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered). The base role's key is `user` so the
installer's `--grant-standing-departments` makes every insider a Member.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `user` | Member | write | `catalog.browse`, `data.read` (where granted), `queries.run` (saved and verified), `metrics.use`, `ask.use`, `access.request`, `sandbox.upload`, `follow.use`, `export.own` (within the download limits), `keys.own` |
| `analyst` | Analyst | write | Member's + `sql.editor`, `queries.save`, `sandbox.save` (a result as a dataset), `sandbox.transform`, `products.propose` |
| `engineer` | Data engineer | write | Analyst's + `sources.manage` (incl. credentials), `loads.manage` (run, replay, roll back a load), `contracts.manage`, `promote.run`, `transforms.manage` (curated and product), `checks.manage`, `incidents.manage`, `catalog.edit` (descriptions, tags, owners, stewards, glossary), `metrics.manage`, `queries.verify`, `maintenance.run` |
| `admin` | Lake admin (is_admin) | admin | every right + `grants.manage` (any dataset, zone or tag), `policies.manage` (classifications, masks, row filters), `retention.manage`, `erasure.manage`, `settings.manage`, `query_limits.manage`, `audit.read` (every query run), `keys.all`, `agents.settings`, `lake.admin_read` (break-glass, logged) |

**Classification** (a column's, and a dataset's = the highest of its columns; ODCS's `classification`): `public` · `internal` (the
default) · `confidential` · `restricted`; and **personal data** as its own flag on a column with a kind (`email`, `phone`, `name`,
`address`, `national_id`, `payment_card`, `bank_account`, `birth_date`, `ip_address`, `member_id`, `free_text_personal`, `other`).

**The levels** on a dataset (or a zone, or a tag — then on every dataset under it): `discover` (the catalog entry, columns,
profile with personal values masked, quality, lineage — no rows) · `read` (rows, **with every personal and confidential column
masked**) · `read_unmasked` (rows as stored) · `write` (load, promote, transform into it) · `own` (all of it + decide access
requests + change its grants within the owner's reach).

**The walls** (`dl_can_discover(dataset)`, `dl_level(dataset)` → `none|discover|read|read_unmasked|write|own`):

1. **Data is deny-by-default.** No row is read without a grant at `read` or above naming the caller, a group, a department they are
   in, `everyone`, or (for a K7 consumer) the application.
2. **The catalog is open by classification.** Every Member may *discover* `public` and `internal` datasets; a `confidential` or
   `restricted` dataset shows as **a stub** (name, owner, "request access") unless granted (R36); an **external member discovers
   only what names them**; an agent discovers what its member id may (and, in a chat turn, what the person may — rule 8).
3. **Grants are additive; a zone or a tag grant covers every dataset under it**, present and future (tag-based policy, the
   lakehouses' convergence — Unity Catalog, Lake Formation, Ranger). A grant may carry a **row filter** (a predicate over the
   dataset's columns and the caller's attributes: department ids, site ids, member id — e.g. `department_id = ANY(:my_departments)`)
   and an **expiry**.
4. **Masks follow classification, not the person's memory.** A column tagged personal or `confidential` is masked for everyone below
   `read_unmasked` by its kind's **masking policy** (`full` → `***`, `partial` → `j***@example.com`, `hash` → a keyed hash stable within
   the installation so joins still work, `null`, `year_only` for birth dates); `restricted` columns are `null` below
   `read_unmasked`. A new column the classifier tags is masked from its first load — nobody has to remember.
5. **One enforcement point.** Every row anyone reads comes from the **query service** (§6.5), which builds the caller's views from
   these rules (`dl_query_policy()`, one SQL function) before it runs a word of their SQL. The preview, the editor, a saved query, a
   metric, an export, the expert, a K7 share and the API all go through it. The web application's database role **cannot read the
   lake's catalog keys or paths, and its user cannot read the data directory**.
6. **The sandbox is the person's**: datasets saved from the editor are `own` to their maker and `none` to everyone else until the
   maker shares them (a grant) or an engineer promotes them; a sandbox dataset inherits the **highest** classification of what it was
   made from and keeps its masks (a person cannot launder a mask by saving a result).
7. **The admin sees every catalog entry; rows only by break-glass** — `lake.admin_read` on a dataset, with a reason, logged
   `dataset.admin_read`, notified to the dataset's owner.
8. **An agent reads what it is granted — and in a person's chat turn, the intersection** of the agent's and that person's access
   (K38: the run facts name who asked). A duty runs as the agent alone. No agent is granted `read_unmasked` by default; `is_eval`
   refuses every write.
9. **Every read is audited** (`query_runs`: who, on whose behalf, which agent run, which datasets and columns, the policy applied,
   rows, bytes, time) — §6.5, with its own retention (§6.7).

**Scope: none.** One business, one installation; departments and sites are attributes for row filters, never a wall of the
application's own.

**The scale rule** (DocCloud's): no function returns the caller's whole visible set per statement; the caller's principals once per
statement (`dl_my_principals()`, STABLE), then an indexed `EXISTS` per candidate dataset — the catalog will hold thousands of
datasets and tens of thousands of columns, and the audit millions of rows.

## 4. Doors for those who are not inside — keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the application's own*.

**A key** (`mcp_access_tokens` with `scope = 'api'`, Knowledge's appended `rate_per_minute`, `rate_per_day`, `rotated_from`) is a
person's own, minted on *My settings*, acts **as that person** with their grants and masks, rate-limited (60/min, 2,000/day — a
query costs more than a file listing), logged under its label. It lists the catalog, runs saved queries and metrics, reads products
in pages and uploads a file into the person's sandbox; **ad-hoc SQL over the API needs `sql.editor`** and a per-key switch.

**A sibling application** reads the lake through **K7** (`shares[]`, §8) — only products and metrics granted to it as an
`application` principal, masked as for a Member, the consumer's identity from the K26 headers. And the lake reads siblings through
**K7 `reads[]`** now and **K37 feeds** later (§0.5).

**Rejected:** links to data for people without an account (Extended at the earliest — an export is a file a person downloads and
carries); a service account that sees everything; a key that acts as nobody; handing a file path or a catalog credential to anyone.

## 5. Agents and the application — the OS requirement, in detail

**Reading, describing, checking and proposing are free; anything that widens who sees data, lets data leave the lake, or destroys
data is a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `export_make` (a result or dataset to a file for download), `share_application` (a grant naming a sibling), `grant_set` / `grant_revoke` (beyond the agent's own sandbox), `access_decide`, `policy_set` (masks, classifications downgraded, row filters), `key_mint` for another | `external_send` | Data reaches someone or something that could not read it before |
| `dataset_drop`, `load_rollback`, `snapshot_expire_now`, `erasure_run`, `retention_set` (shortened), `source_delete`, `transform_delete`, `landing_purge` | `deletion` | |
| `certify`, `verify_query`, `contract_publish` (a breaking change), `settings_save`, `query_limits_set`, `source_credential_set` | `other` | Trust that others will rely on; configuration; credentials |
| `catalog_describe` (as a **proposal** unless the agent is the dataset's steward), `tag_apply`, `tag_propose`, `pii_propose`, `owner_propose`, `check_create` (non-blocking), `check_run`, `incident_note`, `incident_ack`, `query_run`, `metric_value`, `saved_query_save` (own), `sandbox_save`, `load_run` (an existing source), `profile_run`, `steward_proposal_*`, `follow` | — | An agent must do these alone — this is what makes it useful |

**Working.** (a) **The command bar and the ask window**: a person's turn on the expert through the kernel's chat endpoint; the
expert answers *what data do we have about X, where does this number come from, who owns it, can I trust it, what were last month's
sales by region* — by **catalog search first, then a metric or a verified query, then SQL it writes**, every query run through the
query service **as the person** (K38), and its answer **shows the work**: the interpretation, the datasets used (linked), the metric
or verified query or SQL (linked to its `query_runs` row), the as-of time, and "rows were masked" when they were. (b) **A duty** for the
Steward. (c) **A dispatch**: "a blocking check failed on `curated.invoices`" writes an `agent_dispatches` row that wakes the
Steward through one chat turn (K8 when it lands) to triage and note the incident.

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install**):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Data Lake Expert | The command bar and the ask window: finds datasets, explains a dataset (owner, freshness, quality, lineage, who may see it and why), answers questions with metrics, verified queries and SQL **as the asker**, shows its work, drafts an access request for the asker, files an upload into the asker's sandbox; never exports, never grants | every records tool, `catalog_search`, `dataset_describe`, `query_run`, `metric_value`, `lineage`, `access_explain` | `query_run`, `saved_query_save`, `sandbox_save`, `access_request_draft`, `follow` | `export_make`, every `external_send`, every `deletion`, `certify`, `verify_query` |
| `steward` — Data Steward | A duty every weekday 06:40 (`40 6 * * 1-5`): **the steward report** — datasets with no description or undescribed columns (a drafted description each, from the **schema, the profile and the lineage — never sample rows of a personal or confidential column**), columns the classifier marked personal awaiting a person, datasets with no owner, freshness misses, failed checks and open incidents (a triage note each: what changed upstream, by lineage), schema drift, datasets unused for 90 days (Tier "unused"), sandbox datasets proposed for promotion, coverage against the target; each a **proposal** the owner, the steward or an engineer accepts, edits or dismisses (the diff shown); one note per department's data owners, one to the admin's assistant | the report tools of §7, `dataset_describe`, `profile`, `lineage` | `steward_propose`, `incident_note`, `incident_ack`, `check_create` (non-blocking), `notification`, `message_send` (the kernel's) | nothing (it never grants, exports, deletes or certifies) |

**Skills** (`skills/`): `datalake-basics` (zones, datasets, columns, classifications and masks, the levels, grants, checks, incidents,
lineage, query runs; which tool answers what; "never widen access, never export without the person"), `answering-with-data`
(catalog first, then metrics, then verified queries, then SQL; how to show the work; what "as of" means; when to say the data does
not exist or is not trusted), `writing-safe-sql` (the allowed dialect, the limits, the views not the files, joins on hashed keys),
`describing-data` (a good description, from the profile and lineage, never from personal values; ODCS's purpose / usage /
limitations); runbooks (`kind: runbook`): `land-a-new-spreadsheet`, `triage-a-failed-check`, `answer-where-does-this-number-come-from`,
`weekday-steward-report`, `handle-an-erasure-request` (for the admin's assistant — the run itself pauses).

## 6. Memory model — what the Data Lake remembers

Three stores: **record memory** (PostgreSQL 17, `<tenant>_datalake`, schema `public`: the catalog, governance, audit — the
application's tables); **the lake** — DuckLake's catalog in **schema `ducklake` of the same database** plus **Parquet files** under
`DL_STORAGE_DIR/lake/`; **landing** — content-addressed original files under `DL_STORAGE_DIR/landing/` (DocCloud's blob store).
Activity episodes go to the tenant's one MaluDB, tagged `datalake`.

### 6.1 Zones, the engine and the writer (`services/lake/` — Python, `runtime.python`)

**The zones, as DuckLake schemas**: `raw`, `curated`, `product`, `sandbox` (one per person by name prefix `sandbox.m<id>_…`),
`quarantine` (a twin of each curated table, with `_dl_reason`). A **dataset key** is `<zone>.<name>` (`raw.crm_deals`,
`curated.deals`, `product.monthly_revenue`), unique, never reused after a drop (a dropped key is retired, R1's persistent identifier).

| Zone | Holds | Guarantees | Written by |
|---|---|---|---|
| **landing** | the bytes as received (a file, a pull's response body, a K7 page as JSON) | stored once by sha256, never changed; kept `landing_keep_days` (default 30) after its load succeeded, then purged unless a person pins it; **in scope of erasure** | the web application (uploads) and the writer (pulls) |
| **raw** | one table per source dataset; **every column `VARCHAR` as received** + `_dl_load_id`, `_dl_row` (the row number in the file or page), `_dl_loaded_at`, `_dl_landing_sha256`, `_dl_rescued` (JSON of fields that matched no known column) | append-only; complete; replayable into curated at any time | the writer only |
| **curated** | one typed table per raw dataset (or several raw datasets): types from the **contract**, deduplicated by the contract's key (latest-wins by `_dl_loaded_at` or a declared ordering column), checks passed | one validated row per real entity or event; failing rows in `quarantine.<name>` with the reason; every promotion a snapshot | the writer only |
| **product** | datasets made by **transforms** for use: business names, an owner, a contract, metrics defined over them | certified when it earns it; a breaking change is a new major version | the writer only |
| **sandbox** | a person's saved results and sandbox transforms | theirs alone; the highest classification of their inputs, masks kept | the writer, on the person's request |

**DuckLake, attached by the writer** to the PostgreSQL catalog (`METADATA_SCHEMA 'ducklake'`, `DATA_PATH DL_STORAGE_DIR/lake/`,
**`ENCRYPTED`** — a key per Parquet file in the catalog, D6), with data inlining for small loads (flushed by maintenance). Every
write is one DuckLake commit with an author (`dl:<member or agent>`) and a commit message (`load 4182`, `promote curated.deals`,
`transform product.monthly_revenue v3`) — so `ducklake_snapshot_changes` is a second, engine-level history beside `activity_log`.
Time travel (`AT (VERSION => n)`) answers "as of" and backs **load rollback** (a new snapshot that removes a load's rows — never a
rewind of history); the change feed (`table_changes`) makes promotions and transforms **incremental**.

**The writer** (`datalake-writer`, one process, systemd): the **only** process with write access to `lake/` and DML on `ducklake.*`.
It works a queue (`lake_jobs`: `load`, `promote`, `transform`, `profile`, `checks`, `export`, `maintenance`, `erasure`, `rollback`,
`backup_lock`), one job at a time per dataset, several datasets in parallel up to `writer_concurrency` (default 2), each job
**idempotent** (a load is keyed by source + landing sha256 or pull + page cursor; reloading the same file is a no-op that says so;
a promotion is a MERGE by key; a transform overwrites its own output snapshot). Memory capped (`DL_WRITER_MEMORY` 3 GB,
`max_temp_directory_size` 20 GB under `DL_STORAGE_DIR/tmp/`), threads 4.

**Pinned engine** (`runtime.python`: the application's own venv): `duckdb==1.5.6`, `pyarrow==26.0.0`, `sqlglot==30.22.0`,
`psycopg[binary]==3.3.*`, `python-calamine==0.8.3`, `openpyxl==3.1.5`, `xlrd==2.0.2` (legacy `.xls`); **no Polars** in v1 (the CPU,
§0.3). DuckDB's extensions (`ducklake`, `postgres_scanner`, `excel`) fetched once for v1.5.6 linux_amd64 into a root-owned
read-only `extension_directory` by `deploy/ROOT_STEPS.sh`; `autoinstall_known_extensions` and `autoload_known_extensions` off at
runtime. Every wheel smoke-tested on the host in Phase 0 (`tests/engine/cpu.sh`).

### 6.2 The catalog — datasets, columns, glossary, tags, search (`app/features/catalog/`)

- **`datasets`** (id, `key` unique, `zone`, `name`, `title`, `description`, `description_source` human/agent_proposed/accepted,
  ODCS's `purpose`, `usage`, `limitations`, `owner_member_id`, `steward_member_id`, `department_id`, `status`
  draft/active/deprecated/retired, `deprecation_note`, `successor_id`, `certification` none/promoted/certified, `certified_by`,
  `certified_at`, `certification_lost_reason`, `tier` 1/2/3/unused, `classification` (derived), `has_personal_data` (derived),
  `source_id`, `contract_version`, `ducklake_table_id`, `ducklake_schema`, `row_count`, `byte_size`, `file_count`,
  `last_snapshot_id`, `data_updated_at` (the last commit that changed rows), `metadata_updated_at` (CKAN's two dates),
  `freshness_warn_after`, `freshness_error_after` (intervals; dbt's), `retention_days`, `evolution_mode` add/rescue/fail,
  `dedupe_key text[]`, `ordering_column`, `created_by`, `created_at`, `updated_at`, `retired_at`). Written by the application for
  catalog fields and by the writer for the engine's (row count, size, snapshot, `data_updated_at`) — **technical metadata is never
  typed by a person** (R2).
- **`dataset_columns`** (dataset_id, `name`, `ordinal`, `physical_type`, `logical_type` (ODCS: string, integer, number, boolean,
  date, timestamp, object, array), `nullable`, `is_key`, `business_name`, `description`, `description_source`, `classification`,
  `personal_kind`, `classified_by` person/rule/agent, `classification_confirmed_by/at`, `glossary_term_id`, `critical` (ODCS's
  `criticalDataElement`), `first_snapshot_id`, `removed_snapshot_id`, `profile jsonb` (the latest: null %, distinct count and %,
  min, max, mean, stddev for numbers, length range for text, top 10 values **masked when personal or confidential**, the
  classifier's match fraction)) — the profile computed by the writer on every load with DuckDB's `SUMMARIZE` and kept by
  snapshot in **`column_profiles`** for drift (R5, R14).
- **`schema_versions`** (dataset_id, `version`, `snapshot_id`, `columns jsonb`, `change` added/removed/retyped/renamed,
  `breaking` bool, `detected_by` load/contract, `decided_by`) — schema history and drift (R14, R18).
- **`glossary_terms`** (`name`, `definition`, `synonyms text[]`, `status` draft/approved, `steward_member_id`, `parent_id` — a
  category), linked from columns; Draft → Approved by an engineer (OpenMetadata's).
- **Tags**: `tags` (Help Desk's + DocCloud's appended `kind` managed/free, `colour`, `created_by`), `record_tags` (Sales CRM's
  polymorphic shape; `record_type` dataset/column/saved_query/metric/source) — a tag can carry a **grant** (rule 3 of §3).
- **Search**: `search_index` (Spaces' canonical + appended `dataset_id`, `zone`, `classification`, `certification`, `owner_member_id`,
  `department_id`, `usage_30d`): one row per dataset (title A · column names and business names, tags, glossary terms B ·
  description and column descriptions C), per glossary term, per saved query and per metric; `dl_search(q, filters, limit, offset)`
  = `websearch_to_tsquery('simple', unaccent(q))` + `pg_trgm` on keys and names, **ranked certified first, then by usage, then by
  relevance**, the wall per row (`dl_can_discover`, stubs for rule 2); facets zone, department, owner, tag, classification,
  certification, health, glossary term, has personal data.
- **Usage** (`dataset_usage_daily`: dataset, day, queries, distinct people, distinct agents, rows read; `dataset_joins`: the
  datasets queried together) — from `query_runs`, by the worker each night: the popularity in search, "frequent users" on the
  Usage tab, Tier "unused" after 90 days (R7).
- **Follows** (`dataset_follows`: member, dataset, what — schema changes, failed checks, deprecation, access decisions) feed
  notifications.

### 6.3 Ingestion — uploads, sources, loads (`app/features/uploads/`, `sources/`, `loads/`; the writer)

**An upload** (the easy path): the browser sends the file in **8 MB parts** (DocCloud's `upload_sessions` / `upload_parts`;
`POST /upload/start`, `/upload/part`, `/upload/finish`); finish hashes it, decides the type with `finfo`, stores it once in
`landing/<aa>/<bb>/<sha256>` (`blobs`, reference-counted) and queues a **sniff**: DuckDB's sniffer (delimiter, quote, header,
encoding — Windows-1252 transcoded first —, types, date formats; `sample_size` the whole file up to 1 GB) or, for a workbook, the
sheet list and each sheet's used range read by `python-calamine`. The person sees **the mapping screen** (desk): the first 50 rows,
each column's proposed name, type and classification (the classifier's proposal, §6.5), the target (a new raw dataset in their
sandbox, or — engineers — a named raw dataset, append or replace), the dedupe key; they confirm; the writer loads. A saved mapping
is an `import_templates` row (SI's canonical) so next month's file of the same shape loads in one step. **Inference proposes, a
person confirms, nothing is silently dropped**: rows the parser rejects are kept with their line and error (`load_rejects`), not
lost (DuckDB's `store_rejects`).

**A source** (`sources`, Inventory's canonical verbatim; `connector` widened): a place read on a schedule under a policy.

| Connector | v1? | Reads | Notes |
|---|---|---|---|
| `upload` | ✓ | a person's file | the easy path above; a source row per recurring file shape |
| `http_file` | ✓ | a CSV / XLSX / JSON / Parquet file at an HTTPS URL | Inventory's one outbound client (declared user-agent, rate, back-off, conditional GET by `ETag`/`Last-Modified`) |
| `sftp_file` | ✓ | new files in a directory on an SFTP server | Inventory's feed connector; a supplier's or a bank's drop |
| `k7` | ✓ | a sibling's share, paged by `since`/`offset` where the share pages, whole where it is a period summary | `reads[]` declared (§8); `no_connection` → the source pauses with that reason, never a failure storm |
| `postgres` | ✓ | tables or a SQL query on **an outside PostgreSQL database the business owns** (a legacy system) by credential, read-only, incremental by a watermark column, a full reconciliation weekly | **refuses** a host that is this server's socket or loopback with a database name in the tenant's set — never a sibling's database, never the kernel's (§0.5) |
| `k37_feed` | after K37 | a sibling's declared feed by cursor | §12 |
| `rest_json`, `maludb_activity`, `mysql`, `s3_file` | Extended | | §11 |

**A load** (`loads`: id, source, upload or pull, landing sha256, target dataset, `rows_read`, `rows_loaded`, `rows_rejected`,
`rows_rescued`, `snapshot_id`, `status` queued/running/loaded/failed/rolled_back, `idempotency_key` unique, the schema seen, the
checks' outcome, timings): lands rows into raw with their provenance, profiles the dataset, runs the raw checks (row count, schema),
raises **schema drift** by the dataset's `evolution_mode` — `add` (a new column appears in raw and a proposal asks to add it to the
contract), **`rescue` (the default: new fields go to `_dl_rescued`, a proposal is raised, nothing is dropped)**, `fail` (the load
stops and opens an incident); a retyped or removed column is always a proposal, never applied silently (R50). Every load is an
OpenLineage run (§6.6).

### 6.4 Trust — contracts, promotion, checks, quarantine, incidents, certification (`app/features/quality/`)

- **A contract** (`contracts`: dataset_id, `version` semver, `status` draft/active/deprecated, `schema jsonb` — the typed columns, the
  key, the ordering —, `checks` by reference, the SLA — `freshness`, `retention`, `time_to_detect`, `time_to_notify`,
  `time_to_repair` —, `published_by/at`, `breaking` bool) is what promotion types by and what a product promises; **exported as an
  ODCS v3.2.0 YAML** (`GET /datasets/{id}/contract.odcs.yaml`). A breaking change (a column removed or retyped, the key changed)
  is a new major version and **pauses for an agent**.
- **Promotion** (raw → curated): the writer casts by the contract (`TRY_CAST`; a failed cast is a row-level failure, not a crash),
  deduplicates by key, runs the row-level checks — rows that fail a **blocking** check go to `quarantine.<name>` with `_dl_reason`,
  rows that pass are MERGEd into curated — and then the table-level checks. Incremental by the raw table's change feed; a full
  rebuild is one action (from raw, never from landing).
- **Checks** (`checks`: dataset_id, `column_name`, `kind` — `not_null`, `unique`, `accepted_values`, `range`, `regex`, `row_count`
  (absolute or vs. the last load, ±%), `freshness`, `referential` (values exist in another dataset's key), `schema` (no
  unexpected change), `custom_sql` (a SELECT returning failing rows, through the allow-list) —, `params jsonb`, ODCS's `dimension`
  completeness/validity/uniqueness/timeliness/consistency/accuracy/conformity, `severity` warn/error, **`blocking`**, `when`
  on_load/hourly/daily, `created_by`, `source` person/agent/template, `enabled`) — the vocabulary is ODCS's library metrics and
  Great Expectations'/Soda's names mapped onto them, nothing installed. **`check_results`** (check, load or snapshot, `value`,
  `threshold`, `passed`, `rows_failed`, `failing_keys` — at most 20 keys, **hashed when the key is personal**, never values —,
  `took_ms`, at). **Freshness** is a check the worker runs hourly against `data_updated_at` (R13).
- **Incidents** (`incidents`: dataset, the check or freshness or load that opened it, `severity`, `status` new/acknowledged/
  investigating/resolved/dismissed, `assignee_member_id`, `opened_at`, `acknowledged_at`, `resolved_at`, `root_cause`,
  `resolution`, `downstream jsonb` — the datasets lineage says it reaches, badged "upstream incident" while it is open —, notes) —
  one open incident per dataset and check; a passing run resolves it with the run cited (R20).
- **Certification**, computed (`dl_certification_eligible(dataset)` → the missing conditions): an owner and a steward; a description
  and every column described; at least one blocking check; every check passing; fresh; a contract `active`; no open incident of
  severity error. **An engineer certifies** an eligible dataset (pauses for an agent); **it drops by itself** — with the reason in
  `certification_lost_reason` and a notification to followers — when any condition stops holding. `promoted` is the lighter badge an
  owner may set on an eligible-but-uncertified dataset (Fabric's).
- **Verified queries** (`saved_queries.verified_by/at/sql_sha256`): verification **drops when the SQL changes** (Metabase's).

### 6.5 The query service — the one door (`services/query/`; `docs/build-specs/query-service.md`)

A pool of `query_workers` (default 3) behind a Unix socket owned by the application's group, each a **fresh in-memory DuckDB per
request**, under systemd (`MemoryMax` 2.5 GB each, `CPUQuota` 200 %, `TasksMax` 64, `ProtectSystem=strict`, `ReadOnlyPaths` the lake,
`PrivateTmp`, `NoNewPrivileges`, **`IPAddressDeny=any`**, its own user `datalake-query` which alone besides the writer can read
`lake/`). A request: `{caller_member_id, on_behalf_of_member_id?, agent_run_id?, source screen|mcp|api|k7|export|preview, sql |
saved_query_id + params | metric_id + dimensions + filters, row_limit, timeout}` → `{columns, rows (JSON or Arrow IPC), truncated,
masked_columns, datasets, query_run_id}`.

1. **Parse and allow** (sqlglot, DuckDB dialect): exactly one statement; `SELECT` or `WITH … SELECT` only; every table reference a
   **dataset key** the caller may `read`; no table functions (`read_*`, `glob`, `query_table`, `parquet_*`, `iceberg_*`), no `ATTACH`,
   `COPY`, `INSTALL`, `LOAD`, `SET`, `PRAGMA`, `CALL`, `EXPORT`, `CREATE`; no `getenv`, no file paths, no `AT (VERSION…)` except through the
   as-of parameter; a node budget on the tree. A refusal names the rule and the dataset ("you may not read `curated.payroll` —
   request access") — the **Request access** link pre-filled (the field's best pattern).
2. **The caller's policy** — `dl_query_policy(caller, on_behalf_of, datasets)` (SECURITY DEFINER, one function, the only place §3 is
   compiled): per dataset, the effective level (the **intersection** for an agent acting for a person), the masked columns with their
   mask expressions, the row predicate.
3. **The views**: the worker attaches the lake **READ_ONLY** through the `datalake_query` role, then creates a `TEMP VIEW` per
   dataset key in the caller's own namespace (`"curated"."deals"` → the masked, filtered projection), then **locks the configuration**
   (`enable_external_access=false`, `allowed_directories` = the lake only, `lock_configuration=true`, `memory_limit` 1.5 GB,
   `threads` 2, `max_temp_directory_size` 2 GB) and only then runs the caller's SQL against the views.
4. **Limits** (settings, D7): wall clock 30 s for a screen, 60 s for an agent or the API, 300 s for an export (a watchdog `interrupt()`);
   **1,000 rows shown**, a truncation notice; downloads up to 100,000 rows as CSV / XLSX / Parquet through an **export** (Spaces'
   `exports`; logged; pauses for an agent); result bytes ≤ 50 MB; a per-person concurrency of 2; a per-agent rate of 30 queries a
   minute.
5. **The audit**: one **`query_runs`** row per request (caller, on whose behalf, agent run id and request id, source, `sql_sha256`, the
   SQL text **kept `query_text_days` (90) then nulled**, the datasets and columns read, the masks and row filters applied, rows
   returned, bytes scanned, ms, status ok/refused/timeout/error, the refusal's rule), and an OpenLineage-shaped usage record for the
   catalog's Usage tab. The activity log gets `query.run` with the run id, dataset ids and counts — **never the SQL and never a
   value** (a WHERE clause can hold an email address).
6. **Preview** is the same door: `SELECT * FROM <key> LIMIT 100`, source `preview`.

**Metrics** (`metrics`: `name`, `title`, `description`, `dataset_id` (a product), `expression` (an aggregate — `sum(amount)`,
`count(distinct customer_id)`), `time_column`, `grains` day/week/month/quarter/year, `dimensions text[]` (the columns it may be cut
by), `filters` (fixed predicates), `format`, `owner`, `certified`): `metric_value(metric, grain, from, to, by[], where{})` compiles to
one SELECT the allow-list accepts and runs through the same door — the expert's first resort (the semantic-layer finding: a metric
fails as an error, text-to-SQL fails as a plausible wrong number).

### 6.6 Transforms, products and lineage (`app/features/transforms/`, `lineage/`)

- **Transforms** (`transforms`: `name`, `output_dataset_id`, `sql` (one SELECT over dataset keys — the allow-list's dialect), `mode`
  replace/incremental_merge (by the output's key, over the inputs' change feed), `schedule` (cron, or `after_inputs` — run when
  every input has a new snapshot), `version`, `owner`, `enabled`, `last_run_id`) run by the writer **with the transform owner's
  read level frozen at save time**, never an engineer's later break-glass — so a product never quietly widens what it exposes.
  A product is a transform's output in the `product` zone with a contract and an owner.
- **Lineage** — recorded by the machine (R15): every load, promotion, transform, export, erasure and maintenance pass writes a
  **`lineage_runs`** row in OpenLineage's shape (`run_uuid` v7, `job_kind` load/promote/transform/export/erasure/maintenance/
  query, `job_name`, `event` START/COMPLETE/FAIL/ABORT, `inputs jsonb` and `outputs jsonb` — `{namespace, name, version: snapshot_id,
  facets}` —, `facets jsonb` (`sql`, `nominalTime`, `errorMessage`, `dataQualityMetrics`, `inputStatistics`, `outputStatistics`),
  actor, agent run id, started/ended); **`lineage_edges`** (from dataset or source → to dataset, via load/promote/transform/
  export, `column_map jsonb` from sqlglot's lineage for SQL transforms (column-level where sqlglot resolves it; table-level always),
  first_seen, last_seen). The Lineage tab is one hop up and down as lists at 375 px and a small SVG graph at 1280; nodes the caller
  may not discover are shown as "a dataset you cannot see" (counted, unnamed). `GET /api/v1/lineage/events` emits the runs as
  OpenLineage JSON (an outside tool can read them).
- **Impact** — `dl_impact(dataset)`: every dataset, saved query, metric and K7 consumer downstream, so a breaking contract change shows
  whom it breaks before it is published.

### 6.7 Keeping it healthy — retention, erasure, maintenance, backups (`app/features/admin/`; the writer)

- **Retention** (`retention_policies`: zone or dataset, `keep_days`, `by_column` (event time) or load time, `action` delete/archive):
  enforced by the writer nightly — a DELETE committed as a snapshot, then expiry. Landing: `landing_keep_days` (30) after the load
  succeeded. The audit: `query_runs` text 90 days, rows 2 years; `check_results` 400 days; `column_profiles` 400 days.
- **Erasure** (`erasure_requests`: the subject — `identifiers jsonb` (kind + value: an email, a customer id, a member id), the
  scope all/zones/datasets, `reason`, `requested_by`, `approved_by` (an admin; pauses for an agent), `status` draft/scanning/
  awaiting_approval/erasing/expiring/done/failed, `found jsonb` (dataset → rows), `proof jsonb`): the writer **finds** the subject
  through every column whose `personal_kind` matches the identifier's kind (and through lineage to every dataset made from those),
  **reports before it deletes**, then on approval: DELETE in every zone (raw, curated, quarantine, product, sandboxes) as one
  snapshot per dataset → rewrite the affected files (DuckLake's rewrite of files with deletes) → **expire every snapshot that held
  the rows** and clean up the old files → purge the landing blobs of the affected loads (the load marked "landing purged — not
  replayable") → hashes of the identifiers re-checked → the proof report (datasets, rows, snapshots expired, files rewritten, blobs
  purged, the time each step ended). Backups expire on their own window (`backup_keep_days`, default 14), stated in the proof: the
  subject is gone from backups on that date (R30). Masks keyed by hash use an installation salt, so an erased subject's hash in a
  downstream aggregate cannot be reversed.
- **Maintenance** (the writer, nightly at 02:30 and on demand — `maintenance_runs`): flush inlined data, merge adjacent small files
  toward ~128 MB, rewrite files carrying many deletes, expire snapshots older than `snapshot_keep_days` (default 30 — the time-travel
  window) except those a retention or an incident pins, clean up files older than the expiry plus a safety margin of 3 days; storage
  per dataset and zone recomputed (`storage_daily`), quotas per zone and per sandbox (`sandbox_quota_bytes`, default 5 GB) (R58–R60).
- **Backups** (`deploy/backup.sh`, the owner's cron; `backups` records each): take the writer's `backup_lock` job (no maintenance
  runs while a backup is taken), `pg_dump` the database (catalog first — DuckLake's guide), then copy `lake/` and `landing/` (rsync to
  `DL_BACKUP_DIR`, encrypted at rest by the owner's target), release; a **restore drill** (`bin/restore_drill.sh`) restores into a
  scratch database and directory and runs a fixed set of queries against a known snapshot — Phase 0 proves it once, the Steward
  reports the last drill's age. **A snapshot is not a backup** (R68).

### 6.8 Record memory, and the tables against the estate

**Record memory** (PostgreSQL 17, `<tenant>_datalake`, db/001–0NN; prefix `dl_`; views `mcp_*`):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`), `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` |
| Roles | `dl_rights`, `dl_roles`, `dl_role_rights`, `mcp_app_roles` | `dl_has_right(right)` |
| Settings | `dl_settings` (one row: the query limits, `landing_keep_days`, `snapshot_keep_days`, `query_text_days`, `backup_keep_days`, `sandbox_quota_bytes`, `max_upload_bytes` (5 GB), `writer_concurrency`, key rates, the masking salt's id, `certification_target`) | seeded |
| Catalog | `datasets`, `dataset_columns`, `column_profiles`, `schema_versions`, `glossary_terms`, `dataset_follows`, `dataset_usage_daily`, `dataset_joins` | §6.2 |
| Ingestion | `sources`, `source_pulls`, `source_credentials`, `upload_sessions`, `upload_parts`, `blobs`, `import_templates`, `loads`, `load_rejects`, `lake_jobs` | §6.3 |
| Trust | `contracts`, `checks`, `check_results`, `incidents`, `incident_notes` | §6.4 |
| Access | `groups`, `group_members`, `grants` (target dataset/zone/tag, principal kind member/group/department/agent/application/everyone, `level`, `row_filter`, `expires_at`, `granted_by`, `reason`), `masking_policies` (personal kind or classification → mask), `access_requests` (requester, dataset or product, level, reason, wanted until, `status`, decider, decision note), `access_changes` (who changed what access when — "who could read this on June 3rd") | §3 |
| Queries | `query_runs`, `saved_queries` (owner, title, description, sql, `parameters jsonb`, datasets, `shared`, `verified_by/at/sql_sha256`), `metrics` | §6.5 |
| Transforms and lineage | `transforms`, `transform_runs`, `lineage_runs`, `lineage_edges` | §6.6 |
| Health | `retention_policies`, `erasure_requests`, `maintenance_runs`, `storage_daily`, `backups` | §6.7 |
| Exports | `exports` (Spaces' + `params`; kind `result`/`dataset`), `worker_passes` | |
| Agents | `agent_dispatches` (+ `dataset_id`, `incident_id`), `steward_proposals` (kind description/column_description/personal_data/owner/check/drift/unused/promotion/coverage, payload, the diff, status, decided_by/at) | §5 |
| Notifications | `notifications` (check_failed, incident_assigned, freshness_missed, schema_changed, access_requested, access_decided, certification_lost, deprecated, export_ready, load_failed, proposal, steward_note), `notification_prefs`, `notification_outbox` | Spaces' member-only shape |
| Notes, tags, search | `notes` (on a dataset, an incident, a saved query), `tags`, `record_tags`, `search_index` | |
| Keys and tokens | `mcp_access_tokens` (+ Knowledge's rate columns), `key_usage` | a person's own |
| **The lake's catalog** | schema `ducklake`: DuckLake's own 26 tables, created by the engine at first attach (by `deploy/os-provision.sh`), **owned by `datalake_lake`**; the application reads them only through `dl_lake_*` views (`security_barrier`) that **never select `encryption_key` or a file `path`** | the engine's, not ours: never altered by a migration |

**Tables against the estate — read, reuse, new** (the shared-schema rule):

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | the kit (Sales CRM / DocCloud `db/001`); `members` appends `is_agent`, `is_external` |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md`; **appends** `dataset_id`, `query_run_id` |
| `mcp_access_tokens`, `key_usage` | **reuse** | HR `db/003` + Knowledge's rate columns |
| `dl_rights`, `dl_roles`, `dl_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `dl_`) |
| `dl_settings` | **reuse** (skeleton) | the `app_settings` singleton |
| `sources`, `source_pulls`, `source_credentials` | **reuse** | **Inventory `db/009` canonical**, verbatim; `connector` widened (`upload`, `http_file`, `sftp_file`, `k7`, `postgres`, `k37_feed`); `role` widened with `internal_app`, `outside_db`; `supplier_id` kept nullable with no FK (SI's precedent) |
| `upload_sessions`, `upload_parts`, `blobs` | **reuse** | **DocCloud `db/007` canonical**, verbatim; `upload_sessions.folder_id` kept nullable and unused, **appends** `source_id`, `target_dataset_id` |
| `import_templates` | **reuse** | **Shareholder Intelligence `db/014`** (connector, mapping, sample, unique name); the CRM's divergent copy recorded for the catalogue |
| `search_index` | **reuse** | **Spaces `db/013`**, verbatim; **appends** `dataset_id`, `zone`, `classification`, `certification`, `owner_member_id`, `department_id`, `usage_30d`; `entity_kind` widened with `dataset`, `glossary_term`, `saved_query`, `metric` |
| `groups`, `group_members` | **reuse** | **DocCloud `db/006` canonical**, verbatim (custom or a department mirror) |
| `tags`, `record_tags` | **reuse** | Help Desk `db/009` + DocCloud's appended columns; Sales CRM `db/006`'s polymorphic `record_tags` (`record_type` widened) |
| `notes`, `notifications`, `notification_prefs`, `notification_outbox`, `agent_dispatches`, `exports`, `worker_passes` | **reuse** | GL `db/009`, Spaces `db/012`, GL `db/014`, Spaces `db/016` + `023`; enumerations widened; `agent_dispatches` appends `dataset_id`, `incident_id` |
| `access_changes` | **reuse** | **DocCloud** (`access_changes`); `target` widened with `dataset`, `zone`, `tag` |
| `datasets`, `dataset_columns`, `column_profiles`, `schema_versions`, `glossary_terms` | **new** — canonical, "the estate's data catalog" | nothing close (no `datasets`, `catalog`, `schemas` or `columns` table anywhere); Spaces' `databases` (a Notion database with properties) compared: a user-built table inside a page, no zones, no lineage, no classification |
| `contracts`, `checks`, `check_results`, `incidents`, `incident_notes` | **new** — canonical | SI's `signal_rules` / `signals` compared: the same family (a rule → a raised thing a person disposes of), different subject (market evidence vs. data quality); recorded as siblings |
| `grants`, `masking_policies`, `access_requests` | **new** — canonical | DocCloud's `folder_access` compared (levels on a tree node for principals): the principal kinds copied (`member`, `group`, `department`, `agent`, `application`, `everyone`), the target and the levels are the lake's |
| `loads`, `load_rejects`, `lake_jobs`, `transforms`, `transform_runs`, `lineage_runs`, `lineage_edges`, `query_runs`, `saved_queries`, `metrics`, `retention_policies`, `erasure_requests`, `maintenance_runs`, `storage_daily`, `backups`, `dataset_follows`, `dataset_usage_daily`, `dataset_joins`, `steward_proposals` | **new** | `pipelines` (CRM), `runs` (ProcessCore), `builds` (Knowledge), `imports` (Spaces/CRM — an import into an application's own records with rollback, not a load into a table) compared and avoided; `steward_proposals` takes DocCloud's `clerk_proposals` shape |
| HR's employment, the ledger's books, the CRM's relationship, Help Desk's tickets, Projects' issues, Inventory's catalog and stock, Consultant Tracking's engagements and time, Reservations' covers, txtSchedules' shifts, the kernel's directory | **read — as dated extracts** (D4) | through K7 shares now and K37 feeds later; the provider stays the system of record; never a direct database read |

**Owed to the catalogue** (`shared-schema.md`, in the plugin): the lake's canonical tables above; **the lake's exception to "read, never
copied"** (D4) in §2's table; `import_templates`' divergence (SI vs CRM) resolved or recorded; `upload_sessions`' appended columns.

**Views and functions**: `mcp_*` over every application table with `security_barrier` and the per-row gate; credentials, the masking
salt, token hashes, `encryption_key` and file paths never selected; the reads that matter are SQL functions — `dl_my_principals()`,
`dl_can_discover(dataset)`, `dl_level(dataset)`, `dl_query_policy(caller, on_behalf_of, datasets)`, `dl_access_explain(dataset,
member)` ("Sam reads this masked because: member of Finance → grant on zone curated → read; personal columns masked; no
read_unmasked"), `dl_search(...)`, `dl_certification_eligible(dataset)`, `dl_impact(dataset)`, `dl_lineage(dataset, depth)`,
`dl_storage_by_dataset()`, `dl_freshness_due()`, `dl_steward_report()`; the engine is reached only from `services/` (the writer and the
query service) — PHP talks to them over the Unix socket (`app/lake/client.php`, the ONE file that does).

**Activity memory**: `activity_log` through one `log_activity()`, shipped to the tenant's one MaluDB as `activity` episodes tagged
`datalake`. **A row value, a profile's top values, SQL text, a filter, a credential, a token, a file path are never in a log payload**
(ids, keys, names, counts, kinds, statuses, byte sizes). Events: `dataset.create|describe|tag|own|steward|deprecate|retire|certify|
uncertify|admin_read`, `column.describe|classify|confirm|tag`, `glossary.create|approve|link`, `upload.start|finish|map`,
`source.create|update|pause|resume|delete|credential_set|test`, `load.queue|run|fail|replay|rollback`, `contract.draft|publish`,
`promote.run|fail`, `check.create|update|run|fail|disable`, `incident.open|ack|assign|note|resolve|dismiss`, `query.run|refused|
timeout`, `saved_query.save|share|verify|unverify|delete`, `metric.create|update|certify`, `transform.create|update|run|fail|delete`,
`export.make|ready|download|expire`, `grant.set|revoke|expire`, `policy.set`, `access.request|approve|deny|expire`,
`retention.set|run`, `erasure.request|scan|approve|run|done|fail`, `maintenance.run`, `backup.run|drill`, `steward.propose|accept|
edit|dismiss`, `agent.dispatch|reply|fail`, `settings.save`, `share.read`, `key.mint|rotate|revoke|rate_limited`,
`token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`.

### 6.9 What the engine proof changed (Phase 0, 2026-10-10 — `tests/engine/FINDINGS.md`)

The engine proof (`tests/engine/run.sh`: CPU 9, engine 75, query service 73 — **157 checks**; `timing.sh`: 50 million rows over 200
datasets) confirmed D2 on this host and changed the design in these places — each is now built into the schema, `deploy/` or `services/`:

| Finding | Where it lives now |
|---|---|
| DuckLake's ENCRYPTED writes need the `httpfs` extension (DuckDB's built-in crypto only decrypts); `httpfs` can reach URLs | the writer loads `httpfs`, the query service never does; four vendored extensions; the writer's unit `IPAddressDeny=any` + `IPAddressAllow=localhost` |
| The writer's role needs `TEMPORARY`; the query role needs SELECT on catalog tables created later; a DuckLake connection can sit idle in transaction | `deploy/os-provision.sh`: `GRANT TEMPORARY`, default privileges, `idle_in_transaction_session_timeout` on both engine roles |
| `duckdb_databases()` prints an attached catalog's connection string verbatim | the engine's DSNs carry a libpq **passfile** (`/etc/datalake/{lake,query}.pgpass`, 0600, one per service user), never a password |
| SELECT on the catalog + read access to `lake/` = every row (per-file keys in `ducklake_data_file`); inlined rows are plain; column statistics hold real values | the web application's and read servers' roles hold nothing on schema `ducklake`; `db/engine/001_lake_views.sql` exposes sizes, counts, names, types and commits only, behind the dataset wall |
| Small loads (≤ 10 rows) stay as plain PostgreSQL rows until flushed — in WAL and backups too | the worker flushes inlined data every `inline_flush_minutes` (60), not only at night (db/017) |
| An erasure missed a subject's rows keyed by a reference (orders by `customer_id`) | `dataset_columns.references_dataset_id/_column`; `dl_erasure_targets()` follows references; `verify()` stays independent of the scan (db/017) |
| Expiring snapshots is lake-wide: an erasure ends time travel before *now* for every dataset | an approval must acknowledge it (`dl_erasure_approve(id, true)`); the screen says it before the button (db/017) |
| After the lock the attached lake is still reachable by name; `ducklake_list_files()` returns the keys | the allow-list is load-bearing: a random alias per request, the `catalog_reference` refusal, every table function refused (the list computed from the engine at start); the `dl_` prefix reserved |
| A transform reads through its owner's views | a product made from masked inputs is masked for good; `incremental_merge` only for row-wise SQL (an aggregate or a window must be `replace`) |
| DuckDB's `memory_limit` does not bound the process (peak 3,569 MB under 3 GB) | the writer's `MemoryMax` 4 GB; each query worker's 2.5 GB |
| Promotion has a fixed cost of ~5 s per small dataset at 400 tables (catalog-wide metadata lookups per call) | slice 5's spec: no catalog-wide lookups per call |
| Timings on this host | load 50 M rows 286 s · promote 1,042 s · a filtered query over 25 M rows 3.8 s, an aggregate 4.7 s, a join 3.7 s, a masked scan 6.7 s · maintenance over 400 tables 128 s · erasure scan 98 s, erase 135 s, verify 335 s · 3.3 GB encrypted for 50 M raw + 50 M curated rows |

## 7. The question inventory — what the Data Lake exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is filtered by
what the asker may see — and an agent in a chat turn by what the person may see). The tool name is fixed here so Phase 1 is a
transcription.

**Finding data (discoverable)**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| C1 | **What data do we have about X?** — search across datasets, columns, glossary, saved queries and metrics, certified first | R | Member | `catalog_search` |
| C2 | What is dataset X — description, owner, steward, department, zone, certification, freshness, size, rows, as-of, contract? | R | Member | `get_dataset`, `dataset_describe` |
| C3 | What are X's columns — types, business names, descriptions, classification, personal kind, profile? | R | Member | `dataset_columns` |
| C4 | Show me some rows of X (masked as I may see them) | R | Member | `dataset_preview` |
| C5 | Which datasets are in zone Z / department D / tagged T / owned by P; which are certified; which are deprecated? | R | Member | `find_datasets` |
| C6 | What does term T mean, and which columns carry it? | R | Member | `glossary`, `get_term` |
| C7 | Which datasets are most used, by whom, and which are joined with X? | R | Member | `dataset_usage`, `popular_datasets` |
| C8 | Which datasets lack a description, an owner or a classification; what is our coverage against the target? | R | Engineer | `catalog_gaps`, `coverage` |

**Using data (usable)**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| Q1 | **Run this SQL** (the allow-listed dialect, through the query service, as me) | R | Analyst (Member for an agent's own SQL in a chat turn, bounded by the person) | `query_run` |
| Q2 | What is metric M by grain and dimension, over a period, with filters? | R | Member | `find_metrics`, `metric_value` |
| Q3 | Which saved and verified queries exist; run one with parameters | R | Member | `find_saved_queries`, `run_saved_query` |
| Q4 | What did query run R return, from which datasets, with which masks? | R | Member (own) / Admin | `get_query_run` |
| Q5 | What is in my sandbox; how much of my quota is used? | R | Member | `my_sandbox` |
| Q6 | Which exports did I make; which are ready? | R | Member | `my_exports` |

**Trusting data (trustworthy)**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| T1 | **Can I trust X?** — checks and their last results, freshness, open incidents, certification and what it lacks | R | Member | `dataset_health` |
| T2 | Which checks failed today; which datasets are late; which incidents are open, assigned to whom? | R | Member | `failing_checks`, `late_datasets`, `open_incidents` |
| T3 | What is incident I — its check, its history, its notes, what it reaches downstream? | R | Member | `get_incident` |
| T4 | **Where does this number come from?** — X's upstream to its sources, with loads and transforms | R | Member | `lineage_upstream` |
| T5 | What would break if X changed — downstream datasets, saved queries, metrics, consumers? | R | Member | `lineage_downstream`, `impact` |
| T6 | How has X's schema and profile changed over time; what drifted at the last load? | R | Member | `schema_history`, `profile_history` |
| T7 | What is X's contract (as ODCS)? | R | Member | `get_contract` |
| T8 | What did X look like as of a date or snapshot? (through the query service) | R | Analyst | `query_run` (`as_of`) |

**Protecting data (secure)**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| S1 | **Who can read X, at what level, with which masks and filters, and why?** | R | Member (owner) / Admin | `access_list`, `access_explain` |
| S2 | What may I read; what have I requested; what awaits my decision? | R | Member | `my_access`, `my_access_requests`, `access_inbox` |
| S3 | Which columns hold personal data, of which kind, confirmed or proposed? | R | Engineer | `personal_data_map` |
| S4 | Who read dataset X (or a personal column of it) this month — people, agents, siblings, keys? | R | Owner / Admin | `dataset_readers` |
| S5 | Who could read X on a given day? | R | Admin | `access_history` |
| S6 | Where does subject S appear across the lake (an erasure's scan)? what did erasure E remove, and when are backups clear? | R | Admin | `erasure_scan`, `get_erasure` |
| S7 | Which grants expire this week; which are unused for 90 days? | R | Admin | `grants_expiring`, `grants_unused` |

**Building and running the lake**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| I1 | Which sources exist, their connector, schedule, last pull and state; which are paused and why? | R | Engineer | `find_sources`, `get_source` |
| I2 | What did load L do — rows read, loaded, rejected, rescued, the schema seen, the checks' outcome? | R | Member (the dataset's readers) | `get_load`, `find_loads`, `load_rejects` |
| I3 | Which transforms exist, their inputs, schedule, last run? | R | Analyst | `find_transforms`, `get_transform` |
| I4 | How much storage does each zone and dataset use; which sandboxes are near quota? | R | Engineer | `storage_by_dataset`, `quota_state` |
| I5 | When did maintenance, the last backup and the last restore drill run, and how did they end? | R | Admin | `maintenance_state`, `backup_state` |
| I6 | Is the engine healthy — writer queue, query workers, refusals and timeouts today? | R | Admin | `engine_state` |

**Agents, proposals, keys**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| P1 | What did the Steward propose, and what was accepted? | R | Member (own datasets) / Engineer | `steward_proposals` |
| P2 | Which dispatches are pending or failed? | R | Admin | `agent_dispatches` |
| P3 | Which keys exist, their limits, last use? | R | Member (own) / Admin | `find_keys` |
| P4 | Which siblings read which products and metrics, and how often; which sibling sources fed which datasets? | A | Admin | `share_reads` |

**Activity**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| ACT1 | Who did what to dataset X, in order (loads, descriptions, grants, certifications)? | A | Member (what they discover) | `record_history` |
| ACT2 | What did person, key or agent X do here this week? | A | Engineer | `actor_timeline` |
| ACT3 | What changed in the lake since I last looked? | A | Member | `recent_activity` |
| ACT4 | Search the activity for a phrase | A | Member | `activity_search` |

Plus the contract's: `app_roles`, one guarded `search` on each server (the records one IS C1), `record_history`. Records tools: about
62; activity tools: 7.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from Sales CRM / DocCloud; groups of the
  `department` kind follow the directory sync.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); **K38** — the run facts' `requested_by` — makes a
  chat turn's queries the intersection of the agent's and the person's access (K38 BUILT 2026-10-10); on a kernel without it, the expert's `query_run` and
  `dataset_preview` **refuse in a chat turn** any dataset above `internal` or with personal columns, saying why (fail closed, never
  open); the Actions MCP from the registry; the approval hook before every action with a category; the chat endpoint for the command
  bar, the ask window and dispatches; the duty for the Steward.
- **No model key, no model call** — the application never calls a model: descriptions are drafted by the Steward in the kernel and
  arrive as proposals; nothing needs K24.
- **Texts (K6)**: an incident's assignee and a blocking failure on a certified dataset's owner (opt-in).
- **The API** (bearer = a person's key; allow-listed in the vhost): `GET /api/v1/datasets?q=` (the catalog), `GET
  /api/v1/datasets/{key}` (+ `/columns`, `/health`, `/lineage`, `/contract.odcs.yaml`), `GET /api/v1/datasets/{key}/rows?page=` (a
  product, paged ≤ 1,000, masked), `POST /api/v1/queries/{id}/run {params}`, `POST /api/v1/metrics/{name} {grain, from, to, by,
  where}`, `POST /api/v1/sql {sql}` (needs `sql.editor` and the key's switch), `POST /api/v1/upload` (into the person's sandbox; parts
  beyond 64 MB), `GET /api/v1/lineage/events` (OpenLineage JSON), `GET /api/v1/health`. 401, 403 (with the rule), 413, 429 with
  `Retry-After`, 504 `query_timeout`. Logged with source `api`.
- **K7 — the lake as a provider** (`shares[]`; every one a records-server tool, people-free, answering only for products and metrics
  granted to the consumer as an `application` principal, masked as for a Member, the consumer from the K26 headers):
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `datasets_index` | any sibling, Knowledge, Spaces | `os.datalake-datasets/1` | the products granted to the consumer: key, title, description, columns, as-of, certification |
  | `product_rows` | the GL, Spaces' databases, Projects' reports | `os.datalake-rows/1` | a granted product's rows, **paged ≤ 500 and ≤ 256 kB**, `since` by the product's ordering column; `timeout_seconds` 30 |
  | `metric_value` | any sibling's home or report | `os.datalake-metric/1` | a certified metric granted to the consumer by grain and dimension over a period; `timeout_seconds` 30 |
- **K7 — the lake as a consumer** (`reads[]`). **Version 1 — the owner's (c), 2026-10-10:** the GL's `account_balances`,
  `ar_aging_summary`, `ap_due_summary`; Inventory's `sales_closed` (paged); Sales CRM's `deals_won` (paged, `since`). **Later, one line
  each** — the other period summaries and indexes the siblings share today: Inventory `purchases_received`, `stock_valuation`;
  ProcessCore `shipments_by_period`, `inventory_valuation`; Consultant Tracking `invoices_issued`, `credit_notes_issued`,
  `expenses_by_category`, `unbilled_summary`, `engagement_list`; Sales CRM `accounts_index`; Shareholder Intelligence
  `ownership_summary`, `events_index`; Reservations `covers_by_service` (per site); DocCloud `documents_index` (metadata only).
  **Never** a `people: true` share (HR's alone), never Spaces' pages (they answer as the consumer's expert — Extended).
- **Public doors** (allow-listed in the vhost): `/api/v1/*` (key-checked), `/mcp/records`, `/mcp/activity`. No `auth_kind: none`
  door at all.
- **Live-ness without a socket**: an upload reports per part; the mapping screen polls its sniff; the load page polls every 5 s while
  its load runs; the SQL editor posts and swaps the result (a running query shows a cancel button that sends `interrupt`).

## 9. Screens (the nxl look; the reading screens at 375 px first; the SQL editor, the mapping, the transform and policy editors and
the lineage graph are desk screens that still render at 375 without horizontal scroll; no modals but the record picker; **cards for
datasets, sources, products and metrics; tables for columns, rows, loads, check results, query runs and grants** — line items stay
tables; every name a link; every page a way back)

**The shell**: a sidebar at 1280 (Home · **Search** · Browse · Products · Metrics · **Ask** · **SQL** · Queries · My sandbox ·
Upload · Sources · Loads · Transforms · Quality · Access · Glossary · Steward · Admin), a bottom tab bar at 375 (Home · Search · Ask ·
Me). The command bar at the top of every screen. The application switcher in the header.

**Home** — the search box with *Search* and *Ask*; certified products; my recent and followed datasets; popular this month; open
incidents on datasets I use; access decisions awaiting me; engineers: failed loads, late datasets, the Steward's proposals; admin:
the engine's state, storage, the last backup and drill. **A new person's home is one button: "Upload a spreadsheet"** (and "Connect a
source" for engineers).

**Catalog**: `search` (`/search?q=`: the box, facet chips, results as cards with zone, certification badge, health dot, owner,
"data updated", row count; stubs for what I may not discover) · `browse` (zones → datasets; departments → datasets; tags) ·
**`dataset`** (`/datasets/{key}`: the header — crumbs, the certification / deprecated badge, the health dot, owner and steward,
"data updated" and "metadata updated", rows, size, **Query this**, **Request access**, **Follow**; the tabs **About** (description
with its provenance, purpose / usage / limitations, tags, glossary terms, the contract's summary), **Columns** (a table at 1280,
stacked cards at 375: type, business name, description, classification, personal kind, null %, distinct %), **Preview** (100 rows,
masked, "masked for you" on each masked column; at 375 one record at a time), **Quality** (checks, last results, a 7-day strip,
incidents, certification and what it lacks), **Lineage** (one hop up and down as lists; "open the graph" at 1280), **Usage**
(frequent users, queries, saved queries and metrics over it, datasets joined with it), **Access** (who reads it and how — the
explanation; requests), **History** (loads, schema versions, metadata changes, the trail)) · `column` (one column: profile history,
classification history) · `glossary` · `term` · `products` (the gallery: certified product cards) · `metrics` · `metric`.

**Use**: **`ask`** (`/ask`: the conversation with the expert through the kernel's chat endpoint; each answer shows the
interpretation, the result as a table (or a simple bar or line chart for a series), "Show SQL", the datasets and the metric or
verified query used, the query run link, "rows were masked" when they were; feedback *Yes / Fix it / Ask a steward* — the last opens a
steward proposal) · **`sql`** (`/sql`, desk: the dataset browser, the editor (a textarea with the keys of my datasets for
completion from the server), Run / Cancel, the result table with the display cap and the truncation notice, per-column quick stats,
Save, Download (an export), **Save as dataset** (to my sandbox), the history of my runs; a refusal with its **Request access** link)
· `queries` (saved and verified, mine and shared; the **Verified** badge) · `query` (its SQL, parameters, the run page that shows the
result only) · `query-run` (one run: SQL, datasets, masks, rows, time — mine, or any for the admin) · `sandbox` (my datasets, quota)
· `exports` (mine).

**Build** (engineers; desk): `upload` (the drop zone, the per-file list) → `mapping` (`/uploads/{id}/map`: 50 rows, each column's name,
type, classification proposal, the target, the key; save as template) · `sources` (cards; add: the connector's form, test, schedule,
the credential sealed) · `source` (pulls, state, pause / resume) · `loads` (the table; filters) · `load` (counts, rejects with their
lines, rescued fields, the checks' outcome, rollback) · `contract` (the typed schema, the key, the SLA, publish — breaking shown) ·
`transforms` · `transform` (the SQL, inputs, mode, schedule, runs, the lineage it makes) · `lineage-graph` (1280: the SVG of a
dataset's neighbourhood, two hops; nodes I cannot see counted, unnamed).

**Quality**: `quality` (failing checks today, late datasets, open incidents — at 375 a status list) · `incidents` · `incident`
(acknowledge, assign, note, resolve — phone-first) · `checks` (a dataset's checks; the editor with templates: not null, unique,
accepted values, range, regex, row count, freshness, referential, custom SQL).

**Access**: `my-access` · `requests` (tabs *Mine* / *To decide* / *History*; approve with a level and an optional expiry, a reason
required — phone-first) · `grants` (a dataset's, a zone's, a tag's; add a principal by the picker; row filter; expiry) · `policies`
(admin: classifications and their masks, personal kinds, row-filter templates) · `personal-data` (the map: columns by kind,
proposed vs confirmed).

**Steward**: `steward` (the inbox: drafted descriptions, personal-data proposals, owner proposals, drift, unused datasets,
promotion proposals — each accept / edit / dismiss with the diff) · `coverage` (described / owned / classified / checked, by tier and
department, against the target).

**Admin**: Settings (limits, windows, quotas) · Retention · **Erasure** (`erasure-new`: identifiers → the scan's report → approve →
the run's progress → the proof) · Maintenance and storage · Backups and drills · Query audit (every run, filters) · Engine (the
writer's queue, the workers) · Keys (all) · Agents · Connections (K7 reads and shares) · Worker · My settings · My MCP tokens and keys.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers; **the schema** `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; the walls of §3 for ten callers incl. an external member, an agent alone, an agent in a person's chat turn (the intersection), a K7 consumer and the admin's break-glass; discover vs read vs read_unmasked with masks by kind; a zone grant and a tag grant covering a new dataset; a row filter; the stub for a confidential dataset; certification computed and lost; a check failing into an incident that a passing run resolves; access requests decided; the audit's retention; `search_index`, `sources`, `upload_sessions`, `groups` verbatim against the siblings); **the engine proof** (`tests/engine/`, on a scratch catalog database and directory: DuckLake attached with `METADATA_SCHEMA` and `ENCRYPTED`; a CSV, an XLSX with two sheets and a JSON landed, raw loaded all-VARCHAR with provenance, the same file again a no-op; promotion typed by a contract with a quarantined row; a transform incremental over the change feed; time travel and a load rollback as a new snapshot; inlining flushed; merge, expiry and cleanup; an erasure that leaves no trace in any snapshot or file (the bytes searched); the backup and **a restore drill**; **the query service's sandbox** — the allow-list refusing each forbidden form, a masked view, a row filter, `read_csv('/etc/passwd')` refused, the watchdog at the timeout, the memory cap, a worker killed and replaced; `tests/engine/cpu.sh` smoke-testing every pinned wheel on this CPU; timings at **50 million rows** over 200 datasets); the kit without a kernel (`tests/phase0/run.sh`); `maludb-os.json`, `os/{expert,steward}.md`, nine skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (ports, the system users, the data directory and its modes, the venv and the vendored extensions, `os-provision.sh` — the two engine roles and the catalog's first attach); the installer's `plan` clean | the owner's go |
| 1 | `docs/datalake-mcp-tool-surface.md`, `docs/datalake-action-manifest.md`, `mcp/action_registry.json`, `docs/build-specs/lake-engine.md`, `docs/build-specs/query-service.md`, `docs/build-specs/access-and-policies.md`, and the slice specs (`sso-shell` and ten slices; **uploads-and-loads, the query service and promotion-and-quality are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles`, `dl_has_right()`, the ingest bridge, the shell with the record picker and the switcher, `/api/v1/health`, the vhost allow-list, `app/lake/client.php` with `engine_unavailable`, the writer and the query service running empty under systemd | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; the query service refuses a request not from the application's group; 375 and 1280 |
| 3 | the slices, in order: **(1) the catalog** (the CRUD exemplar: datasets, columns, glossary, tags, owners and stewards, follows, the dataset page with About / Columns / History, search and browse, `search_index`) → **(2) uploads and loads — THE FIRST EXEMPLAR** (parts, landing, the sniff, the mapping, templates, raw loads by the writer, rejects and rescued fields, profiles, drift proposals, the Preview tab through the query service's preview door) → **(3) the query service and the SQL editor — THE SECOND EXEMPLAR** (the allow-list, `dl_query_policy`, the views, the limits, `query_runs`, the editor, saved queries, verified queries, as-of, save as dataset, exports with their limits, the Usage tab) → **(4) access and policies** (grants on datasets, zones and tags; groups; masks by classification and personal kind; row filters; the classifier's proposals at load; access requests; `access_explain`; break-glass; the Access tab; the personal-data map) → **(5) contracts, promotion, checks, incidents and certification — THE THIRD EXEMPLAR** (contracts and ODCS export, promotion with quarantine, the check vocabulary, freshness, incidents, computed certification and its loss, the Quality tab) → **(6) sources and pulls** (Inventory's sources verbatim, `http_file`, `sftp_file`, `k7`, `postgres` with its refusal, schedules, credentials, pauses) → **(7) transforms, products, metrics and lineage** (transforms and their runs, the product zone, metrics and `metric_value`, `lineage_runs` / `lineage_edges`, the Lineage tab and graph, impact, the OpenLineage export) → **(8) retention, erasure, maintenance and backups** (retention jobs, the erasure pipeline and its proof, maintenance, storage and quotas, backup and the drill) → **(9) the API, keys and K7** (the routes of §8, keys with rates, the three shares, the consumer's `reads[]` through the `k7` connector) → **(10) agents, the Steward, notifications, home and admin** (the two agents' tools and actions, dispatches, the steward report and proposals, the ask window, notifications and prefs, K6 texts, the homes, coverage, settings, the query audit, the engine page) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database and data directory |
| 4 | the two MCP servers with the run-facts gate (K38's `requested_by` read when present, fail closed when absent), `app_roles` and the three `shares[]` admitted to the kernel's token (the K26 headers read), the registry wrapper, the two agents with their grants and the duty; the Actions-MCP contract proved against the kernel's `application_actions.register()` | the command bar answers "what were last month's sales by region" with a metric, shows the SQL and the datasets, and masks what the person may not see; an agent's `export_make` pauses; the Steward grants nothing, exports nothing, deletes nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first; the data directory on the right disk; the system users; the vendored extensions), DNS and TLS, the hires, **a real spreadsheet and a real sibling's share** | **an engineer drops last year's sales workbook on *Upload* at a desk, confirms the mapping, and within a minute the dataset has a catalog entry, a profile, a preview and two checks; a contract types it into curated with three bad rows quarantined; a transform makes `product.monthly_revenue`, certified; a Member on a phone searches "revenue", sees the certified product first and asks the expert "what was revenue by month last year" — the answer is the metric with its SQL shown; an Analyst's SQL that names a confidential dataset is refused with a Request access link the owner approves on a phone; a personal column the classifier found is masked for the Analyst and visible to the owner; the GL's `account_balances` arrives every night through K7 with lineage back to its share; an erasure of one customer email finds it in four datasets, removes it, and the proof shows every snapshot that held it expired; the backup restores in the drill; every query is in the audit, and the kernel's statement shows the expert's model calls** |

**Extended** (§11). Size: eleven specs (sso-shell + ten slices), about 75 screens, 105 actions, 62 records tools, 7 activity tools,
plus two Python services (the writer and the query service) — the first application of the estate with an engine of its own. **Five
to six days is the honest estimate**: the engine proof and the schema one, the catalog and loads one, the query service and access
one, quality and sources one, transforms, health, the API and the rest with Phase 5 the remainder — the Spaces division (D16).

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **dashboards** (saved queries and metrics as tiles on a page, the kernel's old
reporting model — `report_definitions` naming a tool, never SQL) and **alerts** (a saved query that emails when it returns rows or a
metric crosses a threshold) · **K37 feeds** from the siblings (each provider's first feeds, §12) · **`rest_json`** (a REST API with
paging and a key) and **`maludb_activity`** (the tenant's activity episodes as a dataset — who did what across the applications, under
MaluDB's principal scopes) · **unstructured files** ("volumes": PDFs and images with a metadata table under the same grants; their
text through DocCloud's or Knowledge's K7 shares) · **a catalog graph** in MaluDB (`/v1/graph/import` of datasets, columns, terms and
lineage into a memory database of the lake's own, for "which datasets are about churn" by meaning) · **Presidio** for free-text
personal data · **BI over a wire protocol** (a read-only PostgreSQL-wire or Flight SQL front of the query service, so a desktop BI
tool connects as a person) · **notebooks** · **data products with subscriptions** (Starburst's and SageMaker's) · **outside sharing**
(an expiring, logged link to a product's export) · **DCAT 3 / schema.org export** · **learned anomaly detection** on profiles ·
**Iceberg export** of a product (an exit and an interoperability door) · **evals** of the expert against a set of questions with
expected metrics.

**Later**: streaming and log-based change capture; multi-node compute; branching; a lake shared between two installations.

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K36** | **Catalog entry `datalake`** (kind `ours`, Technology & Infrastructure, category `database` — already admitted by db/172 and db/175 —, `feather-database`); on request, not a default (D16) | owed: kernel `db/179`, after the owner's go |
| **K37** | **Dataset feeds** — a provider declares `feeds[]` in `maludb-os.json` (`name`, `description`, `columns[{name, type, classification, personal_kind}]`, `key`, `watermark`, `people`), the installer records them, **a super-admin approves each feed for a consumer** (`bin/app_connection.php` gains `--feed`), the consumer asks `POST /api/v1/apps/feed.php {provider, feed, cursor}` → the kernel checks the connection, mints a 60-second single-use feed token and answers the provider's loopback URL; the provider answers a page (≤ 10,000 rows, ≤ 16 MB, NDJSON or Arrow IPC) and the next cursor, the bytes never through the kernel's PHP; every page logged `application.feed` (counts, never rows); a `people: true` feed only with the owner's explicit approval and **pseudonymised member ids by default** (the owner's (d), 2026-10-10) — and the plugin's rule amended (D4) | owed: kernel spec + db/180; the plugin 0.10.0 (`sms-and-reads.md` → `feeds`; `shared-schema.md`'s exception) |
| **K38** | **Run facts carry who asked** — `POST /api/v1/runs/facts.php` answers `requested_by_member_id` (from `agent_runs.requested_by`) and whether that person holds this application, for a `chat` run (and a dispatched run that names a person); the application intersects the agent's and the person's access. Until then the lake fails closed (§8) | **built** 2026-10-10 at the owner's choice (kernel 8c9b6fa; `run_facts_requester()`; plugin `agents.md` daa5640; proof 8 checks) |
| **K39** | **The installer and two runtimes, and template units** — `bin/app_install.php` builds one venv (`runtime.python`) and enables only `.service`/`.timer` units: the lake's engine venv (`services/.venv`) is built by `deploy/ROOT_STEPS.sh` and its query pool (`datalake-query@.service` + `datalake-query.target`) installed and started there. Owed: `runtime.python` as a list, a `.target` enabled, an `@`-template rendered and skipped by `enable` | owed: kernel installer; worked around in ROOT_STEPS |
| K26 | K7 tells a provider who asks; `timeout_seconds` | **built** (db/173) — `product_rows`, `metric_value` declare 30 |
| K16 | K8 (wake an agent from an application) — owed to every sibling | the chat-turn dispatch until then |
| K6 | texts to a member | **built** — an incident's text |
| — | **Feeds owed by the siblings once K37 exists** (first candidates, the owner's order): the GL's journal lines and invoices (G5), Inventory's sales and stock movements (INV4), Sales CRM's deals and activities (CRM1), Help Desk's tickets (HD6), Projects' issues (P5), ProcessCore's runs and shipments (PC1), Consultant Tracking's time and invoices (CT5), txtSchedules' shifts (TS1), HR's headcount (H4 — people) | each sibling's spec when K37 lands |
| — | MaluDB: **nothing owed in version 1** — the lake keeps no memory database of its own and calls no model; its activity goes to the tenant's memory as every sibling's | the catalog graph is Extended (`/v1/graph/import` exists) |
| — | **This host** (the owner; decided 2026-10-10, §15): **a new Proxmox virtual disk → `lake-vg` → mounted at `/srv/lake`** (the volume group has no free space today); **the VM's CPU type to `host` / `x86-64-v3`** (a restart); `/var/crash/_usr_bin_python3.12.1000.crash` left by the research's Polars test — remove it. Both Proxmox changes fit one maintenance window; Phase 0's engine proof runs on a scratch directory before either | the owner's, before Phase 5 |

## 13. The decisions (recommendations; the owner's answers go in §15)

| # | Decision | Recommendation | Alternative |
|---|---|---|---|
| D1 | Name, key, label, area | **"Data Lake", key `datalake`, DNS label `datalake`** (the owner, 2026-10-10), database `<tenant>_datalake` (the application's tables + the lake's catalog in schema `ducklake`), roles `datalake_rw` / `_records_ro` / `_activity_ro` + the engine's `datalake_lake` (writer) and `datalake_query` (reader), prefix `dl_`, Technology & Infrastructure, category `database` (no widening) | category `analytics` (both checks widened, the K30 lesson) |
| D2 | The foundation | **DuckLake 1.0 on a PostgreSQL catalog in our own database + Parquet on local disk + DuckDB 1.5.6 pinned, embedded in two services of our own (the writer, the query service)**; extensions vendored; no Polars in v1; no object storage; **no memory database of the lake's own** | Iceberg v2 through PyIceberg's `SqlCatalog`; PostgreSQL-native tables — both in §0.3 |
| D3 | Zones | **landing → raw → curated → product, plus sandbox and quarantine**; raw all-text with provenance, append-only, replayable; curated typed by a contract; products owned and certified | two zones (raw, curated) — simpler, loses the product promise |
| D4 | The estate rule | **An amendment by name: a lake extract is a dated, read-only, provenance-carrying extract, never a copy of a table; the provider stays the system of record; erasure reaches the lake; v1 reads the siblings' existing paged K7 shares; K37 dataset feeds for bulk; never a sibling's or the kernel's database** | read only what K7 gives today and never ask for K37 (the lake then holds summaries, not the business's data) |
| D5 | Roles | **Four: Member (`user`), Analyst, Data engineer, Lake admin**; owner and steward are rows on a dataset; an external member discovers only what names them | five, with a Steward role — not needed: stewardship is per dataset |
| D6 | Access and the one door | **Data deny-by-default; the catalog open by classification (`public`/`internal` discoverable, `confidential`/`restricted` a stub); levels discover / read (masked) / read_unmasked / write / own; grants to member, group, department, agent, application, everyone, on a dataset, a zone or a tag, with row filters and expiries; masks by classification and personal kind; ONE query service the only reader; the web application cannot read the files or the catalog's keys; DuckLake per-file encryption on; the admin reads rows only by a logged break-glass** | an open catalog including restricted names; encryption off (faster, a stolen disk readable) |
| D7 | Query safety and limits | **Four layers (allow-list, per-caller views, locked DuckDB, systemd sandbox); 30 s on a screen, 60 s for an agent or the API, 300 s for an export; 1,000 rows shown, 100,000 downloaded; 3 workers × 1.5 GB; 2 concurrent per person, 30 a minute per agent** — all settings | PostgreSQL's `statement_timeout` model via an FDW — rejected: no engine inside the shared cluster |
| D8 | Agents and whose access | **An agent in a person's chat turn reads the intersection of its access and the person's (K38); a duty reads as the agent; no agent holds `read_unmasked` by default; fail closed until K38** | agents read with their own grants (DocCloud's rule) — rejected for data: the field's anti-pattern (a shared identity) |
| D9 | Trust | **Checks in ODCS's vocabulary on every load and on schedule; blocking checks quarantine rows; incidents with lineage's downstream; freshness warn/error; certification computed, set by an engineer, lost by itself; verified queries lose the badge on edit; contracts exported as ODCS v3.2.0** | manual certification badges (sticky — the anti-pattern) |
| D10 | Personal data | **Deterministic classifiers on every load (column names, patterns with validators — Luhn, IBAN mod-97, emails, phones, dates of birth, IPs —, dictionaries) propose a personal kind a person confirms; masked from the first load; no sample rows of personal or confidential columns ever reach a model; Presidio Extended** | Presidio in v1 (a spaCy model of ~600 MB, untested on this CPU) |
| D11 | Retention, erasure, backups | **Landing 30 days after a good load; snapshots 30 days (the time-travel window); the query text 90 days, the audit 2 years; an erasure pipeline that reports before it deletes, pauses for approval, rewrites files and expires snapshots, and proves it; backups catalog-first under a lock, 14 days, a restore drill** | keep landing forever (replay from the original at any time — but every erasure must then rewrite files the business no longer needs) |
| D12 | Ingestion in v1 | **`upload` (CSV, TSV, XLSX/XLS/ODS, JSON/NDJSON, Parquet; 5 GB a file in 8 MB parts), `http_file`, `sftp_file`, `k7`, `postgres` (an outside database only); `evolution_mode` `rescue` by default; inference proposes, a person confirms; nothing silently dropped** | add `rest_json` to v1 (a day more) |
| D13 | Using it | **The SQL editor (desk; Analysts), saved and verified queries, metrics as a small semantic layer, save-as-dataset to a sandbox, exports within limits; the expert answers catalog → metric → verified query → SQL and shows its work** | no metrics in v1 (the expert writes SQL every time — the field's measured weakness) |
| D14 | The API, keys and K7 | **A key acts as its person (60/min, 2,000/day; ad-hoc SQL only with `sql.editor` and the key's switch); the routes of §8; three shares — `datasets_index`, `product_rows`, `metric_value` — for products granted to the consumer** | an application-wide key — rejected: masks are a person's |
| D15 | Agents | **Expert and Data Steward hired on install; the pause list of §5; the Steward weekdays 06:40** | one agent |
| D16 | Default, ports, the division | **On request, not a default; the repository public (the owner, 2026-10-10); ports 8193 / 8847 / 8848, scratch 9001–9007; the Spaces division** — the planning model builds K36, Phase 0 (the engine proof first), Phases 1–2, slice 1 and the three exemplars (slices 2, 3, 5, with 4 between); Sonnet 5.5 builds slices 6–10 and Phase 4 | the fifth default install — defensible later, not before it has run at one business |

**Open questions for the owner beyond the table** — **answered 2026-10-10, every one as recommended (§15)**: (a) **the data directory** — under the install
(`/srv/apps/datalake/storage`) on the shared volume, or a dedicated volume the owner mounts (recommended: the lake and its spill
should not be able to fill the disk PostgreSQL lives on); (b) **the VM's CPU type** — switch to `host` / `x86-64-v3` (a restart of
the VM; every vectorised library faster, Polars usable) or stay on x86-64-v2; (c) **the first sibling data** the lake reads in v1 —
recommended: the GL's three summaries, Inventory's `sales_closed` and Sales CRM's `deals_won` (money and sales, the questions an owner
asks first); (d) **K37 and people** — feeds that name people pseudonymised by member id by default, or real names with the owner's
approval per feed.

## 14. Ports, names and files

- Repository `/srv/apps/datalake` (origin `github.com/maludb/maludb-os-datalake`, **public** — nothing of a tenant's, no secret, no
  real data ever committed; fixtures are synthetic); record database `<tenant>_datalake` (`subello_datalake`); roles `datalake_rw`
  (the web application), `datalake_records_ro`, `datalake_activity_ro` (the installer's three), **`datalake_lake`** (the writer: owner
  of schema `ducklake`, DML on the application's job tables) and **`datalake_query`** (the query service: `SELECT` on `ducklake.*`,
  `EXECUTE` on `dl_query_policy()` and the audit insert) — the last two made by `deploy/os-provision.sh` (`database.provision`).
- `datalake.<domain>` (`vhost.label = "datalake"`); `APP_INTERNAL_PORT=8193`, `MCP_RECORDS_PORT=8847`, `MCP_ACTIVITY_PORT=8848` **all
  three in `env.required`** and written to `config/.env` before `apply`; `deploy/` files are templates (`{{DOMAIN}}`, `{{APP_DIR}}`,
  `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`); the vhost sets `LimitRequestBody` 70 MB, PHP
  `upload_max_filesize` 64M / `post_max_size` 72M (a part is 8 MB; the API's one-request upload 64 MB).
- **System users**: `datalake-writer` (writes `landing/` pulls, `lake/`, `tmp/`, `exports/`) and `datalake-query` (reads `lake/`
  only); `www-data` writes `landing/` uploads through `upload_parts` and **cannot read `lake/`** (mode 0750, group `datalake`).
  The query socket `/run/datalake/query.sock` (group `www-data` + the MCP services' user).
- **The data directory** `DL_STORAGE_DIR` = **`/srv/lake`, a dedicated volume** (the owner's (a), 2026-10-10; `ROOT_STEPS.sh` refuses a
  directory on PostgreSQL's filesystem unless `--allow-shared-volume`, which only a scratch proof uses): `landing/`, `lake/` (DuckLake's
  `DATA_PATH`), `exports/`, `tmp/` (spill, capped), `backup/` (unless `DL_BACKUP_DIR` points elsewhere).
- Units: `datalake-records-mcp`, `datalake-activity-mcp`, `datalake-activity-ingest` (+ timer), `datalake-directory-sync` (+ timer),
  **`datalake-writer`** (long-running, the queue), **`datalake-query@1..3`** (the pool, hardened), `datalake-worker` (+ timer, every
  minute: schedules → writer jobs, source pulls, freshness, the outbox, usage roll-ups, retention, dispatch retries, grant and request
  expiry, worker passes).
- `maludb-os.json`: `catalog_key datalake`, `name "Data Lake"`, `business_area "Technology & Infrastructure"`, `category database`,
  `icon feather-database`, `criticality medium`, `vhost.label datalake`, no `scopes`, `sso`, `directory {reads: true, writes: false}`,
  `assistant {command_bar: true, agent: expert}`, `agents [expert, steward]` each `hired_on_install: true`, `approvals` (§5),
  endpoints, services (the query pool as `datalake-query.target`), no `runtime.python` (the installer builds `mcp/venv` for the MCP servers; the engine's `services/.venv` is `deploy/ROOT_STEPS.sh`'s — K39),
  `database.provision deploy/os-provision.sh`, `shares` (three; 30 s), `reads` (five — the owner's (c), §8).
- Env: the contract's required keys plus `DL_STORAGE_DIR`, `DL_BACKUP_DIR`, `DL_QUERY_SOCKET`, `DL_QUERY_WORKERS` (3),
  `DL_WRITER_MEMORY`, `DL_QUERY_MEMORY`, `DL_EXTENSION_DIR`, `DL_LAKE_DB_DSN` / `DL_QUERY_DB_DSN` (the engine roles' connections, read
  only by `services/`), `DL_MASK_SALT_FILE` (the installation's masking salt, root-written, read by the query service alone),
  `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` (optional).
- Vhost allow-list (public): `/api/v1/`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/datalake.json` (made by the installer), db/179 (K36).
- Proof scratch ports: 9001–9007; scratch databases `dl_dev`, `dl_proof`, `dl_engine`; fixtures `tests/fixtures/` (synthetic CSV, XLSX,
  JSON and Parquet files with known defects, a generator for the 50-million-row timing set; no third-party or real data committed).

## 15. The owner's answers

**2026-10-10 — every recommendation of §13 taken** (the label and the repository's visibility answered first, then the four open
questions, then D1–D16 "as recommended"):

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"Data Lake", key `datalake`, DNS label `datalake`**, database `<tenant>_datalake` (the application's tables + the lake's catalog in schema `ducklake`), roles `datalake_rw` / `_records_ro` / `_activity_ro` + the engine's `datalake_lake` and `datalake_query`, prefix `dl_`, Technology & Infrastructure, category `database` | `maludb-os.json`; §14; K36 |
| D2 | **DuckLake 1.0 on a PostgreSQL catalog in our own database + Parquet on local disk + DuckDB 1.5.6 pinned inside two services of our own (the writer, the query service)**; extensions vendored; no Polars; no object storage; no memory database of the lake's own | §0.3; §6.1; `services/` |
| D3 | **Zones landing → raw → curated → product, plus sandbox and quarantine** | §6.1 |
| D4 | **The amendment by name** — a lake extract is dated, read-only, provenance-carrying, never a copy of a table; the provider stays the system of record; erasure reaches the lake; v1 over the siblings' paged K7 shares; **K37 dataset feeds** for bulk; never a sibling's or the kernel's database | §0.5; §12; the plugin's `shared-schema.md` |
| D5 | **Four roles: Member (`user`), Analyst, Data engineer, Lake admin**; owner and steward rows on a dataset; an external member discovers only what names them | §3; `dl_roles`; `app_roles` |
| D6 | **Data deny-by-default; the catalog open by classification (stubs for confidential and restricted); levels discover / read (masked) / read_unmasked / write / own; grants on a dataset, a zone or a tag with row filters and expiries; masks by classification and personal kind; ONE query service; the web application blind to the files and the catalog's keys; DuckLake per-file encryption on; the admin's rows by logged break-glass** | §3; `docs/build-specs/access-and-policies.md` |
| D7 | **Four layers of query safety; 30 / 60 / 300 s; 1,000 rows shown, 100,000 downloaded; 3 workers × 1.5 GB; 2 concurrent per person; 30 a minute per agent** — settings | §6.5; `docs/build-specs/query-service.md` |
| D8 | **An agent in a person's chat turn reads the intersection with that person (K38); a duty reads as the agent; no `read_unmasked` by default; fail closed until K38** | §3 rule 8; §8 |
| D9 | **Checks in ODCS's vocabulary on every load and on schedule; blocking checks quarantine; incidents with the downstream; freshness; certification computed, set by an engineer, lost by itself; verified queries unverified on edit; contracts exported as ODCS v3.2.0** | §6.4 |
| D10 | **Deterministic classifiers propose a personal kind a person confirms; masked from the first load; no personal or confidential sample ever reaches a model; Presidio Extended** | §6.3; §6.5 |
| D11 | **Landing 30 days after a good load; snapshots 30 days; query text 90 days, the audit 2 years; erasure that reports, pauses, rewrites, expires and proves; backups catalog-first under a lock, 14 days, a restore drill** | §6.7 |
| D12 | **`upload`, `http_file`, `sftp_file`, `k7`, `postgres` (an outside database only) in v1; `rescue` by default; inference proposes, a person confirms** | §6.3 |
| D13 | **The SQL editor, saved and verified queries, metrics, save-as-dataset, exports within limits; the expert answers catalog → metric → verified query → SQL and shows its work** | §6.5; §5 |
| D14 | **A key acts as its person (60/min, 2,000/day; ad-hoc SQL with `sql.editor` and the key's switch); three shares `datasets_index`, `product_rows`, `metric_value`** | §4; §8 |
| D15 | **Expert and Data Steward hired on install; the pause list of §5; the Steward weekdays 06:40** | §5; `agents[]` |
| D16 | **On request, not a default; the repository public; ports 8193 / 8847 / 8848, scratch 9001–9007; the Spaces division** — the planning model builds K36, Phase 0 (the engine proof first), Phases 1–2, slice 1 and the exemplars (slices 2, 3, 5, with 4 between); Sonnet 5.5 builds slices 6–10 and Phase 4 | §14; `CLAUDE.md` |
| Phase 1 | **APPROVED ("Go", 2026-10-10). The 32 decisions of `docs/phase1-consistency.md` — every recommendation taken** (2026-10-10, walked through): ★1 the agents get no data access on install; ★2 **K38 built now** (kernel 8c9b6fa) with fail-closed as the fallback; ★3 a ≥ 0.8 personal-data proposal masks at once; ★4 only products shared with siblings; ★5 the five reads seeded, nightly; ★6 exports and transforms in the writer; ★7 archive retention Extended; ★8 the nightly backup **and a monthly automatic restore drill**; 9–32 as written | `docs/phase1-consistency.md`; the specs |
| (a) | **The data directory on a dedicated volume.** This host's volume group has no free extent (`ubuntu-vg` 529.99 GB, VFree 0 — the root volume was grown over a second 500 GB disk on 2026-10-05), so the owner adds **a new virtual disk in Proxmox**, makes it its own volume group (`lake-vg`) and filesystem, and mounts it at **`/srv/lake`** (`noatime`) = `DL_STORAGE_DIR`; DuckDB's spill (`tmp/`) and the backups' local copy live there too. `deploy/ROOT_STEPS.sh` **refuses** the storage step when `DL_STORAGE_DIR` is on the same filesystem as PostgreSQL's data directory (`--allow-shared-volume` for a scratch proof only) | §14; `deploy/ROOT_STEPS.sh` |
| (b) | **The VM's CPU type switched to `host` (or `x86-64-v3`)** in Proxmox — a VM restart, the owner's; Phase 0's `tests/engine/cpu.sh` reruns afterwards and records the flags it found. The design does not depend on it: the pinned set runs on x86-64-v2 too, and Polars stays out of v1 either way | §0.3; §12 |
| (c) | **The first sibling data** (`reads[]` in v1): the GL's `account_balances`, `ar_aging_summary`, `ap_due_summary`; Inventory's `sales_closed` (paged); Sales CRM's `deals_won` (paged, `since`) — money and sales first. The other shares of §8 are later `reads[]`, one line each | §8; `maludb-os.json` |
| (d) | **K37 feeds that name people carry pseudonymised member ids by default** (a keyed hash per installation, stable so joins work, the same salt discipline as the masks); real names only with the owner's explicit approval per feed, recorded on the connection | §12 (K37) |

## 16. State

**2026-10-11 — SLICE 6 SOURCES AND PULLS BUILT and proven (a Sonnet 5.5 worker; the planning model verifies)** (`tests/phase3/slice6/run.sh` **428 checks under php -S and
under Apache**: sources 164, pulls 90, postgres 41, K7 51, JSON 36, the browser 46; the schema proof 217): the five v1 connectors — `upload` (a named
shape), `http_file` (a conditional GET through the one polite client: honest user-agent, robots.txt, 1 request/s, https only, streamed to disk; a 304 lands nothing, the same
bytes under a new ETag are refused by the load's key), `sftp_file` (a directory through ssh2 / `curl sftp://` / `sftp`, proven through the transport seam), `k7` (the owner's
five reads of the GL, Inventory and Sales CRM through the kernel — pages as NDJSON, `<yesterday>`, injected fields, a window that moves forward, `no_connection` pausing
without a failure) and `postgres` (one table of an outside database: read-only cursor, pages, a watermark, the weekly count reconciliation and reload); the PHP worker's
pass; credentials sealed under `DL_SECRETS_KEY` (libsodium; the last four only); the four screens and eight actions. **db/025 — `GRANT DELETE ON sources` (the spec planned no
migration; `source_delete` could not have run).** Found: every page of a replacing pull must carry a per-pull marker, or the writer ("already loaded") and the load's own key
(the pages that follow the first) would refuse them; the postgres guard is a name check an alias (`127.0.0.2`, the machine's own address) passes — **closed by the planning
model's review**: `dl_fetch_host_refusal()` judges what a host resolves to (reserved addresses and every address of this machine) for http_file at every hop and for
sftp_file and postgres at the saving and at every connect. Decisions in `sources-pulls.md` "Built and proven". **Next: slices 7–10 and Phase 4.**

**2026-10-11 — SLICE 5 CONTRACTS AND QUALITY (THE THIRD EXEMPLAR) BUILT and proven — THE HANDOFF POINT** (`tests/phase3/slice5/run.sh`
**310 checks under php -S and under Apache**: the writer's jobs in-process 20, contracts 33, promotion 29, checks 43, incidents 28, certification 23,
drift 15, freshness 10, ODCS 14, the walls 28, JSON 31, the browser 36; the schema proof 212): contracts in ODCS v3.2.0's words with versions and a
YAML export / import, promotion raw → curated by the writer (`jobs/quality.py`: typed, refused rows to a quarantine read through the query service
with the curated column's mask, incremental from the last raw snapshot, an added column in place, a removed or retyped one a rebuild), every check
kind, incidents, certification computed by the database and lost / regained with followers told, drift accepted into the contract draft, the
Quality tab; a promotion 0.2–0.6 s in the engine (1.3–3 s the whole job), a check run 0.7–1.9 s; db/024. Found: the writer's check results never
saved (psycopg's smallint / unknown argument types — cast), an empty promotion made a commit, a missing `severity_badge()`. **The command bar
covering a page's last control was the browser's scroll-into-view not knowing the fixed bars — `scroll-padding` on `html`; Phase 2's browser proof
checks it at 375 and 1280 with and without JavaScript.** 16 decisions and "what a worker must copy" in `contracts-quality.md`. **Next: slices 6–10
and Phase 4 by Sonnet 5.5 workers, one at a time, each verified by the planning model.**

**2026-10-11 — SLICE 4 ACCESS AND POLICIES BUILT and proven** (`tests/phase3/slice4/run.sh` **332 checks under php -S and under Apache**: THE
MATRIX 111 — ten callers plus the Expert acting for Priya and for Ann over eight datasets, every cell read through three doors (the dataset page,
`mcp_datasets` as the records role, the query service's preview) and agreeing with `dl_access_of()`; requests 30, row filters 17, grants 25, groups
26, masks 12, personal data 16, break-glass 14, JSON 34, the browser 47; the schema proof 204): grants on dataset / zone / tag to every kind of
principal with expiry and row-filter templates checked by running them (`check_row_filter`: node by node, EXPLAIN, `LIMIT 1` under a 5 s interrupt),
masks and classification, personal data confirmed by people, access requests decided inline with their impact, the break-glass, groups (a
department mirror follows the directory), the Access tab, the worker's expiry pass; db/023. **K38 proven end to end: the Expert for Priya reads the
lower of the two; the Expert for Ann (external, no holding) reads nothing, not even a name; a kernel without K38 → `k38_pending`.** Found: the
row-filter check echoed a data value from DuckDB's cast error (the granter may not read it) — literals stripped; a registered dataset never loaded
was refused as `policy` — now "no data yet". 14 decisions in `access-policies.md` "Built and proven". **Next: slice 5, contracts and quality (THE
THIRD EXEMPLAR) — the last by the planning model.**

**2026-10-11 — SLICE 3 THE QUERY SERVICE AND THE EDITOR (THE SECOND EXEMPLAR) BUILT and proven** (`tests/phase3/slice3/run.sh` **220 checks
under php -S and under Apache**: the editor 27, the refusals 26, saved queries 24, verify 11, the sandbox 17, exports 24, the audit 14, JSON 24, the
socket 16, the browser 37; the schema proof 197): every request kind of the one door (sql, saved_query, dry_run, explain, cancel answered beside a
running request, check_row_filter, preview, ping), `dl_query_admit()` (db/022 — concurrency slots for everyone, the rate for agents), the watchdog,
the editor with completion of my keys, run / explain / cancel, saved queries dry-run at save, my runs, exports and saved results as writer jobs
(`jobs/query_results.py`: the person's SQL on a locked read-only connection streamed as Arrow, the policy frozen); a query through the door costs
~0.6 s, a refusal ~0 ms, the watchdog answers at ~2.5 s of 2. Found: **`dl_query_policy()` answered `not_readable` for a dataset the caller cannot
see — typing its key confirmed it existed; now `no_such_dataset`** (db/022 `dl_member_sees_stub()`); ROOT_STEPS wrote the masking salt as raw bytes
the service refuses (a fresh install's query service would not start) — hex now, `root:datalake 0440` (the writer hashes for exports; www-data never
reads it); an advisory slot was re-acquirable by the same session. 16 decisions in `query-service-editor.md` "Built and proven".
**Next: slice 4, access and policies.**

**2026-10-11 — SLICE 2 UPLOADS AND LOADS (THE FIRST EXEMPLAR) BUILT and proven** (`tests/phase3/slice2/run.sh` **262 checks under php -S and under
Apache** against the real writer, query service and DuckLake: the writer's kinds in-process 26, upload 34, map 45, loads 41, drift 20, rejects 14,
preview 23, JSON 17, the browser 42; the engine 157, the schema proof 189): resumable uploads in 8 MB parts, the sniff with header row / delimiter /
encoding, the mapping confirmed (raw all text; the sandbox typed with CAST rejects), the writer's job frame (`services/lake/jobs/` with DISPATCH and
`JobRefused`) woken by `pg_notify`, loads with drift, rejects, replay and rollback, templates, the sandbox, the Preview tab through the query service
with masks; a 20 MB CSV of 464,305 rows loads in ~1.8 s, a preview answers in 0.2–0.6 s. db/021 (the writer's wake-up, its grants, `loads.mapping`,
the rollback lineage kind, `dl_incident_open_load()`, `mcp_loads` widened); found: **the classifier took ISO dates for phone numbers at 0.84–0.9 —
★3 would have masked every date column** (fixed), a mapped column missing from an existing dataset raised instead of drifting. 18 decisions in
`uploads-loads.md` "Built and proven". **Next: slice 3, the query service and the editor (THE SECOND EXEMPLAR).**

**2026-10-10, later — SLICE 1 THE CATALOG (the CRUD exemplar) BUILT and proven** (`tests/phase3/slice1/run.sh` **229 checks under php -S and
under Apache**: the catalog 65, columns 28, tags 24, the glossary 31, the walls 24, JSON 21, the browser at 375/1280 and without JavaScript 36):
browse with chips, the dataset page's frame with all eight tabs (About, Columns, Usage, History built; Preview, Access, Quality, Lineage the named
slices'), the column pages, the glossary, tags, follows, notes; `require_dataset()` the gate of every handler; db/020 (the two reference columns and
`certification_lost_at` in the views, `dl_dataset_key_taken()`, the follow upsert's grant); found: Apache read `/datasets/save.php` as a dataset key —
a key in the rewrite now starts with its zone. 16 decisions in `catalog.md` "Built and proven". **Next: slice 2, uploads and loads (THE FIRST EXEMPLAR).**

**2026-10-10, later — PHASE 2 THE SHELL BUILT and proven** (`tests/phase2/run.sh`: **322 checks under php -S, 327 under a real Apache** with
the deploy vhost — first run 21, the browser at 375/1280 and without JavaScript 75, rights and screens 109, the engine 33, the guard 6, the vhost
42/47, the picker 24, the switcher 12; the schema proof 181; the registry and approvals in step): the shell, the switcher and the record picker,
home, search with stubs, notifications, my activity, settings and tokens with the four key actions finished, `/api/v1/health` with the engine,
`app/lake/gate.php` (the walls and K38 live) and `app/lake/client.php`, the writer and the query service running empty under their real commands;
db/019; fixed on the way: the provision script wrote unquoted DSNs, the writer's role read the settings table directly, the socket was a path not a
prefix. Decisions recorded in `docs/build-specs/sso-shell.md` "Built and proven". **Next: slice 1, the catalog (the CRUD exemplar).**

**2026-10-10, later — PHASE 1 APPROVED by the owner ("Go").** Next: Phase 2 (`docs/build-specs/sso-shell.md`) → slice 1 → slices 2, 3, 4, 5 by the planning model (2, 3 and 5 the exemplars) → the handoff to Sonnet 5.5 for slices 6–10 and Phase 4.

**2026-10-10, later — the owner walked through Phase 1's 32 decisions and took every recommendation** (§15): K38 BUILT in the kernel the same day (8c9b6fa), the monthly restore drill added to slice 8. **Next: the owner's go → Phase 2.**

**2026-10-10, later — PHASE 1 WRITTEN, for the owner's checkpoint** (`docs/phase1-consistency.md` is the record and lists the 32 decisions
the specs took): the tool surface (`docs/datalake-mcp-tool-surface.md` — records 69 tools, activity 6, 22 resolvers, the three K7 shares, the K38 rule),
the action manifest (`docs/datalake-action-manifest.md` — **80 screens, 105 actions**, 29 paused for an agent, 22 people only) → `mcp/action_registry.json`
and `maludb-os.json` `approvals[]` (generated, `--check` green), the resolve block (`deploy/kernel-registry-datalake.json`), three build specs
(`lake-engine.md`, `query-service.md`, `access-and-policies.md`) and eleven slice specs (`sso-shell` + ten; slices 2, 3 and 5 the exemplars),
db/018 (the Phase 1 reads, the policy's final shape with the K38 ceiling, the stub wall honouring who an agent acts for — the schema proof **181**),
`tests/phase1/check_specs.sh` **61 checks green** (every screen and action claimed once, every tool named, every agent grant real). **Next: the
owner approves Phase 1 → Phase 2 (`docs/build-specs/sso-shell.md`).**

**2026-10-10, later — PHASE 0 COMPLETE.** K36 built (kernel db/179). The kit (`tests/phase0/run.sh`, 83 checks); the schema db/001–017
(`db/proof/phase0_proof.sql` via `tests/phase0/schema.sh`, **158 checks** over ten callers — found and fixed: NULL-unsafe ownership
checks that let a non-owner decide where a dataset had no steward; the query service's role held table grants it does not need); the
engine (`services/lake/`, `services/query/`, `services/bin/`; `tests/engine/run.sh` **157 checks**; `timing.sh` the 50-million-row set;
`tests/engine/FINDINGS.md` — §6.9 records what it changed); `db/engine/001_lake_views.sql`; `maludb-os.json`, `os/{expert,steward}.md`,
nine skills, `deploy/` (passfiles, the engine roles' grants, the hardened writer and query units, ROOT_STEPS refusing a data directory on
PostgreSQL's filesystem); the installer's `plan` clean (36 steps). **Next: the owner's go → Phase 1** (the tool surface, the action
manifest, the three build specs and the slice specs, for approval before any PHP).

**2026-10-10, later — APPROVED: D1–D16 taken as recommended** (§15). **Next:** K36 (kernel db/179) → Phase 0 — the engine proof first (`services/`, `tests/engine/`), the kit, the schema and its proof, `maludb-os.json`, the agents, the skills, `deploy/`.

**2026-10-10, later — the open questions answered** (§15: (a) a dedicated volume at `/srv/lake` on a new Proxmox disk — the volume group has no free space; (b) the CPU type to `host`; (c) the GL's three summaries, Inventory's `sales_closed`, Sales CRM's `deals_won` first; (d) K37 people feeds pseudonymised by default). 
**2026-10-10 — planned; awaiting the owner's decisions (§13).** The research of §0 done in four tracks (the requirements R1–R71 and how
lakes fail; the front ends of eighteen products screen by screen; the formats, engines and this server, with DuckDB, PyArrow and
Polars tested on its CPU; the estate's K7 shares, reusable tables and free numbers), reported in `docs/research/`. Repository
`maludb/maludb-os-datalake` created **public** and cloned to `/srv/apps/datalake`. Found on the way: the kernel's run facts do not say
who asked (K38); K7 cannot carry bulk (K37); the VM's CPU has no AVX. **Next:** the owner's answers → K36 (kernel db/179) → Phase 0
(the engine proof first, then the schema and its proof, the kit, `maludb-os.json`, agents, skills, `deploy/`) → Phase 1 for approval →
Phase 2 → slice 1 → the exemplars (slices 2, 3 and 5) → the handoff to Sonnet 5.5.
