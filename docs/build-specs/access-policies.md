# Build spec: access, policies, groups and personal data (slice 4)

Builds on `access-and-policies.md` (§1 the levels and the walls in PHP, §2 grants, §3 masks and classification, §4 requests, §5 the break-glass, §6 agents and
K38, §8 the proof matrix) and `query-service.md` (§3 the policy, §5 the row-filter check). What exists at the end: **who sees what, operable by people** —
my access and its reasons; requests (mine, to decide, history) with the owner's one-tap approve or deny on a phone; grants on a dataset, a zone or a tag to
a person, a group, a department, an agent or everyone, with a row filter checked before it is saved and an expiry; groups (custom, or mirroring a department);
the masks by personal kind and classification; a column's classification and personal kind set directly, and the classifier's proposals confirmed or rejected;
the admin's break-glass for an hour with the owner told; the dataset page's **Access** tab (who reads it, how and why); the expiry pass. Every rule is the
database's (db/008): PHP renders and calls, never decides a level.

Schema (db/006, 007, 008, 014, 016, 018, never modified): `groups`, `group_members`, `dl_member_group_ids()`, `dl_group_sync_department()` (db/006); `dataset_columns`
(`classification`, `personal_kind`, `proposed_personal_kind`), `dl_column_confirm_personal()`, `dl_dataset_derive()` (db/007); `grants`, `access_changes`,
`masking_policies`, `access_requests`, `admin_reads`, `dl_level()`, `dl_access_of()`, `dl_member_access()`, `dl_access_explain()`, `dl_access_decide()`,
`dl_admin_read_open()`, `dl_bind_row_filter()`, `dl_masks_for()`, `dl_query_policy()` (db/008); `dl_notify()` (db/014); `mcp_grants`, `mcp_access_requests`,
`mcp_masking_policies`, `mcp_access_changes`, `mcp_groups`, `mcp_dataset_columns` (db/016); `dl_access_list()`, `dl_my_access()`, `dl_dataset_readers()`,
`dl_access_history()`, `dl_grants_expiring()`, `dl_grants_unused()` (db/018). **The database is the referee**: the levels and their order, the principals, the
catalog rule and the stubs, the intersection, the binding of a row filter, the masks a level implies, who decides a request (never its requester), a grant
revoked never deleted, the trail, the break-glass's hour.

