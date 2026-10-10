# Build spec: access and policies — the walls in PHP, grants, masks, requests, agents and siblings (design §3, §4, §5; D5, D6, D8, D10, D14)

The walls are the database's: db/008 compiles who sees what (`dl_member_access`, `dl_access_of`, `dl_level`, `dl_can_discover`,
`dl_can_see_stub`, `dl_masks_for`, `dl_query_policy`, `dl_query_policy_app`, `dl_access_explain`, `dl_access_decide`, `dl_admin_read_open`;
db/018 adds the readers' and lists' functions and the five-parameter policy), and the `mcp_*` views apply them per row (db/016). **PHP and
Python never re-implement a rule**: they ask, render, and refuse with the database's words. Slice 4 builds the screens and actions of this
spec; slices 1–3 and 5–10 use §1's gate on every dataset page and handler.

## 1. The levels and walls in PHP
- **The gate**: `require_level(PDO $pdo, int|string $dataset, string $min): array` (`app/access.php`) — resolves an id or a key
  (`find_dataset_ref()`), reads `dl_level(id)` as the caller (the session's `app.member_id`, and `app.on_behalf_of` when set — §6), and:
  below `discover` → **404 with the same page as a dataset that does not exist** (never "forbidden" — a hidden dataset's existence is not
  confirmed); `discover` but `$min` higher → 403 with the reason and the **Request access** button (`/access/requests/new?dataset=<key>&
  level=<min>`); else returns `['dataset' => row, 'level' => level]`. Levels in order: `none < discover < read < read_unmasked < write < own`.
- **Stubs**: a dataset the caller cannot discover but `dl_can_see_stub(id)` allows (an insider holding a role, not external, the dataset not
  sandbox and not retired) renders `dataset-view` as a **stub**: title, key, zone, owner's name, department, certification badge, and Request
  access — no tabs, no description, no columns, no counts (`mcp_datasets.is_stub` rows carry exactly those fields). Search shows the same
  card with `visibility: stub` and no excerpt (db/015). A stub is never shown to an external member or to someone with no role here.
- **Rights versus levels**: a right (`dl_has_right()`) says what *kind* of thing a person may do; the level says whether they may do it to
  *this* dataset. A handler checks both, in that order (`require_right()` then `require_level()`); the manifest's **Who** column names both.
- **Read paths**: lists come from `mcp_*` views as the caller (the wall per row); a page's data comes from the query service
  (query-service.md) — PHP never selects a lake row, never a `ducklake.*` table (the role holds nothing there), only `dl_lake_*` views
  (db/engine/001) for sizes, columns and commits.

## 2. Grants
- **Targets** (`grants.target_kind`): a `dataset`; a `zone` (`raw`, `curated`, `product` — never `sandbox`: a sandbox dataset is shared one
  at a time); a `tag` (every dataset carrying it, present and future — the lakehouses' tag-based policy). **Principals**: `member`,
  `group` (DocCloud's groups: custom or a department mirror kept by `dl_groups_sync_departments()` after each directory sync), `department`,
  `agent`, `application` (a sibling's catalog key — §7), `everyone` (every insider holding a role; never an external). **Levels**:
  `discover`, `read`, `read_unmasked`, `write`, `own`; `everyone` and `application` at most `read`; a row filter only on `read` or
  `read_unmasked`.
- **Row filters** (`grants.row_filter`): a predicate over the dataset's own columns with three tokens — `{member_id}`, `{departments}`,
  `{groups}` — bound to literals **by the database** for the person reading (`dl_bind_row_filter`, e.g. `department_id = ANY({departments})`
  → `department_id = ANY(ARRAY[7]::BIGINT[])`), then **validated node by node by the query service** (only the dataset's columns, literals,
  comparisons, IN/ANY/BETWEEN/LIKE, AND/OR/NOT, CAST, arrays — a subquery, a function or another table refuses the whole policy:
  `refusal_rule: policy`). The `grant-add` form validates on save by asking the query service to compile a policy with the new filter for
  the granter against the dataset (`lake_sql("SELECT count(*) FROM <key>")` under a dry-run flag `explain_only` — slice 4 adds it to the
  service's request as `"dry_run": true`, answering the count of rows the filter leaves for the granter, or the refusal).
- **Who may grant**: a dataset's **owner** on their dataset (levels up to `own`, principals member/group/department/agent/everyone); a holder
  of `grants.manage` anything (zones and tags included). `grant_set` and `grant_revoke` **pause for an agent** (`external_send`).
- **Additive, revoked never deleted**: the effective level is the highest grant's; the row predicate is NULL when any reaching grant at read
  or above has none, else the OR of theirs (db/008). A grant is revoked (`revoked_at`, `revoked_by`) — never deleted (the web role holds no
  DELETE; a trigger backs it). **Expiry**: `expires_at`; the worker's `expiry` pass revokes past-due grants at `expires_at`
  (lake-engine.md §7). **The trail**: `access_changes` gets a row per grant, change and revocation (`dl_grants_trail`) — "who could read
  this on June 3rd" is `dl_access_history(dataset, at)` (db/018).

## 3. Masks and classification
- **Masks follow classification** (`dl_masks_for(dataset, level)`): below `read_unmasked`, a column with a `personal_kind` is masked by
  its kind's `masking_policies` row; a `confidential` column without a kind by `confidential` (`full`); a `restricted` column `null`;
  `_dl_rescued` `null`. Seeded masks: email `partial_email`, phone `partial`, name `partial`, address `full`, national_id `null`,
  payment_card `partial`, bank_account `partial`, birth_date `year_only`, ip_address `hash`, member_id `hash`, free_text_personal `null`,
  other `full`. `policy_set` (policies.manage, pauses `external_send`) changes one row; every reader sees the new mask from their next query.
- **Classification is derived** (db/007): a dataset's is the highest of its live columns' and its floor; a personal column is at least
  `confidential`. Two actions change a column: **`column_classify`** (catalog.edit) sets classification and/or personal kind directly — any
  change pauses for an agent (`external_send`: a downgrade widens who sees values) — and **`personal_confirm`** (owner, steward, catalog.edit;
  people only) accepts or rejects the classifier's proposal through `dl_column_confirm_personal()`. The writer's classifier proposes
  (`proposed_personal_kind`, `proposed_confidence`) and masks at once at ≥ 0.8 (lake-engine.md §3); the `personal-data` screen lists
  proposed and confirmed columns.
- **A sandbox's floor**: a dataset saved from the editor (`sandbox_save`) or made by a sandbox transform keeps the highest classification of
  its inputs (`classification_floor`, `dl_transform_freeze`) and the masks it was made under — no laundering a mask by saving a result.

## 4. Access requests
- **Create** (`access_request_create`, access.request, people): dataset, level, reason (3–2,000), wanted until; one pending request per
  person and dataset (the unique index); `dl_notify` `access_requested` to the dataset's owner (and to holders of `grants.manage` when the
  dataset has no owner).
- **Draft by an agent** (`access_request_draft`): the expert files a request **for the person it acts for** (`for`, the asker by default):
  `requester_id` = the person, `drafted_by_agent` = the agent; the person sees it under Mine with "drafted by the Data Lake Expert" and
  may withdraw it. An agent never drafts for itself.
- **Withdraw** (own). **Decide** (`access_decide` → `dl_access_decide(request, approve, level, until, note)`): the owner or
  `grants.manage`, never the requester; approval makes a `dataset` grant to the requester (`member` or `agent` principal) at the asked or a
  different level with the expiry, denial needs a note; pauses for an agent (`external_send`). `dl_notify` `access_decided` to the requester.
  The worker expires pending requests past `wanted_until`.

## 5. The break-glass
`admin_read_open` (lake.admin_read — the Lake admin; **people only**; pauses `other` if ever relayed): `dl_admin_read_open(dataset,
reason)` writes `admin_reads` (an hour; ≤ 8 h by CHECK); while it lasts `dl_member_access` gives the admin `read_unmasked` on that dataset
(source `break_glass`). The handler logs `dataset.admin_read` (`{dataset_id, admin_read_id, expires_at}` — never the reason's text beyond
200 characters) and `dl_notify`s the owner (`admin_read`: "<admin> opened <title> until <time>: <reason>"). The `admin-read` screen shows
the open break-glasses and their expiry; nothing ends one early but its clock.

## 6. Agents
- **Run tokens**: the records MCP's gate (mcp-and-api.md, the kit's `server_common`) and PHP's handler (an action token relayed by the
  kernel's actions server, `X-Action-Token` + `X-Action-Relay`) resolve the agent's member id and its run; both ask the run facts.
- **K38 — who asked**: when the run facts carry `requested_by_member_id` for a `chat` run (or a dispatched run naming a person) and that
  person holds this application, the gate sets `app.on_behalf_of` (transaction-local, as `app.member_id`) — `dl_level()` then answers the
  **intersection** for every view and function, and the query service is called with `on_behalf_of` (query-service.md §3). PHP sets the same
  setting in `dl_handler_begin()` for a relayed action, so an agent's write is gated by the person's level too (`require_level`).
- **Until K38**: a `chat` run without `requested_by_member_id` reads the catalog as the agent but **its data reads carry
  `ceiling: internal_unpersonal`** (`k38_pending` refusals for anything above internal or personal) and its writes on a confidential or personal
  dataset are refused in PHP with the same rule ("readable once the kernel says who asked (K38)") — **fail closed**. A `duty` run (the
  Steward's report) reads as the agent with its own grants.
- **No `read_unmasked` by default**: the install grants the two agents nothing on data; an admin grants them (`grant_set` naming the agent).
  `is_eval` refuses every write (the kit's handler).
- **What pauses** (the manifest's Agent approval column = `maludb-os.json` `approvals[]`, 29): **external_send** — `key_mint`,
  `key_sql_switch`, `export_make`, `access_decide`, `grant_set`, `grant_revoke`, `policy_set`, `column_classify`, `share_application`,
  `share_revoke`; **deletion** — `dataset_retire`, `load_rollback`, `check_delete`, `source_delete`, `transform_delete`, `metric_retire`,
  `retention_set`, `erasure_approve`, `snapshot_expire_now`, `landing_purge`; **other** — `query_verify`, `admin_read_open`, `contract_publish`,
  `certify`, `source_credential_set`, `metric_certify`, `settings_save`, `query_limits_set`, `agents_settings_save`. The pause is the kernel's
  (its approval hook before the relay); the handler then runs as for a person. Actions marked `people` refuse an agent's token outright
  (`require_human()`).

## 7. Sibling applications
- **The application principal**: a grant with `principal_kind = 'application'`, `principal_ref = <catalog_key>` (`gl`, `spaces` …), level
  `discover` or `read` (never more), on a dataset (usually a product), a zone or a tag. `share_application` / `share_revoke` (grants.manage;
  pause `external_send`) are `grant_set` / `grant_revoke` with that principal fixed; the `connections` screen lists them.
- **Reading**: `dl_application_level(dataset, app)` and `dl_query_policy_app(app, keys)` (db/008): masked as for a Member, never a row
  filter of a person, never a sandbox. The records server's kernel-token gate takes the consumer from **`X-OS-Consumer`** (K26 — set by
  the kernel, never an argument; a consumer-supplied `consumer` / `as_agent` is stripped by the kernel) and answers `datasets_index`
  (`dl_share_datasets_index`, db/018), `product_rows` and `metric_value` (query-service.md §7) for that consumer only; nothing shared →
  an empty index and `not_shared` refusals. `X-OS-Consumer-Agent` is recorded in the audit (`agent_run_id` stays NULL; `key_label` =
  `k7:<app>`), never used to widen.
- **Reading the siblings** is the lake's own `k7` sources (slice 6) — a read, not a grant; the lake's `reads[]` in `maludb-os.json`.

## 8. The proof matrix (db/proof/phase0_proof.sql's fixtures — slice 4 reproduces it through the screens and the JSON)
Levels before any request, revocation or break-glass (`D` discover · `R` read · `RU` read_unmasked · `O` own · `S` stub only · `—` nothing ·
`f` row-filtered to the caller's departments). Datasets: **orders** `curated.sales_orders` (confidential — a personal email; owner Eve,
steward Marco, Sales; grants: Sales department read filtered, the expert read), **products** `curated.products` (internal; Xena's grant
expired), **payroll** `curated.payroll` (confidential; owner Ada; tagged finance → the Finance-analysts group read_unmasked), **revenue**
`product.monthly_revenue` (internal; everyone read; `gl` read), **sandbox** `sandbox.m26_my_upload` (Priya's), **deals** `raw.crm_deals`
(confidential — a personal buyer email; owner Eve).

| Caller | orders | products | payroll | revenue | sandbox | deals |
|---|---|---|---|---|---|---|
| 1 the super-admin (Lake admin) | D | D | D (RU by break-glass) | R | D | D |
| 2 Ada (Lake admin, owner of payroll) | D | D | O | R | D | D |
| 26 Priya (Member, Sales) | R f | D | S (R after her approved request — masked) | R | O | S |
| 27 Marco (Analyst, Finance; steward of orders) | R | D | RU | R | — | S |
| 28 Eve (Data engineer, owner) | O | O | S | O | — | O |
| 29 Xena (external) | — | — | — | — | — | — |
| 30 Sam (no role here) | — | — | — | — | — | — |
| 40 the expert (agent) alone | R | D | S | R | — | S |
| 40 acting for Priya / for Xena | R f / — | D / — | S / — | R / — | — / — | S / — |
| 41 the Steward (agent) | S | D | S | R | — | S |
| 42 an unadmitted agent | — | — | — | — | — | — |
| `gl` (K7 consumer) | not_shared | not_shared | not_shared | R (masked) | not_shared | not_shared |

Proved in slice 4 (`tests/phase3/slice4/run.sh`): every cell through `dataset-view` (200 / 403 with Request access / 404 / stub), through
`mcp_datasets` as the read role, and through the query service (`preview`: rows, masked columns, the filter's effect); a request decided by
Ada turns Priya's payroll cell to R with salary `***`; a revoked everyone grant turns revenue to D; the break-glass opens payroll to the
super-admin for an hour and notifies Ada; the expert acting for Priya before K38 gets `k38_pending` on orders (confidential) and reads revenue.

## Decisions taken here (for the owner)
1. Below `discover` the answer is **404, identical to a missing dataset** — a hidden dataset's existence is never confirmed; `discover`
   without the level is 403 with Request access.
2. A dataset with no owner sends its access requests to the holders of `grants.manage`.
3. A row filter is validated **on save** by a dry run of the query service (`dry_run: true`, slice 4), so a filter that would refuse every
   query is caught by the granter, not by its readers.
4. Until K38, an agent's **writes** on a confidential or personal dataset in a chat turn are refused in PHP with `k38_pending`, like its
   reads.
5. The install grants the two shipped agents **no data** — an admin grants them (they discover through the catalog rules and the
   everyone grant like any insider).
6. A K7 consumer's audit rows carry `key_label = 'k7:<app>'`; `X-OS-Consumer-Agent` is recorded, never used to widen.
7. **A stub honours the intersection**: when `app.on_behalf_of` is set, `dl_can_see_stub()` must also hold for that person (an external
   person's chat turn must not learn a confidential dataset's name through the agent). db/008's function checks the current member only —
   **owed in db/018** (`CREATE OR REPLACE`), proven by the matrix's "acting for Xena" row.
