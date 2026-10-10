# Build spec: the lake's API, keys and the K7 shares (slice 9 — replicates slices 3 and 4)

What exists at the end: the **lake's API** (`/api/v1/*`) for an outside system holding a person's key — the catalog, a dataset's entry, columns, health,
lineage and ODCS contract, a product's rows in pages, saved queries and metrics run, ad-hoc SQL behind the key's switch, a file uploaded into the person's
sandbox, the lineage events as OpenLineage JSON — every call as the key's person, rate-limited, logged; the **three K7 shares** made real underneath
(`datasets_index`'s SQL, the query service's `product_rows` request, `metric_value` for a consumer) with every read logged `share.read`; the
**connections** screen (what the lake reads from the siblings and what it shares with them) with `share_application` / `share_revoke`; the **API** page.
**Build `share_application` / `share_revoke` exactly like slice 4's `grant_set` / `grant_revoke` (the same write function with `principal_kind =
'application'`); the `product_rows` request kind exactly like slice 3's `saved_query` kind (query-service §7); every API route exactly like the kit's
`html/api/v1/health.php` with `app/api/bootstrap.php`'s `api_authenticate()` and `api_rate_limit()`.** Phase 4 wraps the shares as MCP tools; this slice
builds and proves everything they call.

Schema (db/003, 005, 008, 011, 012, 016, 018, never modified): `mcp_access_tokens` (+ `rate_per_minute`, `rate_per_day`, `allow_sql`), `key_usage`, `dl_resolve_key()`, `dl_rate_ok()`,
`dl_key_calls_today()` (db/003, 005); `grants` (`principal_kind = 'application'`, `principal_ref`), `dl_application_level()`, `dl_query_policy_app()` (db/008); `query_runs`,
`dl_query_run_log()` (db/011); `lineage_runs` (db/012); `mcp_grants`, `mcp_datasets`, `mcp_sources`, `mcp_tokens`, `mcp_activity_log` (db/016); `dl_share_datasets_index(app, since, limit,
offset)` (db/018). **One migration**: `db/<next free number>_slice9_share_log.sql` (below).

## Divergences from the design
1. **Shares are products only.** `share_application` refuses a dataset outside the `product` zone (`share_products_only`); a metric is shared by sharing its product.
2. **Every share read is an activity row** through a new SECURITY DEFINER function — the read servers write nothing else: the migration adds
   `dl_share_log(p_app text, p_tool text, p_dataset_id bigint, p_rows integer, p_ms integer) RETURNS void` (VOLATILE; inserts `activity_log` with `source
   'application'`, `action 'share.read'`, `entity_type 'share'`, `dataset_id`, `after = {application, tool, rows, ms}`), `EXECUTE` to `datalake_records_ro` and
   `datalake_query` only. The query service calls it after every `source: "k7"` request; the records server after `datasets_index`.
3. **A person's rows through the API use the `product_rows` kind as the person** (`caller_member_id`, no `consumer_app`): the kind admits either caller; as a person
   it pages ≤ 1,000 rows and only `product` datasets the person may read; as a consumer ≤ 500 rows and ≤ 256 kB (query-service §7).
4. **The API page shows no part of a key** (`mcp_access_tokens` keeps only the hash): its examples carry `<your key>`; the page lists my keys' labels, limits and SQL switch.
5. **An API upload confirms nothing by itself**: `POST /api/v1/upload` lands the file and queues the sniff, answering the upload id and the mapping URL; with `auto=1`
   it confirms the sniff's proposal into a new sandbox dataset named from the file (`sandbox.m<id>_<file stem>`), the personal-data proposals staying proposals (masked
   meanwhile) — the same write function slice 2's `upload_map_confirm` calls.

## Who may do what
share_application / share_revoke: `grants.manage`; both pause for an agent (`external_send`) · the connections and API screens: `grants.manage` (connections), every Member (API) ·
the API: a key's person's rights and levels — `POST /api/v1/sql` needs `sql.editor` AND the key's `allow_sql`; `POST /api/v1/upload` needs `sandbox.upload`; nothing else beyond
the person's own walls · the K7 shares: the kernel's token only (Phase 4's gate), the consumer from `X-OS-Consumer`.

## Screens
`connections` `/admin/connections` (two halves — **What the lake reads** (the `k7` sources: provider, tool, target dataset, last pull, status; `no_connection` shown as "not approved
in the kernel yet" with the kernel's approval path named) and **What the lake shares** (per application: the products granted to it with their level and `since`, the reads in the last
30 days from `share.read` events, Revoke; Share a product: the product picker, the application's catalog key, the level)) · `api` `/api` (the routes of § The API with their parameters
and answers, my keys (label, limits, `allow_sql`, last used), a `curl` example per route with `<your key>`).

## The API (`html/api/v1/*.php`, `app/api/bootstrap.php`, `app/api/routes.php`)
Every route: `api_cors()`, `api_authenticate()` (a key of scope `api` resolved as its person by `dl_resolve_key`, `app.member_id` set), `api_rate_limit()` (`dl_rate_ok` → 429 with
`Retry-After`), the person's walls (the same `mcp_*` views and functions as the screens, through the read role), logged `api.<route>` with source `api` and `token_id` — **never the SQL
text or a value in the payload**. Errors: 401 `unauthenticated`, 403 `not_allowed` (with the rule), 404 `not_found` (also for what the person may not discover), 413 `too_large`, 422
`invalid_argument`, 429 `rate_limited`, 504 `query_timeout`, 503 `engine_unavailable`.

| Method · path | Answers | Through |
|---|---|---|
| `GET /api/v1/datasets?q=&zone=&tag=&certification=&limit=&offset=` | the catalog (`os.datalake-catalog/1`): rows as `find_datasets` | `dl_search` / `mcp_datasets` |
| `GET /api/v1/datasets/{key}` | one entry (stub fields for a stub) | `mcp_datasets` |
| `GET /api/v1/datasets/{key}/columns` | the columns (`mcp_dataset_columns`) | |
| `GET /api/v1/datasets/{key}/health` | `dl_dataset_health()` | |
| `GET /api/v1/datasets/{key}/lineage?direction=&depth=` | as `lineage_upstream` / `lineage_downstream` | `dl_lineage` |
| `GET /api/v1/datasets/{key}/contract.odcs.yaml` | the active contract as ODCS v3.2.0 YAML (slice 5's renderer) | |
| `GET /api/v1/datasets/{key}/rows?since=&limit=&offset=` | a product's rows, masked as for the person (§ Divergences 3) | the query service `product_rows` |
| `POST /api/v1/queries/{id}/run` `{params}` | a saved query's result | `saved_query` kind |
| `POST /api/v1/metrics/{name}` `{grain, from, to, by, where}` | the metric (`os.datalake-metric/1`) | `metric` kind (slice 7) |
| `POST /api/v1/sql` `{sql, as_of?}` | the result, refusals with their rule | `sql` kind |
| `POST /api/v1/upload` (multipart `file`, ≤ 64 MB; `auto`) | `{upload_id, mapping_url, load_id?}` | slice 2's landing store |
| `GET /api/v1/lineage/events?since=&limit=` | `lineage_runs` as OpenLineage RunEvents (`eventType`, `eventTime`, `run.runId`, `job{namespace: 'datalake', name}`, `inputs`, `outputs`, facets), only those whose outputs the person may discover | `lineage_runs` |
| `GET /api/v1/health` | the kit's | |

The vhost (`deploy/apache-datalake.conf`) and `tests/dev_router.php` route `/api/v1/datasets/{key}/{sub}` → `datasets.php?key=&sub=`, `/api/v1/queries/{id}/run` → `queries.php?id=`,
`/api/v1/metrics/{name}` → `metrics.php?name=`.

## The shares underneath (Phase 4 wraps them; `docs/datalake-mcp-tool-surface.md` § K7 shares)
- `datasets_index` — `dl_share_datasets_index(consumer, since, limit, offset)` (db/018) → `{schema 'os.datalake-datasets/1', consumer, datasets[], next_offset?}`; `dl_share_log` after.
- `product_rows` — the query service with `{source: "k7", consumer_app, kind: product_rows, dataset, since, limit ≤ 500, offset}` → `dl_query_policy_app(consumer, [key])`; a page cut at 256 kB with
  `next_offset`; `refused` with `not_shared` for a dataset not granted; `dl_share_log` after.
- `metric_value` for a consumer — the `metric` kind (slice 7) with `consumer_app`: the metric's product must be granted to the consumer.

## Files (exactly these)
`html/admin/connections/{index,share,unshare}.php`, `html/api/index.php` (the API page), `html/api/v1/{datasets,queries,metrics,sql,upload,lineage}.php`; `app/api/routes.php`
(the route table and the shared answers); `app/features/connections/{queries,present,write}.php` (`share_application` calls slice 4's grant write function); `app/features/apidocs/present.php`;
`app/views/admin/connections.php` + `app/views/admin/partials/{reads-table,shares-table,share-form}.php`, `app/views/api/index.php`; the `product_rows` request kind in `services/query/`
(beside slice 3's `saved_query`), its call to `dl_share_log`; `db/<next free number>_slice9_share_log.sql`; `deploy/apache-datalake.conf` and `tests/dev_router.php` (the API rewrites);
`tests/phase3/slice9/{run.sh,lib.php,api.php,shares.php,connections.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`k7_reads(PDO): array` (the `k7` sources with their last pull) · `k7_shares(PDO): array` (per application: grants and read counts) · `share_reads(PDO, ?string $app, string $from, string $to): array` ·
`api_route(string $method, string $path): ?array` · `api_dataset_by_key(PDO, string $key): ?array` · `lineage_events(PDO, ?string $since, int $limit): array` (OpenLineage-shaped) · writes:
`share_application(PDO, int $dataset, string $app, string $level, int $by): int` (refuses a non-product) · `share_revoke(PDO, int $grant, int $by)` (refuses a grant that is not an application's) ·
the client call `lake_query(array $request): array` (query-service §8 — slice 3's)

## MCP tools this slice makes real
`datasets_index`, `product_rows` (the two kernel-only shares; the consumer side of metric_value too), and the activity server's `share_reads`.

## Manifest rows claimed
Screens (2): `connections`, `api`
Actions (2): `share_application`, `share_revoke`

## Activity log events
`share.grant`, `share.revoke` (`{application, dataset, level}`), `share.read` (`{application, tool, rows, ms}` — written by `dl_share_log`, source `application`), `api.datasets|dataset|columns|health|lineage|contract|rows|query|metric|sql|upload|lineage_events`
(`{route, status, rows?, ms}`, source `api`, `token_id`), `key.rate_limited` (the kit's).

## Status vocabulary
A K7 read: ok `success`, paused `warning` ("not approved in the kernel yet" for `no_connection`), failed `danger`. A share: read `primary`, discover `light`. Ids: `connections-reads`, `connections-shares`,
`share-form`, `share-{grant}-revoke`, `api-routes`, `api-keys`.

## Out of scope for this slice
Minting, rotating and revoking keys and the SQL switch (the shell's actions — `settings-tokens`); the MCP servers (Phase 4); approving a connection (the kernel's `bin/app_connection.php`, a super-admin's);
BI over a wire protocol, outside sharing links (Extended).

## Proof (`tests/phase3/slice9/run.sh`: scratch `dl_dev9`, a scratch lake; the query service and the writer started for the run; ports 9001–9007)
A key lists the catalog as its person (a confidential dataset a stub, a sandbox of another person absent); a product's rows page with masks; `POST /sql` refused without `allow_sql`, refused without
`sql.editor`, answered with both; a saved query and a metric through the API; an upload with `auto=1` lands a sandbox dataset; the lineage events are OpenLineage-shaped and walled; 61 calls in a minute →
429 with `Retry-After`; every call one `api.*` row with `token_id` and no SQL; `share_application` refuses a curated dataset and grants a product; `dl_share_datasets_index('gl', …)` lists exactly the granted
products; `product_rows` for `gl` answers masked rows ≤ 500 and ≤ 256 kB with `next_offset`, refuses a product not granted with `not_shared`, and logs `share.read`; an agent's `share_application` pauses;
JSON mode on the two actions; 375 and 1280; `php bin/build_action_registry.php --check` (2 screens, 2 actions built, 2 approvals).

## Open questions
None — decisions taken: products only (§ Divergences 1); `dl_share_log` as the one write of the read servers (2); `product_rows` admits a person or a consumer (3); no part of a key shown (4); an API
upload confirms only with `auto=1` (5).
