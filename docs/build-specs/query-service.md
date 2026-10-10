# Build spec: the query service — the one door to the data (design §3 rule 5, §6.5, §6.9; D6, D7, D8, D13, D14)

Every row anyone reads — a screen's preview, the SQL editor, a saved query, a metric, an export, the expert, a K7 share, the API — comes
from the query service: a pool of sandboxed DuckDB workers that see each caller's datasets as views with that caller's masks and row
filters applied, and nothing else. Phase 0 built and proved its library (`services/query/allowlist.py`, `policy.py`, `service.py`;
`tests/engine/proof_query.py`, 73 checks; FINDINGS §5) and a single-process prototype (`server.py`). **Slice 3 turns the prototype into the
service this spec describes**; slices 1, 2, 5, 7, 9 and 10 call it through the two clients of §8 and never otherwise.

## 1. What it is, and who calls it
- **The service**: `datalake-query@N.service` (N = 1…`query_workers`, 3), each one process (`services/query/server.py --worker N`) serving
  **one request at a time** on its own socket, under systemd (`User=datalake-query`, `MemoryMax=2560M`, `CPUQuota=200%`, `TasksMax=64`,
  `ProtectSystem=strict`, `ReadOnlyPaths={{DL_STORAGE_DIR}}/lake`, `PrivateTmp`, `NoNewPrivileges`, `IPAddressDeny=any` +
  `IPAddressAllow=localhost` for PostgreSQL). A process that overruns is killed and restarted by systemd; its client gets
  `engine_unavailable` and retries another instance once.
