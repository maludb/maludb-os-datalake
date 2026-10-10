# Data Lake — MCP tool surface

2026-10-10 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/datalake-design.md` §7 (C1–C8, Q1–Q6,
T1–T8, S1–S7, I1–I6, P1–P4, ACT1–ACT4), the three K7 shares, and the resolvers the kernel's actions server names. Writes are never here:
they are the action tools the kernel's Actions MCP builds from `docs/datalake-action-manifest.md`. **Every row a tool returns comes either
from an `mcp_*` view (the catalog, the governance, the audit — never a row of data) or from the query service (the data — masked and
filtered for the caller); no tool reads a Parquet file, the DuckLake catalog or a credential** (design §3 rule 5, §6.5).

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd unit each
(`datalake-records-mcp`, `datalake-activity-mcp`); `mcp/db.py` and `mcp/server_common.py` (the kit's, already here). Every query runs through
`db.fetch_scoped()` with `app.member_id` and `app.role` set **transaction-locally** from the verified bearer — and, for an agent acting for a
person, `app.on_behalf_of` (K38) — so the `mcp_*` views (db/016, db/018) and the read functions decide the rows and answer as the caller.
Tool names are snake_case. Every tool takes one `params` object (Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described
`Field` on every field). Every list tool takes `limit` (1–100, default 25) and, where it pages, `offset` (0–10,000). Every tool carries
`readOnlyHint: true` and `openWorldHint: false`; every result is a JSON string (`db.to_json`). Ids are integers, a dataset is named by its
key (`curated.sales_orders`) or its id, times are ISO 8601 with the zone. **The wall is per row** (`dl_can_discover()`, `dl_level()` inlined
in every view and function): no tool asks for the caller's whole visible set.

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`. It
never raises, except for the run-facts gate's own refusal, which is the contract's ToolError. Codes:

| Code | Means |
|---|---|
| `not_found` | no such record, **or one the caller may not discover**: the same words, so a tool never confirms that a hidden dataset exists (a stub the caller may see is `found` with `is_stub: true`) |
| `refused` | the query service refused the SQL or the read: `rule` names it (`statement`, `table_function`, `catalog_reference`, `not_readable`, `discover_only`, `absent_as_of`, `k38_pending`, `rate`, `concurrency`, `too_large`, `timeout`), `dataset` names the key when one is at fault, `request_access` is the screen's URL when access would cure it |
| `not_allowed` | the record is discoverable but this tool is someone else's (the audit, a grant list, the engine's state) |
| `invalid_argument` | a parameter failed validation; `fields` names it |
| `ambiguous` | a name matched several records; `candidates[]` (≤ 6, id + label) so the agent asks one question |
| `engine_unavailable` | the query service did not answer (its socket is down); `retry: true` |
| `too_large` | the answer would pass the tool's byte cap (a preview's 100 rows over 1 MB, a lineage over 500 nodes): ask for less |

Words used below (db/008, db/016, db/018; design §3):

| Word | Means |
|---|---|
| `discover` | `dl_can_discover()` is true: the caller may see the entry (a stub when only `dl_can_see_stub()`) |
| `read` | `dl_level()` ≥ `read`: rows through the query service, masked; `read_unmasked` and above: unmasked |
| `own` | the caller's own: their sandbox, saved queries, exports, requests, notifications, keys, trail |
| `owner` | the dataset's owner (`datasets.owner_member_id`); `steward` likewise |
| `insider` | `dl_is_member_here()`: admitted (any role, agents included) |
| a right | `dl_has_right(…)` — `sql.editor`, `catalog.edit`, `sources.manage`, `loads.manage`, `checks.manage`, `grants.manage`, `audit.read`, `erasure.manage`, `maintenance.run`, `keys.all` … (db/004) |
| `kernel` | the kernel's own token (`kernel.{exp}.{app}.{nonce}.{hmac}`): `app_roles` and the three shares, nothing else |

