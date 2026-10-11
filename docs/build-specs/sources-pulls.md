# Build spec: sources and pulls (slice 6 — replicates slices 1 and 2)

What exists at the end: the sources area — cards, a source's page with its pulls and the datasets it feeds, the add and edit forms per connector, the
sealed credential, Test, Pull now, Pause / Resume, Delete; the **worker's pull** for the five v1 connectors — `upload` (a named recurring shape, no
pull), `http_file`, `sftp_file`, `k7` (a sibling's share through the kernel — the owner's five reads configured and pulling), `postgres` (an outside
database the business owns) — each pull landing its bytes once by sha256 and queuing a **load** exactly as slice 2's upload does. **Build every screen
exactly like slice 1's datasets screens (list as cards, view with a header and tabs by anchor, full-page add/edit with the pinned Save) and every pull's
landing + load exactly like slice 2's `upload_map_confirm` → landing blob → `loads` row → `dl_job_enqueue('load', …)`.** The writer is not touched: it
already loads whatever lands (slice 2). **Pulls are the PHP worker's, never the writer's** (design §6.9: the writer is network-blocked but for loopback).

Schema (db/009, 012, 014, 016, never modified): `sources`, `source_credentials`, `source_pulls`, `dl_sources_guard()` (the postgres connector refuses this
server's databases; a K7 source names its provider), `dl_connectors()`, `blobs`, `loads`, `dl_loads_blob_count()`, `lake_jobs`, `dl_job_enqueue()` (db/009);
`dl_lineage_edge()` (db/012 — a `load` edge from the source to its dataset); `dl_notify()` (db/014); `mcp_sources`, `mcp_source_pulls`, `mcp_loads`
(db/016). No migration.

## Divergences from the design
1. **One source reads one thing into one raw dataset.** A `postgres` source reads ONE table (`settings.table`); several tables are several sources. A
   `k7` source reads ONE share. The target is `sources.settings.target_dataset_id` (a raw dataset — created by `source_create` when named new, through
   slice 1's `dataset_save()` with zone `raw`).
2. **A K7 page lands as NDJSON of its rows, not as the response document.** The share's list (the `rows_path` below) is written one row object per line,
   byte-exact from the decoded JSON, each row given the injected fields the table below names (`as_of` where the document carries it once); the
   document's other top-level keys (counts, totals, `generated_at`) go into the pull's `source_pulls.policy.document`. The NDJSON is the landing blob
   (sha256); `_dl_landing_sha256` points at it.
3. **The `postgres` connector pulls in PHP** (PDO `pgsql`, a server-side cursor through `DECLARE … CURSOR`, `FETCH 50000`): each page an NDJSON blob and
   a load; incremental by the source's watermark column; **weekly reconciliation** (Sunday 03:00 business time) compares `count(*)` with the dataset's
   `row_count` and, when they differ, queues a full reload (first page `mode: replace`, then `append`).
4. **Credentials are sealed with libsodium under `DL_SECRETS_KEY`** (Inventory's `credentials.php` ported verbatim, `inv_` → `dl_`): this slice adds
   `DL_SECRETS_KEY` to `config/.env.example` (64 hex), to `maludb-os.json` `env.optional`, and to `deploy/ROOT_STEPS.sh` step 6 (generated once, kept on
   re-run, never printed). Without it `source_credential_set` refuses with `secrets_key_missing` and a source needing a credential pauses.
5. **The user-agent** of `http_file` is `MaluDB-DataLake/1.0 (+{APP_URL})` unless the source sets `user_agent`; robots are honoured exactly as
   Inventory's client does (`robots.php`), and a blocked file pauses the source with the reason — blocked means blocked.

## Who may do what
Every action: `sources.manage` (the screens too). source_credential_set: people only, pauses for an agent (`other`) · source_delete: pauses for an agent
(`deletion`) · source_test / source_pull_now / source_pause / source_resume / source_create / source_update: free for an agent holding the grant.

## Screens
`source-list` `/sources?connector=&paused=` (cards like slice 1's `browse`: name, connector icon, the target dataset's key, schedule, last pull's status and
time, consecutive failures, paused badge with the reason; Add source) · `source-view` `/sources/{id}` (header: name, connector, target dataset link, Test ·
Pull now · Pause/Resume · ⋯ Edit · Credential · Delete; tabs by anchor — **Settings** (the connector's settings as a definition list; the credential's kind,
label and last four only), **Pulls** (the last 50 `source_pulls`: started, status, items seen, items landed, bytes, requests, error ≤ 200 characters, each
linking its loads), **Feeds** (the datasets it feeds, from `mcp_lineage_edges` where `from_source_id`)) · `source-add` `/sources/new?connector=` (step 1 the
connector as five cards; step 2 its form — the fields below — and the target: an existing raw dataset (the picker) or a new one's name; schedule
minutes (0 = manual); rate) · `source-edit` `/sources/{id}/edit` (the same form, the connector fixed).

**The connectors' forms** (`settings` keys; every one validated in `app/lake/fetch/connectors/<x>.php`'s `validate()`):

| Connector | settings | Pull |
|---|---|---|
| `upload` | `template_id?` (a saved mapping) | none — a named shape for people's uploads; `source_pull_now` refuses `manual_only` |
| `http_file` | `url` (https only), `format` (csv, tsv, xlsx, json, ndjson, parquet, auto), `template_id?` | conditional GET (`ETag` / `Last-Modified` kept in the pull's policy); 304 → status `ok`, nothing landed; changed → one blob, one load |
| `sftp_file` | `sftp: {host, port (22), user, path}`, `pattern` (a glob, default `*`), `format`, `template_id?` | list `path`; every file matching `pattern` not in the policy's `seen` (name + size + mtime, the last 500 kept) is one blob and one load, oldest first; Inventory's SFTP transport (ssh2, else `curl sftp://` with a netrc, else `sftp` batch) |
| `k7` | `provider`, `tool`, `arguments` (an object), `rows_path`, `paging` (none, offset), `page_size` (≤ 500), `window` (none, date — the `from`/`to` arguments moved forward), `inject` (fields added to every row) | `kernel_read()` (`app/api/kernel.php`, the kit's) once per page; `no_connection` → the source **pauses** with `paused_reason = 'no_connection'` and the pull's status `blocked` (no failure storm; `source_resume` retries); `provider_failed` → `failed`, back-off |
| `postgres` | `host`, `port` (5432), `dbname`, `user`, `sslmode` (require, verify-full, prefer), `table` (schema.table), `watermark` (a column), `key[]` | above (§ Divergences 3); the credential kind `database_password` |

**The owner's five reads** (design §15 (c); `maludb-os.json` `reads[]`) — seeded by `bin/seed_k7_sources.php` (this slice; idempotent; run by the
proof and once by the owner after the connections are approved), each a `k7` source with its raw dataset made:

| Source name | provider · tool | arguments | rows_path · paging | inject | Target dataset | Schedule | Key (the contract's, slice 5) |
|---|---|---|---|---|---|---|---|
| GL account balances | `gl` · `account_balances` | `{"as_of": "<yesterday>"}` | `accounts` · none | `as_of` (the document's) | `raw.gl_account_balances` | 1440 (nightly 01:30) | `as_of`, `code` |
| GL receivables aging | `gl` · `ar_aging_summary` | `{"as_of": "<yesterday>"}` | `currencies` · none | `as_of` | `raw.gl_ar_aging` | 1440 | `as_of`, `currency` |
| GL payables due | `gl` · `ap_due_summary` | `{"weeks": 8}` | `buckets` · none | `as_of` = the pull's date | `raw.gl_ap_due` | 1440 | `as_of`, `bucket`, `currency` |
| Inventory sales closed | `inventory` · `sales_closed` | `{"from": <window start>, "to": <yesterday>}` | `orders` · offset, 500 | — | `raw.inventory_sales_closed` | 1440 | `number` |
| CRM deals won | `sales_crm` · `deals_won` | `{"from": <window start>, "to": <yesterday>}` | `deals` · offset, 500 | — | `raw.crm_deals_won` | 1440 | `deal_id` |

The window (`window: date`): the first pull starts 92 days back (Inventory refuses longer periods), every later pull starts the day after the last
pull's `to` (kept in `source_pulls.policy.window_to`); pages until a page shorter than `page_size`. Nested arrays (`products`, `lines`, `payments`) land as
JSON text in raw (raw is all text, slice 2) and are typed by the contract (slice 5).

## The pull (`app/lake/fetch/pulls.php` — Inventory's `pulls.php` ported: `dl_run_pull()`)
`dl_run_pull(PDO $pdo, int $sourceId, string $kind, ?int $by, ?int $queuedPullId)`: refuse when paused or a pull is running (`source_pulls.status =
'running'` younger than 6 h); insert the `source_pulls` row; the connector's `pull($source, $emit)` emits **files**; for each emitted file:
`dl_landing_store(PDO, string $path, string $suggestedName): array{blob_id, sha256, path}` (slice 2's — the same function the upload's finish calls) →
the `loads` row with `source_id`, `pull_id`, `blob_id`, `landing_sha256`, `page_cursor`, `mode`, `idempotency_key = '<dataset>:<sha256>'` (a repeated
page or file is refused by the key and counted `listings_changed = 0`) → `dl_job_enqueue('load', dataset, load_id, {template_id, format})`; finish the
pull with its counts (`listings_seen` = items seen, `listings_new` = items landed, `bytes`, `http_requests`), `policy` (the policy followed, the
document's extra keys, `window_to`, `seen`), status `ok`/`partial`/`failed`/`blocked`; on success `last_ok_at`, `consecutive_failures = 0`, the lineage edge
`dl_lineage_edge(NULL, source, dataset, 'load')`; on failure `consecutive_failures + 1` and `backoff_until = now() + 2^n minutes` (max 24 h); **five
consecutive failures pause the source** and notify its creator and every `sources.manage` holder (`dl_notify` kind `load_failed`, dedupe per source per day).

**The worker** (`bin/worker.php`'s `schedules` pass — this slice's part; slices 5 and 7 add theirs to the same pass): the queued manual pulls first
(`source_pull_now` inserts a `source_pulls` row with `policy.queued = true`, Inventory's way), then `sources_due(PDO, $now)` — a query in
`app/features/sources/queries.php`, no migration: active, not paused, `backoff_until` past, `schedule_minutes > 0`, and `last_ok_at` older than
`schedule_minutes` (or never), oldest first — within `DL_WORKER_PULLS_BUDGET_SECONDS` (default 300). The weekly reconciliation of `postgres` sources runs
in the same pass on Sundays after 03:00 business time.

## Files (exactly these)
`html/sources/{index,view,form,save,credential,test,pull,pause,resume,delete}.php`; `app/features/sources/{queries,present,write}.php`;
`app/lake/fetch/{Connector,http,robots,credentials,pulls,landing_ndjson}.php` (Inventory's `app/sources/` ported — `Connector.php` keeps the interface
`probe/pull/search/lookup/capabilities` with `search` and `lookup` answering `unsupported`; `http.php` verbatim but the prefix; `landing_ndjson.php`
writes the NDJSON blob of a K7 page or a postgres page), `app/lake/fetch/connectors/{upload,http_file,sftp_file,k7,postgres}.php`;
`app/views/sources/{index,view,form}.php` + `app/views/sources/partials/{card,pulls-table,feeds,settings-dl,connector-picker,form-http_file,form-sftp_file,form-k7,form-postgres,form-upload}.php`;
`bin/seed_k7_sources.php`; `bin/worker.php` (the `schedules` pass's source part); `config/.env.example`, `maludb-os.json` (`env.optional` + `DL_SECRETS_KEY`),
`deploy/ROOT_STEPS.sh` (step 6: the secrets key); `tests/phase3/slice6/{run.sh,lib.php,fixtures_server.php,sources.php,pulls.php,k7.php,postgres.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_sources(PDO, array $f, int $page): array` · `find_source(PDO, int $id): ?array` · `source_pulls(PDO, int $id, int $limit): array` · `source_feeds(PDO, int $id): array` ·
`sources_due(PDO, DateTimeImmutable $now): array` · `sources_for_pick(PDO, string $q, array $params): array` · writes: `source_save(PDO, ?int $id, array $f, int $by): int` ·
`source_credential_set(PDO, int $id, string $kind, string $secret, ?string $label, int $by): void` · `source_pause(PDO, int $id, ?string $reason, int $by)` · `source_resume(PDO, int $id, int $by)` ·
`source_delete(PDO, int $id, int $by)` · `source_test(PDO, int $id): array` (the connector's `probe()`; no pull row, logged `source.test`) · `source_pull_queue(PDO, int $id, int $by): int` ·
the pull: `dl_run_pull(PDO, int $sourceId, string $kind, ?int $by, ?int $queuedPullId = null): array` · `dl_landing_ndjson(array $rows, array $inject, string $workDir): string` (a path)

## MCP tools this slice makes real
`find_sources` (the source resolver), `get_source`.

## Manifest rows claimed
Screens (4): `source-list`, `source-view`, `source-add`, `source-edit`
Actions (8): `source_create`, `source_update`, `source_credential_set`, `source_test`, `source_pull_now`, `source_pause`, `source_resume`, `source_delete`

## Activity log events
`source.create|update|credential_set|test|pull|pause|resume|delete` (`dataset_id` = the target), `source.pull_done` (`{pull_id, status, items_seen, items_landed, bytes,
http_requests}` — written by the worker, source `cron`), `source.paused_auto` (`{consecutive_failures, reason}`), `source.reconcile` (`{source_count, lake_count, reload}`).
**Never** a URL's query string, a host's password, a credential, a row.

## Status vocabulary
Pull: running `primary`, ok `success`, partial `warning`, failed `danger`, blocked `dark`. Source: active `success`, paused `warning` (the reason beside), backing off
`secondary`. Connector icons: upload `feather-upload`, http_file `feather-globe`, sftp_file `feather-server`, k7 `feather-share-2`, postgres `feather-database`.
Ids: `source-list`, `source-card-{id}`, `source-view-header`, `source-{id}-test`, `source-{id}-pull`, `source-{id}-pause`, `source-pulls-table`, `pull-{id}`,
`source-form`, `source-form-field-{name}`, `source-credential-form`.

## Out of scope for this slice
The load itself (slice 2's writer job — unchanged); the contracts that type the five K7 datasets (slice 5's screens: the proof publishes them through
`dl_contract_publish` only); `rest_json`, `maludb_activity`, `mysql`, `s3_file` (Extended — `source_create` refuses them with `connector_extended`);
`k37_feed` (after the kernel's K37).

## Proof (`tests/phase3/slice6/run.sh`: scratch `dl_dev6`; ports 9001–9007 — 9004 the fixtures server (`fixtures_server.php`: a CSV with an ETag, a 304, a
robots-blocked path, an XLSX), 9005 a fake kernel answering `/api/v1/apps/read.php` with the five shares' documents (two pages for `sales_closed`) and
`no_connection` for a sixth; a local `sshd` is NOT required — the SFTP connector is proven through its transport seam with a directory fixture;
`postgres` against a second scratch database `dl_dev6_outside` reached as `localhost` **refused** by `dl_sources_guard` and through `127.0.0.2` with a
hosts alias accepted (the proof sets the connector's host to the alias))
A source per connector saved and shown; the credential sealed (the ciphertext ≠ the secret; the view shows the last four; `source_credential_set` by an agent
pauses); `http_file` lands once, a second pull answers 304 and lands nothing, the same bytes under a new ETag are refused by the idempotency key; robots-blocked
pauses with the reason; the five K7 sources seeded and pulled — `raw.inventory_sales_closed` gets both pages, `as_of` injected into the GL rows, the window
moves forward, `no_connection` pauses without a failure count; the postgres source pulls by watermark in pages, the weekly reconciliation queues a reload
when the counts differ; five failures pause and notify; the lineage edge from each source; Test answers the probe without a pull; Delete keeps the dataset;
JSON mode on every action; 375 and 1280; `php bin/build_action_registry.php --check` (4 screens, 8 actions built, 2 approvals).

## Open questions
None — decisions taken: one source one target (§ Divergences 1); NDJSON landing of K7 pages with the document's keys in the pull's policy (2); postgres pulls in
PHP with weekly count reconciliation (3); `DL_SECRETS_KEY` added by this slice to the env example, the manifest and ROOT_STEPS (4); the default user-agent (5).

What the worker found the spec silent on (taken by the worker — the reading closest to the exemplars; each is argued under "Built and proven"):
- taken by the worker: **a migration after all, db/025** — `GRANT DELETE ON sources TO datalake_rw` (db/009 gave SELECT, INSERT, UPDATE; `source_delete` needs it).
- taken by the worker: the K7 proof's fake kernel is the existing one on :9002 (extended with `/api/v1/apps/read.php`), not a second server on :9005.
- taken by the worker: the fetch client refuses a non-https address and any loopback / link-local / unspecified address; `DL_FETCH_ALLOW_INSECURE=1` lifts both outside production
  (the fixtures server); a URL's query string is redacted everywhere it is shown or logged.
- taken by the worker: a pull that REPLACES the dataset carries a per-pull marker (whitespace) on its first page, or the writer would call it "already loaded".
- taken by the worker: blocked (robots.txt, 403/429, a bot wall, `no_connection`, a missing credential or secrets key) pauses the source at once with the reason and counts no
  failure; any other failure climbs the ladder (back-off 2^n minutes, at most a day; the fifth pauses and tells the creator and every `sources.manage` holder).
- taken by the worker: credential kinds by connector (http_file bearer · api_key · basic, sftp_file sftp_password · sftp_key, postgres database_password); the secret
  alone is sealed; the log holds the credential's id, kind and label — not its last four.
- taken by the worker: `target_dataset` takes an id, a key or a name (a new one needs `loads.manage`); a source made through the API with no schedule is manual.

## Built and proven (2026-10-11)
A source reads ONE thing into ONE raw dataset, and the PHP worker — never the writer — pulls it. **The connectors** (`app/lake/fetch/connectors/`, Inventory's
`Connector.php` ported, `inv_` → `dl_`, plus `validate()` per connector): `upload` (a named shape; Pull now answers `manual_only`), `http_file` (a conditional GET
through the one polite client — an honest user-agent, robots.txt honoured, one request a second, https only; the file STREAMS to the work directory; a 304 is `ok`
and lands nothing; the same bytes under a new ETag are refused by the load's key), `sftp_file` (a directory: every new file matching the pattern lands once, oldest
first, over ssh2, `curl sftp://` with a netrc file or the `sftp` command — the password on no command line), `k7` (a sibling's share through the kernel: pages as
NDJSON of their rows, `<yesterday>` arguments, `inject`ed fields, a date window that moves forward, `no_connection` pausing the source without a failure) and
`postgres` (one table of an outside database: a READ-ONLY REPEATABLE READ transaction, a server-side cursor, pages of 50,000 rows, incremental by a watermark, a weekly
reconciliation). **The pull** (`app/lake/fetch/pulls.php`): `dl_run_pull()` starts a `source_pulls` row, builds the `$source` (the ONE place a credential is opened),
lands each emitted file with slice 2's `dl_landing_store()`, makes the `loads` row (the key `'<dataset>:<sha256>'`, the `mapping` the writer reads a pulled file by) and
queues the writer's `load`; the failure ladder, the pause, the notice and the lineage edge are PHP's (the spec planned no SQL for them). **The screens**: the list as
cards, a source's page with Settings / Pulls (polling itself while a pull runs) / Feeds, the credential form (sealed, last four only), Add (five connector cards, then
the form with the record picker) and Edit. The registry reads **54 screens and 79 actions built** (4 + 8 here; the approvals unchanged by this slice: 29 in all).

**Proven** by `tests/phase3/slice6/run.sh` (scratch `dl_dev6`, the engine real, the fixtures server on :9004, an outside database `dl_dev6_outside`): **428 checks under
php -S and the same under a real Apache** — sources 164, pulls 90, postgres 41, k7 51, JSON 36, the browser 46 (375 and 1280, screenshots in
`/tmp/dl-shots/slice6`; the HTMX flows; without JavaScript the add form, Test and Pause as plain forms) — plus the schema proof (**217** — db/025's five checks) and
the registry `--check`. Re-run green: slices 5 (310), 4 (332), 3 (220), 2 (262), 1 (229) and Phase 2 (331 / 336), the engine proof (157), Phase 0's schema and kit, Phase 1's claims.

**Decisions taken in the build** (the spec left them open, or the code showed better):
1. **db/025 — the one migration the spec said it would not need**: `GRANT DELETE ON sources TO datalake_rw`. db/009 gave the application SELECT, INSERT and UPDATE on
   `sources` and DELETE only on `source_credentials`; `source_delete` could not have run. The pulls, the credential and the lineage edges go with the row by foreign key,
   the datasets and their loads stay (their `source_id` and `pull_id` are set null). The application still cannot delete a pull; the writer never deletes a source.
2. **The K7 shares are served by the existing fake kernel on :9002** (extended with `POST /api/v1/apps/read.php`, state-driven, every request logged), not a second server
   on :9005 — the application's kernel URL is already wired to :9002.
3. **`DL_FETCH_ALLOW_INSECURE=1` (outside production only) lets the fetch client reach `http://` and loopback** — the proofs' fixtures server is plain http on 127.0.0.1.
   Without it (and always in production) the URL must be https and must not resolve to a loopback, link-local (the cloud metadata address) or unspecified address; a
   business's private LAN address is allowed. A URL with `user:password@` is refused; a URL's query string is replaced by `?…` in every page, JSON answer and log row.
   A redirect to another host drops the Authorization header.
4. **Replace needs a marker — on every page.** A load that REPLACES the dataset (a postgres source with no watermark, the weekly reload) would be answered "already
   loaded" by the writer when its first page's bytes match an earlier landing — the delete would never run — and the load's own key (`'<dataset>:<sha256>'`) would refuse
   the pages that FOLLOW the first, whose bytes are last time's (found by the proof: the dataset would end with the first page only). Every page of such a pull is
   therefore preceded by a line of spaces and tabs spelling the pull's number in binary (`dl_pull_marker()`): invisible to a JSON reader, different bytes every
   pull, the same rows. Only the first page's mode is `replace`; the rest `append`.
5. **The writer's JSON reader types before it casts back to text** (DuckDB's `read_json_auto`): every scalar of a postgres row is written as a JSON string, so a
   `numeric(12,2)` keeps its zero and a code its leading ones; a string the reader takes for an ISO-8601 date or timestamp (`2026-09-01T10:00:00`) comes back in
   DuckDB's spelling (`2026-09-01 10:00:00`) — PostgreSQL's own `2026-09-01 00:01:00+00` is left as printed. The landing blob keeps the original. Nested arrays land as JSON text.
6. **Blocked means blocked**: robots.txt forbidding the path, a 403 or 429, a bot wall, a K7 `no_connection` / `not_shared`, a missing or unopenable credential or secrets
   key — the pull is `blocked`, the source PAUSES at once with the reason beside it (`robots.txt disallows …`, `no_connection`, `credential: …`) and no failure is counted;
   `source_resume` retries (and sealing a credential resumes a source paused for want of one). Every other failure climbs the ladder: back-off 2^n minutes (at most a day),
   the fifth in a row pauses and tells the creator and every holder of `sources.manage` (`dl_notify` kind `load_failed`, once a day for a source).
7. **A failed load of bytes the key already knows is queued again** by the next pull of them (the writer answers "already loaded" if the rows are in the lake).
8. **Credential kinds by connector**: http_file bearer · api_key (header `X-API-Key`, or `api_key_header`) · basic (the user name is a setting); sftp_file sftp_password ·
   sftp_key; postgres database_password; k7 and upload none. Only the secret is sealed; for an `sftp_key` the form's textarea (`secret_key`) carries it and its "last four"
   is a fingerprint prefix. The log holds the credential's id, kind, label and whether it replaced one — not even its last four.
9. **`mcp_sources` leaves out `rate_per_second` and `user_agent`**; `find_source()` reads those two (not secret) from the base table once the view has admitted the source.
10. **The weekly reconciliation** (Sundays from 03:00 business time, once a week per source, before the queued pulls so a reload runs in the same pass) compares the table's
    `count(*)` with the dataset's `row_count`, skips a source whose loads are in flight or that never loaded, and queues a pull with `policy.reload`. An updated row is
    a second row in raw (raw is history), so a table that is updated differs every week and is reloaded every week — the cost of the spec's design; a source with a
    watermark column the table does not bump on update should have none.
11. **State is kept in the pull's policy**: `policy.state` (the ETag and Last-Modified, the window's end, the watermark, the SFTP files seen) of the last good pull, valid only
    for the settings it was kept under (a fingerprint) — a source whose address changed starts again. A pull still `running` after six hours is abandoned by the next one.
12. **Test's answer** is the notice on the source page (`?notice=test_ok|test_blocked|test_misconfigured&said=…`, the sentence ≤ 240 characters — no secret, no row); JSON
    gets `state`, `message` and `facts`. A source's page polls its Pulls table through `GET /sources/{id}?tab=pulls&partial=pulls` (HTMX only; `html/sources/view.php`).
13. **`target_dataset`** is an id, a key (`raw.x`) or a bare name (made when new, which needs `loads.manage`); the form's `target_name` is the same for a new one. A dataset
    the person may not even discover is "not here" (the gate's rule); one they may only discover, "you may not change …". A new dataset takes the postgres source's `key[]` (or
    the seed's) as its `dedupe_key` and its `source_id`.
14. **Defaults**: a source made through the API with no `schedule_minutes` is manual (0); the form offers daily. The postgres guard in SQL is a name check — `localhost`,
    `127.0.0.1`, `::1` and a socket are refused, an alias such as `127.0.0.2` or the machine's own address is not. **Closed by the planning model's review:**
    `dl_fetch_host_refusal()` (`app/lake/fetch/http.php`) resolves a host and refuses a reserved address (loopback, link-local with the metadata address, unspecified)
    or ANY of this machine's own addresses (`net_get_interfaces()` and the host name's) — for http_file at every hop (through `dl_fetch_url_refusal()`), for sftp_file and
    postgres at the source's saving and again at every connect (a name may come to resolve to this server). `DL_FETCH_ALLOW_INSECURE=1` lifts it outside production
    only, which is how the proofs still reach their outside database as `127.0.0.2`; `sources.php` proves the refusal with the switch off (127.0.0.2, `localhost`, the
    machine's own address, the metadata address — and a server elsewhere accepted).

15. **`kernel_rows()`** (app/api/kernel.php — the log of a K7 read counts its rows) learned the shape of the shares that answer a DOCUMENT: `{schema, as_of, accounts: […]}` has
    one top-level list of rows, and `share.read` now says `rows: 2` instead of 0. Nothing else of the kit changed.
16. **Not proven against a real server**: the SFTP transports (`ssh2` is not installed here; `curl sftp://` and `sftp` exist but no sshd runs in the proofs) — the plan each
    builds, the netrc and the argv are proven through the seam, and the `ls -l` parser against a canned listing; a first real pull is the owner's check.

**Found and fixed in slice 6:** a replacing pull's later pages refused by the load's key (decision 4); nothing in the writer; the schema lacked the DELETE grant (db/025); a `~`-delimited regular expression containing `~` in the SFTP path
rule; `datasets.dedupe_key` is NOT NULL (`'{}'`); `servers.sh start` kills every `*.pid` in the state directory, so the fixtures server starts after it.
