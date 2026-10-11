# Build spec: the query service, the SQL editor, saved queries and exports (slice 3 — THE SECOND EXEMPLAR)

Builds on `query-service.md` (§1 who calls, §2 the protocol, §3 the policy, §4 the allow-list and its refusals, §5 views, masks, filters, the lock and the
limits, §6 the audit, §7 the request kinds `sql`, `saved_query`, `export`, §8 the clients) and `lake-engine.md` (§1 the writer's loop, §5 transforms — the
machinery `sandbox_save` reuses, §8 statuses). What exists at the end: **the one door, used by people** — the SQL editor at a desk (the datasets I may read
with their columns for completion, Run and Cancel, the result with the display cap and the truncation notice, per-column quick stats, the masked columns
marked, a refusal that names its rule and offers Request access, my recent runs); saved queries with parameters, shared or mine, **verified by an engineer and
unverified by themselves when their SQL changes**; a query run's page; a result saved as a sandbox dataset (keeping the highest classification of what it read);
exports within the download limits as CSV, XLSX or Parquet, logged, pausing for an agent; the Usage tab and search ranking fed nightly from the audit; the
dataset header's **Query this** live. **This slice is THE SECOND EXEMPLAR**: every later screen that runs SQL for a person (metrics in 7, the API's SQL in 9) and
every later job that writes a file or a dataset from a person's query copies its request shape, its refusal rendering, its audit and its proof.

