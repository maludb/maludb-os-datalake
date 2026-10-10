# Build spec: the lake engine — the writer, its jobs and the worker (design §6.1, §6.3, §6.4, §6.6, §6.7, §6.9; D2, D3, D9, D11, D12)

The writer is the **only** process that writes the lake: one long-running Python service (`datalake-writer`, `services/lake/writer_main.py`)
working the `lake_jobs` queue with the library Phase 0 built and proved (`services/lake/*.py`, `tests/engine/run.sh` — 157 checks;
`tests/engine/FINDINGS.md`). PHP and the MCP servers never open DuckDB: they **enqueue** (`dl_job_enqueue`) and **read** (the `mcp_*` views,
`dl_lake_*`, the query service). The PHP worker (`bin/worker.php`, every minute) decides *when* a job is due; the writer decides *how*. Slices
2, 5, 6, 7 and 8 build the job kinds; slice 2 builds the loop itself. Everything below is the contract those slices replicate.

## 1. The writer service and its job loop
- **The loop** (`writer_main.py`): `LakeConfig.from_env()`; one PostgreSQL connection (psycopg, the `datalake_lake` role via
  `DL_LAKE_DB_DSN`) for the queue and the application's tables; **one DuckDB writer connection per job** (`engine.writer_connection(cfg)`),
  closed when the job ends — never a DuckDB transaction held across jobs (FINDINGS §2: a DuckLake connection sits *idle in transaction* in
  PostgreSQL while one is open; `idle_in_transaction_session_timeout` 10 min is the backstop). Up to `writer_concurrency` (dl_settings, 2)
  jobs at once as threads, each with its own DuckDB connection; `memory_limit` = `writer_memory_mb / writer_concurrency`.
- **Claiming**: `SELECT * FROM dl_job_claim('<host>:<pid>:<thread>')` (db/009) — never two running jobs on one dataset; a `backup_lock`
  runs alone; maintenance never beside a backup. Nothing claimable → sleep 2 s (a `LISTEN lake_jobs` wakes it early: `dl_job_enqueue`
  issues `pg_notify('lake_jobs', kind)` — slice 2 appends the NOTIFY by `CREATE OR REPLACE`).
- **Finishing**: `dl_job_finish(id, ok, result jsonb, error text)` — ok → `done` with `result`; a failure re-queues with back-off `2^attempts`
  minutes until `max_attempts` (3), then `failed`. An exception's first line ≤ 2,000 characters is the error; **never a row value, SQL text
  with literals, a path or a credential in `error` or `result`**.
- **Kinds** (`lake_jobs.kind`; `params` → `result`):

| Kind | Enqueued by | params | result |
|---|---|---|---|
| `sniff` | `/upload/finish` (slice 2) | `{upload_id}` | `{format, columns, sheets, rows_estimate}` (and `upload_sessions.sniff` written) |
| `load` | `upload_map_confirm`, `load_queue`, a source pull | `{load_id}` | `load_raw`'s answer: `{status loaded|noop, rows_read, rows_loaded, rows_rejected, rows_rescued, drift, snapshot_id}` |
| `profile` | after every `load`, `promote`, `transform` | `{dataset_id}` | `{snapshot_id, columns: n, proposals: n}` |
| `promote` | `promote_run`, `promote_rebuild` (`{full: true}`), a load into a raw dataset with a curated twin under an active contract | `{dataset_id (curated), full?}` | `promote`'s answer: `{status promoted|nothing_new, rows_in, rows_promoted, rows_quarantined, warnings, snapshot_id}` |
| `checks` | `check_run`, after `promote`, the worker (hourly/daily checks) | `{dataset_id, check_id?}` | `{ran, failed}` |
| `transform` | `transform_run`, the worker (schedule / `after_inputs`) | `{transform_id}` | `{status ran|no_op, mode, rows_written, snapshot_id}` |
| `export` | `export_make` | `{export_id}` | `{rows, bytes, truncated}` |
| `maintenance` | the worker nightly, `maintenance_run`, `snapshot_expire_now` (`{older_than}`), the hourly flush (`{flush_only: true}`) | `{scope, dataset_id?, older_than?, flush_only?}` | `maintenance.run`'s steps |
| `retention` | the worker nightly | `{retention_id}` | `{rows_deleted, snapshot_id}` |
| `erasure` | `erasure_scan` (`{phase: scan}`), `dl_erasure_approve` (`{phase: erase}`) | `{phase}` + `ref_id` = the erasure | scan: `{found}`; erase: `{proof_ok}` |
| `rollback` | `load_rollback` | `{load_id}` | `{removed, snapshot_id}` |
| `backup_lock` | `deploy/backup.sh`, `drill_run` | `{purpose: backup|drill}` | `{held_ms}` |

