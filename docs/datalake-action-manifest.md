# Data Lake Action Manifest

2026-10-10 · **Phase 1, for the checkpoint**

> The registry that the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/datalake-mcp-tool-surface.md` (read tools) and the eleven specs in `docs/build-specs/`.
> `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file; an action whose file does not exist yet is
> registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**: `php bin/sync_approvals.php` rewrites
> that block from this file, and `--check` (in every proof) fails when the registry or the approvals are stale. The installer reads the
> resolve block from `deploy/kernel-registry-datalake.json` (the resolvers of the tool surface).

## Conventions

### Screens
- **Ids** are kebab-case: `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-datalake.conf` (and `tests/dev_router.php`): `/{x}/new` → `form.php`, `/{x}/{id}/edit`
  → `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`. **A dataset's URL takes its id or its key**
  (`/datasets/42` = `/datasets/curated.sales_orders`). The reading screens — home, search, a dataset's About / Columns / Quality, an
  incident, a request to decide, ask — are read at 375 px first; the SQL editor, the mapping, the transform, check, grant and policy editors
  and the lineage graph are designed at 1280 px and still render at 375 without horizontal scroll. No screen is a modal; the record picker
  is the one sanctioned modal.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity` and `data-record-id` (and `data-dataset-key` on a dataset's pages) on
  `#page-content`, so "describe this", "who can see it", "follow it", "run that" resolve against the page the user is on.
- **Doors that are not screens** have no row here: the upload parts (`/upload/start`, `/upload/part`, `/upload/finish` — the browser's,
  DocCloud's protocol), the SQL editor's run and cancel partials (`/sql/run`, `/sql/cancel` — a read through the query service, logged
  `query.run`, never an action: an agent reads with the records tool `query_run`), the preview partial (`/datasets/{id}/preview`), the
  export download (`/exports/{id}/download`), the contract's ODCS export (`/datasets/{id}/contract.odcs.yaml`), the lake's API (`/api/v1/*`),
  the picker (`/pick/`), the polling partials (`/uploads/{id}/sniff`, `/loads/{id}/status`, `/erasures/{id}/status`), `/sso` and `/sso/logout`. The ask window's turn and feedback (`/ask/turn` — posts the utterance to the kernel's chat endpoint as the person, like the command bar; `/ask/feedback` — Yes / Fix it / Ask a steward, the last opening a steward proposal through `steward_propose`) are doors too (slice 10).

### Actions
- **Name** `{entity}_{verb}`. The **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `dataset_id` on every
  row that concerns one). It is also what the kernel's approval policies match (`approval_policies.action_pattern` = the registry's
  `log_event`), so **an action that pauses for an agent has a log event no free action shares**: `grant.set` beside `access.request`;
  `export.make` beside `sandbox.save`; `dataset.retire` beside `dataset.deprecate`; `certify.set` beside `certify.remove`; `query.verify`
  beside `saved_query.save`; `contract.publish` beside `contract.draft`; `check.delete` beside `check.disable`; `load.rollback` beside
  `load.queue`; `source.delete` beside `source.pause`; `column.classify` beside `column.update`.
- **Endpoints** are POST to `/{base}/{file}` with `dl_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the relayed
  action token), the right (`require_right()`), the dataset's level (`require_level($dataset, 'discover'|'read'|'write'|'own')`: below
  `discover` → 404, the same nothing as a dataset that does not exist), `dl_guard()` around the database's verb, `log_activity()`,
  `emit_action_status()` and `dl_done()`. PHP never re-implements a rule the database keeps (the derived classification, the technical
  metadata, a contract's breaking check, certification's eligibility, the walls, the policy compiler, a request's decider, an erasure's
  approver). A create's `location` ends in the new record's id. **Anything that reads or writes the lake's data goes through the query
  service or a writer job** (`dl_job_enqueue`) — never DuckDB in PHP.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record accepts an id or a name, resolved through the tool surface's resolvers: `dataset`,
  `output_dataset`, `input`, `target_dataset`, `successor`, `references_dataset`, `member`, `owner`, `steward`, `assignee`, `agent`, `group`,
  `department`, `tag`, `term`, `source`, `load`, `upload`, `template`, `check`, `incident`, `transform`, `metric`, `saved_query`,
  `access_request`, `grant`, `erasure`, `proposal`, `export`, `api_key`, `notification`. `contract`, `column`, `query_run`, `note`,
  `retention`, `token`, `dispatch`, `schema_version` are ids only. `any field of X` means X's fields, all optional: a partial update.
- **Who** is enforced in the endpoint: `own` = the caller's own; `owner` = the dataset's owner; a right (`catalog.edit`, `sql.editor`,
  `queries.save`, `queries.verify`, `sandbox.save`, `sandbox.upload`, `loads.manage`, `sources.manage`, `contracts.manage`, `promote.run`,
  `checks.manage`, `incidents.manage`, `transforms.manage`, `metrics.manage`, `grants.manage`, `policies.manage`, `retention.manage`,
  `erasure.manage`, `maintenance.run`, `settings.manage`, `query_limits.manage`, `agents.settings`, `keys.all`, `lake.admin_read`,
  `access.request`, `export.own`, `follow.use`, `keys.own`) is held through `dl_has_right()`; a **level** (`discover`, `read`, `write`,
  `own`) is the caller's on the dataset (`dl_level()`). A super-admin holds every right. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies. `—` means it cannot be undone, and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, it widens who sees data, or it lets data leave the lake).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5): `external_send` (data
  reaches someone or something that could not read it before: a grant, a decided request, a mask or a classification loosened, a share to a
  sibling, an export, a key), `deletion` (a dataset retired, a load rolled back, a snapshot expired, an erasure approved, a retention
  shortened, a source, a transform, a check, landing purged), `other` (trust others rely on and the lake's configuration: certify, verify a
  query, publish a contract, a credential, the settings, the query limits, the agents' settings, a break-glass). People are never paused.
  **Reading, describing, checking, proposing and saving into one's own sandbox are free.** The categories are exactly `maludb-os.json`
  `approvals[]`.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`). An action
  that pauses is still `yes`: the pause comes first, then the call.