- **Its callers** (the only ones): PHP through `app/lake/client.php` (the web application: the editor, previews, saved queries, metrics,
  the API), the records MCP through `mcp/lake_client.py` (agents and people's MCP tokens; the K7 shares). Both run as `www-data`.
- **Not its callers**: the writer. Transforms and exports are **writer jobs** (lake-engine.md §1) that import the same library
  (`query.service.run`) in-process with the writer's own READ_ONLY attach (`dsn = DL_LAKE_DB_DSN`), the policy compiled by the database
  exactly as the service does (§3) — the frozen policy for a transform, the requester's for an export — and the same allow-list, views,
  masks, lock and limits. One code path, two hosts.

## 2. The protocol
- **Sockets**: `DL_QUERY_SOCKET` is a **prefix** (`/run/datalake/query`); instance N listens on `/run/datalake/query-N.sock` (0660,
  group www-data; `RuntimeDirectory=datalake`, mode 0750, `datalake-query:www-data`). **Peer check**: on accept the server reads
  `SO_PEERCRED` and refuses any uid but `www-data`'s (`refusal_rule: peer`) — the socket's group is the first wall, the uid the second; no
  TCP listener exists.
- **Framing**: one request per connection; one line of UTF-8 JSON in (≤ 1 MB, else `too_long`), one line of JSON out. The client connects
  to a random instance first, then the others in order; an instance busy with a request answers at once `{"status": "refused",
  "refusal_rule": "busy"}` (it never queues), and the client tries the next; all busy → retry every 200 ms for 2 s, then answer `busy`.
- **Request** (`v` 1):
```json
{"v": 1, "kind": "sql|preview|saved_query|metric|product_rows",
 "caller_member_id": 26, "on_behalf_of": null, "ceiling": null, "agent_run_id": null, "request_id": "r-…",
 "source": "screen|preview|mcp|agent|api|k7|export|transform", "consumer_app": null, "key_label": null,
 "sql": "SELECT …", "params": {"region": "North"}, "as_of": null,
 "saved_query_id": null, "metric": null, "grain": null, "from": null, "to": null, "by": [], "where": {},
 "dataset": null, "ordering_column": null, "since": null, "offset": 0, "limit": null}
```
  `caller_member_id` is the authenticated person or agent (PHP's session member; the MCP gate's resolved member). `on_behalf_of` the
  person an agent acts for (K38) — never sent by a person's own call. `ceiling` = `"internal_unpersonal"` for an agent's chat turn while
  the run facts carry no requester (§3). A K7 call carries `consumer_app` and `caller_member_id: null`. `as_of` is a snapshot id or an
  ISO 8601 time (resolved to the latest snapshot at or before it).
- **Response**:
```json
{"status": "ok|refused|timeout|error", "refusal_rule": null, "message": null, "dataset": null,
 "columns": [{"name": "region", "type": "VARCHAR"}], "rows": [["North", "1204.50"]], "truncated": false,
 "masked_columns": ["curated.sales_orders.customer_email"], "datasets": ["curated.sales_orders"],
 "as_of_snapshot": 812, "ms": 412, "query_run_id": 99012, "next_offset": null}
```
  Values are JSON-safe (decimals and dates as strings, bytes as `\x…` hex — `service._jsonable`). `query_run_id` is always set (refusals
  and timeouts are audited too). `dataset` names the key at fault in a refusal.

## 3. The policy
- **The service compiles it** (never the caller — a caller can forge nothing): as `datalake_query`, `SELECT dl_query_policy($caller,
  $on_behalf_of, $keys, $source, $ceiling)` (db/018's five-parameter version) or, for `consumer_app`, `dl_query_policy_app($app, $keys)`;
  `$keys` = the dataset keys the allow-list's first pass found in the SQL (or the preview's / product's / metric's dataset). It then adds
  `salt_hex` from `DL_MASK_SALT_FILE` (root:datalake-query 0440 — read at start, never logged) and maps `limits.temp_mb` → `"<n>MB"`.
- **The JSON** (db/008 + db/018): `{caller, on_behalf_of, source, datasets: {<key>: {level read|read_unmasked, columns[], masks{col: mask},
  row_filter, classification, has_personal}}, refused: {<key>: {rule, message}}, limits: {timeout_s, row_limit, result_bytes, memory,
  threads, temp_mb}}`. `refused[].rule` ∈ `no_such_dataset`, `discover_only`, `not_readable`, `k38_pending`, `not_shared` (the app variant).
- **The intersection** (D8): with `on_behalf_of`, each dataset's level is the lower of the agent's and the person's, both row filters apply
  (AND), masks are the lower level's — compiled in `dl_access_of` (db/008), nothing in Python.
- **The fail-closed ceiling** until K38: with `ceiling = 'internal_unpersonal'` any dataset classified above `internal` or with a personal
  column is under `refused` with rule `k38_pending` ("readable once the kernel says who asked (K38)"). The MCP gate passes the ceiling
  for a `chat` run with no `requested_by_member_id` in its run facts; a `duty` run passes none (it reads as the agent).
- **A key in the caller's SQL** that is under `refused` is refused with that rule and message; a key in neither map is `not_readable`.

## 4. The allow-list and the refusal vocabulary
Every refusal is `{"status": "refused", "refusal_rule": <rule>, "message": <one sentence for a person>, "dataset"?}`:

| Rule | From | Means | PHP shows / MCP answers |
|---|---|---|---|
| `empty`, `too_long`, `parse`, `one_statement`, `select_only`, `too_complex` | allowlist | not one readable SELECT under 100,000 characters / 5,000 nodes | the message under the editor; `refused` |
| `function`, `table_function` | allowlist | a denied function (DuckDB's table functions computed at start, `getenv`, `current_setting`, the prefixes `read_`, `duckdb_`, `ducklake_`, `pragma_`, `dl_` …) | "…is not allowed" |
| `catalog_reference`, `unknown_table`, `time_travel` | allowlist | a name that is not `<zone>.<dataset>`, or `AT (VERSION …)` in the SQL | "name a dataset" / "use the as-of option" |
| `unbound_parameter` | allowlist | a `$name` with no value | the parameter's field marked |
| `no_such_dataset`, `discover_only`, `not_readable`, `not_shared` | the policy | the key cannot be read by this caller | the message + **Request access** (`/access/requests/new?dataset=<key>`) — except `no_such_dataset` |
| `k38_pending` | the policy | an agent's chat turn before K38 | the message; the expert says it may not read that dataset yet |
| `absent_as_of` | service | a granted dataset did not exist at the snapshot | "…did not exist then" |
| `policy` | service | the compiled policy could not be honoured (a row filter with a forbidden node) — fail closed | "the lake refused this read (its policy)"; the admin is notified once a day |
| `rate`, `concurrency` | service | an agent over `query_rate_agent_per_minute`; a person over `query_concurrency_person` running | "too many queries at once — try again in a moment" |
| `busy` | client | every instance busy for 2 s | `engine_unavailable` with `retry: true` |
| `peer`, `bad_request`, `too_long` | server | not from www-data / not JSON / over 1 MB | logged, never shown |
| `timeout` | service | stopped by the watchdog at the source's limit | "stopped after N s — narrow it" (`status: timeout`) |
| `memory`, `engine`, `permission` | service | DuckDB's error (`status: error`) | "the engine could not finish this read"; the first line ≤ 300 kept in the audit |

`not_granted` (the library's internal word) is never answered: it is mapped per §3's last rule. **Rate and concurrency** are admitted in
PostgreSQL before the run: slice 3 adds `dl_query_admit(p_caller bigint, p_is_agent boolean) RETURNS text` (SECURITY DEFINER, EXECUTE to
`datalake_query`): `NULL` = admitted, else the rule — an agent's runs (`query_runs`) in the last minute against the setting; a person's
concurrency by two advisory-lock slots per caller (`pg_try_advisory_lock(hashtext('dlq'), caller*4+slot)`) that the service holds for
the run on its PostgreSQL connection.

## 5. Views, masks, row filters, the lock and the limits (as built — FINDINGS §5)
1. **First pass** (no connection): `allowlist.check(sql, {key: key for every key the policy names}, table_functions, params)` — refuses
   early; collects the keys.
2. **Attach** READ_ONLY under a random alias `lk_<12 hex>` (`engine.query_connection`) with `memory_limit` / `threads` /
   `max_temp_directory_size` from the limits.
3. **Views** (`policy.create_views`): one `TEMP VIEW dlv_<zone>__<name>` per readable dataset — only the policy's columns; each masked
   column replaced by its mask expression (`full` → `***`, `partial_email`, `partial`, `hash` → `'h:' || left(sha256(salt||v), 16)`, `null`,
   `year_only`); the row filter as WHERE, **validated node by node** (columns of the dataset, literals, comparisons, IN, BETWEEN, LIKE,
   AND/OR/NOT, CAST, arrays — slice 3 adds `ANY` so the bound `department_id = ANY(ARRAY[7]::BIGINT[])` of db/008 passes); the as-of snapshot
   as `AT (VERSION => n)`.
4. **Second pass**: the SQL rewritten to name only the views (`granted`), CTE names allowed, parameters bound as literals.
5. **The lock**: `allowed_directories = ['<store>/lake/']`, `enable_external_access = false`, `lock_configuration = true` — after the
   views, before the SQL. The attached lake stays reachable by name; the allow-list's `catalog_reference` and the random alias are what keep
   the SQL to the views (load-bearing — FINDINGS §5).
6. **The run** under a watchdog (`threading.Timer(timeout_s, con.interrupt)`); `fetchmany(row_limit + 1)` → `truncated`; rows cut at
   `result_bytes` → `truncated` with a message.

**Limits by source** (from the policy, D7, settings): `screen` 30 s / 1,000 rows · `preview` 30 s / 100 rows · `mcp`, `agent`, `api` 60 s /
1,000 · `export`, `transform` 300 s / 100,000 · `k7` 60 s / 500 rows / 256 kB. Result bytes 50 MB (256 kB for `k7`).

## 6. The audit
The service writes every request's row (refusals, timeouts, errors included) with `SELECT dl_query_run_log($json)` (db/011) on its
PostgreSQL connection, after the run: `{caller, on_behalf_of, agent_run_id, request_id, source, consumer_app, key_label, saved_query_id,
metric_id, sql_sha256, sql, as_of, datasets, masked_columns, row_filters, rows_returned, truncated, bytes_returned, ms, status, refusal_rule,
error, worker}` → `query_run_id` in the answer. `sql` is the caller's text as sent (kept `query_text_days`); `row_filters` the predicates as
applied. **Never** in the audit, an activity row or a log line: a row value, a mask's salt, a passfile or DSN, a file path. The caller (PHP,
MCP) logs `query.run` to `activity_log` with `query_run_id`, the dataset ids and the counts only — never the SQL. A writer job's run (export,
transform) is audited the same way by the writer (slice 3 grants `EXECUTE ON dl_query_run_log` to `datalake_lake`).

## 7. The request kinds

**Kinds and flags, by the slice that builds them** (the protocol's `kind` is one of `ping`, `sql`, `preview`, `saved_query`, `metric`, `product_rows`, `cancel`; `export` is a writer job, §1): `ping` (Phase 2 — the health check and the shell's engine badge; answers `{status: ok, worker, version}` and touches no catalog) · `preview` (slice 2; with `quarantine: true` — slice 5 — it reads `quarantine.<name>` at the dataset's `write` level, masked below `read_unmasked`) · `sql` with `params` (named `$name` placeholders bound as literals) and `dry_run: true` (slice 3 — parse, allow-list, policy and `EXPLAIN` only, no rows, still audited as `status: ok, rows_returned: 0`; used by saving a query and freezing a transform — slice 7 — to learn the datasets a SQL reads) · `saved_query` (slice 3) · `cancel` (slice 3 — `{request_id}` of the caller's own running request; the watchdog's `interrupt()`; anyone else's id answers `not_found`) · `check_row_filter` (slice 4 — `{dataset, row_filter}` bound for the caller, validated node by node against the dataset's columns exactly as §5 does at query time; answers `{valid, rule?, message?}`; the grant form calls it before `grant_set` saves) · `metric` (slice 7) · `product_rows` (slice 9).

- **`sql`**: the caller's SQL as is. `params` bind `$name` placeholders (the allow-list refuses an unbound one).
- **`preview`**: `SELECT * FROM <dataset> LIMIT 100`, source `preview`; the response's `masked_columns` drive the "masked for you" marks.
- **`saved_query`**: the **caller** reads the saved query under its own wall (`mcp_saved_queries`: own, or shared and readable) and sends
  `sql` + `saved_query_id` + `params`; the service runs it as `sql` (the audit row carries `saved_query_id`).
- **`metric`**: the caller reads the metric (`mcp_metrics`) and sends `metric: {id, dataset, expression, time_column, grains, dimensions,
  filters}` with `grain`, `from`, `to`, `by[]`, `where{}`. The service refuses a grain not in `grains`, a `by` or `where` key not in
  `dimensions` (`invalid_metric`), then compiles `SELECT date_trunc('<grain>', <time_column>) AS period, <by…>, <expression> AS value FROM
  <dataset> WHERE <time_column> >= $from AND <time_column> < $to [AND (<filters>)] [AND <dim> = $w_i | IN (…)] GROUP BY ALL ORDER BY period`
  and runs it through the allow-list like any SQL (the expression and filters are an engineer's text: nothing forbidden passes).
- **`product_rows`** (K7): `consumer_app`, `dataset`, `ordering_column` (the caller reads `datasets.ordering_column`), `since`, `offset`,
  `limit` ≤ 500: `SELECT * FROM <dataset> [WHERE <ordering> > $since] ORDER BY <ordering>, _dl_load_id, _dl_row LIMIT limit+1 OFFSET
  offset` under `dl_query_policy_app`; the page is cut at 256 kB (JSON) and `next_offset` set when rows remain.
- **`export`** (a writer job, not a socket kind): the policy for the export's maker (and `on_behalf_of`) with source `export`; the rows
  written to `<store>/exports/<export_id>.<csv|xlsx|parquet>`. `COPY … TO` is never used (the allow-list refuses it and the lock forbids
  writing files): the writer fetches through the locked connection in batches of 10,000 and writes the file in Python — pyarrow for Parquet,
  openpyxl for XLSX, `csv` for CSV — capped at `query_rows_download` (100,000) → `exports.truncated`,
  `row_count`, `masked`, `byte_size`, `status done`; a notification `export_ready`.

## 8. The clients
**`app/lake/client.php`** — the ONE PHP file that reaches the engine:
`lake_request(array $req, int $timeoutS): array` (the socket call: instances, `busy` retries, framing; `['status' => 'error', 'refusal_rule' =>
'engine_unavailable', 'message' => 'The lake's query service is not answering — try again in a moment.', 'retry' => true]` on any socket
failure) · `lake_sql(string $sql, array $opts = []): array` (`source` screen, `as_of`, `params`, `limit`) · `lake_preview(string $key, ?string $asOf
= null): array` · `lake_saved_query(array $saved, array $params = []): array` · `lake_metric(array $metric, string $grain, string $from, string
$to, array $by = [], array $where = []): array` · `lake_refusal_message(array $answer): string` (the person's sentence, with the Request access
link for the policy's rules) · `lake_caller(): array` (the session member and, for an action token relayed for an agent, `on_behalf_of` and the
ceiling — access-and-policies.md §6). Every function fills `caller_member_id`, `request_id` (`X-Request-Id` or a new one) and `source`.
**`mcp/lake_client.py`** — the records server's: `async def lake_request(req: dict, timeout: float) -> dict` (asyncio Unix streams, the same
instance rotation and `busy` rule) and `def caller_fields(facts) -> dict` (the gate's member, `on_behalf_of` from `requested_by_member_id`,
`ceiling` for a chat turn without it, `agent_run_id`, `source` agent or mcp).

## 9. Proofs (slice 3's `tests/phase3/slice3/run.sh`; FINDINGS' proofs stay)
- Each of the 32 forbidden forms of `proof_query.py` through the socket (not the library) answers its rule.
- The peer check: a connection from a uid other than www-data refused (`peer`) — the proof runs the service as the test user and
  configures the allowed uid to a second scratch user.
- Each source's limits: a screen query stops at 30 s (a 1-second setting in the proof), a preview returns 100 rows, an export caps at the
  setting, `truncated` set.
- The intersection: the expert acting for Priya sees her department's rows only; for Xena nothing (`not_readable`).
- `k38_pending`: an agent's chat-turn call with the ceiling refuses a confidential dataset and reads an internal one.
- A K7 consumer's page: `product_rows` cut at 256 kB with `next_offset`; a dataset not shared → `not_shared`.
- The audit: every request (ok, refused, timeout) has its `query_runs` row; no SQL in `activity_log`.
- `busy`: three instances held, the fourth request waits and answers `busy` after 2 s.

## Decisions taken here (for the owner)
1. **The service compiles the policy itself** from the caller's identity (the Phase 0 prototype took a policy from the caller): a caller
   can forge nothing. The salt is added from its file inside the service.
2. **One socket per instance** (`/run/datalake/query-N.sock`, `DL_QUERY_SOCKET` a prefix), one request at a time per instance, `busy` answered
   at once, the client rotating — so systemd's `MemoryMax` bounds each request's process.
3. Only the uid `www-data` may connect (`SO_PEERCRED`).
4. **Exports and transforms run in the writer** through the same library and the same compiled policy, not through the socket; the writer's
   role gets `EXECUTE` on `dl_query_run_log`.
5. Saved queries and metrics are **read by the caller under its wall** and sent to the service with their definition (the service reads no
   application table).
6. Rate (agents per minute) and concurrency (two per person) are admitted in PostgreSQL by a new `dl_query_admit()` (slice 3's migration)
   with advisory-lock slots, so the limits hold across instances.
7. `as_of` accepts a snapshot id or a time (the latest snapshot at or before it).
8. The row-filter validator admits `ANY` (the bound department filter of db/008 uses `= ANY(ARRAY[…]::BIGINT[])`).
9. A `policy` refusal notifies the admin once a day (a filter that cannot be honoured is a configuration fault, not the caller's).
