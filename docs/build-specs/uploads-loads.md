# Build spec: uploads, loads and the sandbox (slice 2 — THE FIRST EXEMPLAR)

Builds on `lake-engine.md` (§1 the writer's loop, §2 connections, §3 sniff, map, load, §8 statuses) and `query-service.md` (§2 the protocol, §3 the
policy, §5 views and limits, §6 the audit, §7 the `preview` kind, §8 the clients). What exists at the end: **the easy path of design §1, whole** — a
person drops a spreadsheet on *Upload* (parts of 8 MB, resumable, up to 5 GB), sees it land once by its hash, the lake sniffs it (encoding, delimiter,
sheets, header rows, each column's name, type and personal-data proposal), the person confirms the mapping on one screen, the writer loads it, profiles
it and proposes what looks personal, and **the dataset's Preview tab shows 100 rows through the query service, masked as the person may see them**;
rejected lines kept with their line and error; drift proposed, never applied silently; a load replayed or rolled back as a new snapshot; saved
mappings for next month's file; my sandbox and its quota. **This slice is THE FIRST EXEMPLAR**: it builds the writer's job loop with its first
four job kinds and the query service's production protocol with its first request kind — every later slice that adds a writer job (5, 7, 8) or a
query kind (3, 7, 9) copies how this one registers, runs, reports, logs and proves it.

Schema (db/007, 009, 011, 013, 017, never modified): `upload_sessions`, `upload_parts`, `blobs`, `import_templates`, `loads`, `load_rejects`, `lake_jobs`,
`dl_job_enqueue()`, `dl_job_claim()`, `dl_job_finish()`, `dl_loads_blob_count()` (the landing window) (db/009); `datasets`, `dataset_columns`, `column_profiles`,
`schema_versions`, `dl_dataset_derive()` (db/007); `query_runs`, `dl_query_run_log()` (db/011); `dl_sandbox_usage()` (db/013); `dl_query_policy()`, `dl_masks_for()`
(db/008, 018); `mcp_uploads`, `mcp_loads`, `mcp_load_rejects`, `mcp_import_templates`, `mcp_lake_jobs`, `mcp_schema_versions`, `mcp_column_profiles` (db/016).
Engine (built and proven in Phase 0): `services/lake/{engine,sniff,classify,load,profile}.py`, `services/query/{allowlist,policy,service,server}.py`.
**The database is the referee**: the load's idempotency key, the blob's reference count and landing window, the technical metadata (the writer's role
alone), the sandbox name rule, the derived classification; **the engine is the referee** of encoding, parsing, rejects and the masks.

## Divergences from the design
1. **A sandbox load is typed; a raw load is text.** An engineer's raw dataset lands every column as `VARCHAR` with the `_dl_*` provenance (design §6.1);
   a person's sandbox upload lands **typed by the confirmed mapping** (`TRY_CAST(trim(src) AS type)`): a cell that does not cast is `NULL` and one
   `load_rejects` row per failing line and column (`error_type` `CAST`, at most 1,000 a load) — "drop a spreadsheet, confirm the columns, query it" means
   numbers are numbers. lake-engine §3 holds the function (`load_raw(…, types=…)`).
2. **The sniff's 50 sample rows live in `upload_sessions.sniff` only until the mapping is confirmed or discarded or the session expires**, then the worker
   nulls `sniff->'sample'` — they are the uploader's own file shown back to them, never kept.
3. **An upload makes no lineage edge** (no source row exists for a person's file); its provenance is the load and the landing hash. A source's loads
   (slice 6) record `source → dataset`.

## Who may do what
upload (the doors): `sandbox.upload` · upload_map_confirm: `sandbox.upload`; a raw target needs `loads.manage` and `write` on the dataset; people ·
upload_discard: own, people · load_queue: `loads.manage` + `write` · load_rollback: `loads.manage` + `write`, pauses for an agent (`deletion`) ·
template_save: `sandbox.upload` · template_delete: its maker or `loads.manage` · the sandbox and its uploads: own · a load's page: `read` on its dataset
(its rejected lines' raw text: `write`).

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `upload` | `/upload` | the drop zone (many files at once), a row per file with its parts' progress, the hash check, "already here" when the blob exists (dedupe), then "Map it" — the link to `upload-map`; my last uploads below (`find_uploads`) |
| `upload-map` | `/uploads/{id}/map` | while the sniff runs: "Reading the file…" (polls `/uploads/{id}/sniff` every 2 s); then the mapping: the format, encoding and delimiter found (editable), the sheet (a select for a workbook) and the header row (1–50, the two-row header joined and flagged), **a table at 1280 / cards at 375: each source column → its name (editable, normalised), its type (`VARCHAR`, `BIGINT`, `DECIMAL(18,2)`, `DOUBLE`, `BOOLEAN`, `DATE`, `TIMESTAMP`), the personal-data proposal with its confidence ("looks like email addresses — 98 %": keep or clear)**, the first 50 rows under it; the target: *My sandbox* (a new dataset's name → `sandbox.m{me}_{name}`) or, for engineers, *A raw dataset* (picker of raw datasets I may write, or a new name) with append or replace; the dedupe key (columns); apply a template / save as a template; Confirm (`page-header-form`) |
| `sandbox` | `/sandbox` | my sandbox datasets as cards (title, key, rows, size, data updated, "made from an upload" or "saved from a query"), my quota bar (`dl_sandbox_usage`), Retire (slice 1's action) |
| `load-list` | `/loads?dataset=&source=&status=&since=` | loads as a table (a ledger): id, dataset, source or "upload", status badge, read / loaded / rejected / rescued, the checks' outcome, queued and finished, the requester |
| `load-view` | `/loads/{id}` | one load: the counts, the file (name, size, hash's first 12, "landing purged — not replayable" when purged), the schema seen and the drift (added / removed / retyped columns, the evolution mode applied, "a proposal waits" linking the dataset's History), rescued fields count, the checks' outcome (slice 5 fills), the error; the rejected lines (line, column, error type, message — the raw text only at `write`); Replay (`load_queue`) · Roll back (`load_rollback`) |
| `templates` | `/templates` | saved mappings as cards: name, kind, columns count, made by; edit (name, mapping as a table), delete |

The **Preview tab** of `dataset-view` (slice 1's frame) is built here: `app/views/datasets/partials/tab-preview.php` replaces the pending partial — 100 rows
through the query service (`preview`), a column header marked "masked for you" (and the mask's kind on hover), the as-of picker (the dataset's last ten
snapshots from `dl_lake_snapshots` for engineers, else "latest"), at 375 one record at a time with Prev / Next; a refusal renders its sentence and Request access.

## Files (exactly these)
- Doors (not screens): `html/upload/{start,part,finish}.php` (DocCloud's protocol: `start` {filename, byte_size, sha256_claimed?} → {upload_id, part_bytes,
  parts_total}; `part` {upload_id, part_no, the bytes as the body}; `finish` {upload_id} → assemble, sha256, `finfo`, refuse executables, dedupe into
  `blobs` at `landing/<aa>/<bb>/<sha256>` under `DL_STORAGE_DIR`, queue `sniff`), `html/uploads/sniff.php` (the polling partial), `html/loads/status.php`
  (the polling partial), `html/datasets/preview.php` (the Preview tab's partial)
- Screens and actions: `html/upload/index.php`, `html/uploads/{map,discard}.php` (map.php: GET the screen, POST `upload_map_confirm`), `html/sandbox.php`,
  `html/loads/{index,view,queue,rollback}.php`, `html/templates/{index,save,delete}.php`
- `app/features/uploads/{queries,present,write,store}.php` (store: the parts, the assembly, the hash, `finfo`, the blob's path — DocCloud's `store.php` rules),
  `app/features/loads/{queries,present,write}.php`, `app/features/sandbox/queries.php`, `app/features/templates/{queries,write}.php`, `app/features/preview/{queries,present}.php`
- `app/views/upload/{index,map}.php`, `app/views/upload/partials/{file-row,sniffing,mapping-table,mapping-card,target}.php`, `app/views/sandbox/index.php`,
  `app/views/loads/{index,view}.php`, `app/views/loads/partials/{counts,drift,rejects}.php`, `app/views/templates/index.php`, `app/views/datasets/partials/tab-preview.php`
- `app/pickers.php` gains `raw_dataset` (raw datasets I may write), `template`
- `assets/js/upload.js` (DocCloud's resumable uploader: parts, retries, resume after a broken connection)
- **The writer** (lake-engine §1, §3): `services/lake/jobs/__init__.py` (`DISPATCH` gains `sniff`, `load`, `profile`, `rollback`),
  `services/lake/jobs/ingest.py` (`job_sniff`, `job_load`, `job_profile`, `job_rollback` — each reads its row, calls the Phase 0 function, writes its results
  through the writer's role, records the lineage run (`lineage_runs` START/COMPLETE/FAIL), returns `{result}` for `dl_job_finish`); `services/lake/writer_main.py`
  imports `DISPATCH`
- **The query service** (query-service §2–§7): `services/query/protocol.py` (the request's shape and kinds: `ping`, `preview`), `services/query/identity.py` (the
  service's own `datalake_query` connection: `dl_query_policy(caller, on_behalf_of, keys, source)` and `dl_query_run_log(...)`; the salt from `DL_MASK_SALT_FILE`),
  `services/query/server.py` (the production server: identity in, the policy compiled inside, the audit row written, the answer out)
- `bin/worker.php`: `worker_pass_upload_sessions` made real (expire sessions past `expires_at`, unlink their parts, null the sniff's sample of confirmed,
  discarded or expired sessions)
- `tests/phase3/slice2/{run.sh,lib.php,upload.php,map.php,loads.php,preview.php,rejects.php,drift.php,json.php,browser.mjs}` and synthetic fixtures copied
  from `tests/fixtures/` (a CSV with a bad row and a Windows-1252 é, an XLSX with two sheets and a merged header, NDJSON, Parquet, personal-looking columns)

## Query functions (signatures fixed)
- `upload_start(PDO, int $member, string $filename, int $bytes, ?string $sha): array{upload_id, part_bytes, parts_total}` · `upload_part(PDO, int $upload, int $partNo, string $tmpPath): array{parts_done}` ·
  `upload_finish(PDO, int $upload): array{status, blob_id, sha256, deduped}` · `upload_discard(PDO, int $upload): void` · `dl_landing_store(PDO, string $path, string $suggestedName): array{blob_id, sha256, path}` (in `app/features/uploads/store.php` — THE one landing function: hash, `finfo`, dedupe by sha256 into `blobs`, move to `landing/<aa>/<bb>/<sha256>`; `upload_finish` calls it after assembling the parts and slice 6's pulls call it for every file and page they land)
- `find_upload(PDO, int $id): ?array` (with `sniff`) · `my_uploads(PDO, int $member, int $limit): array` · `sniff_state(PDO, int $id): array{status, sniff}`
- `map_confirm(PDO, int $upload, array $mapping, int $by): array{dataset_id, load_id, job_id}` — validates the mapping against the sniff (every source column
  named, names unique and valid, types from the list), makes the sandbox dataset (`sandbox.m{me}_{name}`) or checks the raw target, applies or saves a template,
  inserts the `loads` row (`idempotency_key` = `{dataset}:{sha256}`; an existing key answers "this file is already loaded into {key}" with the load's link),
  records the confirmed personal kinds as `dataset_columns` proposals the writer turns into rows, enqueues `load`
- `find_loads(PDO, array $filters, int $page): array{rows, total}` · `find_load(PDO, int $id): ?array` · `load_rejects(PDO, int $load, bool $raw, int $page): array` ·
  `load_queue(PDO, int $load, int $by): int` (refuses a purged landing: *"Its landing file was purged — the load cannot be replayed"*) · `load_rollback(PDO, int $load, int $by): int` (the job's id)
- `my_sandbox(PDO, int $member): array{datasets, used_bytes, quota_bytes}` · `find_templates(PDO, ?string $q): array` · `template_save(PDO, ?int $id, array $f, int $by): int` · `template_delete(PDO, int $id): void`
- `preview(PDO, int $member, int $datasetId, ?int $asOf): array{status, columns, rows, masked_columns, as_of, query_run_id}` (one `lake_query(['kind' => 'preview', …])`)
- Python (the writer): `job_sniff(con, pg, job) -> dict` · `job_load(con, pg, job) -> dict` · `job_profile(con, pg, job) -> dict` · `job_rollback(con, pg, job) -> dict`
- Python (the query service): `protocol.parse(line: bytes) -> Request` · `identity.policy(req) -> dict` · `identity.audit(req, answer) -> int` · `server.handle(req) -> dict`

## MCP tools this slice makes real
`dataset_preview`, `find_uploads` (the upload resolver), `find_import_templates` (the template resolver), `find_loads` (the load resolver), `get_load`,
`load_rejects`, `my_sandbox`, `schema_history`, `profile_history`.

## Manifest rows claimed
Screens (6): `upload`, `upload-map`, `sandbox`, `load-list`, `load-view`, `templates`
Actions (6): `upload_map_confirm`, `upload_discard`, `load_queue`, `load_rollback`, `template_save`, `template_delete`

## What makes this the exemplar (the pattern later slices copy)
- **A writer job kind** = a row in `DISPATCH`, a `job_<kind>(con, pg, job) -> dict` in `services/lake/jobs/<area>.py`, enqueued from PHP by `lake_enqueue()` (never
  run in PHP), its progress shown by a polling partial (`/{x}/{id}/status`, 2 s, stops at a final state), its `lineage_runs` START and COMPLETE/FAIL, its
  `dl_job_finish(ok, result, error)`, its activity row written by the writer with `source = 'system'` and the requester as `actor_member_id`, its proof a
  Python test against a scratch catalog + a PHP test of the screen.
- **A query kind** = a branch in `protocol.py`, the policy compiled by `identity.policy()` for the dataset keys it touches, the views made and the lock taken
  before the caller's SQL (query-service §5), the `query_runs` row written by `identity.audit()` whatever the outcome, the answer's `query_run_id` shown on
  the screen; PHP calls it through `lake_query()` only.

## Activity log events
`upload.start|finish|discard` (the filename's extension, the size, deduped — never the name's path), `upload.map` (the target key, the column count, the
personal proposals' count), `load.queue|run|fail|rollback` (counts, the dataset key, the snapshot id), `load.profile` (the column count), `drift.detect` (the
change kinds' count), `query.run` with `source = 'preview'` (the run id, the dataset id, rows, masked columns' count), `template.save|delete`. Every row about
a dataset carries `dataset_id`; `load.run` and `query.run` carry `query_run_id` / the load id in `entity_id`.

## Status vocabulary
Upload: open `secondary` "Uploading", assembling `info` "Checking", done `success` "Landed", failed `danger`, expired `dark`; sniff: queued/running `info`
"Reading…", done none, failed `danger` "Could not read it — {reason}". Load (lake-engine §8): queued `secondary`, running `info`, loaded `success`, no_op `light`
"Already loaded", failed `danger`, rolled_back `dark` "Rolled back". Checks' outcome: none, passed `success`, warned `warning`, failed `danger`. Ids:
`upload-drop`, `upload-file-{n}`, `map-column-{n}-name`, `map-column-{n}-type`, `map-column-{n}-personal`, `map-target`, `load-row-{id}`, `load-counts`,
`load-rejects`, `preview-table`, `preview-masked-{column}`, `sandbox-card-{id}`, `quota-bar`.

## Out of scope for this slice
Promotion, contracts and checks (5 — the load page's checks' outcome shows "none" until then), sources and pulls (6), purging landing files (8),
the SQL editor (3).

## Proof (`tests/phase3/slice2/run.sh`: scratch database `dl_dev2s` and a scratch catalog `dl_dev2s_lake` with the engine roles (lake-engine §2), the writer and the query service on scratch sockets under `var/`, php -S on 9001, the fake kernel on 9002; then a real Apache on 9007; Playwright; the registry `--check`)
1. A 20 MB CSV uploads in three parts; a broken connection resumes at part 2; the same file again is "already here" (one blob, `ref_count` follows the loads).
2. The sniff reads the Windows-1252 CSV (é survives), the XLSX's two sheets and the joined two-row header, NDJSON and Parquet; an executable is refused at finish.
3. The mapping: a renamed column, a type changed to `DECIMAL(18,2)`, a leading-zero id kept `VARCHAR`; the personal proposal on the email column kept, on a
   name column cleared; confirm makes `sandbox.m{me}_{name}` and the load; the same file into the same dataset again answers "already loaded".
4. The load: rows loaded, one rejected line with its line and error, `_dl_rescued` counted; the sandbox dataset typed (a bad number → `NULL` + a `CAST` reject); an
   engineer's raw load all text with the provenance columns; the profile written, the email column's top values masked in `profile`.
5. Drift: a second file with a new column under `rescue` → `_dl_rescued` + a pending `schema_versions` row; under `fail` → the load fails and says why.
6. The Preview tab: the uploader sees the email column unmasked (`own`); a Member granted `read` (a fixture grant) sees it `partial_email` and "masked for you";
   a Member without a grant sees Request access; every preview writes a `query_runs` row (`source = preview`); the query service down → "The engine is not answering".
7. Replay re-runs a load; replay after a purged landing is refused with the sentence; rollback makes a new snapshot without the load's rows (the previous snapshot
   still readable as of); an agent's `load_rollback` pauses (`deletion`).
8. Templates: saved from the mapping, applied to next month's file in one step, deleted by the maker; another Member's delete 403.
9. The sandbox: my datasets and my quota; another Member never sees them (404 on the page, absent from search).
10. The worker's `upload_sessions` pass expires a session and nulls a confirmed sniff's sample.
11. Python: `tests/phase3/slice2/jobs_test.py` — each of the four job kinds against the scratch catalog (results, lineage runs, idempotence, the job finished).
12. JSON mode on every screen and handler; the six screens and the Preview tab at 375 and 1280 with no console errors; the registry `--check`: 6 screens
    built, 6 actions, 1 approval (`load_rollback`) in step.

## Open questions
None — decisions taken in this spec: sandbox loads are typed by the confirmed mapping and raw loads are text; the sniff's sample is nulled once the mapping is
decided; an upload makes no lineage edge; the job handlers live in `services/lake/jobs/<area>.py` behind one `DISPATCH`; the query service compiles the policy
itself through its own role (the caller sends identity, never a policy).