- **record_id** is what the JSON answer's `record_id` names: the record made (a create) or the record acted on.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity); a job-queuing action returns `jobQueued` and the page
  polls its status partial.
- **Log payloads** (what `before`/`after` carry) never hold a row value, a profile's top values, SQL text, a filter, a credential, a token, a
  file path, an erasure identifier's value (ids, keys, names, counts, kinds, statuses, levels and dates are allowed).

### Result contract
```json
{"status": "success", "did": "Granted Sales read on Sales orders (filtered to their department)", "record_id": 88, "undo_id": "act_31", "refresh": "grantChanged"}
{"status": "pending_approval", "did": "Granting the Finance group read on Payroll waits for a person's approval", "approval_request_id": 17}
{"status": "refused", "did": "Not eligible — it lacks a steward and a blocking check", "errors": {"dataset": "lacks: a steward, a blocking check"}}
{"status": "queued", "did": "Loading march-sales.xlsx into raw.sales — I'll tell you when it lands", "record_id": 412, "job_id": 977, "refresh": "jobQueued"}
```

## Home, me, search and the shell (Phase 2 — sso-shell)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | the start: the search box with Search and Ask, certified products, my recent and followed datasets, popular this month, open incidents on datasets I use, decisions waiting for me; engineers: failed loads, late datasets, the Steward's proposals; the admin: the engine, storage, the last backup and drill; a new person's one button, Upload a spreadsheet |
| `search` | `/search` | the catalog search's results as cards — certified first, stubs for what I may not discover (params: `q`, `kind`, `zone`, `department`, `owner`, `tag`, `classification`, `certification`, `personal`) |
| `notifications` | `/notifications` | my notices, unread first |
| `activity` | `/activity` | my own trail here |
| `settings` | `/settings/` | my preferences: which notices by email and by text, the digest |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens and my API keys (with the SQL switch): mint (shown once), rotate, revoke |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[] (the kinds that notify), text_kinds[], digest (yes or no) | restore prior | | | `settings.prefs` | own | yes | the prefs |
| `notification_read` | `/notifications/read.php` | notification (one — or none for all) | — | | | `notification.read` | own | yes | the notification |
| `notifications_read_all` | `/notifications/read-all.php` | all (yes — every unread notice of mine) | — | | | `notification.read_all` | own | yes | the count |
| `token_mint` | `tokens/mint.php` | **label** (up to 80 characters), expires_in_days (blank for never) | token_revoke | | | `token.mint` | own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own or keys.all | people | the token |
| `key_mint` | `tokens/key-mint.php` | **label** (up to 80 characters), rate_per_minute (1 to 100000 — the setting by default), rate_per_day (1 to 10000000), allow_sql (yes or no — ad-hoc SQL needs sql.editor too), expires_in_days | key_revoke | | external_send | `key.mint` | own and keys.own | people | the key |
| `key_rotate` | `tokens/key-rotate.php` | **api_key** (the old one keeps 24 hours) | — | ✔ | | `key.rotate` | own | people | the new key |
| `key_revoke` | `tokens/key-revoke.php` | **api_key** | — | ✔ | | `key.revoke` | own or keys.all | people | the key |
| `key_sql_switch` | `tokens/key-sql.php` | **api_key**, **allow_sql** (yes or no) | key_sql_switch | ✔ | external_send | `key.sql_switch` | own and sql.editor | people | the key |

