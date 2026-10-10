> Research report for `docs/datalake-design.md` §0, written 2026-10-10 by a research agent of the planning session (web sources cited inline; local facts read on the build server). Kept as written; the design document is where its findings become decisions.

# Self-built SMB data lake: technical foundations and what this server has

Researched 2026-10-10. I changed nothing on the server, with one side effect explained in the server section. Everything downloaded or run was kept outside the repository.

## Summary

1. **Use DuckLake 1.0 as the table format, with its catalog in a PostgreSQL 17 database, Parquet files on the local filesystem, and DuckDB 1.5.x embedded in our own Python workers.**
   - It is the only open table format whose whole catalog is ordinary SQL tables in PostgreSQL. PHP can read the catalog through `pdo_pgsql` with no engine in between, and `pg_dump` backs it up with everything else.
   - It is a specification plus an MIT library, not a platform.
2. **The CPU is the main finding about the server.**
   - The VM shows "QEMU Virtual CPU version 2.5+". That is x86-64-v2: it has SSE4.2 but no AVX, AVX2, FMA or BMI2.
   - I tested in a scratch directory. DuckDB 1.5.6 and PyArrow 26.0.0 run correctly. The default Polars 2.0.0 runtime crashed with an illegal instruction. `polars[rtcompat]` works.
   - Changing the Proxmox CPU type to `host` or `x86-64-v3` is a cheap owner action that would speed up every vectorised library.
3. **No object storage is needed.**
   - DuckLake, DuckDB and PyIceberg all work on local paths.
   - MinIO went into maintenance mode on 2025-12-03 and its repository was archived in 2026, so it should not be adopted now.
4. **Do not adopt pg_lake, pg_duckdb or pg_mooncake now.** Each would put an analytical engine inside the PostgreSQL cluster that every application shares:
   - pg_lake and pg_duckdb need `shared_preload_libraries` changed and the cluster restarted, and pg_lake adds a separate daemon.
   - None of the three is in the PGDG apt repository for noble.
   - pg_mooncake's company was bought by Databricks, and its last release was February 2025.
5. **DuckDB is not a sandbox. Its own documentation says to "Treat SQL in DuckDB like code in Bash or Python."**
   - Safe SQL for people and agents needs four layers: an allow-list check of the query's syntax tree (sqlglot), views per person, DuckDB locked down (tested here), and a process-level sandbox (systemd).
   - DuckDB 1.5.6 has no query-timeout setting. A watchdog calling `con.interrupt()` works; I tested it: a query was cancelled at 1.0 s.

---

## Part 1: Research

### 1. Open table formats

**Apache Iceberg** (Apache-2.0)
- **Versions.**
  - Format versions 1, 2 and 3 are adopted. Version 4 is "under development" and not formally adopted; it adds relative locations and stops new equality deletes.
  - Java 1.10.0 (September 2025) made v3 generally available. 1.11.0 came out 2026-05-15. 1.12.0 was reported out on 2026-09-30, and it no longer writes position-delete files that embed row values.
  - PyIceberg is at 0.12.0 (2026-09-01).
- **Metadata hierarchy.**
  - A table metadata file (`metadata.json`) holds the schemas, partition specs, sort orders, properties and the list of snapshots.
  - Each snapshot points to one manifest list.
  - The manifest list (Avro) lists manifests with partition summaries, so a planner can skip whole manifests.
  - Manifests (Avro) list data or delete files with their partition tuple and column metrics. Manifests are reused across snapshots.
- **Schema evolution** is by field ID, not by name or position. Renames keep the ID, and safe promotions such as int→long are allowed.
- **Hidden partitioning.** Partition values come from transforms: `identity`, `bucket[N]`, `truncate[W]`, `year`, `month`, `day`, `hour` and `void`. Queries filter on the data columns, and partition specs can evolve.
- **Deletes.**
  - v2 adds position-delete and equality-delete files plus sequence numbers.
  - v3 replaces position deletes with binary deletion vectors in Puffin files. v3 also adds row lineage, default values, the types `variant`, `geometry`, `geography`, `timestamp_ns` and `unknown`, multi-argument transforms and table encryption keys.
- **Commits.** A writer builds new metadata optimistically and commits by atomically swapping the catalog's pointer from the base `metadata.json` to the new one, retrying on conflict.
- **REST Catalog spec.** It is an OpenAPI 3.1.1 document (`info.version` 0.0.1). Endpoints include:
  - `/v1/config`
  - namespaces and tables
  - single-table commit (`POST …/tables/{table}`) and multi-table `transactions/commit`
  - views, `credentials`, server-side scan planning (`plan`, `tasks`), metrics and rename
  - Security is OAuth2 or a Bearer token.
- **Fit with PostgreSQL.**
  - PyIceberg's `SqlCatalog` stores only one pointer row per table in PostgreSQL. Everything else stays in JSON and Avro files that PHP cannot read cheaply.
  - DuckDB's `iceberg` extension can write only through an attached REST catalog. Tables read directly with `iceberg_scan` are read-only.

**Delta Lake** (Apache-2.0, Linux Foundation)
- The protocol is a `_delta_log/` of zero-padded JSON commits plus Parquet checkpoints (v2 checkpoints use sidecars). The actions are `add`, `remove`, `metaData`, `protocol`, `commitInfo`, `txn`, `domainMetadata` and `cdc`.
- Features are listed per table under reader version 3 / writer version 7: deletion vectors, column mapping, row tracking, v2 checkpoints, catalog-managed tables, variant, Iceberg compatibility, and others.
- Atomicity is the commit file: put-if-absent, or a coordinating catalog.
- Versions: Spark-side Delta is at 4.4.0 (2026-08-20). delta-rs reached rust-v1.0.0 (2026-09-28); its Python package `deltalake` is at 1.6.6.
- DuckDB's `delta` extension reads Delta tables and writes blind appends only.
- Governance and the catalog story centre on Databricks and Unity Catalog. There is no PostgreSQL-catalog model.

