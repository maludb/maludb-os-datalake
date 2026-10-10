# The engine proof — what DuckLake, DuckDB and this host actually do (Phase 0, 2026-10-10)

`tests/engine/run.sh` rebuilds the scratch world from nothing (`setup_scratch.sh`: the catalog databases `dl_engine` and
`dl_engine_restore`, the engine roles `dl_eng_lake` and `dl_eng_query`, `var/engine/`) and runs three proofs: **`cpu.sh` 9 checks,
`proof_engine.py` 75, `proof_query.py` 73 — 157 green**, in about 78 s. `tests/engine/timing.sh` is the 50-million-row set (§10).
Everything below is what the proofs showed, not what the documentation promised; where the design is contradicted it says so.

## 1. What is pinned, and this CPU

`services/requirements.txt`: duckdb 1.5.6 · pyarrow 26.0.0 · sqlglot 30.22.0 · psycopg[binary] 3.3.6 · python-calamine 0.8.3 ·
openpyxl 3.1.5 · xlrd 2.0.2 · **pytz 2026.5** (DuckDB needs it to hand a `TIMESTAMPTZ` to Python — snapshots' timestamps fail
without it) · et_xmlfile, typing_extensions (dependencies). DuckDB extensions for v1.5.6 linux_amd64, vendored by
`services/bin/fetch_extensions.sh`: ducklake `ac7595b0`, postgres_scanner `318dabb`, excel `f4c72b5`, **httpfs `4bc690d`** (§2).
The VM's CPU is "QEMU Virtual CPU version 2.5+", **x86-64-v2: SSE4.2 and POPCNT, no AVX, AVX2, FMA, BMI2 or AVX-512**; every
pinned wheel passes its smoke test on it (`cpu.sh` 9/9). No Polars anywhere.

## 2. DuckLake on PostgreSQL — the exact forms that worked

- **Attach**: `ATTACH 'ducklake:postgres:<conninfo>' AS lake (DATA_PATH '<dir>/lake/', METADATA_SCHEMA 'ducklake', ENCRYPTED)`.
  The schema must exist first, owned by the writer's role; DuckLake creates its tables there at the first attach: `ducklake_column`,
  `_column_mapping`, `_column_tag`, `_data_file`, `_delete_file`, `_file_column_stats`, `_file_partition_value`,
  `_file_variant_stats`, `_files_scheduled_for_deletion`, `_inlined_data_tables`, `_macro`, `_macro_impl`, `_macro_parameters`,
  `_metadata`, `_name_mapping`, `_partition_column`, `_partition_info`, `_schema`, `_schema_versions`, `_snapshot`,
  `_snapshot_changes`, `_sort_expression`, `_sort_info`, `_table`, `_table_column_stats`, `_table_stats`, `_tag`, `_view`
  (28, plus one `ducklake_inlined_data_<table>_<version>` per table that has received an inlined batch).
- **ENCRYPTED writes need `httpfs` loaded.** Without it: *"DuckDB currently has a read-only crypto module loaded. Please ensure
  httpfs is loaded"* — DuckDB's built-in mbedtls only decrypts. Reading encrypted files needs no httpfs. So the **writer loads
  httpfs; the query service never does.** The failed write left a 0-byte `ducklake-….parquet` behind (an orphan for
  `ducklake_delete_orphaned_files`).
- **The writer's role needs `TEMPORARY` on the catalog database**: DuckLake updates catalog rows through a temporary table —
  `ducklake_set_option` failed with *"permission denied to create temporary tables in database"* after `REVOKE ALL … FROM PUBLIC`.
- **The query role** needs `CONNECT`, `USAGE` on the schema and `SELECT` on every catalog table **including the inlined-data
  tables the writer creates later** → `ALTER DEFAULT PRIVILEGES FOR ROLE <writer> IN SCHEMA ducklake GRANT SELECT ON TABLES TO
  <query>`. Proven: it cannot `INSERT` into `ducklake_snapshot` nor `CREATE` in the schema, and a `READ_ONLY` attach refuses DML.