Schema (db/007, 008, 011, 014, 018, never modified): `query_runs`, `dl_query_run_log()`, `dl_query_runs_prune()`, `saved_queries`, `dl_saved_queries_before()` (the
badge drops on a change), `dl_saved_query_verify()`, `dataset_usage_daily`, `dataset_joins`, `dl_usage_rollup()`, `dl_usage_30d()` (db/011); `dl_query_policy()`,
`dl_level()`, `dl_masks_for()` (db/008, 018 — the policy carries each dataset's classification and personal flag); `datasets.classification_floor`,
`dl_dataset_derive()` (db/007); `exports` (+ `dataset_id`, `query_run_id`, `sql_sha256`, `row_count`, `truncated`, `masked`) (db/014); `lake_jobs` (db/009);
`mcp_query_runs`, `mcp_saved_queries`, `mcp_exports`, `mcp_dataset_usage` (db/016); `dl_settings` query limits (db/005). Engine: `services/query/*` (slice 2's
production server), `services/lake/transform.py` (Phase 0).

## Divergences from the design
1. **Running SQL from a screen is a read door, never an action**: `/sql/run` (POST + CSRF, `query.run`) and `/sql/cancel`; an agent runs SQL with the records tool
   `query_run`. The manifest has no `query_run` action, so no approval category touches a read.
2. **Saving a query asks the service to validate it first** (`kind: sql`, `dry_run: true` — query-service §7): the SQL is parsed and allow-listed, the dataset keys
   it reads are returned and stored in `saved_queries.dataset_ids`, nothing runs. A query that would be refused is saved with its refusal shown, never silently.
3. **`sandbox_save` and `export_make` are writer jobs** (`sandbox_save`, `export` — lake-engine §1): the writer compiles the person's policy at the moment of the
   request (`dl_query_policy(member, on_behalf_of, keys, 'transform' | 'export')`), freezes it in the job's `params`, and runs the SQL through the person's views
   (`services/query/policy.create_views`) — a sandbox result is masked exactly as the person saw it, an export likewise; the query service itself stays read-only.

**Slice 3 also builds** `dl_query_admit(p_caller bigint, p_source text, p_request_id text) RETURNS jsonb` (query-service §2 decision 6 — the per-person concurrency and the per-agent rate admitted in PostgreSQL with advisory-lock slots, so the limits hold across the worker instances; `{admitted, rule?}` with rule `rate` or `concurrency`) in its migration `db/<next>_slice3_admit.sql`, granted to `datalake_query`.

## Who may do what
the editor (`/sql`, run, cancel): `sql.editor` · saved_query_save: `queries.save` (own; updating another's: never — copy it) · saved_query_delete: own ·
query_verify: `queries.verify`, pauses for an agent (`other`) · sandbox_save: `sandbox.save` · export_make: `export.own` + `read` on every dataset the SQL reads,
pauses for an agent (`external_send`) · export_delete: own · a saved query's page: own, or shared and readable · a query run's page: its caller, the person it
acted for, the owner of a dataset it read (no SQL), `audit.read` (with SQL).

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `sql` | `/sql?dataset=&saved_query=&as_of=` | desk-first (renders at 375 with the editor full width and the result as records): left, **my readable datasets** (keys with their columns, from `editor_catalog()`; a click inserts the key or the column); the editor (a `<textarea>` with the keys and columns offered as completion by `assets/js/sql-editor.js`, no third-party editor), the as-of field, **Run** (POST `/sql/run`, HTMX into `#sql-result`) and **Cancel** (shown while running; POST `/sql/cancel`); the result: the table (≤ `query_rows_shown`), "Showing the first 1,000 rows" when truncated, each masked column's header marked "masked for you", per-column quick stats under each header (nulls, distinct, min, max — computed on the shown rows in PHP), the time and the query run link; **a refusal** renders its sentence (query-service §4's words), the dataset it names and **Request access** (`/access/requests/new?dataset=…&level=read`); actions above the result: Save (→ the save form inline), Download (→ `export_make`), Save as dataset (→ `sandbox_save`); my last 20 runs below (`my_runs()`) |
| `queries` | `/queries?q=&verified=&dataset=&owner=` | saved queries as cards: title, the question, owner, Verified badge (by whom, when) or "unverified: the SQL changed", the datasets as chips, shared/mine |
| `query-view` | `/queries/{id}?run=1` | one saved query: question, description, the SQL (read-only, highlighted), the parameters as a form (name, type, default), the datasets; **Run** shows the result only (as the editor's result partial); Verify / Unverify (`queries.verify`); Edit; Delete |
| `query-edit` | `/queries/{id}/edit` (and `/queries/new?sql=` from the editor) | `page-header-form`: title, question, description, SQL, parameters (rows of name, type `text`/`integer`/`number`/`date`, default, required), shared; "saving re-checks the SQL" (the dry run's datasets shown; a refusal shown under the SQL) |
| `query-run-view` | `/runs/{id}` | one run: who (and on whose behalf, which agent run), source, the datasets (links), masks and row filters applied (names only), rows, truncated, bytes, ms, status, the refusal's rule; the SQL to its caller, the person it acted for and `audit.read` — "hidden" to a dataset's owner |
| `exports` | `/exports` | my exports as a table: format, the dataset or the query, rows, truncated, masked, status, made, expires; Download (the door `/exports/{id}/download` — 404 after expiry), Delete |

The **Usage tab** (slice 1's partial) now shows data; the dataset header's **Query this** links to `/sql?dataset={key}` with `SELECT * FROM {key} LIMIT 100` prefilled.

## Files (exactly these)
- Doors: `html/sql/{index,run,cancel}.php` (index: the `sql` screen; run/cancel: the read partials), `html/exports/download.php` (the gate: own, not expired;
  streamed with `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, logged `export.download`)
- Screens and actions: `html/queries/{index,view,form,save,delete,verify}.php`, `html/runs/view.php`, `html/sql/save-dataset.php` (`sandbox_save`),
  `html/exports/{index,make,delete}.php`
- `app/features/editor/{queries,present}.php` (`editor_catalog`, `run_sql`, `my_runs`, the result's quick stats), `app/features/saved_queries/{queries,present,write}.php`,
  `app/features/runs/{queries,present}.php`, `app/features/exports/{queries,present,write}.php`
- `app/views/sql/index.php`, `app/views/sql/partials/{catalog,editor,result,refusal,stats,runs,save-inline}.php`, `app/views/queries/{index,view,form}.php`,
  `app/views/runs/view.php`, `app/views/exports/index.php`
- `app/pickers.php` gains `saved_query`
- `assets/js/sql-editor.js` (completion from the server-rendered catalog list; Ctrl/⌘+Enter runs; no external library)
- **The query service** (query-service §7): `services/query/protocol.py` gains the kinds `sql` (with `dry_run`, `params` bound as literals after the parse — never
  string-pasted), `saved_query` (the service never reads `saved_queries`: PHP or the MCP tool passes the SQL and the id), `export` (source `export`, `row_limit`
  = `query_rows_download`, timeout `query_timeout_export_s`, the rows streamed back as Arrow IPC to the writer), and `cancel` (`{kind: cancel, request_id}` —
  interrupts the caller's own running request; another caller's id is refused `not_yours`)
- **The writer** (lake-engine §1): `services/lake/jobs/__init__.py` `DISPATCH` gains `sandbox_save` and `export`; `services/lake/jobs/query_results.py`
  (`job_sandbox_save`: the frozen policy → views → `CREATE TABLE lake.sandbox.{name} AS` the person's SELECT, one commit, the dataset's columns and profile
  through slice 2's `job_profile`, `classification_floor` = the highest of the inputs (from the frozen policy); `job_export`: the frozen policy → the rows →
  `DL_STORAGE_DIR/exports/{id}.{csv|xlsx|parquet}` (xlsx by `openpyxl`, ≤ 1,048,575 rows), `exports.status`, `byte_size`, `row_count`, `truncated`, `masked`)
- `bin/worker.php`: `worker_pass_usage_rollup` (yesterday's `dl_usage_rollup()` once a day after 01:00, then `dl_query_runs_prune()`), `worker_pass_exports`
  (unlink files past `expires_at`, `storage_path` nulled, `exports.status` kept)
- `tests/phase3/slice3/{run.sh,lib.php,editor.php,refusals.php,saved.php,verify.php,sandbox.php,exports.php,audit.php,json.php,browser.mjs}`,
  `tests/phase3/slice3/service_test.py`

## Query functions (signatures fixed)
- `editor_catalog(PDO, int $member): array` (datasets at `read`+ with their live columns: `[{key, title, columns: [{name, logical_type, masked}]}]`) ·
  `run_sql(PDO, int $member, string $sql, ?string $asOf, array $params): array{status, columns, rows, truncated, masked_columns, datasets, ms, query_run_id, refusal?}`
  (one `lake_query(['kind' => 'sql', 'source' => 'screen', …])`) · `cancel_sql(int $member, string $requestId): bool` · `result_stats(array $columns, array $rows): array` ·
  `my_runs(PDO, int $member, int $limit): array`
- `find_saved_queries(PDO, array $filters, int $page): array{rows, total}` · `find_saved_query(PDO, int $id): ?array` · `run_saved_query(PDO, int $member, int $id, array $params): array` (as `run_sql` + `saved_query_id`) ·
  `saved_query_save(PDO, ?int $id, array $f, int $by): array{id, refusal?}` (the dry run first) · `saved_query_delete(PDO, int $id): void` · `query_verify(PDO, int $id, bool $on): void` (`dl_saved_query_verify`)
- `find_run(PDO, int $id): ?array` (the view's row; `sql` as the view decides)
- `sandbox_save(PDO, int $member, string $name, string $sql, ?string $title, ?string $asOf): array{dataset_id, job_id}` · `export_make(PDO, int $member, string $format, array $what, ?string $asOf): array{export_id, job_id}` ·
  `export_delete(PDO, int $id): void` · `my_exports(PDO, int $member, int $limit): array` · `export_file(PDO, int $member, int $id): ?array{path, filename, mime}`
- Python: `protocol.parse()` gains the four kinds · `job_sandbox_save(con, pg, job) -> dict` · `job_export(con, pg, job) -> dict`

## MCP tools this slice makes real
`query_run`, `find_saved_queries` (the saved_query resolver), `run_saved_query`, `get_query_run`, `my_exports` (the export resolver).

## Manifest rows claimed
Screens (6): `sql`, `queries`, `query-view`, `query-edit`, `query-run-view`, `exports`
Actions (6): `saved_query_save`, `saved_query_delete`, `query_verify`, `sandbox_save`, `export_make`, `export_delete`

## What makes this the exemplar (the pattern later slices copy)
- **Running a person's SQL from a screen** = `run_sql()` → `lake_query()` → the result partial or the refusal partial; the refusal's sentence is the service's
  (query-service §4), never re-worded in PHP; the masked columns and the truncation are always shown; the run's id is always linked.
- **A job that writes from a person's query** = the policy compiled and frozen at the request (never at the job's run), the person's views in the writer, the
  output's classification floor from the inputs, the job's status polled, the result owned by the person.
- **The audit is the service's**: PHP never writes `query_runs`.

## Activity log events
`query.run` (written by the query service's audit and mirrored to `activity_log` by `run_sql()` with `query_run_id`, `dataset_id` of the first dataset, rows,
status — never the SQL), `query.cancel`, `saved_query.save|delete` (title, dataset count — never the SQL), `query.verify` (on/off), `sandbox.save` (the new key,
the job id), `export.make|ready|download|delete|expire` (format, rows, masked — never the SQL), `worker.usage_rollup`.

## Status vocabulary
Run status: ok none, refused `warning` "Refused — {rule}", timeout `danger` "Stopped at {n} s", error `danger`, cancelled `secondary`. Verified `success`
"Verified by {name}"; unverified `outline-warning` "Unverified — the SQL changed". Export: queued/running `info`, done `success` "Ready", failed `danger`,
expired `dark`. Ids: `sql-catalog`, `sql-editor`, `sql-run`, `sql-cancel`, `sql-result`, `sql-refusal`, `sql-stats-{column}`, `sql-masked-{column}`,
`query-card-{id}`, `query-form-field-{name}`, `run-view`, `export-row-{id}`.

## Out of scope for this slice
Metrics and transforms (7), the lake's API SQL door and K7 rows (9), the query audit screen for the admin (10 — `query-run-view` is the one-run page), the
agents' K38 intersection beyond what the policy function already does (access-and-policies §6, Phase 4 wires the MCP side).

## Proof (`tests/phase3/slice3/run.sh`: scratch `dl_dev3` + `dl_dev3_lake`, the writer and the query service on scratch sockets, slice 2's fixtures loaded first; php -S on 9001, the fake kernel on 9002; then a real Apache on 9007; Playwright; the registry `--check`)
1. The editor lists only datasets I may read; a Member without `sql.editor` gets 403 on `/sql`.
2. A SELECT over a dataset key answers within the cap; 1,001 rows truncate with the notice; the masked email column is marked and masked (a Member with
   `read`); the owner sees it unmasked; a row filter (a fixture grant) returns only the department's rows.
3. Every forbidden form refused with its rule and nothing run: two statements, `COPY`, `ATTACH`, `read_csv('/etc/passwd')`, a table function, `SET`, a path, a
   dataset I may not read (Request access linked), a dataset I only discover (`discover_only`); each writes a `query_runs` row `refused`.
4. Cancel stops a long query (a cross join) within 2 s; another person's request id is `not_yours`; the timeout stops one at the setting.
5. Saved queries: saved with parameters (a `$region` bound as a literal — an injection attempt in the value stays a string), run from the page, shared and found
   by a reader, not by a Member who cannot read its dataset; verify by an engineer; edit the SQL → "Unverified — the SQL changed"; an agent's `query_verify` pauses.
6. Save as dataset: `sandbox.m{me}_{name}` made by the writer from my masked view (the email arrives masked for a Member), its classification `confidential`
   from its input, its profile written; another Member never sees it.
7. Export: CSV, XLSX and Parquet; 100,000 rows cap → truncated; the masked flag; the download door for the owner only; expired → 404 and the file unlinked by the
   worker; an agent's `export_make` pauses (`external_send`).
8. The run page: the caller sees the SQL, the dataset's owner sees the run without it, `audit.read` sees it.
9. The worker's `usage_rollup` fills the Usage tab and `usage_30d`; search ranks the used dataset above an unused one.
10. Python `service_test.py`: the four kinds against the scratch catalog — `dry_run` returns datasets without running, params bound, cancel, the export stream.
11. JSON mode on every screen, door and handler; the six screens at 375 and 1280 with no console errors; the registry `--check`: 6 screens built, 6 actions,
    2 approvals (`query_verify`, `export_make`) in step.

## Open questions
None — decisions taken in this spec: running SQL is a read door, not an action; saving a query dry-runs it; `sandbox_save` and `export` are writer jobs with the
policy frozen at the request; the editor is a `<textarea>` with server-fed completion, no external library; the quick stats are computed on the shown rows; XLSX
exports stop at Excel's row limit with `truncated`.

## Built and proven (2026-10-11)
The one door stands, used by people: **the SQL editor** at a desk — my readable datasets with their columns (a click inserts the key or the
column), a `<textarea>` with completion of those keys and columns (`assets/js/sql-editor.js`, no library), Ctrl/⌘+Enter, **Run**, **Explain**
(the dry run's plan), **Cancel** while running; the result with the display cap and "Showing the first 1,000 rows", each masked column marked
"masked for you", quick stats under each header on the shown rows, the run linked; a refusal in the service's own words with its rule and
Request access; my last 20 runs. **Saved queries** with typed parameters, shared or mine, verified by an engineer and unverified by themselves
when their SQL changes, run from their page (the result only), copied, deleted. **A run's page** (the SQL to its caller, the person it acted
for and the audit; "hidden" to a dataset's owner). **Save as dataset** and **exports** (CSV, XLSX, Parquet) as writer jobs with the policy frozen
at the request. The query service answers `sql` (with `dry_run`), `saved_query` and `cancel`, admits a person's concurrency and an agent's rate in
PostgreSQL, and audits everything it answers. The Usage tab, search's ranking and the home's Recent tile are fed. The registry reads **29 screens
and 39 actions built** (6 + 6 here; 2 approvals in step).

**Proven** by `tests/phase3/slice3/run.sh` (scratch `dl_dev3` with the engine real; the slice's lake written by the writer's own library —
`seed.py`): **220 checks under php -S and the same 220 under a real Apache** — editor 27, refusals 26 (18 forbidden forms through the door, the
policy's four refusals, Cancel, not_yours, the watchdog, `concurrency`, `rate`, `busy`, K38 through the saved-query door), saved 24, verify 11,
sandbox 17, exports 24, audit 14, json 24, service 16 (the socket: dry_run, params as literals, saved_query's id, cancel, 32 forbidden forms, the
export stream in Arrow batches, the lock), browser 37 (completion and Tab, Ctrl+Enter, a table at 1280 and records at 375, Explain, Cancel in the
browser, the six screens stamped with no sideways scroll and no console error, and without JavaScript the editor's form posting back the whole
screen; screenshots in `/tmp/dl-shots/slice3`) — plus the schema proof (**197** — db/022's eight checks added), the server and engine logs, and
the registry `--check`. Re-run green: slices 2 and 1 and Phase 2 under both modes, the engine proof, Phase 0's kit, Phase 1's claims.

**Timings on this VM** (the audit's own, `query_runs.ms`): a refusal of the shape answers in ~0 ms (no connection made); an `ok` read from the
editor ~0.6 s at the median (p90 0.7 s) — almost all of it the fresh DuckDB, the READ-ONLY attach of DuckLake through PostgreSQL and the
caller's views, built per request by design (query-service §5); an export of 1,500 rows ~0.7 s, 120,000 rows capped at 100,000 in ~1.5 s; Cancel
stops a 14-billion-pair cross join in **< 0.3 s** after the click (proven < 2 s); the watchdog at a 2 s setting answers `timeout` at ~2.5 s.

**Decisions taken in the build** (the spec left them open, or the code showed better):
1. **The `export` kind is not a socket kind** (this spec's file list named one "streamed back as Arrow IPC"; query-service §1 and §7 say exports
   run in the writer): `query.service.prepare()` — the allow-list, the person's views, the lock — is shared by the service's `run()` and the
   writer's jobs, which stream `to_arrow_reader()` batches from that locked READ-ONLY connection into the lake (`sandbox_save`: `CREATE TABLE
   … AS SELECT * FROM` the registered reader, one commit) or into a file. The person's SQL never runs on the writer's read-write connection.
2. **`dl_query_admit(p_caller, p_source, p_request_id) → jsonb`** (this spec's shape; query-service §4 named a `text` variant): `{admitted,
   slot}` | `{admitted: false, rule}` — the slot a SESSION advisory lock on the instance's connection, released after the audit
   (`dl_query_release`). **A slot held by any session — this one included — is taken** (`pg_locks` first): advisory locks are reentrant within
   a session, so a missed release can never stack. Concurrency applies to every caller (2 a person by default), the rate to agents only.
3. **Cancel is answered beside the running request** (never `busy`): the client asks EVERY instance; the one running it interrupts it (the
   run ends `cancelled` — query_runs admits it), another caller's id answers `not_yours`, an id not running here `not_found`. The editor's
   request id is the page's (`sql-{me}-{hex}`, set by the script on submit; the server accepts only its own member's prefix), and **/sql/run
   releases the PHP session before asking the lake**, so Cancel is never queued behind its run.
4. **The policy decides the shape of a refusal; db/022 fixes one defect**: db/018's `dl_query_policy()` answered `not_readable` for a dataset
   the caller may not even know exists, so a key typed in the editor confirmed it existed — now **`no_such_dataset`, identical to a missing
   key, unless the caller sees its stub** (an insider holding a role; never an external, never another's sandbox). Request access is offered
   for discover_only, not_readable and not_shared only.
5. **A dataset granted but with no data yet** answers `ok`, no rows, "{key} has no data yet." (as the preview does) — never "you may not read".
6. **A `policy` refusal** (a compiled policy the service cannot honour) tells every Lake admin once a day per dataset (`dl_query_policy_fault()`,
   notification kind `policy_fault` — db/022 widens the check); the caller reads "the lake refused this read (its policy)".
7. **Saving a query** dry-runs it with every parameter bound to its default or a typed placeholder: a SQL the ALLOW-LIST refuses is never saved
   (it could never run — the sentence on `sql`); one the POLICY refuses today is saved, its refusal returned and the page's notice saying so
   (the spec's "saved with its refusal shown, never silently"). `dataset_ids` from the parse. An update leaves out what it does not send.
8. **Parameters** are `{name, type, default, required}` (types text, integer, number, date — `$name` in the SQL), coerced by PHP by type and
   bound by the SERVICE as literals after the parse; a form sends rows, an agent JSON. A missing required value is a field error, nothing run.
9. **`sandbox_save` and `export_make` compile the person's policy in PHP at the request** (`dl_query_policy(…, 'transform' | 'export',
   ceiling)` as the application — the database compiles it, PHP only carries it) and freeze it in the job's params without the salt; the
   writer adds the salt. **The salt is now `root:datalake 0440`** — readable by the writer and the query service (both in `datalake`), never
   www-data (ROOT_STEPS step 6 and the query unit's comment changed; the writer's exports and saved results apply hash masks).
10. **A saved result keeps its inputs' sensitivity**: the dataset's floor is the highest input classification, and an output column named as an
    input column inherits that column's classification and personal kind (conservative — a mask applied at the save protects nobody who later
    reads it unmasked); then slice 2's `job_profile` runs in the same job (★3's classifier). Saving my own name again replaces its rows.
11. **Exports**: a whole dataset's export is `SELECT * FROM {key}` (provenance columns included); `masked` = some column of a dataset it read was
    masked for the maker (conservative — the flag does not trace which columns the result used); XLSX stops at 1,048,575 rows; files are
    written to `exports/{id}.{ext}.part` and renamed. **The writer owns `exports/`** (lake-engine §6's rule extended): delete removes the ROW
    at once (the door 404s), expiry 404s at once, and an `export` job with `op: sweep` (queued by the delete and by the worker's `exports`
    pass — one queued at a time) unlinks every file whose row is gone or expired and nulls `storage_path`. PHP never deletes there.
12. **Usage**: `worker_pass_usage_rollup` runs once a day after 01:00 (a `worker.usage_rollup` row names the day), then `dl_query_runs_prune()`,
    then **re-indexes every dataset whose `search_index.usage_30d` moved** — search ranks by it, and the rollup changes no dataset row, so no
    trigger would. `--passes=usage_rollup` forces it (`$GLOBALS['__worker_only']`).
13. **A run's page**: the masks and row filters are shown by NAME (the column, the dataset bounded) — never a filter's text, even to the caller.
14. **Query this** shows on the dataset header to whoever holds `sql.editor` and may read the dataset (it was shown to everyone once built).
15. The **home's Recent tile** reads the datasets of my last ok runs (`recent_datasets()`, through `mcp_query_runs` and `mcp_datasets`), merged
    with my followed ones, each once.
16. Files beyond the spec's list: `app/features/editor/write.php` (`sandbox_save`, `frozen_policy`, `dry_run_for_job` — writes live in
    `write.php`), `app/features/runs/present.php` (as the list said), `app/features/{saved_queries,exports}/write.php`, `tests/phase3/slice3/seed.py`
    (the slice's real tables). `FieldRefused` (slice 2's) is reused, not copied.

**Schema defects found and fixed (db/022, additive):** the hidden-dataset leak above (decision 4); `lake_jobs.kind` and `lineage_runs.job_kind`
lacked `sandbox_save`; `notifications.kind` lacked `policy_fault`. **Deploy defect:** ROOT_STEPS wrote the masking salt as 32 RAW bytes while
the query service refuses anything but 32–128 hex characters — a fresh install's query service would not have started; it writes hex now.
**Earlier proofs updated** (they follow what is built): Phase 2's engine proof and slice 2's preview proof now name `check_row_filter`
(slice 4) as the unbuilt kind, and the writer's unbuilt kinds list drops `export`; Phase 2's gates expect the Recent tile built.

**For later slices:** a screen that runs a person's SQL copies `run_sql()` → the result or refusal partial (`app/views/sql/partials/`); a job
that writes from a person's query copies `services/lake/jobs/query_results.py` (the frozen policy, `_prepared()`, `_arrow_reader()`, the
watchdog, `_audit()` with its source, lineage for a dataset it writes); a new query kind copies `server._sql` (keys first → admit → policy →
run → audit → release in `finally`). Slice 4's `check_row_filter` adds its branch in `protocol._check` and a handler in `BUILT`; slice 7's
metrics go through `lake_sql()`'s shape; slice 9's API SQL door calls `run_sql()` with `source: api`.