**Apache Hudi** (Apache-2.0)
- Current release 1.2.1 (2026-09-24). 1.1 (November 2025) added a "pluggable table format" through Apache XTable.
- Its strengths (indexes, table services, upserts) run in Spark and Flink, the JVM. For us that amounts to adopting a cluster platform. It is not a fit.

**DuckLake** (MIT, specification and extension; DuckDB Foundation). This is the most relevant format for us.
- **Release.** v1.0 shipped 2026-04-13 and is described as "production-ready … with guaranteed backward-compatibility". It needs DuckDB ≥ 1.5.2. The FAQ said v1.1 was expected in September 2026; I found no release yet.
- **Building blocks.** A catalog database that has transactions and primary keys, plus Parquet data on file, block or object storage.
- **The catalog is 26 SQL tables**, prefixed `ducklake_`: `snapshot`, `snapshot_changes`, `schema`, `table`, `column`, `data_file`, `delete_file`, `file_column_stats`, `table_stats`, `partition_info`, `sort_info`, `inlined_data_tables`, `files_scheduled_for_deletion`, `tag`, `view`, `macro`, and others.
  - Every object row carries a `begin_snapshot`/`end_snapshot` range, so the state at any snapshot is a `WHERE` clause.
- **Commit.**
  - A writer writes Parquet first. Then, in one short catalog transaction, it inserts snapshot `max+1`, a `snapshot_changes` row (changes, author, commit message), the file rows and the statistics.
  - The primary key on `snapshot_id` detects a race. DuckLake then reads `snapshot_changes` and retries automatically if the changes do not logically conflict. Two inserts into the same table never conflict. Two deletes on the same data file do.
- **Catalog databases.**
  - DuckDB: single client only.
  - SQLite: several local clients.
  - PostgreSQL ≥ 12: recommended for "a multi-user lakehouse".
  - MySQL: not recommended.
- **Features.**
  - Time travel: `AT (VERSION => n)` and the `SNAPSHOT_VERSION`/`SNAPSHOT_TIME` attach options.
  - Change feed: `table_changes(tbl, from, to)` returns insert, delete, update_preimage and update_postimage rows with `snapshot_id`. This is incremental processing for free.
  - Data inlining: inserts and deletes under 10 rows by default go into catalog tables until `ducklake_flush_inlined_data`. This cures the small-files problem for SMB trickle writes.
  - Encryption: each Parquet file gets its own key, stored in `ducklake_data_file.encryption_key`.
  - Also: sorted tables, bucket partitioning, Iceberg-compatible Puffin deletion vectors, and the `METADATA_SCHEMA` attach option, so the catalog can live in a schema of our own database.
  - Maintenance functions: merge adjacent files, expire snapshots, clean up files, rewrite files with deletes, checkpoint.
- **Not supported:** indexes; enforced primary, foreign or unique keys; upserts other than `MERGE INTO`; sequences; CHECK constraints; non-literal defaults.
- **Other implementations.**
  - DataFusion: an Apache-2.0 implementation by Hotdata, donated to datafusion-contrib in July 2026; it uses a PostgreSQL catalog backend that its authors label experimental.
  - Spark: a connector from MotherDuck.
  - Iceberg interoperability: `iceberg_to_ducklake()` imports, and copying from DuckLake to Iceberg is documented. These are our exit paths.
- **What it means for us.**
  - The lake's catalog would be a PostgreSQL database we own, read and write with the stack we already use.
  - The HTMX front end can list schemas, tables, columns, snapshots, history, sizes and statistics with plain SQL, and only actual queries need DuckDB.
  - Commits are ACID across many tables. Backup is one `pg_dump` plus the data directory.
  - The specification is short enough that a second implementation exists. Our dependency is a format, not a vendor.
  - The risks are maturity (six months since 1.0), effectively one production-grade implementation, and the PostgreSQL catalog under heavy write contention: an anecdotal ~5 transactions per second with about 20 contending writers, plus a reported connection exhaustion.

**Comparison when the catalog lives in PostgreSQL anyway**

| | DuckLake | Iceberg (`SqlCatalog`/JDBC pointer) | Delta | Hudi |
|---|---|---|---|---|
| Metadata location | All in PG tables | PG pointer + JSON/Avro files | JSON log files | Timeline files + metadata table |
| PHP can browse the catalog | Yes, plain SQL | No (needs Avro and JSON parsing) | Partly (JSON) | No |
| Multi-table atomic commit | Yes (one PG transaction) | Only via REST `transactions/commit` | No | No |
| Writes from DuckDB | Full | Only via a REST catalog | Appends only | No |
| Small-write handling | Inlining | Compaction | Compaction | Compaction (Spark) |
| Engine breadth | DuckDB, DataFusion, Spark connector | Widest | Wide (Spark-first) | JVM |
| Licence | MIT | Apache-2.0 | Apache-2.0 | Apache-2.0 |

### 2. File formats

- **Parquet** (parquet-format 2.14.0, 2026-09-11).
  - Structure: the file is `PAR1`, then row groups of column chunks of pages (dictionary and data), then a footer with the schema, row-group locations and min/max/null-count statistics, then `PAR1`.
  - Optional pruning aids: a page index (ColumnIndex/OffsetIndex) and bloom filters.
  - Encodings: PLAIN, RLE_DICTIONARY, the RLE/bit-packing hybrid, DELTA_BINARY_PACKED, DELTA_LENGTH_BYTE_ARRAY, DELTA_BYTE_ARRAY, BYTE_STREAM_SPLIT, and ALP for floats (preview). PLAIN_DICTIONARY and BIT_PACKED are deprecated.
  - Codecs: SNAPPY, GZIP, ZSTD, LZ4_RAW, BROTLI.
  - Newer logical types: VARIANT (with shredding) and GEOMETRY/GEOGRAPHY.
  - Row groups: the specification suggests 512 MB–1 GB, which is Hadoop-era guidance. DuckDB's default row group is 122,880 rows; my 20 M-row test file had 163 row groups.
  - Recommendation: ZSTD, about 100k–1M rows per row group, and target files of 64–512 MB, compacted.