## Divergences from the design
1. **A row filter is checked before it is saved.** `grant_set` with a `row_filter` asks the query service to check the predicate against the dataset's columns
   (query-service §5's node-by-node check — columns, literals, comparisons, `IN`, `BETWEEN`, `LIKE`, `AND`/`OR`/`NOT`, `CAST`; no subquery, no function, no other
   table) with the tokens bound to the granter's own attributes: `lake_query(['kind' => 'sql', 'dry_run' => true, 'check_row_filter' => ['dataset' => key,
   'predicate' => bound]])`. A refusal is the field's error (`row_filter`: *"A row filter uses the dataset's columns, literals and comparisons only — {node}"*).
2. **A zone or tag grant is the admin's** (`grants.manage`); **a dataset grant is also its owner's** — an owner widens access to their own dataset only.
3. **`personal_confirm` is people only** (the manifest's `people`): the Steward proposes (slice 10), a person confirms — never an agent.

## Who may do what
access_request_create: `access.request`, people · access_request_draft: `access.request`, an agent for the person it acts for (`for` defaults to the run's
requester; a duty may not draft) · access_request_withdraw: own · access_decide: the dataset's owner or `grants.manage`, never the requester, pauses for an agent
(`external_send`) · grant_set / grant_revoke: the owner on their dataset (dataset grants only), or `grants.manage` (any target), pauses for an agent
(`external_send`) · group_create / group_archive: `grants.manage` · group_update / group_member_add / group_member_remove: `grants.manage` or a group owner ·
policy_set: `policies.manage`, pauses (`external_send`) · column_classify: `catalog.edit`, pauses (`external_send`) · personal_confirm: the owner, the steward or
`catalog.edit`, people · admin_read_open: `lake.admin_read`, people, pauses (`other`) · the Access tab: the owner and `grants.manage` see `access_list`; anyone
who discovers sees their own `access_explain`.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `my-access` | `/access?level=` | what I may read as cards (`dl_my_access`): key, title, my level, through what ("grant to Sales", "owner", "steward"), "rows filtered" when filtered, masked columns' count, expiry; my open requests below |
| `requests` | `/access/requests?box=mine|to_decide|history` | tabs Mine · To decide (badge) · History; a request card: who, the dataset, the level asked, the reason, wanted until, drafted by an agent (marked), the requester's current level; **To decide** at 375 first: Approve (level select defaulting to the asked one, optional expiry) and Deny (reason required) inline — the impact line "Approving gives {name} {level} on {key}: {masked count} columns stay masked" |
| `request-add` | `/access/requests/new?dataset=&level=` | the dataset (picker; a stub's key accepted), the level (read, read_unmasked, write, discover), the reason (3–2000), wanted until; "a request already waits" when one is pending |
| `grants` | `/access/grants?dataset=&zone=&tag=&principal=` | a table (a ledger): target, principal (with its kind's icon), level, row filter (the template), expiry, granted by, when, reason; Revoke per row; Add grant; `include_revoked` toggle shows the revoked rows greyed |
| `grant-add` | `/access/grants/new?dataset=&zone=&tag=` | `page-header-form`: target (dataset picker, or zone select, or tag picker — a dataset only for an owner), principal kind and principal (picker by kind; everyone has none), level (the choices the database admits for the kind: an application and everyone discover/read), **row filter** (a text input with the dataset's columns listed and the tokens `{member_id}` `{departments}` `{groups}` as buttons; "Check" posts the dry run and shows "valid" or the node), expiry, reason |
| `groups` | `/groups` | groups as cards: name, kind (custom / mirrors {department}), members' count, owners |
| `group-view` | `/groups/{id}` | members (avatar, role owner/member, joined) with Add (member picker) and Remove; a department mirror's members read-only ("follows {department}"); the grants it holds (`find_grants` by principal) |
| `group-add` / `group-edit` | `/groups/new` · `/groups/{id}/edit` | name, key (from the name), description, kind (custom, or mirror a department — the department picker; one live mirror per department) |
| `policies` | `/access/policies` | the fifteen masks as rows: what it applies to (a personal kind, confidential, restricted, rescued), the mask (select), an example of the masked form ("j***@example.com"), the note; Save per row (pauses for an agent) |
| `personal-data` | `/access/personal-data?kind=&confirmed=&dataset=` | the map as a table: dataset key, column, personal kind (confirmed) or proposed kind + confidence, classified by, confirmed when; Confirm / Reject (people) inline; links to the column's page |
| `admin-read` | `/access/break-glass?dataset=` | the dataset (picker of every dataset — the admin discovers all), the reason (10–2000), "the owner {name} is told; rows open for one hour; every query is audited"; my open break-glass windows with their end |

The dataset page's **Access** tab (slice 1's partial `tab-access.php`) is built here: for the owner and `grants.manage`, `access_list` as a table (principal, level,
filter, masked columns, via) with Add grant and Revoke; for everyone else, **why I have what I have** (`access_explain` rendered as sentences: "You read this
dataset masked because: member of Sales → a grant on this dataset → read; email and phone are masked for you") and Request access when below `read`.

## Files (exactly these)
- `html/access/{index,break-glass}.php`, `html/access/requests/{index,form,save,draft,withdraw,decide}.php`, `html/access/grants/{index,form,save,revoke,check}.php`
  (`check.php`: the row filter's dry run, a read door), `html/access/policies/{index,save}.php`, `html/access/personal-data.php`, `html/access/columns/{classify,confirm}.php`,
  `html/groups/{index,view,form,save,archive}.php`, `html/groups/members/{add,remove}.php`
- `app/features/access/{queries,present,write}.php` (my access, requests, grants, explain, the impact line), `app/features/policies/{queries,write}.php`,
  `app/features/personal/{queries,write}.php` (the map, classify, confirm), `app/features/groups/{queries,present,write}.php`, `app/features/breakglass/{queries,write}.php`
- `app/views/access/{index,requests,request-form,grants,grant-form,policies,personal-data,break-glass}.php`,
  `app/views/access/partials/{request-card,decide-inline,grant-row,explain,row-filter-input,impact}.php`, `app/views/groups/{index,view,form}.php`,
  `app/views/datasets/partials/tab-access.php`
- `app/pickers.php` gains `group`, `principal` (kind-aware: members, groups, departments, agents), `any_dataset` (the admin's: every dataset)
- `bin/worker.php`: `worker_pass_expiry` (pending requests older than 30 days → `expired` + `access.expire` + the requester told; grants expiring within 7 days → one
  `dl_notify` to the grantee and the dataset's owner, deduped by `grant_expiring:{grant}`)
- `tests/phase3/slice4/{run.sh,lib.php,requests.php,grants.php,filters.php,groups.php,policies.php,personal.php,breakglass.php,matrix.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `my_access(PDO, int $member, ?string $level, int $page): array{rows, total}` (`dl_my_access`) · `access_tab(PDO, int $member, int $dataset): array{mode: list|explain, rows?, explain?}` ·
  `access_explain_sentences(array $explain): array` (the reasons as sentences)
- `find_access_requests(PDO, int $member, string $box, array $filters, int $page): array{rows, total}` · `find_access_request(PDO, int $id): ?array` · `request_impact(PDO, int $request, ?string $level): array{level, masked_count, row_filtered}`
- `request_create(PDO, int $requester, int $dataset, string $level, string $reason, ?string $until, ?int $draftedBy): int` · `request_withdraw(PDO, int $id, int $by): void` ·
  `request_decide(PDO, int $id, bool $approve, ?string $level, ?string $until, ?string $note): ?int` (`dl_access_decide`; the grant id)
- `find_grants(PDO, array $filters, int $page): array{rows, total}` · `grant_set(PDO, array $f, int $by): int` (the row filter checked first) · `grant_revoke(PDO, int $id, int $by): void` (`revoked_at`, `revoked_by` — never DELETE) ·
  `row_filter_check(int $member, string $datasetKey, string $template): array{ok, bound?, error?}`
- `find_groups(PDO, ?string $q, ?string $kind): array` · `find_group(PDO, int $id): ?array` · `group_members(PDO, int $id): array` · `group_save(PDO, ?int $id, array $f, int $by): int` · `group_archive(PDO, int $id): void` ·
  `group_member_add(PDO, int $group, int $member, string $role, int $by): void` · `group_member_remove(PDO, int $group, int $member): void` (`left_at`, never DELETE)
- `masking_policies(PDO): array` · `policy_set(PDO, string $appliesTo, string $mask, int $by): void`
- `personal_map(PDO, array $filters, int $page): array{rows, total}` · `column_classify(PDO, int $column, ?string $classification, ?string $personalKind, int $by): void` (then `dl_dataset_derive`) ·
  `personal_confirm(PDO, int $column, bool $accept, ?string $kind): void` (`dl_column_confirm_personal`)
- `admin_read_open(PDO, int $dataset, string $reason): int` (`dl_admin_read_open`, then `dl_notify(owner, 'admin_read', …)`) · `my_admin_reads(PDO, int $member): array`
- `groups_for_pick(…)`, `principals_for_pick(PDO, string $q, array $params, int $page)` (`params.kind` required), `any_datasets_for_pick(…)`

## MCP tools this slice makes real
`access_list`, `access_explain`, `my_access`, `find_access_requests` (the access_request resolver), `find_grants` (the grant resolver), `find_groups` (the group resolver), `personal_data_map`, `dataset_readers`, `access_history`, `grants_expiring`, `grants_unused`.

## Manifest rows claimed
Screens (12): `my-access`, `requests`, `request-add`, `grants`, `grant-add`, `groups`, `group-view`, `group-add`, `group-edit`, `policies`, `personal-data`, `admin-read`
Actions (15): `access_request_create`, `access_request_draft`, `access_request_withdraw`, `access_decide`, `grant_set`, `grant_revoke`, `group_create`, `group_update`, `group_archive`, `group_member_add`, `group_member_remove`, `policy_set`, `column_classify`, `personal_confirm`, `admin_read_open`

## Activity log events
`access.request|draft|withdraw|decide|expire` (the dataset id, the level, approved or denied — never the reason's text over 200 characters), `grant.set|revoke`
(target kind, principal kind and id, level, `row_filtered: bool` — never the predicate), `group.create|update|archive|member_add|member_remove`, `policy.set`
(applies_to, the mask), `column.classify` (from → to), `column.confirm` (accepted, the kind), `dataset.admin_read` (the dataset, the hour's end — the reason's
length only), `screen.view` with `dataset_id` on the Access tab.

## Status vocabulary
Levels as badges: discover `light`, read `info`, read_unmasked `primary`, write `warning`, own `success`. Request: pending `warning` "Waiting", approved `success`,
denied `danger`, withdrawn `secondary`, expired `dark`. Grant: live none, expiring (≤ 7 days) `outline-warning` "Expires {date}", revoked `dark` (greyed row).
"Masked for you" `outline-secondary`; "Rows filtered" `outline-info`; break-glass open `danger` "Break-glass until {time}". Ids: `access-card-{dataset}`,
`request-card-{id}`, `request-{id}-approve`, `request-{id}-deny`, `grant-row-{id}`, `grant-form-field-{name}`, `row-filter-check`, `group-card-{id}`,
`group-member-{member}`, `policy-row-{applies_to}`, `personal-row-{column}`, `breakglass-form`.

## Out of scope for this slice
Sharing with a sibling application (`share_application`, slice 9 — `grant-add` offers no `application` kind); the Steward's personal-data proposals (10 — this slice
confirms what the classifier proposed at load, slice 2); the agents' MCP side of K38 (Phase 4).

## Proof (`tests/phase3/slice4/run.sh`: scratch `dl_dev4` + `dl_dev4_lake`, slice 2's fixtures loaded, the writer and the query service on scratch sockets; php -S on 9001, the fake kernel on 9002; then a real Apache on 9007; Playwright; the registry `--check`)
1. **The matrix** (access-and-policies §8): ten callers × the fixture datasets — the level, the row filter, the masks and the stub — read through the screens
   (`my-access`, the Access tab, the dataset page, the preview) and agreeing with `dl_access_of()` every time.
2. A request created, a second refused ("a request already waits"), withdrawn; decided by the owner (approve with a lower level and an expiry → a grant, the
   requester told); the requester's own decide refused; a denial without a reason refused; an agent's draft for its person, and an agent's decide paused.
3. Grants: a department grant with `department_id = ANY({departments})` checked, saved, and the preview of a Sales member filtered; a filter with a subquery or a
   function refused on the field; a zone grant by an owner refused (`grants.manage`); revoke writes the trail and a revoked grant gives nothing; DELETE never possible.
4. Groups: a custom group with an owner adding a member; a department mirror follows the directory sync (a member leaves the department → leaves the group); the
   grants a group holds reach its members and stop when they leave.
5. Masks: `email` switched to `hash` → the preview shows the keyed hash; an agent's `policy_set` paused.
6. Classification: a column set `restricted` → `null` below `read_unmasked`, the dataset reclassified; personal data confirmed and rejected by a person; an agent's
   `personal_confirm` 403 (`people`); an agent's `column_classify` paused.
7. Break-glass: the admin opens payroll with a reason; the owner's bell; the hour's rows readable unmasked; after the hour (the clock moved) nothing.
8. The worker's `expiry` pass: a 31-day request expires; a grant expiring in 5 days is announced once.
9. JSON mode on every screen and handler; the twelve screens and the Access tab at 375 and 1280 (To decide at 375 first) with no console errors; the registry
   `--check`: 12 screens built, 15 actions, 6 approvals (`access_decide`, `grant_set`, `grant_revoke`, `policy_set`, `column_classify`, `admin_read_open`) in step.

## Open questions
None — decisions taken in this spec: the row filter is checked by a dry run before it is saved; zone and tag grants are the admin's, dataset grants also the owner's;
personal data is confirmed by people only; a pending request expires after 30 days and a grant's expiry is announced 7 days ahead, once.

## Built and proven (2026-10-11)
Who sees what is operable by people. **My access** shows what I may read as cards — my level, through what ("a grant to Sales (your
department)", "you own it", "you are its steward"), "Rows filtered", the columns masked for me, the end date — and my open requests.
**Requests** has Mine · To decide · History; on a phone To decide comes first and each card carries the one-tap decision: Approve at a
level (the asked one by default) with an optional end date, the impact line following the level ("Approving gives SMOKE Priya read (masked)
on curated.payroll: 2 columns stay masked"), or Deny with a reason; an agent's draft is marked "Drafted by" it. **Grants** is a ledger
(target, who, level, the row filter's template, expiry, granted by, reason; Revoke; revoked rows greyed in on request); **Add grant** takes
a dataset (an owner's own only), a zone or a tag (the admin's), who (the kind, then the picker for it), the levels the database admits for
the kind, the row filter with its tokens as buttons and **Check** — the dry run through the query service — an end date and a reason.
**Groups** (custom, or mirroring a department and following the directory), **Masks** (fifteen rows with an example each), the
**personal-data map** with Confirm / Reject for a person, **Break-glass** with the open windows, and the dataset page's **Access** tab: who
reads it (the owner and grants.manage) or why I have what I have (everyone else, as sentences, Request access below read). The worker's
`expiry` pass is real. The registry reads **41 screens and 54 actions built** (12 + 15 here; the six approvals in step).

**Proven** by `tests/phase3/slice4/run.sh` (scratch `dl_dev4` with the engine real — the writer and the query service on their units' command
lines, DuckLake on the scratch catalog; slice 3's lake plus `seed.py` here: products with an expired grant, crm_deals with a personal email by the
classifier's rule and a phone proposed at 0.6, the finance tag read unmasked by a group, the `gl` share): **332 checks under php -S and the same
332 under a real Apache** — the matrix 111 (ten callers + the Expert acting for Priya and for Ann × eight datasets, every cell through the page,
mcp_datasets as the records server's read role and the query service's preview, all agreeing with dl_access_of(); the cells the design names;
the K7 consumer `gl`; the fail-closed ceiling of a kernel without K38), requests 30 (with the worker's expiry pass), filters 17 (the Check door,
K38 through a row filter), grants 25, groups 26 (a department mirror following the directory sync), masks 12, personal data 16, break-glass 14,
JSON 34, the browser 47 (at 375 first — the owner's one-tap Approve with the impact line following the level —, the twelve screens and the Access
tab at 375 and 1280, Check in the grant form, without JavaScript a plain-form Deny; screenshots in `/tmp/dl-shots/slice4`) — plus the schema proof
(**204** — db/023's seven checks added), the server and engine logs, and the registry `--check`.

**Decisions taken in the build** (the spec left them open, or the code showed better):
1. **db/023** adds what the screens and the pass need to SAY who and why, never a rule: `dl_access_deciders(dataset)` (the owner, else every
   holder of grants.manage — the one answer to "whom does a request go to"), `dl_members_with_right(right)` (the worker has no session, so a
   function, not mcp_members), `dl_grant_via(grant)` (the words for what a grant reaches through, answered only to someone it reaches, the
   dataset's owner or grants.manage — mcp_grants shows a Member none of the department grants that reach them), `dl_access_request_expire()`,
   and the notification kind `grant_expiring`.
2. **`check_row_filter`** (the query service): node by node (policy.check_row_filter, as at query time), then BOUND (`EXPLAIN`) and RUN
   (`SELECT 1 … LIMIT 1` under a 5-second interrupt) on the locked READ-ONLY attach, so a cast that fails on the values (a text key compared
   with a number) refuses at the granter's save. It answers only valid or not — no row, no count — so it is not audited (it is no read), and
   the engine's words lose every quoted literal (`_no_values()`: DuckDB names the value a cast failed on). A dataset with no table yet is
   checked against the catalog's column names. An engine that cannot answer means the grant is NOT saved (fail closed).
3. **A row filter belongs to a grant on one dataset** (its columns), at read or read unmasked; the TEMPLATE is stored and bound per reader
   (db/008). A zone or tag grant with a filter is refused on the field.
4. **The same live grant twice is refused** ("That grant is already there"). An end date (a grant's, a decision's) is the end of that day in the
   person's time zone.
5. **Requests**: a stub's dataset is requestable (the form's picker is `any_dataset`); asking for a level one already holds is refused on the
   level; To decide = pending, not mine, that mcp_access_requests shows me (my datasets', or all for grants.manage — the header's count too);
   the requester is told of a decision with no dataset gate (they asked, so they know it exists); a request someone may not see is nothing to
   them (404), the requester's own decision a 403 in the database's words.
6. **Drafts are an agent's, for the person its run acts for** (K38's requester) — `for` may name only them; a duty drafts nothing (403); a
   person asks with the form (403 on draft). The gate is the intersection: both must at least see the stub.
7. **The break-glass tells the owner AND every other Lake admin** (holders of lake.admin_read) — never the opener; one open window per admin
   and dataset (a second names when the first ends); people only for the screen and the action; logged with the reason's length only.
8. **Groups**: the maker is not made a member (a group's grants never reach the admin by making it); a custom group's owners change its
   members and words; archiving is grants.manage's; a mirror's members are the directory's (never added or removed here; "follows the
   department"), its name the department's when left empty; a key is made from the name (a suffix when taken).
9. **Who opens what**: Masks — policies.manage, and catalog.edit read-only; the personal-data map, Groups and Grants — insiders (an external
   none); Confirm / Reject — a person who owns or stewards the dataset or holds catalog.edit (★15: `require_human()` — an agent's token 403).
10. **`column_classify`** sets the classification and/or the kind as a person's, confirmed, clearing any proposal; `personal_kind` "none" clears
    it; the database keeps a personal column at least confidential (a "public" asked becomes confidential) and re-derives the dataset.
11. **The Access tab** is rendered with the page (two small reads: dl_access_list or dl_access_explain); the owner also sees their own reasons
    under the list; the dataset page's JSON carries `access` (slice 1's view.php gained the key).
12. **The expiry pass**: past-due grants are revoked at their `expires_at` (lake-engine §7 — a `grant.expire` row, the grantee told when a person
    or an agent); pending requests past `wanted_until` or 30 days old expire (`access.expire`, the requester told); a grant ending within seven
    days is announced to its grantee and the dataset's owner ONCE per grant and person (the pass dedupes on `grant_expiring` notices — dl_notify's
    own dedupe holds ten minutes, and an unmailed kind leaves no outbox row).
13. **The kit's `dl_guard()` maps `insufficient_privilege` (42501) to a 403** in the function's own words (dl_access_decide, dl_admin_read_open);
    PostgreSQL's own "permission denied for …" (a grant missing) is said generically.
14. **Files beyond the list**: `app/views/access/partials/{subnav,grant-who}.php` (the Access screens' tabs; the grant form's who-and-level,
    fetched again when the kind changes — `/access/grants/new?fragment=who`); the requests screen answers `?impact={id}&level=` with the impact
    line alone; `html/assets/js/tag-editor.js` gains `button[data-insert]` (the row filter's tokens and columns insert at the cursor — the form
    works without it); `tests/phase3/slice4/seed.py`.

**Found and fixed in slice 4:**
- **The query service answered `policy` for a dataset registered and never loaded** (its policy has no columns, so `validate()` refused): the
  preview and `sql` now answer "{key} has no data yet." — slice 3's decision 5, extended to a dataset with no column.
- **The dev router took only one-segment canonical URLs** (`/x/new`, `/x/{id}`): `/access/requests/new` and `/access/grants/new` were 404 under
  php -S while Apache's rules take any depth — the router now does too.
- **An omitted required text field fell through to the database** (the kit's `dl_text()` keeps a field left out as it was): the request,
  draft and break-glass handlers require their reason when it is missing, not only when it is empty.
- Phase 2's engine proof and slice 2's preview proof named `check_row_filter` as the unbuilt kind; they now name `metric` (slice 7).

**What later slices copy**: a query kind that answers a judgment, not rows — `server._check_row_filter` (no audit, `_no_values()` on the
engine's words); a screen family with its own tabs — `access/partials/subnav.php`; a decision made inline on a card with a fragment that
follows a select — `access/partials/{decide-inline,impact}.php` and the `?impact=` door; the proofs' `ref_level()` (the referee),
`read_role_row()` (mcp_datasets as the read role), `agent_get()` / `agent_post()` (a run with K38's requester, a duty, or a kernel without K38),
`last_notice()`.