## The catalog (slice 1 — the CRUD exemplar)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `browse` | `/browse` | the lake by zone, by department and by tag, as cards with certification, health, owner and "data updated" (params: `zone`, `department`, `tag`, `owner`, `status`) |
| `dataset-view` | `/datasets/{id}` | one dataset: the header (crumbs, badge, health, owner and steward, data and metadata updated, rows, size, Query this, Request access, Follow); tabs About, Columns, Preview, Quality, Lineage, Usage, Access, History — by anchor; a stub shows the name, the owner and Request access only (params: `tab`) |
| `dataset-add` | `/datasets/new` | to register a curated or product dataset before a promotion or a transform makes it: zone, name, title, owner, steward, department, the contract's key (params: `zone`, `name`) |
| `dataset-edit` | `/datasets/{id}/edit` | to describe a dataset (title, description, purpose, usage, limitations), set its owner, steward, department, tier, freshness expectations, schema-evolution mode, status, the deprecation note and successor |
| `column-view` | `/datasets/{id}/columns/{column}` | one column: its meaning, type, classification and personal kind, the glossary term, the reference, the profile over time |
| `column-edit` | `/datasets/{id}/columns/{column}/edit` | to describe a column: business name, description, glossary term, critical, the dataset and column it references |
| `glossary` | `/glossary` | the business glossary: terms by category, draft and approved (params: `q`, `status`) |
| `term-view` | `/glossary/{id}` | one term: definition, synonyms, the columns that carry it |
| `term-add` | `/glossary/new` | to add a term (params: `name`, `parent`) |
| `term-edit` | `/glossary/{id}/edit` | to change a term's definition, synonyms, category, steward |
| `tags` | `/tags` | the tag vocabulary: managed and free, counts, colours |