- **Arrow** (26.0.0, 2026-10-09; columnar format version 1.5). Arrow is the in-memory interchange between DuckDB, PyArrow, Polars and DataFusion, with no copies through the C Data Interface. The IPC stream format (`.arrows`) is a good wire format between a query worker and an MCP server.
- **Ingestion realities.**
  - CSV is the hard case.
    - DuckDB's sniffer detects delimiter, quote, escape, header, types and date format from a 20,480-row sample by default; use `sample_size=-1` for a full pass.
    - `store_rejects=true` writes bad rows to `reject_scans` and `reject_errors` (line, byte position, column, error type, raw line). The error types are CAST, MISSING COLUMNS, TOO MANY COLUMNS, UNQUOTED VALUE, LINE SIZE OVER MAXIMUM and INVALID ENCODING.
    - Supported encodings are UTF-8, UTF-16 and Latin-1 only, so Windows-1252 needs transcoding first.
    - Design rule: land the original file immutably (sha256), parse to all-VARCHAR "staged", then type through a contract. Never trust inferred types for money or identifiers; leading zeros get lost.
  - JSON: `read_json` auto-infers, and newline-delimited JSON is preferred.
  - XLSX: DuckDB's `excel` extension provides `read_xlsx` with `sheet`, `range`, `header`, `all_varchar`, `ignore_errors` and `empty_as_varchar`.
    - It infers types from the first data row, does not read `.xls`, and its documentation does not say how formulas are handled.
    - For messy workbooks, use `python-calamine` 0.8.3 (MIT, fast, reads cached values, handles .xls/.xlsx/.ods) or openpyxl 3.1.5. xlrd 2.0.2 for legacy .xls is already in the Shareholder Intelligence venv.
    - Merged cells, several header rows and several tables per sheet need a "sheet mapping" step that a person confirms.

### 3. Engines and libraries

| Component | Version / date | Licence | PG17 | Library or platform | What it gives us | Operational cost |
|---|---|---|---|---|---|---|
| **DuckDB** (Python wheel, CLI) | 1.5.6, 2026-09-28; 2.0 due October 2026 | MIT | Reads and writes PG via the `postgres` extension | Embeddable library | Vectorised SQL over Parquet, CSV, JSON, XLSX and DuckLake; spills to disk | 21.6 MB wheel. One read-write process per native `.duckdb` file (other processes read-only; "Quack" client-server is beta). Default `memory_limit` is 80% of RAM (12.4 GiB here), so it must be capped. Extensions download at runtime unless vendored. |
| **DuckLake extension** | spec 1.0, 2026-04-13 | MIT | PG ≥ 12 catalog | Spec + library | Table format, multi-writer through PG | Our own maintenance jobs; catalog roles |
| **pg_duckdb** | v1.1.1, 2025-12-18 (last push 2026-07) | MIT | PG 14–18 | DuckDB inside every PG backend | Analytics on heap tables; reads Parquet, Iceberg, Delta | Not in PGDG noble; source build; preload + restart of the shared cluster; superuser-only unless `duckdb.postgres_role` is set; local files closed to non-superusers without `pg_read/write_server_files`; DuckDB memory per backend |
| **pg_parquet** (Crunchy) | v0.5.1, 2025-10-01 | PostgreSQL licence | PG 14–19 | Small library | `COPY … TO/FROM` Parquet, local or S3; `parquet.schema` and `parquet.metadata` inspection | pgrx/Rust source build; no packages |
| **pg_lake** (Snowflake, ex-Crunchy Data Warehouse) | v3.5.6, 2026-10-09 | Apache-2.0 | PG 16–19 | Close to a platform: ~10 extensions + the `pgduck_server` daemon | Iceberg v2 tables `USING iceberg` with PG transactions | `shared_preload_libraries=pg_extension_base` + restart; vcpkg + Azure SDK build; storage oriented to `s3://` (MinIO for local development) |
| **pg_mooncake** | v0.1.2, 2025-02-12 | MIT | (v0.2 preview) | Extension + moonlink | Iceberg columnstore mirror | Databricks acquired the company about October 2025; stale. Avoid. |
| **Apache DataFusion** (Python `datafusion`) | 54.1.0, 2026-10-05 | Apache-2.0 | none | Library | Second engine; DuckLake contrib crate | Untested on this CPU; smaller SQL surface |
| **Polars** | 2.0.0, 2026-10-06 (new major) | MIT | via connectorx/ADBC | Library | DataFrame transforms, lazy scans | **Must be `polars[rtcompat]` here**; a .0 major release |
| **PyArrow** | 26.0.0, 2026-10-09 | Apache-2.0 | — | Library | Parquet I/O, metadata, Arrow IPC | 54 MB wheel; works here (tested) |
| **PyIceberg** | 0.12.0, 2026-09-01 | Apache-2.0 | `SqlCatalog` on PG via SQLAlchemy | Library | Iceberg reads and writes without a JVM | Metadata files; maintenance on us |
| **deltalake** (delta-rs) | 1.6.6, 2026-09-24 | Apache-2.0 | — | Library | Delta reads and writes | Not needed |

### 4. Object storage