**An agent is a member**: the same functions, no special case — **and in a person's chat turn the intersection** (§ "Who is asking").

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `datalake_records_mcp` | `datalake_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8847) | `MCP_ACTIVITY_PORT` (pinned 8848) |
| Role / env | `datalake_records_ro` | `datalake_activity_ro` |
| Sees | every `mcp_*` view but `mcp_activity_log`; the read functions granted to it (db/007–018); `dl_lake_tables`, `dl_lake_columns`, `dl_lake_snapshots` (db/engine/001); `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind`; **the query service's socket** (`DL_QUERY_SOCKET`, group `www-data`) | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_datasets`, `mcp_query_runs`, `mcp_loads`, `mcp_incidents` (names beside a row) |
| Public path | `datalake.<domain>/mcp/records` | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`, the kit's).

1. **A person's own token** (`mcp_` + 48 hex, scope `mcp`, shown once, sha256 kept): `mcp_resolve_token(hash, 'mcp')`, active and admitted. **A
   key (scope `api`) is refused here** (401): keys are for the lake's API. Never tool-filtered; its rows are the views'.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): the person's `{mid}.{exp}.{hmac}` or the agent run token `{mid}.{exp}.{run}.{hmac}`. The
   member id must be in the mirror and admitted; **an unknown id is refused, never created**. For a run token the server asks the kernel's
   run-facts (`POST {OS_INTERNAL_URL}/api/v1/runs/facts.php`, bearer `OS_APPLICATION_TOKEN`), caches 300 s, and offers exactly the tools named
   for `Records MCP` / `Activity MCP`; a vouched agent is admitted on first contact (`mcp_admit_agent`). **Fail closed** when the kernel is
   unreachable or `valid: false`. **`is_eval`:** these servers never write (the query service's audit row is written; no data changes).
3. **The kernel's own token** (`verify_kernel_token`, 60 s, bound to `APP_KEY = datalake`): `KERNEL_TOOLS = {app_roles, datasets_index,
   product_rows, metric_value}`. No member identity is set; the shares answer for the consumer named in `X-OS-Consumer` (K26) through
   `dl_share_datasets_index()` and the query service's application policy (`dl_query_policy_app`, db/008).

### Who is asking — the intersection (design §3 rule 8, D8, K38)
For an agent's run token the server reads the run facts' **`requested_by_member_id`** (K38). When the run is a `chat` turn (or a dispatched run
that names a person) and that person holds this application, `app.on_behalf_of` is set to them and every read is the **intersection**: the views
through `dl_level()` (db/008), the data through the query service with `on_behalf_of`. **Until K38 lands** (the run facts carry no requester), a
`chat` turn's data reads run with `ceiling = "internal_unpersonal"`: the query service refuses (`rule: k38_pending`) any dataset whose
classification is above `internal` or that has a personal column, and says why — **fail closed, never open**. A `duty` run reads as the agent.

### The query service (how a Python tool reaches the data)
`query_run`, `run_saved_query`, `metric_value`, `dataset_preview` and `product_rows` never open DuckDB: they send one request over the Unix socket
`DL_QUERY_SOCKET` (`docs/build-specs/query-service.md` §2, the protocol) — `{caller_member_id, on_behalf_of?, ceiling?, agent_run_id?,
request_id, source: "mcp"|"agent"|"k7", consumer_app?, sql | saved_query_id + params | metric + grain + from + to + by + where | preview: key,
as_of?, row_limit}` — and return its answer: `{columns, rows, truncated, masked_columns, datasets, ms, query_run_id}` or a refusal. The service
compiles the caller's policy itself (`dl_query_policy` / `dl_query_policy_app`), writes the `query_runs` row and enforces the limits
(D7: 60 s and the display cap for an agent; 256 kB and 500 rows for a K7 share).

## Records server: `datalake_records_mcp`

### Roles (kernel db/145)
`app_roles` takes no arguments. It answers `os.app-roles/1`: `{"schema", "application": "datalake", "roles": [{key, name, description,
capability, is_admin, rights[]}], "rights": [{key, description}]}` from `mcp_app_roles` and `dl_rights` (db/004): 4 roles, 36 rights. Callers:
`kernel` and anyone admitted.

### Finding data (C1–C8) and the catalog's resolvers
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `catalog_search` | **What data do we have about X** — the search box, certified first, then by use; stubs for what the caller may not discover. The server's one guarded search | C1 | `dl_search(q, filters, limit, offset)` | `q` (≤ 200; empty lists the top), `kind?` (dataset, glossary_term, saved_query, metric), `zone?`, `department?`, `owner?`, `tag?`, `classification?`, `certification?` (certified), `personal?` (bool), `limit`, `offset` | rows `{entity_kind, entity_id, dataset_id, title, excerpt, zone, certification, classification, owner_member_id, usage_30d, visibility (full, stub)}`, `total` | insider (the function's wall) |
| `find_datasets` | Which datasets: by zone, department, owner, steward, tag, certification, status, classification, personal data, tier. `q` alone resolves a dataset by key or title (the registry's `dataset` resolver) | C5 | `mcp_datasets` (+ `pg_trgm` on key and title) | `q?`, `zone?[]`, `department?`, `owner?` (member, `me`, `none`), `steward?`, `tag?`, `certification?`, `status?[]` (draft, active, deprecated, retired), `classification?[]`, `has_personal?`, `tier?`, `sort?` (key, updated, usage, rows), `limit`, `offset` | rows `{dataset_id, key, zone, title, owner_member_id, steward_member_id, department_id, status, certification, classification, has_personal_data, row_count, data_updated_at, my_level, is_stub, open_incidents, usage_30d}`, `total` | discover (a stub row for a stub) |
| `get_dataset` | Everything about one dataset at a glance: the entry, the active contract's version, health, tags, the glossary terms of its columns, the engine's size | C2 | `mcp_datasets`, `mcp_contracts` (active), `dl_dataset_health()`, `dl_lake_tables` | `dataset` (id or key) | `{dataset (the view's row), contract?: {version, published_at}, health: green/amber/red, tags[], terms[], engine?: {record_count, file_size_bytes, data_files}}` — a stub answers its stub fields only | discover |
| `dataset_describe` | A description an agent can read aloud or cite: purpose, usage, limitations, the columns with their meaning and whether they are masked for the caller, owner and steward by name, as-of, certification and what it lacks | C2 | `mcp_datasets`, `mcp_dataset_columns`, `mcp_members`, `dl_certification_eligible()`, `dl_masks_for()` with the caller's level | `dataset` | `{key, title, description, purpose, usage, limitations, owner, steward, as_of, certification, certification_missing[], my_level, columns: [{name, business_name, logical_type, description, personal_kind, masked_for_you}]}` | discover |
| `dataset_columns` | A dataset's columns: types, business names, descriptions, classification, personal kind (confirmed or proposed), the latest profile, references | C3 | `mcp_dataset_columns` | `dataset`, `include_removed?`, `include_system?` | rows `{column_id, name, ordinal, physical_type, logical_type, nullable, is_key, business_name, description, description_source, classification, personal_kind, proposed_personal_kind, proposed_confidence, glossary_term_id, critical, references_dataset_id, references_column, profile, profiled_at}` | discover |
| `dataset_preview` | **Show me some rows** of a dataset as the caller may see them | C4 | the query service (`preview`) | `dataset`, `as_of?` (a snapshot id or an ISO time) | `{columns, rows (≤ 100), masked_columns, as_of, query_run_id}` | read |
| `glossary` | The business glossary; `q` alone resolves a term (the registry's `term` resolver) | C6 | `mcp_glossary_terms` | `q?`, `status?` (draft, approved), `parent?`, `limit`, `offset` | rows `{term_id, name, definition, synonyms, parent_id, status, steward_member_id, column_count}` | insider |
| `get_term` | What a term means and which columns carry it | C6 | `mcp_glossary_terms`, `mcp_dataset_columns` | `term` | `{term, columns: [{dataset_id, key, column}]}` (columns of discoverable datasets only) | insider |
| `dataset_usage` | Who uses a dataset, how much, and what it is read with | C7 | `mcp_dataset_usage`, `dl_dataset_frequent_users()`, `dl_dataset_joined_with()` (db/018) | `dataset`, `days?` (30) | `{daily: [{day, queries, people, agents, rows_read}], frequent_users: [{member_id, name, queries}], joined_with: [{dataset_id, key, times}]}` | discover |
| `popular_datasets` | The most used datasets this month (the home's tile) | C7 | `dl_popular_datasets(days, limit)` (db/018) | `days?` (30), `zone?`, `limit` | rows `{dataset_id, key, title, certification, queries, people}` | insider (discoverable only) |
| `catalog_gaps` | What the catalog lacks, tier 1 first: owner, steward, descriptions, personal data to confirm, checks, freshness | C8 | `dl_catalog_gaps(limit)` | `limit` (≤ 1000) | rows `{dataset_id, key, tier, gaps[]}` | `catalog.edit` |
| `coverage` | Coverage against the target, by tier | C8 | `dl_coverage()` | — | `{target, by_tier: {tier: {datasets, described, owned, classified, checked, certified}}}` | `catalog.edit` |
| `find_tags` | The tag vocabulary; `q` alone resolves a tag (the `tag` resolver) | — | `mcp_tags` | `q?`, `kind?`, `limit` | rows `{tag_id, slug, label, usage_count, kind, colour}` | insider |
| `find_members` | People and agents here; `q` alone resolves one (the `member`, `owner`, `steward`, `assignee`, `agent` resolvers) | — | `mcp_members` | `q?`, `kind?` (human, agent), `role?`, `department?`, `limit` | rows `{member_id, display_name, member_kind, roles_resolved, department_ids, job_title}` | insider (an external: themselves) |
| `find_groups` | Groups; `q` alone resolves one (the `group` resolver) | — | `mcp_groups` | `q?`, `kind?` (custom, department), `limit` | rows `{group_id, key, name, kind, department_id, member_count}` | insider, not external |
| `find_departments` | Departments; `q` alone resolves one (the `department` resolver) | — | `mcp_departments` | `q?`, `limit` | rows `{department_id, name, manager_member_id}` | insider |

### Using data (Q1–Q6)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `query_run` | **Run this SQL** — one SELECT over dataset keys, through the query service, as the caller (an agent in a chat turn: the intersection) | Q1, T8 | the query service | `sql` (≤ 20,000), `params?` (named values for `$name` placeholders), `as_of?`, `limit?` (≤ the display cap) | `{columns, rows, truncated, masked_columns, datasets, ms, query_run_id}` or `refused` | `sql.editor` for a person; an agent: its grant of this tool (its reads bounded by the person) |
| `find_metrics` | The metrics; `q` alone resolves one (the `metric` resolver) | Q2 | `mcp_metrics` | `q?`, `dataset?`, `certified?`, `limit` | rows `{metric_id, name, title, description, dataset_id, time_column, grains, dimensions, format, unit, certified}` | discover (its dataset) |
| `metric_value` | **A number defined once** — a metric by grain and dimensions over a period. Also a **K7 share** (`os.datalake-metric/1`) for a consumer granted the metric's product | Q2 | the query service (`metric`) | `metric`, `grain` (day, week, month, quarter, year), `from`, `to` (dates), `by?[]` (dimensions the metric allows), `where?` (`{dimension: value or [values]}`) | `{metric, grain, rows: [{period, …dimensions, value}], definition: {expression, dataset, filters}, as_of, query_run_id}` | read on its dataset; `kernel` for a consumer |
| `find_saved_queries` | Saved and verified queries; `q` alone resolves one (the `saved_query` resolver) | Q3 | `mcp_saved_queries` | `q?` (title or question), `verified?`, `owner?`, `dataset?`, `limit`, `offset` | rows `{saved_query_id, title, question, owner_member_id, shared, verified_at, dataset_ids, parameters}` | own or shared and readable |
| `run_saved_query` | Run a saved (ideally verified) query with its parameters | Q3 | the query service (`saved_query_id`) | `saved_query`, `params?` | as `query_run` + `{saved_query_id, verified}` | as `find_saved_queries`; reads as the caller |
| `get_query_run` | What a query run returned: the SQL (when the caller may see it), datasets, masks, rows, time | Q4 | `mcp_query_runs` | `query_run` (id) | the view's row | own, the dataset's owner (no SQL), `audit.read` |
| `my_sandbox` | My sandbox datasets and my quota | Q5 | `mcp_datasets` (zone sandbox, owner me), `dl_sandbox_usage()` | `limit` | `{datasets: [...], used_bytes, quota_bytes}` | own |
| `my_exports` | My exports and which are ready; `q` alone resolves one (the `export` resolver) | Q6 | `mcp_exports` | `status?`, `limit` | rows `{export_id, kind, format, status, row_count, truncated, masked, dataset_id, created_at, expires_at}` | own |

### Trusting data (T1–T8)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `dataset_health` | **Can I trust X** — checks and their last results, freshness, open incidents (its own and upstream), certification and what it lacks | T1 | `dl_dataset_health()` | `dataset` | the function's object (`health` green/amber/red, `freshness`, `checks[]`, `open_incidents[]`, `upstream_incidents[]`, `certification_missing[]`) | discover |
| `failing_checks` | Which checks failed at their last run (today, by default) | T2 | `mcp_checks` (`last_result.passed = false`) | `dataset?`, `since?`, `blocking?`, `limit` | rows `{check_id, dataset_id, kind, column_name, severity, blocking, last_result}` | discover |
| `late_datasets` | Which datasets are past their freshness expectation | T2 | `dl_freshness_due()` ∩ `mcp_datasets` | `state?` (warn, error), `limit` | rows `{dataset_id, key, state, data_updated_at, overdue}` | discover |
| `open_incidents` | Open incidents, assigned to whom; `q` alone resolves one (the `incident` resolver) | T2 | `mcp_incidents` | `q?`, `dataset?`, `assignee?` (member, `me`), `severity?`, `status?[]`, `limit`, `offset` | rows `{incident_id, dataset_id, kind, severity, status, title, assignee_member_id, opened_at, downstream}` | discover |
| `get_incident` | One incident: its check, the result that opened it, the notes (the Steward's triage first), what it reaches downstream | T3 | `mcp_incidents`, `mcp_incident_notes`, `mcp_checks`, `mcp_check_results` | `incident` | `{incident, check?, opening_result?, notes[], downstream[]}` | discover |
| `find_checks` | A dataset's checks; `q` alone resolves one (the `check` resolver) | T2 | `mcp_checks` | `dataset?`, `kind?`, `failing?`, `limit` | rows as `failing_checks` + `enabled`, `source`, `params` | discover |
| `lineage_upstream` | **Where does this number come from** — the inputs up to the sources, with the loads and transforms on each hop; a node the caller may not discover is counted, unnamed | T4 | `dl_lineage(dataset, 'up', depth)`, `mcp_lineage_edges`, `mcp_sources` (names only) | `dataset`, `depth?` (1–5, default 3) | `{nodes: [{dataset_id?, key?, source_id?, source_name?, depth, via, certification?, data_updated_at?}], hidden: n}` | discover |
| `lineage_downstream` | What is made from a dataset, down the graph | T5 | `dl_lineage(dataset, 'down', depth)` | `dataset`, `depth?` | as `lineage_upstream` | discover |
| `impact` | What breaks if X changes: datasets, saved queries, metrics, transforms, K7 consumers downstream (hidden ones counted) | T5 | `dl_impact()` | `dataset` | `{datasets[], saved_queries[], metrics[], transforms[], consumers[], hidden: n}` | discover |
| `schema_history` | How a dataset's schema changed; what drifted at the last load; what waits for a decision | T6 | `mcp_schema_versions` | `dataset`, `pending?`, `limit` | rows `{schema_version_id, version, snapshot_id, columns, changes, breaking, detected_by, decision, decided_at}` | discover |
| `profile_history` | A column's profile over time (null %, distinct %, range) | T6 | `mcp_column_profiles` | `dataset`, `column`, `limit` (≤ 400) | rows `{snapshot_id, load_id, profile, profiled_at}` | discover |
| `get_contract` | A dataset's contract (the active one, or a version) as ODCS fields | T7 | `mcp_contracts` | `dataset`, `version?` | `{contract_id, version, status, schema, key, ordering_column, sla, description, breaking, breaking_changes, published_at}` | discover |

### Protecting data (S1–S7)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `access_list` | **Who can read X**, at what level, with which masks and filters, and through what (grants expanded to their principals, the owner, the steward) | S1 | `dl_access_list(dataset)` (db/018) | `dataset`, `limit` | rows `{principal_kind, principal_id, principal_name, level, row_filter, masked_columns, via, grant_id, expires_at}` | owner, `grants.manage` |
| `access_explain` | **Why** a person (the caller by default) has the access they have | S1 | `dl_access_explain(dataset, member)` | `dataset`, `member?` (another person: owner or `grants.manage`) | the function's object (`level`, `source`, `row_filter`, `masked_columns`, `reasons[]`) | discover; another member: owner, `grants.manage` |
| `my_access` | What I may read, and through what | S2 | `dl_my_access()` (db/018) | `level?` (read, read_unmasked, write, own), `limit`, `offset` | rows `{dataset_id, key, title, level, source, row_filtered, masked_columns_count, expires_at}` | own |
| `find_access_requests` | My requests, the ones waiting for my decision, or all (the `access_request` resolver) | S2 | `mcp_access_requests` | `box?` (mine, to_decide, all), `status?[]`, `dataset?`, `limit` | rows `{request_id, requester_id, dataset_id, level, reason, wanted_until, drafted_by_agent, status, decided_by, decided_at, grant_id}` | own, owner, `grants.manage` |
| `personal_data_map` | Which columns hold personal data, of which kind, confirmed or proposed | S3 | `mcp_dataset_columns` (personal or proposed) | `kind?`, `confirmed?`, `dataset?`, `limit`, `offset` | rows `{dataset_id, key, column, personal_kind, proposed_personal_kind, proposed_confidence, classified_by, confirmed_at}` | `catalog.edit` |
| `dataset_readers` | Who read a dataset (or a personal column of it): people, agents, applications, keys — counts, never SQL | S4 | `dl_dataset_readers(dataset, from, to)` (db/018) | `dataset`, `from?`, `to?` (30 days), `personal_only?` | rows `{caller_member_id, name, kind (person, agent, application, key), queries, rows, masked_reads, unmasked_reads, last_at}` | owner, `audit.read` |
| `access_history` | Who could read a dataset on a given day | S5 | `dl_access_history(dataset, at)` (db/018) | `dataset`, `at` (a date or time) | rows `{principal_kind, principal_id, principal_name, level, row_filter, grant_id}` | `grants.manage` |
| `get_erasure` | An erasure: the identifiers' kinds and count (never values), the scan's report, the proof, the time-travel acknowledgement (the `erasure` resolver) | S6 | `mcp_erasure_requests` | `erasure?` (id; none lists them), `status?`, `limit` | the view's rows | `erasure.manage` |
| `erasure_targets` | Where the subject of an erasure will be looked for: every column of a matching kind, the referencing datasets, the lineage | S6 | `dl_erasure_targets()` | `erasure` | rows `{dataset_id, key, zone, column_name, personal_kind, via (column, reference, lineage), references_dataset_id, references_column}` | `erasure.manage` |
| `grants_expiring` | Grants that expire within N days | S7 | `dl_grants_expiring(days)` (db/018) | `days?` (7), `limit` | rows `{grant_id, target, principal, level, expires_at}` | `grants.manage` |
| `grants_unused` | Grants whose principals have not read the dataset in N days | S7 | `dl_grants_unused(days)` (db/018) | `days?` (90), `limit` | rows `{grant_id, target, principal, level, last_read_at}` | `grants.manage` |
| `find_grants` | Grants on a dataset, a zone or a tag, or of a principal (the `grant` resolver) | S1 | `mcp_grants` | `dataset?`, `zone?`, `tag?`, `principal_kind?`, `principal?`, `include_revoked?`, `limit` | the view's rows | owner, grantee, `grants.manage` |

### Building and running the lake (I1–I6)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_sources` | Sources: connector, schedule, last pull, state, why paused; `q` alone resolves one (the `source` resolver) | I1 | `mcp_sources` | `q?`, `connector?`, `paused?`, `limit` | rows `{source_id, name, connector, role, schedule_minutes, last_ok_at, consecutive_failures, paused_at, paused_reason, active, has_credential, credential_last4}` | `sources.manage` |
| `get_source` | One source with its recent pulls and the datasets it feeds | I1 | `mcp_sources`, `mcp_source_pulls`, `mcp_lineage_edges` | `source`, `pulls?` (10) | `{source, pulls[], feeds: [{dataset_id, key}]}` | `sources.manage` |
| `find_loads` | Loads of a dataset or a source, by status (the `load` resolver) | I2 | `mcp_loads` | `dataset?`, `source?`, `status?[]`, `since?`, `limit`, `offset` | rows `{load_id, dataset_id, source_id, status, rows_read, rows_loaded, rows_rejected, rows_rescued, checks_outcome, queued_at, finished_at}` | read on the dataset |
| `get_load` | What a load did: counts, the schema seen, drift, the checks' outcome, the error | I2 | `mcp_loads` | `load` | the view's row | read on the dataset |
| `load_rejects` | The rows a load's parser refused, with their line and error (the raw line only at write level) | I2 | `mcp_load_rejects` | `load`, `limit` (≤ 500), `offset` | rows `{reject_id, line, column_name, error_type, message, raw_line?}` | read (raw: write) |
| `find_transforms` | Transforms; `q` alone resolves one (the `transform` resolver) | I3 | `mcp_transforms` | `q?`, `dataset?` (as output or input), `enabled?`, `limit` | rows `{transform_id, name, output_dataset_id, mode, schedule, version, owner_member_id, enabled, frozen_at, last_run_id}` | discover (its output) |
| `get_transform` | One transform: its SQL, inputs, mode, schedule, the last runs | I3 | `mcp_transforms`, `mcp_transform_runs` | `transform`, `runs?` (10) | `{transform, runs[]}` | discover (its output) |
| `storage_by_dataset` | Storage per dataset and zone, the largest first | I4 | `dl_storage_by_dataset()`, `mcp_storage_daily` | `zone?`, `limit` | rows `{dataset_id, key, zone, bytes, files, rows}` | `maintenance.run` |
| `quota_state` | My sandbox against its quota (everyone's for the admin) | I4 | `dl_sandbox_usage()` | `member?` (admin) | `{member_id, used_bytes, quota_bytes, datasets}` | own; admin |
| `maintenance_state` | The last maintenance passes and how they ended | I5 | `mcp_maintenance_runs` | `limit` | rows (the view) | `maintenance.run` |
| `backup_state` | The last backups and restore drills | I5 | `mcp_backups` | `kind?`, `limit` | rows (the view) | admin |
| `engine_state` | Is the engine healthy: the writer's queue by status and the running jobs, the query service's runs, refusals and timeouts today, the last snapshot | I6 | `dl_engine_state()` (db/018), `dl_lake_snapshots` (last) | — | `{queue: {queued, running, failed_24h}, running: [{job_id, kind, dataset_id, started_at}], queries_today: {ok, refused, timeout, error}, last_snapshot: {snapshot_id, snapshot_time}}` | admin |
| `find_uploads` | My uploads and their sniff (the `upload` resolver) | I2 | `mcp_uploads` | `status?`, `limit` | rows `{upload_id, filename, byte_size, status, sniff_status, target_dataset_id, created_at}` | own |
| `find_import_templates` | Saved mappings; `q` alone resolves one (the `template` resolver) | I2 | `mcp_import_templates` | `q?`, `kind?`, `limit` | rows `{template_id, name, kind, connector, mapping}` | insider |

### Agents, proposals, keys (P1–P3)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `steward_proposals` | What the Steward proposed and what was decided (the `proposal` resolver) | P1 | `mcp_steward_proposals` | `status?` (pending, accepted, edited, dismissed), `kind?`, `dataset?`, `since?`, `limit`, `offset` | rows `{proposal_id, kind, dataset_id, column_id, current_value, proposed_value, rationale, status, decided_by, decided_at}` | discover (its dataset) |
| `steward_report` | The Steward's weekday read: undescribed, personal to confirm, no owner, late, open incidents, drift, unused, promotion candidates, coverage, the last drill | P1 | `dl_steward_report()` | — | the function's object (names, counts, ids — never a value) | the Steward agent, `catalog.edit` |
| `agent_dispatches` | Dispatches pending or failed | P2 | `mcp_agent_dispatches` | `status?`, `limit` | rows (the view) | admin |
| `find_keys` | My keys (all for `keys.all`): limits, SQL switch, last use, today's count (the `api_key` resolver) | P3 | `mcp_tokens` | `scope?` (mcp, api), `limit` | rows `{key_id, label, scope, rate_per_minute, rate_per_day, allow_sql, calls_today, last_used_at, expires_at, revoked_at}` | own, `keys.all` |
| `my_notifications` | My notices, unread first (the `notification` resolver) | — | `mcp_notifications` | `unread?`, `limit` | rows (the view) | own |

### K7 shares (the kernel's token only; `X-OS-Consumer` names the consumer — K26)
| Tool | Document | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|
| `datasets_index` | `os.datalake-datasets/1` | `dl_share_datasets_index(app)` (db/018): products granted to the consumer as an `application` principal | `since?` (metadata updated), `limit` (≤ 200), `offset` | `{schema, consumer, datasets: [{key, title, description, columns: [{name, logical_type, description, masked}], as_of, certification}], next_offset?}` | `kernel` |
| `product_rows` | `os.datalake-rows/1` | the query service, `consumer_app` = the consumer (`dl_query_policy_app`) | `dataset` (a key), `since?` (the dataset's ordering column), `limit` (≤ 500), `offset` | `{schema, dataset, columns, rows, masked_columns, as_of, next_offset?}` — never over 256 kB (the service cuts the page and sets `next_offset`) | `kernel` |
| `metric_value` | `os.datalake-metric/1` | as the records tool, with the consumer's application policy | as the records tool | as the records tool | `kernel` (or a member, above) |

A `timeout_seconds` of 30 is declared for `product_rows` and `metric_value` in `maludb-os.json` `shares[]`. `no_connection` is the kernel's answer,
never ours. A consumer granted nothing gets an empty index, and `product_rows` / `metric_value` answer `refused` (`rule: not_shared`).

## Activity server: `datalake_activity_mcp`

Every tool reads `mcp_activity_log` (db/016: the dataset wall on `dataset_id`; a query run's events only to its caller and `audit.read`). **A
payload never carries a row value, SQL text, a filter, a credential, a token or a file path** (design §6.8) — the tools return what is there.

| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `record_history` | Who did what to a dataset (or a load, a check, an incident, a grant…), in order | ACT1 | `mcp_activity_log` by `entity_type`/`entity_id` or `dataset_id` | `kind` (dataset, load, check, incident, grant, source, transform, saved_query, metric, erasure), `record`, `limit`, `offset` | rows `{log_id, occurred_at, actor_member_id, actor_name, source, action, after}` | discover (the view) |
| `actor_timeline` | What a person, a key or an agent did here | ACT2 | `mcp_activity_log` by `actor_member_id` / `token_id` | `member?`, `key?`, `from?`, `to?`, `limit` | rows as `record_history` | own; `catalog.edit` for another's |
| `recent_activity` | What changed in the lake (or in a zone, a department's datasets) since a time | ACT3 | `mcp_activity_log` | `since?` (my last visit), `zone?`, `department?`, `actions?[]`, `limit` | rows as `record_history` + `dataset_key` | insider (the view) |
| `activity_search` | Search the activity for a phrase. The server's one guarded search | ACT4 | `mcp_activity_log` (`action`, `entity_type`, the `after` text, `ILIKE`, ≤ 120 characters) | `q`, `from?`, `to?`, `limit` | rows as `record_history` | insider (the view) |
| `share_reads` | Which siblings read which products and metrics, and how often; which sibling sources fed which datasets | P4 | `mcp_activity_log` (`share.read`, `load.run` from `k7` sources) | `from?`, `to?`, `consumer?` | rows `{consumer_or_provider, tool, dataset_key, calls, last_at}` | admin |
| `my_trail` | My own trail here | — | `mcp_activity_log WHERE actor_member_id = me` | `from?`, `limit` | rows as `record_history` | own |

## The question inventory → tools (every question has a tool)

| Q | Tool(s) | Q | Tool(s) | Q | Tool(s) |
|---|---|---|---|---|---|
| C1 | `catalog_search` | Q4 | `get_query_run` | S4 | `dataset_readers` |
| C2 | `get_dataset`, `dataset_describe` | Q5 | `my_sandbox` | S5 | `access_history` |
| C3 | `dataset_columns` | Q6 | `my_exports` | S6 | `get_erasure`, `erasure_targets` |
| C4 | `dataset_preview` | T1 | `dataset_health` | S7 | `grants_expiring`, `grants_unused` |
| C5 | `find_datasets` | T2 | `failing_checks`, `late_datasets`, `open_incidents` | I1 | `find_sources`, `get_source` |
| C6 | `glossary`, `get_term` | T3 | `get_incident` | I2 | `get_load`, `find_loads`, `load_rejects` |
| C7 | `dataset_usage`, `popular_datasets` | T4 | `lineage_upstream` | I3 | `find_transforms`, `get_transform` |
| C8 | `catalog_gaps`, `coverage` | T5 | `lineage_downstream`, `impact` | I4 | `storage_by_dataset`, `quota_state` |
| Q1 | `query_run` | T6 | `schema_history`, `profile_history` | I5 | `maintenance_state`, `backup_state` |
| Q2 | `find_metrics`, `metric_value` | T7 | `get_contract` | I6 | `engine_state` |
| Q3 | `find_saved_queries`, `run_saved_query` | T8 | `query_run` (`as_of`) | P1 | `steward_proposals`, `steward_report` |
| S1 | `access_list`, `access_explain` | S2 | `my_access`, `find_access_requests` | S3 | `personal_data_map` |
| P2 | `agent_dispatches` | P3 | `find_keys` | P4 | `share_reads` |
| ACT1 | `record_history` | ACT2 | `actor_timeline` | ACT3 | `recent_activity` |
| ACT4 | `activity_search` | | | | |

**Count:** records 69 tools (`app_roles`, 65 member tools — `metric_value` among them —, and the two kernel-only shares `datasets_index`,
`product_rows`); activity 6. Every name above is a row of exactly one table here, and the slices name them (`tests/phase1/check_specs.php`).

## The resolvers (`deploy/kernel-registry-datalake.json`)

A parameter named for a record accepts an id or a name; the kernel's actions server resolves it with these records tools (each answers a
plain list of rows carrying the id field and a label when given `q` alone):

| Param names | Tool | id field | label |
|---|---|---|---|
| `dataset`, `output_dataset`, `input`, `target_dataset`, `successor`, `references_dataset` | `find_datasets` | `dataset_id` | `key` |
| `member`, `owner`, `steward`, `assignee`, `agent`, `requester`, `approver` | `find_members` | `member_id` | `display_name` |
| `group` | `find_groups` | `group_id` | `name` |
| `department` | `find_departments` | `department_id` | `name` |
| `tag` | `find_tags` | `tag_id` | `slug` |
| `term` | `glossary` | `term_id` | `name` |
| `source` | `find_sources` | `source_id` | `name` |
| `load` | `find_loads` | `load_id` | `load_id` |
| `upload` | `find_uploads` | `upload_id` | `filename` |
| `template` | `find_import_templates` | `template_id` | `name` |
| `check` | `find_checks` | `check_id` | `kind` |
| `incident` | `open_incidents` | `incident_id` | `title` |
| `transform` | `find_transforms` | `transform_id` | `name` |
| `metric` | `find_metrics` | `metric_id` | `name` |
| `saved_query` | `find_saved_queries` | `saved_query_id` | `title` |
| `access_request` | `find_access_requests` | `request_id` | `request_id` |
| `grant` | `find_grants` | `grant_id` | `grant_id` |
| `erasure` | `get_erasure` | `erasure_id` | `erasure_id` |
| `proposal` | `steward_proposals` | `proposal_id` | `kind` |
| `export` | `my_exports` | `export_id` | `export_id` |
| `api_key` | `find_keys` | `key_id` | `label` |
| `notification` | `my_notifications` | `notification_id` | `title` |

`contract`, `column`, `query_run`, `note`, `retention` are ids only.