- **Idempotency**: a load is keyed by `loads.idempotency_key` (unique — the first guard) and by `_dl_landing_sha256` in the data (the
  engine's backstop: a same-file reload answers `noop` with no snapshot); a promotion is a MERGE by key; a transform overwrites its own
  output snapshot; maintenance and retention are naturally repeatable; an erasure re-run finds nothing and proves again.
- **The hourly inline flush** (db/017 `inline_flush_minutes`, 60): the worker enqueues `maintenance {flush_only: true}` — the writer runs
  `ducklake_flush_inlined_data('lake')` alone (FINDINGS §4: inlined rows are plain PostgreSQL rows, in WAL and backups, until flushed).
- **Author and message** on every commit (`engine.commit(con, author, message)`): `author` = `dl:m<member_id>` of `lake_jobs.requested_by`
  (`dl:system` for the worker, `dl:a<agent_id>` for an agent); `message` = `<kind> <ref>` (`load 4182`, `promote curated.deals`,
  `transform monthly_revenue v3`, `erasure 12: raw.crm_deals`).

## 2. Connections, roles and files
- **LakeConfig** (`services/lake/engine.py`): `DL_LAKE_DB_DSN`, `DL_QUERY_DB_DSN` (libpq conninfo with `passfile=` — a `password=` is
  refused: `duckdb_databases()` prints the conninfo, FINDINGS §2), `DL_STORAGE_DIR`, `DL_EXTENSION_DIR`, `DL_WRITER_MEMORY` (3GB),
  `DL_WRITER_THREADS` (4), `DL_WRITER_TEMP_MAX` (20GB). Passfiles `/etc/datalake/lake.pgpass` (datalake-writer, 0600) and `query.pgpass`
  (datalake-query, 0600), written by `deploy/os-provision.sh`.
- **Attach**: `ATTACH 'ducklake:postgres:<dsn>' AS lake (DATA_PATH '<store>/lake/', METADATA_SCHEMA 'ducklake', ENCRYPTED)`; zones `raw`,
  `curated`, `product`, `sandbox`, `quarantine` made if missing; `data_inlining_row_limit` 10. **The writer loads `ducklake`, `postgres_scanner`,
  `httpfs`** (ENCRYPTED writes need httpfs's crypto); the query service `ducklake`, `postgres_scanner`; the sniff loads `excel` only when DuckDB
  reads a workbook (calamine reads them by default). Extensions vendored read-only in `/var/lib/datalake/duckdb_extensions`; autoinstall,
  autoload and community extensions off.
- **Roles** (db/006, `os-provision.sh`): `datalake_lake` owns schema `ducklake`, holds `TEMPORARY` on the database, DML on its job tables
  (db/007–014 grants); `datalake_query` SELECT on `ducklake.*` (default privileges) + `EXECUTE` on `dl_query_policy`, `dl_query_policy_app`,
  `dl_query_run_log`. `datalake_rw` / `_records_ro` / `_activity_ro` hold **nothing** on schema `ducklake` (their window is `db/engine/001`).
- **The data directory** `DL_STORAGE_DIR` = `/srv/lake` on its own volume (the owner's (a)): `landing/` www-data:datalake 2770 (uploads by
  PHP, pulls by the writer — `landing/<aa>/<bb>/<sha256>`, `blobs.storage_path` relative), `lake/` datalake-writer:datalake 0750, `exports/`
  datalake-writer:www-data 2750, `tmp/` datalake-writer:datalake 2770 (DuckDB's spill; `max_temp_directory_size` caps it), `backup/` root 0700.
  The writer's unit `UMask=0027`.
- **Units**: `datalake-writer.service` (User datalake-writer, Group datalake, `MemoryMax=4096M` — the proof peaked at 3,569 MB under a 3 GB
  DuckDB limit —, `ReadWritePaths={{DL_STORAGE_DIR}}`, `IPAddressDeny=any` + `IPAddressAllow=localhost`, `ProtectSystem=strict`);
  `datalake-query@.service` + `datalake-query.target` (query-service.md §2); `datalake-worker.timer` (PHP, every minute).

**The job modules** (slice 2 builds the frame, every later slice adds its kinds): `services/lake/jobs/__init__.py` holds `DISPATCH = {kind: handler}`; each handler is `job_<kind>(con, pg, job) -> dict` in `services/lake/jobs/<area>.py` (`ingest.py` — sniff, load, profile, rollback, slice 2; `queries.py` — sandbox_save, export, slice 3; `quality.py` — promote, checks, slice 5; `transforms.py` — transform, slice 7; `health.py` — maintenance, retention, erasure, backup_lock, slice 8); a kind with no handler yet fails at once with *"not built until slice N"* (Phase 2). `writer_main.py` claims (`dl_job_claim`), dispatches, finishes (`dl_job_finish`) and never holds a DuckDB transaction across jobs.

## 3. Sniff, map and load
- **Sniff** (`sniff.sniff(path, work_dir)`, job `sniff`): the answer is stored as `upload_sessions.sniff` and `sniff_status = 'done'`:
  `{format: csv|workbook|json|parquet, encoding: utf-8|utf-16|latin-1|cp1252|null, delimiter, header: bool, read_options: {delim, quote,
  escape, header, encoding}, sheets: [{name, header_rows, rows_estimate, warning?, columns: [...]}]|null, columns: [{source_name, name,
  proposed_type, sample: [≤5 strings], proposed_personal_kind?, confidence?}], rows_estimate, warnings: []}`. CSV: the header line's
  delimiter is the hint (`sniff_csv(…, delim => ?, ignore_errors => true, sample_size => -1)`); cp1252 → a UTF-8 working copy in `tmp/`
  (the landing original never changes). Workbooks through python-calamine (xlsx, xlsm, xls, ods): a two-row header is joined and flagged.
  **Identifiers stay text** (`keep_text_if_identifier`: leading zeros or ≥ 12 digits → VARCHAR). The sniff also runs the classifier on the
  50-row sample (`classify.classify_column`) so the mapping screen shows proposals. **Samples are shown only to the uploader** (the
  `upload-map` screen) and never logged.
- **The mapping** `upload_map_confirm` posts (slice 2): `columns` = `{<source_name>: {"name": "<raw column>", "type": "<proposed type kept
  as a hint for the contract>", "personal_kind": "<kind>"|null, "skip": bool}}`, `sheet`, `header_row`, `dedupe_key[]`, `mode`. The
  handler: creates the raw (or sandbox) `datasets` row when new (zone, name, title = the file's, owner = the uploader, `evolution_mode`
  rescue), the `loads` row (`idempotency_key = '<dataset_id>:<sha256>'`), the `dataset_columns` rows the person confirmed (as
  `datalake_rw` — `classified_by = 'person'` for a kind the person set), and enqueues `load`. A saved template is an `import_templates` row.
- **Load into raw** (`load.load_raw(con, dataset_key, landing_path, landing_sha256, load_id, author, mapping, evolution_mode, read_options)`): (a **raw** dataset keeps every column `VARCHAR`; a **sandbox** load passes `types={column: duckdb_type}` from the confirmed mapping — each column `TRY_CAST`, a cell that does not cast becomes NULL and one `CAST` row in `load_rejects` (line, column, the raw text at write level) — slice 2's typed sandbox load)
  every column `VARCHAR` as received + `_dl_load_id BIGINT`, `_dl_row BIGINT`, `_dl_loaded_at TIMESTAMPTZ`, `_dl_landing_sha256 VARCHAR`,
  `_dl_rescued JSON`; streamed (`INSERT … SELECT FROM read_*()`). **`_dl_row`**: a workbook's own row number; Parquet `file_row_number + 1`;
  CSV and JSON the ordinal of the parsed data row (a rejected line's file line is in its reject). **Rejects** (`store_rejects`) →
  `load_rejects` (line, byte position, column, error type, message ≤ 500, raw line ≤ 2,000 — personal: shown at write level only, purged with
  the landing window, in an erasure's scope). The writer then: `loads` counts, `status`, `snapshot_id`, `finished_at`; `datasets.row_count`,
  `byte_size`, `file_count`, `last_snapshot_id`, `data_updated_at` (from `dl_lake_tables`' sources — the engine's numbers);
  `dl_lineage_edge(NULL, source_id, dataset_id, 'load')` when a source fed it; a `lineage_runs` START/COMPLETE pair (job `load.<name>`).
- **Drift** by `evolution_mode`: `add` — a new column added to raw and a `schema_versions` row (`detected_by load`, `decision pending`);
  `rescue` (default) — unknown fields into `_dl_rescued` (`rows_rescued` counted) and the same pending row; `fail` — the load fails
  (`status failed`, an incident `kind load` via `incidents`, error "new columns: a, b — the dataset refuses drift"). A removed or retyped
  column is always a pending `schema_versions` row, never applied (R50). `breaking` = removed or retyped.
- **Classifiers and profiles** (job `profile`, `profile.profile(con, key, personal=…)`): `SUMMARIZE` per column → `dataset_columns.profile`
  (null %, distinct count and %, min, max, mean, stddev, length range, top 10) and a `column_profiles` row per column per snapshot; **min,
  max and top values masked** (`profile.mask_text`) for a column whose `personal_kind` is set or whose classification is confidential or
  restricted; the classifier over ≤ 1,000 non-null values → `dataset_columns.proposed_personal_kind`, `proposed_confidence` (only when no
  person has confirmed the column: `classification_confirmed_at IS NULL`). A proposal ≥ 0.8 makes the column **masked at once** (the writer
  sets `personal_kind` with `classified_by = 'rule'` — masked from the first load, §3 rule 4) and leaves the confirmation to a person; below 0.8
  it stays a proposal only. The writer writes `dataset_columns` as `datalake_lake` (the engine's fields: `physical_type`, `ordinal`,
  `first_snapshot_id`, `removed_snapshot_id`, `profile`).

## 4. Promotion and checks
- **The contract dict** the writer builds from `contracts` (active): `{key: [...], ordering: <column>|null, columns: [{name, source?, type,
  nullable, unique}]}`. **Typing**: `TRY_CAST(trim(src) AS type)`; a value that does not cast gives the row the reason `cast:<column>`;
  `nullable: false` → `null:<column>`.
- **Dedupe**: `QUALIFY row_number() OVER (PARTITION BY key ORDER BY ordering DESC, _dl_load_id DESC, _dl_row DESC) = 1`. **Quarantine**:
  rows failing a **blocking** row check or a cast go to `quarantine.<name>` with `_dl_reason` (every reason joined `;`), the provenance kept;
  non-blocking failures are `warnings`. **Write**: an empty target → `INSERT`; else `MERGE INTO curated.<name> USING … ON key` — an older
  row never overwrites a newer one (ordering). **Incremental** over `ducklake_table_insertions('lake', 'raw', name, since+1, now)` where
  `since` is the last promotion's raw snapshot (kept in `lake_jobs.result.raw_snapshot` of the last done `promote` for the dataset);
  `promote_rebuild` = `full: true`. Re-running the same input changes nothing (proven, quarantine included).
- **Check kinds** (`checks.run_checks(con, key, checks, key_columns, personal, salt_hex)`; `checks.params`): `not_null {max_failures}`,
  `unique {columns[]}`, `accepted_values {values[]}`, `range {min, max}`, `regex {pattern}`, `row_count {min, max, change_pct}` (previous =
  the last result's value), `freshness` (the worker's: hours since `data_updated_at` vs `freshness_error_after`), `referential {dataset_key,
  column}` (values absent from the other dataset's column — read through the writer's own connection; the other dataset must be in the
  lake), `schema` (no pending breaking `schema_versions`), `custom_sql {sql}` (a SELECT returning failing rows, checked by the query
  service's allow-list against the dataset's own key before it runs). Each result → `dl_check_result_record(check_id, load_id, snapshot_id,
  value, threshold, passed, rows_failed, failing_keys, took_ms, error)` (db/010): it opens or resolves the incident, rechecks certification,
  notifies. **Failing keys**: at most 20, the key columns' values hashed with the installation salt (`'h:' || left(sha256(salt||v), 16)`)
  when a key column is personal — never values otherwise either beyond the key.
- **Freshness** is the PHP worker's (§7): `dl_freshness_due()` → for each dataset a freshness check's result (creating the `freshness`
  check row on first need, `source = 'contract'`).

## 5. Transforms and lineage
- **Run** (`transform.transform(con, output_key, sql, policy, author, name, version, mode, key_columns, since)`): the policy is
  `transforms.frozen_policy` (refused when NULL — "the transform must be saved again"); the writer creates the owner's views from it
  (`query/policy.create_views`) and runs the SQL through the allow-list (`allowlist.check`) — **a transform reads exactly what its owner could
  at save time, masked as they were** (FINDINGS §6: a masked input stays masked in the product for good). `replace`: `CREATE OR REPLACE TABLE`
  in one commit. `incremental_merge`: **row-wise SQL only** — the writer refuses an aggregate, a window or a DISTINCT over a whole input
  (sqlglot: any `AggFunc`, `Window`, `Distinct` at the top level → `error: incremental_merge needs row-wise SQL — use replace`) and merges the
  rows derived from each input's insertions since its last run by the output's contract key. `transform_runs` gets the input snapshots, the
  output snapshot, rows written.
- **The output's columns**: after every run the writer upserts `dataset_columns` for the output (name, ordinal, `physical_type`,
  `logical_type`), marks dropped ones `removed_snapshot_id`, and copies an input column's `personal_kind` to an output column that sqlglot's
  lineage says is DIRECT from it (a product that keeps an email keeps its kind).
- **Lineage**: one `lineage_runs` START and COMPLETE (or FAIL) per load, promotion, transform, export, erasure and maintenance pass —
  `run_uuid` UUIDv7, `job_kind`, `job_name` (`load.<name>`, `promote.<name>`, `transform.<name>`), `inputs`/`outputs` `[{namespace:
  "datalake", name: <key>, version: <snapshot_id>, facets: {}}]`, `facets` (`sql` for a transform, `outputStatistics {rowCount}`,
  `errorMessage` on FAIL). `dl_lineage_edge(from_dataset, from_source, to_dataset, via, transform_id, column_map)` — `column_map` from
  `sqlglot.lineage` per output column (`{"col": [{"dataset": key, "column": c, "kind": "DIRECT"|"INDIRECT"}]}`; table-level always).
- **The fixed cost** (FINDINGS §10: ~5 s per small dataset at 400 tables): the writer never calls `duckdb_tables()` / `duckdb_columns()`
  over the whole catalog per job — it asks for one table (`WHERE schema_name = ? AND table_name = ?`) and caches the zone schemas per
  connection. Slice 5's proof times 50 small promotions and fails above 1 s each.

## 6. Maintenance, retention, erasure, backups
- **Maintenance** (`maintenance.run`, nightly at `maintenance_hour:maintenance_minute`, `maintenance_runs` row): flush inlined data → merge
  adjacent files toward `target_file_mb` → rewrite files with > 10 % deletes → expire snapshots older than `snapshot_keep_days` (except a
  snapshot an open incident or a running erasure pins) → clean up old files older than the expiry + 3 days → delete orphaned files; then
  `storage_daily` per dataset and zone. Recorded: each step's rows and ms, files merged/rewritten/deleted, bytes freed.
- **Retention** (job `retention` per enabled `retention_policies` row, nightly): `DELETE … WHERE <by_column or _dl_loaded_at> < now() -
  keep_days` as one commit per dataset; `last_run_at`, `last_rows`. Landing: the worker deletes `blobs` past `purge_after` (unpinned, no
  queued load) and marks their loads `landing_purged_at`; the file in `landing/` is unlinked by the writer (a `maintenance` step) — PHP never
  deletes from `landing/` (the directory is setgid `datalake`, so uploads are group-writable and the writer can remove both kinds).
- **Erasure** (design §6.7; db/013, db/017): `phase scan` — `erase.scan(identifiers, personal_map, follow)` with `personal_map` from
  `dl_erasure_targets()` rows `via column|lineage` and `follow` from `via reference` rows (`{from: references_dataset, key:
  references_column, to: [{dataset, column}]}`) → `erasure_requests.found` `{key: {rows, columns, zone}}`, status `awaiting_approval`.
  `phase erase` (after `dl_erasure_approve(id, true)`): `erase.erase` (DELETE per target, one commit each) → rewrite the affected files →
  **expire every snapshot older than now** (lake-wide — the approval acknowledged it) → clean up old files → `vacuum_inlined` (VACUUM FULL of
  the inlined tables — a brief exclusive lock) → purge the landing blobs of the affected loads (`landing_purged_at`) → `load_rejects` of
  those loads deleted → **`erase.verify(needles, …)` independent of the scan** (every snapshot, every file decrypted with its key, files
  not in the catalog, inlined rows, column statistics, landing) → `proof` `{datasets, rows_deleted, snapshots_expired, files_rewritten,
  blobs_purged, verify: {…}, backups_clear_on: now + backup_keep_days, steps: [{name, ended_at}]}`, status `done` (or `failed` with the
  verify's findings), `dl_erasure_seal(id)` hashes the identifiers, `dl_notify` `erasure_done` to the requester and the approver.
- **Backups** (`deploy/backup.sh`, the owner's cron; `backups` row): enqueue `backup_lock` and wait for it to run → `backup.backup(cfg,
  dest)`: `pg_dump -Fc -n ducklake` through `PGPASSFILE` first, then `lake/` and `landing/`, then a manifest of every file's sha256 and the
  dump's snapshot → release. **Drill** (`drill_run`, `backup.restore_drill`): restore into `<tenant>_datalake_drill` and `tmp/drill/`, rewrite
  `ducklake_metadata.data_path`, attach READ_ONLY, run the fixed queries (row counts of every dataset at the dump's snapshot) → `backups`
  `kind drill` with `checks`; a tampered file → `corrupt`. Every subprocess `stdin=DEVNULL` and a timeout.

## 7. The PHP worker's schedule (`bin/worker.php`, every minute, `worker_passes` row)
| Pass | When | Does |
|---|---|---|
| `upload_sessions` | every run | expire open sessions past `expires_at`; delete their parts |
| `schedules` | every run | enqueue `load` jobs for sources due (`schedule_minutes`, not paused, past `backoff_until`) — the pull itself (HTTP, SFTP, K7, PostgreSQL) is the PHP fetch layer's (slice 6), landing a blob then a `loads` row; `transform` jobs for transforms due (cron) or `after_inputs` with new input snapshots; hourly/daily `checks` |
| `freshness` | hourly | `dl_freshness_due()` → freshness check results (§4) |
| `inline_flush` | every `inline_flush_minutes` | `maintenance {flush_only: true}` |
| `maintenance` | nightly at the setting | `maintenance {scope: all}` |
| `retention` | nightly | a `retention` job per policy; `dl_query_runs_prune()`; `check_results` and `column_profiles` past their windows deleted; landing blobs past `purge_after` |
| `usage_rollup` | 00:15 daily | `dl_usage_rollup(yesterday)`; reindex usage (`dl_index_dataset`) for datasets with usage |
| `expiry` | every run | grants past `expires_at` → `revoked_at = expires_at` (trail row, `access_decided`-style notice to the grantee); pending requests past `wanted_until` → `expired` |
| `exports` | every run | delete export files past `expires_at` (`storage_path` NULL, row kept) |
| `outbox` | every run | `notification_outbox` → MaluMail (email) and the kernel's K6 (text); five attempts |
| `dispatches` | every run | `agent_dispatches` `sent` → the kernel's chat endpoint (one turn of the Steward); retries |
| `engine_health` | every 5 min | the writer's heartbeat (the oldest `running` job's age, the queue depth) — a stuck job over 2 h → a notice to the admin |
| `key_usage_prune` | daily | the kit's |

## 8. Statuses (screens and JSON say these words)
`lake_jobs`: queued · running · done · failed · cancelled. `loads`: queued · running · loaded · no_op ("already loaded — the same file") ·
failed · rolled_back. `source_pulls`: running · ok · partial · failed · blocked. `transform_runs`: queued · running · ok · no_op · failed.
`maintenance_runs`: running · ok · failed. `backups`: running · ok · failed (`corrupt` in `checks`). `erasure_requests`: draft · scanning ·
awaiting_approval · erasing · expiring · done · failed · cancelled. `upload_sessions.sniff_status`: none · queued · done · failed. A queued
job shows "Queued — the writer will start it shortly"; a running one its kind and elapsed time (the status partials poll every 5 s).

## 9. Proofs
- `tests/engine/run.sh` (157) and `timing.sh` stay; every slice that touches the engine runs `run.sh` again before its own proof.
- A slice's proof: a scratch catalog (`tests/engine/setup_scratch.sh` → `dl_engine`), a scratch application database
  (`tests/phase0/schema.sh` → `dl_proof`, or the slice's own `dl_devN`), the writer run **in-process** on one job at a time
  (`writer_main.run_one(job_id)`), `php -S` on ports **9001–9007** (never another), fixtures from `tests/fixtures/` (synthetic only).
- What each adds: slice 2 — sniff of every fixture format, the mapping, a load, a same-file no-op, rejects, drift in each mode, the
  classifier's ≥ 0.8 auto-mask, profiles masked, rollback as a new snapshot; slice 5 — promotion typed, deduped, quarantined, incremental,
  every check kind, an incident opened and resolved, certification lost, 50 small promotions under 1 s each; slice 6 — each connector's pull
  against `tests/fetch/` stubs into a load; slice 7 — replace and incremental transforms, the refusal of an aggregate in incremental mode,
  lineage rows and edges, a masked input masked in the product; slice 8 — maintenance's steps, retention, the erasure end to end with
  references and the verify, a backup and a drill, a tampered file refused.
- **Never touch an installed database, `/srv/lake`, systemd or Apache** — scratch names only (`dl_*`), `var/` for files.

## Decisions taken here (for the owner)
1. The writer runs `writer_concurrency` jobs as threads of one process, each with its own DuckDB connection and `memory_limit =
   writer_memory_mb / writer_concurrency` (the unit's `MemoryMax` bounds the process).
2. `dl_job_enqueue` gains a `pg_notify('lake_jobs', …)` (slice 2, `CREATE OR REPLACE`) so the writer wakes without polling hard.
3. A classifier proposal with confidence ≥ 0.8 masks the column at once (`classified_by = 'rule'`), still awaiting a person's confirmation;
   below 0.8 it is a proposal only.
4. A promotion's incremental starting point is the last done `promote` job's `result.raw_snapshot` (no new column).
5. `incremental_merge` refuses aggregates, windows and DISTINCT at the top level (sqlglot) — the writer's check, not the database's.
6. A transform output column DIRECT from a personal input column inherits its personal kind.
7. The writer unlinks landing files (uploads included); PHP never deletes from `landing/`.
8. The drill restores into `<tenant>_datalake_drill`, which `deploy/backup.sh --drill` (root, as postgres) creates before the job and drops
   after — the writer's role is never given CREATEDB.