- **Does an SMB need it?** On one server, no. Local ext4/xfs is faster, and DuckLake's documentation lists "local files and folders" first. Because DuckLake and Iceberg never modify files in place, the filesystem needs no special consistency.
- **When object storage becomes worth it:**
  - several hosts or remote engines need the files over HTTP;
  - presigned download links;
  - an S3-API offsite replica.
  - Even then, backup is better done with restic or rsync than by running a store.
- **MinIO.** AGPLv3 since 2021. Admin functions were removed from the Community Edition console in 2025. It entered maintenance mode on 2025-12-03 (no features, security fixes case by case), and the repository was archived in 2026: sources say February, then April 25. **Do not adopt.**
- **Garage.** v2.3.0 (2026), AGPLv3, built for small self-hosted geo-distributed sites. It is the lightest option if one is ever needed. AGPL obligations apply only if we modify it and offer it over a network.
- **SeaweedFS.** 4.48 (2026-09-28), Apache-2.0, mature, but more moving parts (master, volume, filer, S3 gateway).
- **RustFS.** 1.0.1 (2026-10-03), Apache-2.0, a drop-in MinIO replacement, but only just at 1.0.
- **Ceph RGW** is far too heavy for one server.
- **Proposed layout** (local, one tree per tenant):
  - `/srv/lake/<tenant>/landing/<source>/<yyyy>/<mm>/<dd>/<upload_id>/<original-name>`: immutable originals with sha256.
  - `…/quarantine/`
  - `…/lake/`: the DuckLake `DATA_PATH`, written only by the writer service; DuckLake manages its own layout under it.
  - `…/exports/`
  - `…/tmp/`: DuckDB spill, size-capped.
  - The zone names raw → staged → curated → products are conventions, not a platform.
  - Ideally `/srv/lake` is a separate logical volume. Today `/`, `/srv` and `/var` share one 522 GB volume with PostgreSQL, whose pgaudit logs already filled the disk once.

### 5. Metadata standards to implement natively (as tables plus JSON or YAML export)

- **OpenLineage** (JSON-schema spec 2-0-2; Python client 1.53.0, 2026-09-01; Apache-2.0).
  - Three event kinds: `RunEvent` (`eventType` START, RUNNING, COMPLETE, ABORT, FAIL or OTHER, with `eventTime`, `run.runId` as a UUIDv7, `job{namespace,name}`, `inputs[]`, `outputs[]`, `producer`, `schemaURL`), `JobEvent` and `DatasetEvent`.
  - Datasets are identified by `namespace` + `name`.
  - Facets to copy: `schema`, `dataSource`, `columnLineage`, `sql`, `documentation`, `ownership`, `tags`, `lifecycleStateChange`, `version`, `storage`, `catalog`, `dataQualityMetrics`, `dataQualityAssertions`, `inputStatistics`, `outputStatistics`, `errorMessage`, `nominalTime`, `parent`, `processingEngine`.
  - Emit one RunEvent pair per ingest, transform and compaction. A DuckLake snapshot ID is a natural `version` facet.
- **Open Data Contract Standard (ODCS)** (Bitol / LF AI & Data, Apache-2.0; **v3.2.0, 2026-09-08**, after v3.1.0 in December 2025).
  - Sections: fundamentals, schema, context, references, data quality, support and channels, pricing, team, roles, SLA, infrastructure and servers, custom properties, authoritative definitions, tags, variables.
  - Quality rules have `type` text, library, sql or custom.
  - Library metrics: `nullValues`, `missingValues`, `invalidValues` (with `validValues` or `pattern`), `duplicateValues`, `rowCount`.
  - Operators: `mustBe`, `mustNotBe`, `mustBeGreaterThan`, `mustBeGreaterOrEqualTo`, `mustBeLessThan`, `mustBeLessOrEqualTo`, `mustBeBetween`, `mustNotBeBetween`.
  - Dimensions: accuracy, completeness, conformity, consistency, coverage, timeliness, uniqueness. Plus `severity` and `schedule`.
  - Proposal: this is our dataset-contract model, stored in PG and exported as `.odcs.yaml`.
- **Data products.** Three different specifications share similar names:
  - Bitol's Open Data Product Standard, ODPS v1.1.0 (2026-09-08), Apache-2.0, built to pair with ODCS. This is the one to follow.
  - The Linux Foundation "Open Data Product Specification" v4.0 (opendataproducts.org), business-facing.
  - Open Data Mesh's Data Product Descriptor Specification (DPDS) 1.0.0, still a DRAFT.
- **DCAT 3** (W3C Recommendation, 2024-08-22) for exporting the catalog: `dcat:Catalog`, `Dataset`, `Distribution` (`accessURL`, `downloadURL`, `mediaType`), `DataService`, `DatasetSeries`, `CatalogRecord`, `Relationship`, with `dcterms:` publisher, licence, temporal, spatial and conformsTo.
- **schema.org/Dataset.** JSON-LD (`distribution` → `DataDownload`, `variableMeasured`, `temporalCoverage`, `license`, `creator`). Useful only for datasets that are deliberately published, since Google Dataset Search reads it.
- **Quality vocabularies to copy, not install.**
  - Great Expectations (1.24.0, Apache-2.0): `expect_column_values_to_not_be_null`, `…_to_be_unique`, `…_to_be_between`, `…_to_match_regex`, `…_to_be_in_set`, `expect_table_row_count_to_be_between`.
  - Soda: `missing_count`, `duplicate_count`, `invalid_count`, `row_count`, `freshness`, and schema checks.
  - Map both onto ODCS library metrics.
  - Note: PyPI metadata for soda-core 4.x says "Proprietary", while 3.x was Apache-2.0. One more reason to copy the words only.

### 6. Query safety when people and AI agents write SQL