- **`duckdb_databases()` prints an attached catalog's conninfo verbatim** — a `password=` in it is readable by anyone who can run
  that function. The engine takes `passfile=` (libpq) and `LakeConfig` refuses a DSN that carries `password=`.
- **Options** live in `ducklake.ducklake_metadata` (key/value): `version 1.0`, `data_path`, `encrypted true`,
  `data_inlining_row_limit` (set with `CALL ducklake_set_option('lake', 'data_inlining_row_limit', 10)`; it also takes a schema and a
  table, so a limit can be per table).
- **Commits with an author**: `CALL ducklake_set_commit_message('lake', <author>, <message>)` inside the transaction;
  `ducklake_snapshots('lake')` shows `snapshot_id, changes, author, commit_message`. Connecting writes nothing (proven) once the
  zones exist and the option is set.
- **Time travel**: `… FROM lake.raw.t AT (VERSION => n)`; a table that did not exist at `n` raises *"does not exist at version n"*.
- **Change feed**: `ducklake_table_changes(catalog, schema, table, from, to)` → `snapshot_id, rowid, change_type` (`insert`,
  `delete`, `update_preimage`, `update_postimage`) + the columns; `ducklake_table_insertions` / `_deletions` likewise; `to` must be
  ≤ the current snapshot. `MERGE INTO` works on DuckLake tables; `INSERT` and `MERGE` return their row counts.
- **Maintenance** (signatures read from `duckdb_functions()`): `ducklake_flush_inlined_data(catalog, table_name, schema_name)` ·
  `ducklake_merge_adjacent_files(catalog [, table, schema], max_file_size, min_file_size, max_compacted_files)` ·
  `ducklake_rewrite_data_files(catalog [, table, schema], delete_threshold)` · `ducklake_expire_snapshots(catalog, older_than |
  versions, dry_run)` · `ducklake_cleanup_old_files(catalog, older_than, cleanup_all, dry_run)` ·
  `ducklake_delete_orphaned_files(catalog, older_than, cleanup_all, dry_run)`. Proven in that order: twelve small files → one, the
  inlined rows → a Parquet file, the orphan gone, no file of an expired snapshot left on disk.
- **Concurrency**: a `READ_ONLY` reader sees the writer's commit on its next statement; three writer processes appending to one
  table at once all committed (DuckLake retried; no error surfaced). A DuckLake connection keeps a PostgreSQL session **idle in
  transaction** while it holds a DuckDB transaction (seen while a restore hung) — a long-lived writer must not hold a transaction
  across jobs.

## 3. Ingestion realities

- **DuckDB's CSV sniffer was fooled by one malformed row** (it chose `|` and one column). The sniff takes the header line's
  delimiter as a hint (`sniff_csv(…, delim => ?, ignore_errors => true, sample_size => -1)`) and then agrees with the file.
- **Windows-1252 is refused** (*"Invalid unicode … not utf-8 encoded"*; DuckDB reads UTF-8, UTF-16 and Latin-1 only): the original
  is kept byte-for-byte in landing and a UTF-8 working copy is transcoded in Python — é, the curly quote and € survive.
- **Rejects**: `read_csv(…, store_rejects = true, rejects_table, rejects_scan)` keeps each bad row's line, column, error type
  (`TOO MANY COLUMNS` here), message and **raw text**. The raw text can hold personal data → `load_rejects.raw` is personal: masked
  in the UI like its dataset, inside an erasure's scope.
- The sniffer proposes `BIGINT` for card numbers: a numeric proposal with leading zeros or 12+ digits is turned back into text.
- Workbooks through python-calamine (all of .xlsx/.xls/.ods): a merged cell's value sits in its first cell with empties after —
  a two-row header is detected and joined (`Q1` over `units` → `q1_units`), flagged for the person to confirm.
- **`_dl_row` means**: the sheet's own row number (workbooks); Parquet's `file_row_number + 1`; the ordinal of the parsed data row
  for CSV and JSON (a rejected line is not counted — its file line is in the reject).