Actions (base `/datasets/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `dataset_create` | `save.php` | **zone** (curated or product — raw datasets are made by loads and sandbox ones by their owners), **name** (lowercase letters digits and underscores), **title**, description, owner (a member — the caller by default), steward, department, tier (1 or 2 or 3) | dataset_retire | | | `dataset.create` | catalog.edit | yes | the dataset |
| `dataset_update` | `save.php` | **dataset**, title, description, purpose, usage, limitations, owner, steward, department, tier (1 or 2 or 3), freshness_warn_after (an interval such as 1 day), freshness_error_after, evolution_mode (add or rescue or fail) | restore prior | | | `dataset.update` | owner or steward or catalog.edit | yes | the dataset |
| `dataset_tag` | `tag.php` | **dataset**, **tag** (a slug — a new free tag is made) | dataset_untag | | | `dataset.tag` | owner or steward or catalog.edit | yes | the dataset |
| `dataset_untag` | `untag.php` | **dataset**, **tag** | dataset_tag | | | `dataset.untag` | owner or steward or catalog.edit | yes | the dataset |
| `dataset_deprecate` | `deprecate.php` | **dataset**, **note** (why and what to use instead), successor (a dataset) | dataset_update | ✔ | | `dataset.deprecate` | owner or catalog.edit | yes | the dataset |
| `dataset_retire` | `retire.php` | **dataset** (its key is never reused — a sandbox dataset by its owner) | — | ✔ | deletion | `dataset.retire` | own (sandbox) or catalog.edit | yes | the dataset |
| `dataset_follow` | `follow.php` | **dataset**, what[] (schema_changed or check_failed or deprecated or access_decided or certification_lost or load_failed — all by default) | dataset_unfollow | | | `dataset.follow` | follow.use and discover | yes | the dataset |
| `dataset_unfollow` | `unfollow.php` | **dataset** | dataset_follow | | | `dataset.unfollow` | own | yes | the dataset |
| `column_update` | `columns/save.php` | **dataset**, **column** (its id or name), business_name, description, term (a glossary term — or none), critical (yes or no), references_dataset (a dataset — or none), references_column | restore prior | | | `column.update` | owner or steward or catalog.edit | yes | the column |
| `note_add` | `/notes/add.php` | **kind** (dataset or incident or saved_query), **record** (its id), **body** (up to 8000 characters) | note_delete | | | `note.add` | discover | yes | the note |
| `note_delete` | `/notes/delete.php` | **note** | — | ✔ | | `note.delete` | own or catalog.edit | yes | the note |
| `term_create` | `/glossary/save.php` | **name**, **definition**, synonyms[], parent (a term), steward (a member) | term_deprecate | | | `term.create` | catalog.edit | yes | the term |
| `term_update` | `/glossary/save.php` | **term**, any field of term_create | restore prior | | | `term.update` | catalog.edit | yes | the term |
| `term_approve` | `/glossary/approve.php` | **term** | term_update | | | `term.approve` | catalog.edit | people | the term |
| `term_deprecate` | `/glossary/deprecate.php` | **term** | term_update | ✔ | | `term.deprecate` | catalog.edit | yes | the term |
| `tag_create` | `/tags/save.php` | **label**, slug (made from the label when omitted), kind (managed or free), colour (a hex colour) | tag_archive | | | `tag.create` | catalog.edit | yes | the tag |
| `tag_update` | `/tags/save.php` | **tag**, label, kind, colour | restore prior | | | `tag.update` | catalog.edit | yes | the tag |
| `tag_archive` | `/tags/archive.php` | **tag** (grants on the tag stay until revoked) | tag_update | ✔ | | `tag.archive` | catalog.edit | yes | the tag |

## Uploads, loads and the sandbox (slice 2 — THE FIRST EXEMPLAR)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `upload` | `/upload` | to drop files (CSV, TSV, XLSX, XLS, ODS, JSON, NDJSON, Parquet — up to 5 GB, sent in parts) and see each land |
| `upload-map` | `/uploads/{id}/map` | the mapping: the first 50 rows, each column's name, type and personal-data proposal, the sheet and header row, the target (my sandbox, or an engineer's raw dataset, append or replace), the key; save as a template |
| `sandbox` | `/sandbox` | my sandbox datasets and my quota |
| `load-list` | `/loads` | loads by dataset, source and status (params: `dataset`, `source`, `status`, `since`) |
| `load-view` | `/loads/{id}` | one load: counts, the schema seen, drift, rescued fields, the checks' outcome, the rejected lines (their raw text at write level), rollback |
| `templates` | `/templates` | saved mappings for files that come back every month |

Actions (base `/uploads/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `upload_map_confirm` | `map.php` | **upload**, **target** (sandbox or raw), target_dataset (an existing raw dataset — engineers), name (a new dataset's name), mode (append or replace), sheet, header_row (1 to 50), columns (an object of source column to name and type and personal kind), dedupe_key[], template (a template to apply), save_template (a name to save this mapping as) | load_rollback | | | `upload.map` | sandbox.upload (raw: loads.manage) | people | the load |
| `upload_discard` | `discard.php` | **upload** (an unmapped upload — its landing file goes when no load uses it) | — | ✔ | | `upload.discard` | own | people | the upload |
| `load_queue` | `/loads/queue.php` | **load** (replays a load from its landing file — refused when the landing was purged) | — | ✔ | | `load.queue` | loads.manage and write | yes | the load |
| `load_rollback` | `/loads/rollback.php` | **load** (a new snapshot without its rows — history is never rewritten) | — | ✔ | deletion | `load.rollback` | loads.manage and write | yes | the load |
| `template_save` | `/templates/save.php` | **name**, **kind** (csv or xlsx or json or parquet), **mapping** (an object as upload_map_confirm's columns), template (to update one) | restore prior | | | `template.save` | sandbox.upload | yes | the template |
| `template_delete` | `/templates/delete.php` | **template** | — | ✔ | | `template.delete` | own or loads.manage | yes | the template |

## The query service, the SQL editor, saved queries and exports (slice 3 — THE SECOND EXEMPLAR)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `sql` | `/sql` | the editor (desk): the datasets I may read, the editor with completion of their keys, Run and Cancel, the result with the display cap and the truncation notice, quick column stats, Save, Download, Save as dataset, my recent runs; a refusal names its rule and offers Request access (params: `dataset`, `saved_query`, `as_of`) |
| `queries` | `/queries` | saved queries: mine and shared, the Verified badge (params: `q`, `verified`, `dataset`, `owner`) |
| `query-view` | `/queries/{id}` | one saved query: its question, SQL, parameters, the datasets it reads, verified by whom; Run shows the result only |
| `query-edit` | `/queries/{id}/edit` | to change a saved query's title, question, SQL, parameters, sharing |
| `query-run-view` | `/runs/{id}` | one query run: the SQL (mine, or all for the audit), datasets, masks, row filters, rows, time, status |
| `exports` | `/exports` | my exports: ready, expired, the rows and whether some columns were masked |

Actions (base `/queries/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `saved_query_save` | `save.php` | **title**, **sql** (one SELECT over dataset keys), question (the plain question it answers), description, parameters (a list of name and type and default), shared (yes or no), saved_query (to update one — its badge drops when the SQL changes) | restore prior | | | `saved_query.save` | queries.save | yes | the saved query |
| `saved_query_delete` | `delete.php` | **saved_query** | — | ✔ | | `saved_query.delete` | own | yes | the saved query |
| `query_verify` | `verify.php` | **saved_query**, verify (yes or no — no removes the badge) | query_verify | | other | `query.verify` | queries.verify | yes | the saved query |
| `sandbox_save` | `/sql/save-dataset.php` | **name** (my sandbox dataset — sandbox.m{me}_name), **sql** (one SELECT), title, as_of | dataset_retire | | | `sandbox.save` | sandbox.save | yes | the dataset |
| `export_make` | `/exports/make.php` | **format** (csv or xlsx or parquet), sql (one SELECT), saved_query, dataset (a whole dataset I may read), as_of | export_delete | ✔ | external_send | `export.make` | export.own | yes | the export |
| `export_delete` | `/exports/delete.php` | **export** | — | ✔ | | `export.delete` | own | yes | the export |

## Access, policies, groups and personal data (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `my-access` | `/access` | what I may read and through what; my requests (params: `level`) |
| `requests` | `/access/requests` | requests: tabs Mine, To decide, History — approve with a level and an optional expiry, or deny with a reason (params: `box`) |
| `request-add` | `/access/requests/new` | to ask for access to a dataset: the level and the reason (params: `dataset`, `level`) |
| `grants` | `/access/grants` | the grants on a dataset, a zone or a tag (params: `dataset`, `zone`, `tag`, `principal`) |
| `grant-add` | `/access/grants/new` | to grant a level on a dataset, a zone or a tag to a person, a group, a department, an agent or everyone — with a row filter and an expiry (params: `dataset`, `zone`, `tag`) |
| `groups` | `/groups` | groups as cards: custom and department mirrors |
| `group-view` | `/groups/{id}` | one group: members, owners, the grants it holds |
| `group-add` | `/groups/new` | to make a group: custom, or mirroring a department |
| `group-edit` | `/groups/{id}/edit` | to rename a group or change its description |
| `policies` | `/access/policies` | the masks: each personal kind and classification and how it is masked below read_unmasked |
| `personal-data` | `/access/personal-data` | the personal-data map: columns by kind, proposed and confirmed — confirm or reject (params: `kind`, `confirmed`, `dataset`) |
| `admin-read` | `/access/break-glass` | the admin's break-glass: open a dataset's rows for an hour with a reason (params: `dataset`) |

Actions (base `/access/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `access_request_create` | `requests/save.php` | **dataset**, **level** (discover or read or read_unmasked or write), **reason** (3 to 2000 characters), wanted_until (a date) | access_request_withdraw | | | `access.request` | access.request | people | the request |
| `access_request_draft` | `requests/draft.php` | **dataset**, **level**, **reason**, for (the person the agent acts for — the asker by default) | access_request_withdraw | | | `access.draft` | access.request | yes | the request |
| `access_request_withdraw` | `requests/withdraw.php` | **access_request** | — | | | `access.withdraw` | own | yes | the request |
| `access_decide` | `requests/decide.php` | **access_request**, **approve** (yes or no), level (another level than asked), until (a date), note (a denial says why) | grant_revoke | ✔ | external_send | `access.decide` | owner or grants.manage | yes | the request |
| `grant_set` | `grants/save.php` | **target** (dataset or zone or tag), dataset, zone (raw or curated or product), tag, **principal_kind** (member or group or department or agent or everyone), principal (the member or group or department or agent), **level** (discover or read or read_unmasked or write or own), row_filter (a predicate over the dataset's columns with {member_id} {departments} {groups}), until (a date), reason | grant_revoke | ✔ | external_send | `grant.set` | owner (on their dataset) or grants.manage | yes | the grant |
| `grant_revoke` | `grants/revoke.php` | **grant** | grant_set | ✔ | external_send | `grant.revoke` | owner (on their dataset) or grants.manage | yes | the grant |
| `group_create` | `/groups/save.php` | **name**, key, description, kind (custom or department), department (for a mirror) | group_archive | | | `group.create` | grants.manage | yes | the group |
| `group_update` | `/groups/save.php` | **group**, name, description | restore prior | | | `group.update` | grants.manage or a group owner | yes | the group |
| `group_archive` | `/groups/archive.php` | **group** (its grants stay until revoked) | group_update | ✔ | | `group.archive` | grants.manage | yes | the group |
| `group_member_add` | `/groups/members/add.php` | **group**, **member**, role (owner or member) | group_member_remove | | | `group.member_add` | grants.manage or a group owner | yes | the group |
| `group_member_remove` | `/groups/members/remove.php` | **group**, **member** | group_member_add | ✔ | | `group.member_remove` | grants.manage or a group owner | yes | the group |
| `policy_set` | `policies/save.php` | **applies_to** (a personal kind or confidential or restricted or rescued), **mask** (full or partial_email or partial or hash or null or year_only) | policy_set | ✔ | external_send | `policy.set` | policies.manage | yes | the policy |
| `column_classify` | `columns/classify.php` | **dataset**, **column**, classification (public or internal or confidential or restricted), personal_kind (a kind — or none) | column_classify | ✔ | external_send | `column.classify` | catalog.edit | yes | the column |
| `personal_confirm` | `columns/confirm.php` | **dataset**, **column**, **accept** (yes or no), personal_kind (another kind than proposed) | column_classify | | | `column.confirm` | owner or steward or catalog.edit | people | the column |
| `admin_read_open` | `break-glass.php` | **dataset**, **reason** (10 to 2000 characters — the owner is told) | — | ✔ | other | `dataset.admin_read` | lake.admin_read | people | the dataset |

## Contracts, promotion, checks, incidents and certification (slice 5 — THE THIRD EXEMPLAR)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `quality` | `/quality` | the lake's health: failing checks today, late datasets, open incidents — at 375 a status list (params: `severity`, `department`) |
| `incident-list` | `/incidents` | incidents by status, severity and assignee (params: `status`, `severity`, `assignee`, `dataset`) |
| `incident-view` | `/incidents/{id}` | one incident: its check and the result that opened it, the Steward's triage, the notes, what it reaches downstream; acknowledge, assign, note, resolve |
| `checks` | `/datasets/{id}/checks` | a dataset's checks with their last results and the 7-day strip |
| `check-add` | `/datasets/{id}/checks/new` | to add a check from a template: not null, unique, accepted values, range, regex, row count, freshness, referential, schema, custom SQL (params: `kind`, `column`) |
| `check-edit` | `/checks/{id}/edit` | to change a check's parameters, severity, blocking, schedule |
| `contract-view` | `/datasets/{id}/contract` | the dataset's contract: the typed schema, the key, the SLA, the versions, the ODCS download (params: `version`) |
| `contract-edit` | `/datasets/{id}/contract/edit` | to draft the next version: columns and types, required, unique, the key, the ordering column, the SLA — breaking changes shown, with their impact |
| `quarantine` | `/datasets/{id}/quarantine` | the rows promotion held back, with their reasons (at write level; masked below) |

Actions (base `/quality/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `contract_draft_save` | `contracts/save.php` | **dataset**, **version** (major.minor.patch), **schema** (a list of name and logical_type and physical_type and required and unique and primary_key and description), key[], ordering_column, sla (an object of freshness and retention and time_to_detect and time_to_notify and time_to_repair), contract (a draft to update) | restore prior | | | `contract.draft` | contracts.manage | yes | the contract |
| `contract_publish` | `contracts/publish.php` | **contract** (a draft — a breaking change must be a new major version) | — | ✔ | other | `contract.publish` | contracts.manage | yes | the contract |
| `promote_run` | `promote.php` | **dataset** (a curated dataset — its raw inputs since the last promotion) | — | | | `promote.run` | promote.run and write | yes | the dataset |
| `promote_rebuild` | `rebuild.php` | **dataset** (rebuilds curated from all of raw under the active contract) | — | ✔ | | `promote.rebuild` | promote.run and write | yes | the dataset |
| `drift_decide` | `drift.php` | **dataset**, **schema_version** (a pending change), **accept** (yes or no) | — | ✔ | | `drift.decide` | contracts.manage | people | the dataset |
| `check_create` | `checks/save.php` | **dataset**, **kind** (not_null or unique or accepted_values or range or regex or row_count or freshness or referential or schema or custom_sql), column, params (an object as the kind needs), **dimension** (accuracy or completeness or conformity or consistency or coverage or timeliness or uniqueness or validity), severity (warn or error), blocking (yes or no — an agent's check is never blocking), run_when (on_load or hourly or daily), description | check_disable | | | `check.create` | checks.manage (an agent: its grant of this action) | yes | the check |
| `check_update` | `checks/save.php` | **check**, any field of check_create | restore prior | | | `check.update` | checks.manage | yes | the check |
| `check_disable` | `checks/disable.php` | **check**, enable (yes — turns it back on) | check_disable | | | `check.disable` | checks.manage | yes | the check |
| `check_delete` | `checks/delete.php` | **check** (its results go with it) | — | ✔ | deletion | `check.delete` | checks.manage | yes | the check |
| `check_run` | `checks/run.php` | **dataset** (every enabled check now), check (one) | — | | | `check.run` | checks.manage or steward | yes | the dataset |
| `incident_ack` | `/incidents/ack.php` | **incident** | — | | | `incident.ack` | incidents.manage or steward or owner | yes | the incident |
| `incident_assign` | `/incidents/assign.php` | **incident**, **assignee** (a member) | incident_assign | | | `incident.assign` | incidents.manage or owner | yes | the incident |
| `incident_note` | `/incidents/note.php` | **incident**, **body** (up to 8000 characters — names and counts never values), kind (note or triage) | — | | | `incident.note` | discover | yes | the incident |
| `incident_resolve` | `/incidents/resolve.php` | **incident**, **resolution**, root_cause | — | ✔ | | `incident.resolve` | incidents.manage or owner | people | the incident |
| `incident_dismiss` | `/incidents/dismiss.php` | **incident**, **reason** | — | ✔ | | `incident.dismiss` | incidents.manage | people | the incident |
| `certify` | `certify.php` | **dataset**, level (certified or promoted — promoted is the owner's lighter badge) | certify_remove | ✔ | other | `certify.set` | checks.manage (promoted: owner) | yes | the dataset |
| `certify_remove` | `uncertify.php` | **dataset**, **reason** | certify | ✔ | | `certify.remove` | checks.manage or owner | yes | the dataset |

## Sources and pulls (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `source-list` | `/sources` | sources as cards: connector, schedule, last pull, state, why paused (params: `connector`, `paused`) |
| `source-view` | `/sources/{id}` | one source: its settings (never the credential, its last four only), the pulls with their counts, the datasets it feeds; Test, Pull now, Pause |
| `source-add` | `/sources/new` | to add a source: the connector's form — a file at a URL, an SFTP directory, a sibling's share through the kernel, an outside PostgreSQL database — the target dataset and the schedule (params: `connector`) |
| `source-edit` | `/sources/{id}/edit` | to change a source's settings, target, schedule, rate |

Actions (base `/sources/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `source_create` | `save.php` | **name**, **connector** (upload or http_file or sftp_file or k7 or postgres), **target_dataset** (a raw dataset — made when new), settings (an object as the connector needs — a URL or a directory or a provider and tool or a host and database and tables and watermark), schedule_minutes (0 for manual), rate_per_second (0.1 to 10), user_agent | source_delete | | | `source.create` | sources.manage | yes | the source |
| `source_update` | `save.php` | **source**, any field of source_create | restore prior | | | `source.update` | sources.manage | yes | the source |
| `source_credential_set` | `credential.php` | **source**, **kind** (api_key or oauth_client or basic or sftp_password or sftp_key or bearer or database_password), **secret** (sealed at once — never shown again), label | — | ✔ | other | `source.credential_set` | sources.manage | people | the source |
| `source_test` | `test.php` | **source** (a probe — no pull is counted) | — | | | `source.test` | sources.manage | yes | the source |
| `source_pull_now` | `pull.php` | **source** | — | | | `source.pull` | sources.manage | yes | the source |
| `source_pause` | `pause.php` | **source**, reason | source_resume | | | `source.pause` | sources.manage | yes | the source |
| `source_resume` | `resume.php` | **source** | source_pause | | | `source.resume` | sources.manage | yes | the source |
| `source_delete` | `delete.php` | **source** (its datasets stay — their loads lose the source) | — | ✔ | deletion | `source.delete` | sources.manage | yes | the source |

## Transforms, products, metrics and lineage (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `products` | `/products` | the product gallery: certified products first, their owners, freshness and metrics |
| `transform-list` | `/transforms` | transforms: output, inputs, mode, schedule, last run (params: `dataset`, `enabled`) |
| `transform-view` | `/transforms/{id}` | one transform: the SQL, inputs and output, mode, schedule, the frozen level, the runs, the lineage it makes |
| `transform-add` | `/transforms/new` | to write a transform (desk): the output dataset, the SQL over dataset keys, mode, schedule (params: `output_dataset`) |
| `transform-edit` | `/transforms/{id}/edit` | to change a transform's SQL, mode or schedule (a new version) |
| `metric-list` | `/metrics` | metrics: certified first, their product, grains and dimensions (params: `dataset`, `certified`) |
| `metric-view` | `/metrics/{id}` | one metric: its definition, its value by month as a small chart, the dimensions to cut by |
| `metric-add` | `/metrics/new` | to define a metric over a product (params: `dataset`) |
| `metric-edit` | `/metrics/{id}/edit` | to change a metric's expression, time column, grains, dimensions, filters |
| `lineage-graph` | `/datasets/{id}/lineage` | the lineage graph (desk): two hops up and down as an SVG; nodes I may not discover counted, unnamed (params: `depth`) |

Actions (base `/transforms/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `transform_save` | `save.php` | **name**, **output_dataset** (curated or product or my sandbox), **sql** (one SELECT over dataset keys), mode (replace or incremental_merge — row-wise SQL only), schedule (a cron expression or after_inputs), description, transform (to update one — a new version refrozen) | restore prior | | | `transform.save` | transforms.manage (a sandbox output: sandbox.transform) | yes | the transform |
| `transform_run` | `run.php` | **transform** | — | | | `transform.run` | transforms.manage or the owner | yes | the transform |
| `transform_enable` | `enable.php` | **transform**, enabled (yes or no) | transform_enable | | | `transform.enable` | transforms.manage or the owner | yes | the transform |
| `transform_delete` | `delete.php` | **transform** (its output dataset stays) | — | ✔ | deletion | `transform.delete` | transforms.manage or the owner | yes | the transform |
| `metric_save` | `/metrics/save.php` | **name**, **title**, **dataset** (a product), **expression** (an aggregate), time_column, grains[] (day or week or month or quarter or year), dimensions[], filters (a fixed predicate), format (number or currency or percent or integer), unit, description, metric (to update one — its certification drops when the definition changes) | restore prior | | | `metric.save` | metrics.manage | yes | the metric |
| `metric_certify` | `/metrics/certify.php` | **metric**, certify (yes or no) | metric_certify | | other | `metric.certify` | metrics.manage | yes | the metric |
| `metric_retire` | `/metrics/retire.php` | **metric** | — | ✔ | deletion | `metric.retire` | metrics.manage | yes | the metric |

## Retention, erasure, maintenance and backups (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `retention` | `/admin/retention` | retention by zone and by dataset, the landing window, the audit's windows |
| `erasure-list` | `/admin/erasures` | erasure requests and their state |
| `erasure-add` | `/admin/erasures/new` | to ask the lake to forget a person: the identifiers by kind, the reason, the scope |
| `erasure-view` | `/admin/erasures/{id}` | one erasure: the scan's report (datasets, rows, zones, what downstream loses rows, which loads become not replayable), the warning that time travel ends for every dataset, approve (another admin), the progress, the proof |
| `maintenance` | `/admin/maintenance` | storage by dataset and zone, quotas, the last maintenance passes, the writer's queue; run maintenance now |
| `backups` | `/admin/backups` | the last backups and restore drills, their checks; run a drill |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `retention_set` | `retention/save.php` | zone (raw or curated or product or sandbox), dataset, **keep_days** (1 or more), by_column (an event-time column), action (delete or archive), retention (to update one) | restore prior | ✔ | deletion | `retention.set` | retention.manage | yes | the retention |
| `retention_remove` | `retention/remove.php` | **retention** | retention_set | ✔ | | `retention.remove` | retention.manage | yes | the retention |
| `erasure_create` | `erasures/save.php` | **identifiers** (a list of kind and value — up to 20), **reason**, scope (all or zones or datasets), zones[], datasets[] | erasure_cancel | | | `erasure.request` | erasure.manage | people | the erasure |
| `erasure_scan` | `erasures/scan.php` | **erasure** (the writer reports before anything is deleted) | — | | | `erasure.scan` | erasure.manage | people | the erasure |
| `erasure_approve` | `erasures/approve.php` | **erasure**, **time_travel_acknowledged** (yes — time travel before now ends for every dataset) | — | ✔ | deletion | `erasure.approve` | erasure.manage and not its requester | yes | the erasure |
| `erasure_cancel` | `erasures/cancel.php` | **erasure** (before it is approved) | — | ✔ | | `erasure.cancel` | erasure.manage | people | the erasure |
| `maintenance_run` | `maintenance/run.php` | scope (all or a dataset), dataset | — | | | `maintenance.run` | maintenance.run | yes | the maintenance run |
| `snapshot_expire_now` | `maintenance/expire.php` | **older_than** (a time — every dataset loses time travel before it) | — | ✔ | deletion | `snapshot.expire` | maintenance.run | yes | the maintenance run |
| `landing_purge` | `maintenance/landing-purge.php` | **load** (its landing file goes now — the load becomes not replayable) | — | ✔ | deletion | `landing.purge` | loads.manage | yes | the load |
| `landing_pin` | `maintenance/landing-pin.php` | **load**, pin (yes or no — a pinned landing file outlives the window) | landing_pin | | | `landing.pin` | loads.manage | yes | the load |
| `drill_run` | `backups/drill.php` | confirm (yes — restores the last backup into a scratch catalog and runs the fixed queries) | — | ✔ | | `backup.drill` | maintenance.run | people | the drill |

## The lake's API, keys and the K7 shares (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `connections` | `/admin/connections` | the siblings: what the lake reads from them (K7 sources, their last pull, no_connection) and what it shares with them (products and metrics granted to each application, their reads) |
| `api` | `/api` | the lake's API: the routes, my keys' limits and the SQL switch, examples with my own key's last four |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `share_application` | `connections/share.php` | **dataset** (a product), **application** (the sibling's catalog key), level (discover or read — read by default) | share_revoke | ✔ | external_send | `share.grant` | grants.manage | yes | the grant |
| `share_revoke` | `connections/unshare.php` | **grant** (a share to an application) | share_application | ✔ | external_send | `share.revoke` | grants.manage | yes | the grant |

## Agents, the Steward, notifications, home and admin (slice 10)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `ask` | `/ask` | to ask the lake in words: the conversation with the expert through the kernel's chat endpoint; each answer with the interpretation, the result as a table or a simple chart, Show SQL, the datasets and the metric or verified query used, the query run, a note when columns were masked; Yes / Fix it / Ask a steward |
| `steward` | `/steward` | the Steward's inbox: drafted descriptions, personal data, owners, checks, drift, unused, promotion — accept, edit or dismiss with the diff (params: `kind`, `dataset`, `status`) |
| `coverage` | `/coverage` | coverage against the target by tier and department: described, owned, classified, checked, certified |
| `admin-settings` | `/admin/settings` | the lake's settings: windows, quotas, the upload ceiling, the masking salt's id, the certification target |
| `admin-limits` | `/admin/limits` | the query service's limits: timeouts, rows shown and downloaded, result bytes, workers, memory, concurrency, the agents' rate |
| `query-audit` | `/admin/queries` | every query run: who, on whose behalf, which agent, which datasets, masks, rows, status — the SQL for the audit (params: `member`, `dataset`, `status`, `source`, `from`, `to`) |
| `engine` | `/admin/engine` | the engine: the writer's queue and running jobs, the query service's runs, refusals and timeouts today, the last snapshot |
| `agents-settings` | `/admin/agents` | the agents: which is the Steward, the duty's hour, the daily proposal cap, the dispatches |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `steward_propose` | `/steward/propose.php` | **kind** (description or column_description or personal_data or owner or check or drift or unused or promotion or coverage or glossary_link), **proposed_value** (an object), dataset, column, rationale (from schema and profile and lineage — never a value) | proposal_decide | | | `steward.propose` | catalog.browse (the Steward) | yes | the proposal |
| `proposal_decide` | `/steward/decide.php` | **proposal**, **decision** (accepted or edited or dismissed), value (the edited value), note | — | | | `steward.decide` | owner or steward or catalog.edit | people | the proposal |
| `settings_save` | `settings/save.php` | landing_keep_days, snapshot_keep_days, query_text_days, audit_keep_days, backup_keep_days, sandbox_quota_bytes, max_upload_bytes, inline_flush_minutes, certification_target, unused_after_days, business_name, timezone | restore prior | ✔ | other | `settings.save` | settings.manage | yes | the settings |
| `query_limits_set` | `limits/save.php` | query_timeout_screen_s, query_timeout_agent_s, query_timeout_export_s, query_rows_shown, query_rows_download, query_result_bytes, query_workers, query_memory_mb, query_threads, query_concurrency_person, query_rate_agent_per_minute | restore prior | ✔ | other | `query_limits.set` | query_limits.manage | yes | the settings |
| `agents_settings_save` | `agents/save.php` | steward (an agent), steward_hour (0 to 23), steward_minute (0 to 59), proposals_per_day (1 to 100) | restore prior | ✔ | other | `agents_settings.save` | agents.settings | yes | the settings |
| `dispatch_retry` | `agents/retry.php` | **dispatch** | — | | | `agent.dispatch_retry` | agents.settings | people | the dispatch |