DuckDB's own security guidance:
- Disable external access: `enable_external_access=false`.
- Restrict paths: `allowed_directories` and `allowed_paths`; `disabled_filesystems='LocalFileSystem'`.
- Turn off autoload and autoinstall of extensions, and set `allow_community_extensions=false`. This one cannot be undone in a running database.
- Freeze the settings with `lock_configuration=true` (optionally `allowed_configs` for what may still change).
- Cap resources: `threads`, `memory_limit`, `max_temp_directory_size`.
- Never `allow_unredacted_secrets`. Do not run as root.
- Above all, sandbox the process, because the settings "are not a substitute for proper sandboxing".

Verified on this host with DuckDB 1.5.6:
- With `allowed_directories=[test dir]` + `enable_external_access=false` + `lock_configuration=true`, reading the allowed Parquet worked.
- `read_csv('/etc/passwd')` was refused with a PermissionException.
- `SET enable_external_access=true` afterwards was refused.
- There is no timeout setting; `con.interrupt()` from a timer cancelled a runaway query at 1.0 s.

Proposed design, defence in depth:
1. **Syntax-tree allow-list (sqlglot 30.22.0, MIT; also in Ubuntu as `python3-sqlglot`).**
   - Allow exactly one statement, and only SELECT or WITH … SELECT.
   - Table references must resolve only to names in our published-views registry.
   - Refuse table functions (`read_*`, `glob`, `query_table`), ATTACH, COPY, INSTALL, LOAD, SET, PRAGMA, CALL and EXPORT.
   - Limit the size of the syntax tree.
2. **Per-person views.**
   - Each query runs in a fresh in-memory DuckDB connection.
   - The worker attaches the DuckLake `READ_ONLY`, through a PostgreSQL role with SELECT-only grants on the catalog schema.
   - It then creates `TEMP` views per person: masked columns (hash, partial or null) and row filters taken from our policy tables.
   - Only then does it lock the configuration and run the user's SQL against those views.
   - Caveat: the lake directory must be in `allowed_directories` for DuckLake to read it. Step 1 is what stops a direct `read_parquet()` that would bypass the views, and step 4 is the backstop.
3. **Limits.**
   - Wall-clock watchdog plus `interrupt()`.
   - Wrap the query in `LIMIT n+1` or use `fetchmany`, and cap result size in bytes.
   - `memory_limit` around 1–2 GB per worker, `threads` 2–4, a capped temp directory.
4. **Process sandbox.** A pool of query workers under systemd: `MemoryMax`, `CPUQuota`, `TasksMax`, `ProtectSystem=strict`, `ReadOnlyPaths=/srv/lake`, `PrivateTmp`, `NoNewPrivileges`, `IPAddressDeny=any` (no exfiltration), and a dedicated user. A worker is killed and replaced on overrun. `memory_limit` does not cover every allocation, for example Python result conversion.
5. **Writer.** One writer service runs ingestion, compaction and maintenance through a queue.
6. **Where PostgreSQL is the engine** (the catalog, our metadata): a read-only role with `default_transaction_read_only=on`, `statement_timeout`, `idle_in_transaction_session_timeout`, `temp_file_limit`, row-level security and `security_barrier` views, which the kernel already uses.
7. **Audit.** Log every query (the person or agent, the SQL, the referenced datasets, rows returned) to the activity trail, and emit it as an OpenLineage RunEvent.

### 7. PII detection on the box

- **Tier 1: our own deterministic classifiers.** No new dependency.
  - Column-name heuristics (email, ssn, dob, phone, iban…).
  - Regular expressions with validators: Luhn for card numbers, mod-97 for IBAN, US SSN area and group rules, email and phone (libphonenumber optional), IP and MAC addresses, dates of birth.
  - Dictionaries: first and last names, our own customer names from the applications' K7 shares.
  - Profile a sample: fraction of matches per column, distinct counts, entropy. Classify columns, not every cell, and record results as tags with a confidence score that a person confirms.
- **Tier 2: Microsoft Presidio, optional.** Version 2.2.364 (2026-07-22), MIT. It moved to the `data-privacy-stack` GitHub organisation. Python 3.10–3.13.
  - Runs entirely locally as pip packages or self-run containers (`ghcr.io/data-privacy-stack/presidio-analyzer`).
  - Its default spaCy model `en_core_web_lg` is several hundred MB; `en_core_web_sm` is about 12 MB with weaker named-entity recognition.
  - Its `PatternRecognizer` supports regular expressions, deny lists and context words.
  - Use it for free-text columns only. spaCy's math kernels on this CPU without AVX are untested.

---

## Part 2: This server (read-only inventory, 2026-10-10)

- **OS:** Ubuntu 24.04.5 LTS, kernel 6.8.0-146.
- **CPU:** 12 vCPU (2 sockets × 6), "QEMU Virtual CPU version 2.5+". Flags include sse4_1, sse4_2, popcnt and aes, but **no avx, avx2, fma, bmi2 or avx512**; glibc reports x86-64-v2 as the highest supported level.
- **RAM:** 15 GiB total, about 4 GiB used, 10 GiB available (buffers and cache 10). Swap 3 GiB.
- **Disk:** `/`, `/srv` and `/var` are the same logical volume: 522 GB, 43 GB used, 458 GB free (9%).
- **PostgreSQL:** 17.11 (PGDG). `shared_preload_libraries = pgaudit,pg_stat_statements`. `shared_buffers` is 128 MB, `work_mem` 4 MB, `max_connections` 100, `max_worker_processes` 8.
  - Available extensions include: amcheck, btree_gin/gist, citext, dblink, **file_fdw**, hstore, **maludb_core 0.106.0**, **pg_partman 5.5.0**, pg_stat_statements, **pg_trgm**, pgaudit 17.1, pgcrypto, **postgres_fdw**, tablefunc, tsm_system_rows/time, unaccent, uuid-ossp, **vector 0.8.7**, xml2, plus the other contrib modules.
  - Installed packages: postgresql-17, -partman, -pgaudit, -pgvector, and **postgresql-server-dev-17** (so pgrx and C extensions could be built).