- **Loads stream.** The first version staged every file in a TEMP table (in memory); it was rewritten so CSV, JSON and Parquet go
  `INSERT INTO lake.raw.x … SELECT … FROM read_*()` without materialising (§10's 25-million-row file).
- Idempotency is checked in the data itself (`_dl_landing_sha256`): the same file again is a no-op with no snapshot. On a large
  raw table that check scans the column (SHA-256 values defeat min/max pruning) — the application's `loads.idempotency_key`
  (unique) must be the first guard; the engine's is the backstop.

## 4. Encryption, keys and what the catalog holds

- Encrypted files carry the `PARE` magic; reading one without its key: *"is encrypted, but 'encryption_config' was not set"*.
- The keys: a 16-byte AES key per file, **base64 in `ducklake_data_file.encryption_key`** (and `ducklake_delete_file`);
  `ducklake_list_files()` returns them as BLOBs. DuckLake decrypts with `read_parquet(path, encryption_config = {footer_key_value:
  <the BLOB>})`; `PRAGMA add_parquet_key('k', '<base64>')` does NOT work — a 24-character string is taken as an AES-192 key
  (*"Computed AES tag differs"*).
- **So `SELECT` on the catalog + read access to `lake/` = every row.** The query role has both by necessity (it decrypts); only the
  query service may hold it. The web application's role must not `SELECT` **any** `ducklake.*` table directly — not
  `encryption_key`, not `path`, **not `ducklake_inlined_data_*` (plain rows)**, and not the column statistics (§4a).
- **Inlined data is NOT encrypted.** A batch of ≤ 10 rows lives in `ducklake_inlined_data_<table>_<version>` as plain PostgreSQL
  rows until it is flushed; `ENCRYPTED` covers Parquet files only. Personal data of small loads is therefore in plain text in the
  catalog database — and in PostgreSQL's WAL and base backups — until the flush.
- **§4a — the column statistics keep values.** `ducklake_file_column_stats.min_value` / `max_value` and `ducklake_table_column_stats`
  hold real values in plain text — a file's minimum email IS an email. The web application never reads them; erasure covers them
  (it recomputes them); `verify()` searches them — the proof (check 60) shows the subject there before the erasure and nowhere after.

## 5. The query service — what each layer actually stops

Locked as the design says (`allowed_directories` = the lake, `enable_external_access=false`, `lock_configuration=true`, memory and
threads capped at connect): `read_csv('/etc/passwd')` → *PermissionException*; any `SET` → *"the configuration has been locked"*;
`ATTACH` refused; a lake file read directly → *encrypted*. **But the locked connection still reaches the attached lake by name**
(`<alias>.curated.people` answers). Layer 3 does not hide the catalog: the allow-list's `catalog_reference` rule and a **random
alias per request** (`lk_<12 hex>`) are what keep a caller to the views. Equally load-bearing: refusing every table function by
name — `ducklake_list_files()` returns the encryption keys and `duckdb_databases()` the conninfo; the deny list is computed at
start from `duckdb_functions()` (every `table` and `table_macro`) plus names (`getenv`, `current_setting`, …) and prefixes
(`read_`, `duckdb_`, `ducklake_`, `pragma_`, `dl_` …). 32 forbidden forms each refused by their rule (`proof_query` 1–32).
- **No timeout setting exists**: the watchdog's `interrupt()` stopped a runaway cross join set at 1 s after 1.57 s.
- An out-of-memory query fails cleanly (*Out of Memory Error*); the next request runs.
- The caller's SQL is **regenerated from sqlglot's tree** (`tree.sql('duckdb')`) after the rewrite — fidelity is sqlglot's; an
  unsupported form is refused as `parse` (`EXPORT DATABASE`), never passed through.
- Masks are view expressions; the keyed hash is `'h:' || left(sha256(salt || value), 16)` with the salt in the view's text — not
  readable through the allow-list (`duckdb_views()` refused) and absent from every answer and error the proof provoked.
- A row filter is checked node by node (the dataset's columns, literals, comparisons, IN, BETWEEN, LIKE, AND/OR/NOT, CAST) — a
  subquery, a function or another table's column refuses the whole policy (fail closed).
- `as_of` applies to every view; a granted dataset that did not exist then is refused by name (`absent_as_of`).

## 6. Promotion, checks and transforms

- Promotion types with `TRY_CAST(trim(src) AS type)` — a value that does not cast is the row's reason `cast:<column>`, never a
  crash; blocking row checks quarantine with all reasons joined; deduplication is `QUALIFY row_number() OVER (PARTITION BY key ORDER
  BY ordering DESC, _dl_load_id DESC, _dl_row DESC) = 1`; an empty target takes a plain `INSERT`, otherwise `MERGE` (an older row
  never overwrites a newer one). Incremental by `ducklake_table_insertions(since + 1, now)`; re-running the same input changes
  nothing (proven, quarantine included).
- A failing check reports at most 20 KEYS, hashed with the installation salt when the key is personal — never values.
- **`incremental_merge` transforms are correct only for row-wise SQL** (projections, filters, joins to a dimension); an aggregate or
  a window over a whole input must be `replace`.
- **A transform reads through its owner's views — so a masked input arrives masked, permanently.** The product never widens what it
  exposes (proven: the email arrives hashed), and equally can never be unmasked for anyone: the owner's level at save time is the
  ceiling of the product.

## 7. Erasure — the method, and what the proof caught

`erase.scan` → `erase.erase` → `erase.verify`. The proof: (1) at **every remaining snapshot** of every table, no matching row;
(2) **every file on disk, decrypted with its catalog key**, searched; (3) no file on disk the catalog does not list; (4) no inlined
row in the catalog matches; (5) the landing files of the affected loads purged. Then the same erasure on an **unencrypted,
uncompressed twin lake** (`ducklake_plain`), where a raw byte search of every file means something: found before, absent after.
- **The proof caught what the scan missed**: customer 00042's *orders* (keyed by `customer_id`, no email) were still in
  `raw.orders` — found by `verify` at every snapshot and in the decrypted file. Erasure's scope must come from the personal-data map
  **and** lineage **and** the references between datasets (the referential checks' declarations), and `verify` must stay
  independent of the scan.
- **Expiring snapshots is lake-wide**: a snapshot is the whole lake's state, so an erasure that expires "every snapshot that held
  the rows" ends time travel before *now* for **every** dataset, not just the subject's.
- `VACUUM FULL` of the catalog tables that held inlined rows and file entries (a deleted row's bytes otherwise stay in the heap) takes
  a brief exclusive lock on them — readers wait for it.
- PostgreSQL's WAL and earlier base backups, and earlier lake backups, still hold the rows until they expire.

## 8. Backups and the restore drill

`pg_dump -Fc -n ducklake` through the writer's role (`PGPASSFILE`), THEN a copy of `lake/` and `landing/`, THEN a manifest of every
file's SHA-256 and the dump's snapshot. Restore: drop the target schema's tables (the schema itself pre-made, with its grants),
`pg_restore -L <list without the SCHEMA entry>`, copy the files, **`UPDATE ducklake_metadata SET value = <new dir> WHERE key =
'data_path'`** (files are stored with relative paths, so a relocation is that one row), attach `READ_ONLY`, run fixed queries:
identical to the live lake at the dump's snapshot. A tampered file is refused as `corrupt` before anything is restored.
Found on the way: `pg_restore` without an input file reads standard input and waits for ever — every subprocess has
`stdin=DEVNULL` and a timeout.

## 9. Design changes this proof asks for (each with its evidence above)

1. **§6.1, §14 — the writer loads `httpfs`** (ENCRYPTED writes; §2). ROOT_STEPS fetches four extensions, not three. Because httpfs
   can reach URLs, the writer's unit also gets `IPAddressDeny=any` + `IPAddressAllow=127.0.0.1` (PostgreSQL) — pulls are the
   worker's (Inventory's client), never the writer's.
2. **§14 — the engine roles**: `GRANT TEMPORARY ON DATABASE <tenant>_datalake TO datalake_lake`; `ALTER DEFAULT PRIVILEGES FOR ROLE
   datalake_lake IN SCHEMA ducklake GRANT SELECT ON TABLES TO datalake_query`; `idle_in_transaction_session_timeout` on both
   (a DuckLake connection can sit idle in transaction); `datalake_rw` (PHP) gets **no** grant on schema `ducklake` at all — the
   `dl_lake_*` views it reads must be SECURITY DEFINER functions or views owned by a role that excludes `encryption_key`, `path`, the
   inlined tables and the statistics' values.
3. **§14 env** — `DL_LAKE_DB_DSN` / `DL_QUERY_DB_DSN` carry `passfile=`, never a password; one passfile per service user (0600).
4. **§6.1 — inlined data is plain text in the catalog** (§4): the worker flushes inlined data **hourly**, not only in the nightly
   maintenance; erasure vacuums the inlined tables. (Alternative for the owner: `data_inlining_row_limit` 0 on tables with personal
   columns — no plain rows ever, at the price of small files.)
5. **§6.7 — erasure ends time travel lake-wide** (§7) — say so on the erasure screen before approval; and the scope needs the
   references between datasets: add `references_dataset_id` / `references_column` to `dataset_columns` (or take them from the
   referential checks) so `follow` is derived, not typed.
6. **§6.5 — the allow-list is load-bearing** for "views only" (§5): the random alias and `catalog_reference` stay; the deny list is
   computed from the engine at start; the name prefix `dl_` is reserved for our own helpers.
7. **§6.6 — transforms**: `incremental_merge` only for row-wise SQL (the spec must say which, or the writer refuses an aggregate in
   incremental mode); a product made from masked inputs is masked for ever — an admin decides whether a transform's owner holds
   `read_unmasked`.
8. **§6.3 — `load_rejects.raw` is personal** (§3); `_dl_row`'s meaning per format is as stated in §3.
9. **§6.2 — profiles**: a personal column's min, max and top values are masked before they are stored (proven); the profile of a
   non-personal column keeps its values.
10. **§6.5 — the query pool**: the prototype (`services/query/server.py`) is one threaded process bounded by a semaphore; DuckDB's
    `memory_limit` is per connection, so the real bound is systemd's `MemoryMax` per worker process (`datalake-query@N`), as designed.
11. **requirements** — `pytz` (DuckDB hands a `TIMESTAMPTZ` to Python through it).
12. **§6.1 — small-dataset promotion** costs ~5 s each at 400 tables: no catalog-wide metadata lookups per call.

## 10. Timings (`tests/engine/timing.sh`, 50 million rows over 200 datasets)

| Step | Time |
|---|---|
| Generate the landing files (Parquet) | 125 s |
| Load into raw, all 200 (streamed, encrypted) | 286 s (the 25-million-row table 17 s) |
| Promote raw → curated, all 200 | 1,042 s (the 25-million-row table 61 s) |
| Query: one customer over 25 million rows | 3.8 s |
| Query: aggregate by region and month | 4.7 s |
| Query: join 25 million to 125,000 rows | 3.7 s |
| Query: masked scan (count distinct email) | 6.7 s |
| Maintenance across 400 tables | 128 s |
| Erasure: scan 400 tables | 98 s |
| Erasure: delete, rewrite, expire, clean | 135 s |
| Erasure: verify (decrypt and search every file) | 335 s |

Writer capped at 3 GB / 4 threads, query service at 1.5 GB / 2 threads. The erasure found 50 rows and its proof came back clean. The
encrypted lake (raw + curated) is 3.30 GB. **Peak memory 3,569 MB at a 3 GB DuckDB limit** — DuckDB's limit does not bound the whole
process, so the writer's systemd `MemoryMax` must be at least 4 GB (or DuckDB's limit 2.5 GB). Total wall time about 36 minutes; the
overrun is a **fixed cost of ~5 s per small dataset in promotion** (199 tables of 125,000 rows ≈ 980 s, against 61 s for the 25-million-row
table) — most likely catalog-wide metadata lookups (`duckdb_tables()` / `duckdb_columns()`) on every call; slice 5 removes them.
Numbers also in `var/timing.json` (not committed).