- **apt:** PGDG noble lists (updated 2026-10-09) contain **no duckdb, parquet, iceberg, lake or mooncake package for postgresql-17**. `apt-cache policy` for `postgresql-17-duckdb`, `-pg-duckdb`, `-parquet`, `-pg-parquet` and `-pg-lake` returned nothing. The only hit for "analytics" is `postgresql-17-timescaledb`. `python3-sqlglot` is available from Ubuntu.
- **DuckDB CLI:** not installed (`duckdb: command not found`).
- **Python:** system 3.12.3. None of duckdb, pyarrow, polars, pyiceberg, pandas, openpyxl or numpy is importable system-wide.
  - Venvs, all Python 3.12.3:
    - `/var/www/mcp/venv`: mcp 1.30.0, asyncpg 0.31.0, httpx, pydantic, starlette, uvicorn. No data libraries.
    - The `mcp/venv` of hr, projects, helpdesk, spaces, gl, consultant_tracking, doccloud and txtschedules: mcp 1.30.0 only.
    - `/srv/apps/shareholder_intelligence/mcp/venv`: mcp 1.30.0, **openpyxl 3.1.5, xlrd 2.0.2**.
    - `/srv/apps/processcore/services/.venv` and `/srv/apps/cidery/services/.venv`: psycopg 3.3.6.
  - None of these has duckdb, pyarrow, polars, pyiceberg or pandas.
- **PHP:** 8.3.6 under Apache `mpm_prefork` + mod_php. Modules include pdo_pgsql, pgsql, curl, json, mbstring, zip, xml, sodium, **FFI**, pcntl, sockets.
  - Apache's php.ini: **`upload_max_filesize` 2M, `post_max_size` 8M, `memory_limit` 128M, `max_execution_time` 30**.
  - Large CSV and XLSX uploads therefore need chunked or resumable upload (DocCloud's 8 MB parts) and asynchronous Python processing.
- **Services:** postgresql@17-main, maludb-api and the certstudy MCP services. No MinIO, Garage, SeaweedFS, Docker or Java.

**Tests in a scratch directory** (wheels extracted there, nothing installed; host load average about 5 at the time):

| Library | Result |
|---|---|
| DuckDB 1.5.6 | Runs. Built 20 M rows in 7.8 s; GROUP BY over 1,000 groups in 0.51 s; wrote ZSTD Parquet in 3.2 s; filtered Parquet scan in 0.22 s. Lockdown and interrupt verified. Built into the wheel: parquet, json, icu, core_functions. **Downloaded separately:** ducklake, postgres_scanner, httpfs, iceberg, delta, excel, spatial. |
| PyArrow 26.0.0 | Runs. Read 20 M rows in 2.0 s; row-group statistics and encodings readable. |
| Polars 2.0.0, default `polars-runtime-32` | **Crashed (core dumped)** after the CPU-feature warning. |
| `polars[rtcompat]` | Runs (0.26 s on the test aggregate). |

**Side effect to report.** When the default Polars wheel crashed, Ubuntu's crash reporter (apport) wrote **`/var/crash/_usr_bin_python3.12.1000.crash`** (2.5 MB, owner maludb, 2026-10-10 12:12). The permission system blocked my attempt to delete it. The owner may remove it with `rm /var/crash/_usr_bin_python3.12.1000.crash`.

---

## (a) Recommended foundation and alternatives: decisions to approve

**D1 (recommended): DuckLake 1.0 + PostgreSQL 17 catalog + local Parquet + DuckDB 1.5.x embedded in our own workers.**
- **The catalog.**
  - The catalog is a new database `<tenant>_lake`. DuckLake's tables go in schema `ducklake` (`METADATA_SCHEMA`), and our application's own tables go in a separate schema.
  - Our tables: datasets and products (ODCS/ODPS fields), contracts, quality rules and results, OpenLineage runs, PII tags, access policies (row filters and masks), and saved queries.
- **The services.**
  - One writer service: ingest → staged → curated, flushing inlined data, merging files, expiring snapshots, cleaning up files.
  - A pool of sandboxed read-only query workers.
  - The plugin's records and activity MCP servers.
- **The PHP front end** browses the catalog and our metadata directly with SQL, and sends query execution to the workers over localhost.
- **Pinning and offline extensions.** Pin DuckDB 1.5.6 and vendor the DuckLake extension offline into a fixed `extension_directory`, with autoinstall off. Adopt DuckDB 2.0 only after its first patch releases.
- **Why.** It matches our PostgreSQL-centred stack. It gives multi-table ACID commits, time travel, a change feed for incremental jobs, inlining for small SMB writes, per-file encryption, and an MIT licence. Its exits are Iceberg (export or import) and DataFusion.
- **Trade-offs.** Young (six months at 1.0), and effectively one production-grade engine.

**D2 (alternative): Apache Iceberg v2, with the catalog pointer in PostgreSQL.**
- Writes through PyIceberg `SqlCatalog` + PyArrow; reads through DuckDB's iceberg extension. Optionally, our own Python implementation of the Iceberg REST OpenAPI later, so that DuckDB and other engines can write.
- **For:** the most widely supported format, Apache governance, maximum future interoperability.
- **Against:** the metadata lives in JSON and Avro files, so the PHP catalog browser needs a worker for everything. There is no multi-table atomicity without a REST server. Manifest and metadata maintenance is heavier, and support for v3 is uneven across engines. Implementing a REST catalog properly is a project of its own.

**D3 (alternative, minimal): PostgreSQL-native.**
- Curated data in partitioned PostgreSQL tables (pg_partman is installed; BRIN indexes), raw files on disk, and Parquet only for export or archive (DuckDB, or pg_parquet built from source).
- **For:** one engine, native GRANT, row-level security and `statement_timeout`; the fastest to build; fine below roughly 50–100 GB.
- **Against:** column scans are an order of magnitude slower, and storage is several times larger than ZSTD Parquet. It competes with every application on a shared cluster (`shared_buffers` 128 MB). There is no time travel, and it is not a lake format, so outgrowing it means migrating.

**Not recommended now:**
- **pg_lake.** It is effectively Crunchy Data Warehouse: a preload change and restart of the shared cluster, a daemon, a heavy build, and storage oriented to `s3://`. Reconsider only on a dedicated PostgreSQL cluster.
- **pg_duckdb.** No packages, preload required, DuckDB memory inside each PostgreSQL backend, and a slowing release cadence.
- **pg_mooncake, Delta, Hudi, and any object-storage server including MinIO.**

## (b) Risks

- **Single writer.**
  - A native `.duckdb` file allows one read-write process. With DuckLake + PostgreSQL, many processes can write, but conflicting deletes or compactions abort and must be retried.
  - Mitigation: one writer service with a queue, and readers attach READ_ONLY with a SELECT-only catalog role.
  - PostgreSQL catalog throughput under heavy contention is low (anecdotal ~5 transactions per second with 20 writers), which is irrelevant at SMB ingest rates if writes are serialised.
- **Memory.**
  - 15 GiB is shared with PostgreSQL, about 16 applications, MaluDB and the MCP servers. DuckDB's default takes 80% of RAM.
  - Cap each worker (1–2 GB), cap temp files, cap the pool size, and enforce `MemoryMax` in systemd.
  - Out-of-core spill works, but spilling onto the shared root volume is a disk-full risk, which is a reason for a separate volume.
- **CPU.**
  - x86-64-v2 rules out the default Polars runtime and probably costs throughput in PyArrow, DataFusion and spaCy.
  - Any new wheel must be smoke-tested here. DataFusion, deltalake and spaCy were not tested.
  - Owner fix: Proxmox CPU type `host` or `x86-64-v3`, which needs a VM restart.
- **Maturity and churn.**
  - DuckLake 1.0 (April 2026; 1.1 pending), DuckDB 2.0 (due October 2026, a major version), Polars 2.0 (four days old) and the DuckDB iceberg extension (writes only via REST) are all young.
  - Pin exact versions and upgrade deliberately behind our own tests.
- **Security.**
  - DuckDB is not a sandbox. Row filters and masks are enforced by our views and the syntax-tree allow-list, not by the engine.
  - The DuckLake catalog holds file paths and encryption keys, so catalog read access equals data access. The catalog roles must be guarded like the data itself.
  - Extensions run with full process privileges, so vendor and pin them.
- **Licences.**
  - Everything recommended is MIT, Apache-2.0 or the PostgreSQL licence.
  - AGPL applies only to Garage and MinIO, if ever used.
  - Soda Core 4.x metadata says Proprietary, so do not depend on it.
  - The DuckLake specification is governed by the DuckDB Foundation, and MotherDuck sells a hosted version. Our use is of the open specification, which the owner's rule permits.
- **Operations that become ours:** compaction, snapshot expiry, orphan-file cleanup, backups that keep the catalog and data consistent (`pg_dump` first, then the data directory, never deleting files newer than the last expiry), and restore drills.

## (c) Exactly what would have to be installed (D1)

1. **Python packages, in the application's own venv** (no system pip):
   - `duckdb==1.5.6`, `pyarrow==26.0.0`, `sqlglot==30.22.0`, `psycopg[binary]` 3.3.x
   - `python-calamine==0.8.3` and/or `openpyxl==3.1.5`
   - Optional: `polars[rtcompat]==2.0.0` (never plain `polars` on this CPU), `presidio-analyzer`/`presidio-anonymizer==2.2.364` + a spaCy model (`en_core_web_sm` to start), `openlineage-python==1.53.0` (or emit the JSON ourselves).
   - About 150 MB of wheels for the core set.
2. **DuckDB extensions,** fetched once from extensions.duckdb.org for v1.5.6 linux_amd64 into a root-owned, read-only `extension_directory` (`ducklake`, `postgres_scanner`, `excel`; optionally `iceberg`, `httpfs`, `spatial`). Signed extensions only; autoinstall and autoload off at runtime.
3. **PostgreSQL:** one new database `<tenant>_lake` and the roles `lake_owner`, `lake_writer` (DML on the catalog) and `lake_reader` (SELECT on the catalog). **No new extension, no `shared_preload_libraries` change, no restart.**
4. **Filesystem:** `/srv/lake/<tenant>/{landing,quarantine,lake,exports,tmp}` owned by a new `bos-lake` system user, mode 0750. Recommended: a dedicated logical volume or mount (owner's decision).
5. **systemd units:** `lake-writer` (one), `lake-query@` (a pool with `MemoryMax`, `CPUQuota`, `ProtectSystem=strict`, `ReadOnlyPaths`, `IPAddressDeny=any`, `PrivateTmp`, `NoNewPrivileges`), and the application's records and activity MCP units.
6. **PHP and Apache:** a per-vhost upload path designed for chunked uploads (raise `post_max_size` and `upload_max_filesize` only modestly), with no FFI use.
7. **Optional, owner:** change the VM CPU type to `x86-64-v3` or `host`. The DuckDB CLI binary for administrators is optional.
8. **Nothing from apt is required,** and there is no object-storage server.

---

## Sources

- Iceberg spec: https://iceberg.apache.org/spec/ (raw: https://raw.githubusercontent.com/apache/iceberg/main/format/spec.md); REST OpenAPI: https://raw.githubusercontent.com/apache/iceberg/main/open-api/rest-catalog-open-api.yaml ; releases: https://iceberg.apache.org/releases/ ; 1.12.0 notes: https://www.dremio.com/blog/apache-iceberg-1-12-0-whats-new-breaking-changes-and-upgrade-guide ; 1.10 v3 GA: https://www.dremio.com/blog/whats-new-in-apache-iceberg-1-10-0-and-what-comes-next/
- PyIceberg configuration (SqlCatalog): https://py.iceberg.apache.org/configuration/ ; 0.11 release: https://iceberg.apache.org/blog/apache-iceberg-python-0.11.0-release/
- Delta protocol: https://raw.githubusercontent.com/delta-io/delta/master/PROTOCOL.md ; 4.1: https://delta.io/blog/2026-03-01-delta-lake-4-1-0-released ; 4.2: https://delta.io/blog/2026-04-17-delta-4-2-released
- Hudi 1.1: https://hudi.apache.org/blog/2025/11/25/apache-hudi-release-1-1-announcement ; roadmap: https://hudi.apache.org/roadmap
- DuckLake: spec https://ducklake.select/docs/stable/specification/introduction ; queries https://ducklake.select/docs/stable/specification/queries ; FAQ https://ducklake.select/faq.html ; catalog choice https://ducklake.select/docs/stable/duckdb/usage/choosing_a_catalog_database.html ; storage https://ducklake.select/docs/stable/duckdb/usage/choosing_storage.html ; connecting https://ducklake.select/docs/stable/duckdb/usage/connecting ; inlining https://ducklake.select/docs/stable/duckdb/advanced_features/data_inlining ; encryption https://ducklake.select/docs/stable/duckdb/advanced_features/encryption ; conflicts https://ducklake.select/docs/stable/duckdb/advanced_features/conflict_resolution ; change feed https://ducklake.select/docs/stable/duckdb/advanced_features/data_change_feed ; unsupported https://ducklake.select/docs/stable/duckdb/unsupported_features ; roadmap https://ducklake.select/roadmap ; 1.0 announcement https://duckdb.org/2026/04/13/ducklake-10.html ; DataFusion https://ducklake.select/2026/07/29/bringing-ducklake-to-datafusion/ ; news https://ducklake.select/news/
- DuckDB: 1.5.0 https://duckdb.org/2026/03/09/announcing-duckdb-150 ; 1.5.6 https://duckdb.org/2026/09/28/announcing-duckdb-156 ; security https://duckdb.org/docs/current/operations_manual/securing_duckdb/overview.html ; concurrency https://duckdb.org/docs/current/connect/concurrency.html ; faulty CSV https://duckdb.org/docs/current/data/csv/reading_faulty_csv_files.html ; excel https://duckdb.org/docs/current/core_extensions/excel.html ; iceberg https://duckdb.org/docs/current/core_extensions/iceberg/overview.html and https://duckdb.org/2025/11/28/iceberg-writes-in-duckdb ; delta https://duckdb.org/docs/current/core_extensions/delta.html ; extensions https://duckdb.org/docs/current/extensions/installing_extensions
- pg_duckdb: https://github.com/duckdb/pg_duckdb and docs/settings.md there ; 1.0 https://motherduck.com/blog/pg-duckdb-release/
- pg_lake: https://github.com/Snowflake-Labs/pg_lake (README, docs/building-from-source.md) ; https://www.snowflake.com/en/blog/engineering/pg-lake-postgres-lakehouse-integration/
- pg_parquet: https://github.com/CrunchyData/pg_parquet (LICENSE: PostgreSQL licence)
- pg_mooncake: https://github.com/Mooncake-Labs/pg_mooncake ; acquisition https://venturebeat.com/ai/databricks-set-to-accelerate-agentic-ai-by-up-to-100x-with-mooncake
- Polars installation (rtcompat): https://docs.pola.rs/user-guide/installation/ ; PyArrow and SIMD: https://bugs.archlinux.org/task/75747
- Parquet: https://parquet.apache.org/docs/file-format/ ; encodings https://parquet.apache.org/docs/file-format/data-pages/encodings/ ; configurations https://parquet.apache.org/docs/file-format/configurations/ ; Arrow format https://arrow.apache.org/docs/format/Columnar.html
- MinIO: https://infoq.com/news/2025/12/minio-s3-api-alternatives/ ; https://blocksandfiles.com/2025/06/19/minio-removes-management-features-from-basic-community-edition-object-storage-code/ ; https://pinggy.io/blog/minio_archived_self_hosted_s3_alternatives/ ; Garage https://github.com/deuxfleurs-org/garage and https://archlinux.org/packages/extra/x86_64/garage/
- OpenLineage: https://openlineage.io/docs/spec/object-model ; schema https://raw.githubusercontent.com/OpenLineage/OpenLineage/main/spec/OpenLineage.json
- ODCS: https://bitol-io.github.io/open-data-contract-standard/latest/ ; quality https://bitol-io.github.io/open-data-contract-standard/latest/data-quality/ ; v3.1.0 https://bitol.io/bitol-announces-odcs-v3-1-0-stronger-smarter-and-stricter/ ; ODPS https://bitol.io/announcing-odps-v1-0-0-building-the-language-of-data-products/ ; DPDS https://github.com/opendatamesh-initiative/odm-specification-dpdescriptor/blob/main/versions/1.0.0-DRAFT.md
- DCAT 3: https://www.w3.org/TR/vocab-dcat-3/ ; schema.org Dataset: https://schema.org/Dataset and https://developers.google.com/search/docs/data-types/dataset
- SodaCL: https://docs.soda.io/soda-cl-overview
- Presidio: https://presidio.dataprivacystack.org/installation/ (moved from https://microsoft.github.io/presidio/)
- Lakekeeper: https://github.com/lakekeeper/lakekeeper
- Versions and licences were also read live from the PyPI JSON API and the GitHub API on 2026-10-10.

The wheels and test scripts (about 180 MB) were kept outside the repository and are not committed.
