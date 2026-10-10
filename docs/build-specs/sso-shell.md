# Build spec: Phase 2 — sign-on, the mirror, the shell, the engine running empty (before any slice)

What exists at the end: a person clicks Data Lake on the launcher — or types `datalake.<domain>` on a phone — and lands, signed in, on a home
that is finished though the lake is empty: **one button for a new person ("Upload a spreadsheet")**, the search box with Search and Ask, the
bell, the command bar, the application switcher, the bottom tab bar; their notification preferences exist before they look; the mirror
refreshes every minute; every request is logged; the command bar answers through the kernel; the record picker is in the shell for every
later form; **the writer service and the query service run under systemd and answer, with nothing to do yet**, and PHP reaches them through one
client that answers `engine_unavailable` when they do not. **The kit is in the repo already (Phase 0) and proven without a kernel
(`tests/phase0/run.sh`, 83 checks)**; this phase wires the shell around it and proves it under Apache at 375 and 1280. It is copied from Sales
CRM's Phase 2 (the nearest kit, 2026-10-09); every file keeps its sibling's contract.

Schema it runs on (db/001–018, never modified): `members` (+ `is_agent`), `departments`, `department_members`, `directory_sync_state`, `sso_nonces`,
`member_sessions` (db/001); `activity_log` (+ `token_id`, `dataset_id`, `query_run_id`) (db/002); `mcp_access_tokens` (+ the rate columns,
`rotated_from`, `allow_sql`), `key_usage`, `mcp_resolve_token()` (db/003); `dl_rights`, `dl_roles`, `dl_role_rights`, `dl_member_roles()`,
`dl_has_right()`, `dl_member_has_right()`, `dl_is_admin()`, `dl_is_engineer()`, `dl_is_member_here()`, `mcp_app_roles` (db/004); `dl_settings`,
`dl_setting_*()`, `dl_resolve_key()`, `dl_rate_ok()`, `dl_key_rotate()`, `dl_key_calls_today()`, `dl_prune_key_usage()` (db/005);
`notifications`, `notification_prefs`, `notification_outbox`, `dl_notify()`, `worker_passes` (db/014); `search_index`, `dl_search()`,
`dl_search_row_visible()` (db/015); `dl_level()`, `dl_can_discover()`, `dl_can_see_stub()` (db/008); `lake_jobs`, `dl_job_claim()`,
`dl_job_finish()` (db/009); `dl_popular_datasets()` (db/018); the `mcp_*` views (db/016).

## Divergences from the design
1. **The engine runs empty here, not in a slice.** `services/lake/writer_main.py` (the writer's loop: claim → dispatch → finish) is built with
   an EMPTY dispatch table — a job whose kind no slice has built yet is finished `failed` at once with `"not built until slice N"` and no retry
   (lake-engine §1); `services/query/server.py` answers the request kind `ping` only (query-service §7 adds the others slice by slice).
2. **The keys are finished here** (the manifest's shell section): `key_mint`, `key_rotate`, `key_revoke`, `key_sql_switch` with the SQL switch —
   the lake's API itself (slice 9) is what a key opens.

## Who may do what
prefs_save, notification_read, notifications_read_all: own, `yes` (an agent's own) · token_mint, token_revoke: own (`keys.all` revokes anyone's),
people · key_mint: own + `keys.own`, people, pauses for an agent (`external_send`) — an agent never reaches it (`people`) · key_rotate: own, people ·
key_revoke: own or `keys.all`, people · key_sql_switch: own + `sql.editor`, people, `external_send`.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | the search box (Search · Ask), certified products (cards), my recent and followed datasets, popular this month (`dl_popular_datasets(30, NULL, 6)`), open incidents on datasets I use, decisions waiting for me (requests to decide, proposals); engineers: failed loads, late datasets, the Steward's proposals; the admin: the engine's state, storage, the last backup and drill — **each tile renders from its own partial and shows its slice's "not built yet" line until the slice lands**; a person who may discover no dataset sees ONE button: Upload a spreadsheet (a plain link until slice 2) |
| `search` | `/search?q=&kind=&zone=&department=&owner=&tag=&classification=&certification=&personal=` | `dl_search()` results as `RecordCard`s: title, key, zone, the certification badge, owner, usage, the excerpt with `<mark>`; a stub card shows the name, the owner and "Request access" only (no excerpt, no classification); facets as chips; paged 25 |
| `notifications` | `/notifications` | my notices, unread first; read one, read all |
| `activity` | `/activity` | my own trail here, in sentences (`activity_sentence()`) |
| `settings` | `/settings/` | how I am told: the kinds by email, the kinds by text, the digest |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens (mint — shown once —, revoke) and my API keys (mint with the rates and the SQL switch, rotate — the old keeps 24 h —, revoke); the SQL switch only for `sql.editor` |

## Files (exactly these)
**In the repo (proven by `tests/phase0/run.sh`):** `app/{bootstrap,db,http,directory,auth,handler,activity,mail,partial_update}.php`, `app/api/{bootstrap,kernel}.php`,
`bin/{directory_sync,dev_handoff,mint_mcp_token,make_brand_images,worker}.php`, `bin/dev_directory.json`, `mcp/{activity_ingest.py,db.py,server_common.py,requirements.txt}`,
`tests/{dev_router,fake_kernel,fake_maludb,fake_malumail}.php`, `tests/setup_dev.sh`, `tests/phase0/`, `services/` (the engine, proven by `tests/engine/run.sh`).
**Phase 2 adds:**
- `app/features/shell/{nav,queries}.php` (`NAV_ITEMS`: Home, Search, Browse, Products, Metrics, Ask, SQL, Queries, My sandbox, Upload, Sources, Loads,
  Transforms, Quality, Access, Glossary, Steward, Admin — each with the right that shows it and `NAV_SLICES` naming who builds its placeholder;
  `bell_count()`, `decisions_count()`, `business_name()`), `app/features/home/queries.php` (`home_summary()`), `app/features/settings/{queries,present,write}.php`,
  `app/features/search/{queries,present}.php`, `app/features/activity/queries.php`, `app/features/assistant/queries.php`, `app/switcher.php` (the kernel's
  `GET /api/v1/apps/mine.php`, cached 5 min).
- **`app/lake/gate.php`** — the dataset gate every later slice uses (access-and-policies §1): `dataset_by_ref()`, `dataset_level()`, `require_level()`; **`app/lake/client.php`**
  — the ONE file PHP reaches the engine through (query-service §8): `lake_query()` (one request on `DL_QUERY_SOCKET`, a 2 s connect and the request's
  own timeout + 5 s read), `lake_enqueue()` (`dl_job_enqueue()`), `lake_ping()`; a dead socket answers `['status' => 'engine_unavailable']`, never an exception.
- `app/picker.php` (`picker_field()`, `picker_source()`, `picker_label()`), **`app/pickers.php` with `member` and `department` only** (each slice adds its
  sources), `html/pick/index.php`, `shared/picker-rows.php`, `assets/js/record-picker.js`, the one `#record-picker` modal in `layout.php` after `</main>`.
- Views: `layout.php` (the nxl shell, the header with the search box (Search · Ask), the switcher, the bell, the command bar; the bottom tab bar at 375:
  Home · Search · Ask · Me), `shared/{header,flash,notice,message,tab-bar,assistant-bar,assistant-reply,picker-rows,app-switcher,page-header,record-card,stub-card,nav-stub}.php`,
  `home/{index,first-run}.php` + `home/partials/{products,recent,popular,incidents,decisions,engineer,admin}.php`, `search/index.php` + `search/partials/result.php`,
  `settings/{index,tokens}.php` + `settings/partials/{prefs,tokens,keys}.php`, `notifications/{page.php,partials/bell.php}`, `activity/{page.php,partials/rows.php}`.
- Handlers: `html/{index,search,logout,notifications,activity}.php`, `html/notifications/{read,read-all}.php`, `html/settings/{index,prefs,tokens}.php`,
  `html/settings/tokens/{mint,revoke,key-mint,key-rotate,key-revoke,key-sql}.php`, `html/assistant/ask.php`, the placeholders (one `nav-stub` file per slice screen URL).
- **The engine, running empty:** `services/lake/writer_main.py` (the loop of lake-engine §1, `DISPATCH = {}`), `services/query/server.py` gains `ping`
  (query-service §7) and the socket's mode (`0660`, group `www-data`); `services/lake/README.md` names what each slice adds to `DISPATCH`.
- `deploy/apache-datalake.conf` and `tests/dev_router.php` carry every rewrite of the manifest's URL forms (a dataset by id OR key: `/datasets/{ref}` where
  `{ref}` is digits or `^(raw|curated|product|sandbox)\.[a-z][a-z0-9_]*$`).

## The Data Lake is not Sales CRM
No accounts, no calendar, no mailbox: the shell's data is the catalog. **The search box is the catalog search** (`dl_search`, four kinds, stubs). The home's
tiles are the catalog's and the engine's. **The engine is two services the shell starts and pings** — the writer (one process) and the query service (the pool,
`datalake-query.target`) — and the health endpoint reports both (`engine: {writer: ok|down, query: ok|down}`) without failing on them. `notification_prefs` is
created on sign-on and by the directory sync for every admitted member.

## The receiver (`/sso`) — in order
As Sales CRM's: verify the two signatures, the TTL, the audience (`APP_KEY = datalake`), the single-use nonce; mirror the member and departments from the
claims; refuse an unknown or ungranted member (403, logged `member.sign_on.refused` with the reason, never told); open the hardened session; ensure
`notification_prefs`; sync department groups (`dl_group_sync_department` for the member's departments); log `member.sign_on` with the roles; 302 to `/`
(or the `next` the launcher carried, same-origin only).

## Every request
`app/bootstrap.php`: the session, the member re-checked against the mirror (revoked → the launcher within a minute), `app.member_id` on the PDO connection,
the action-token / relay path for the kernel's actions server (an agent's relayed call also sets `app.on_behalf_of` from the run facts' `requested_by`
when present — access-and-policies §6), `json_mode_begin()`, `log_screen_view()` on GET screens.

## The dataset gate (`app/lake/gate.php`) — every later slice calls it, never re-implements it
- `dataset_by_ref(PDO $pdo, string $ref): ?array` — digits → `id`, else the key (`^(raw|curated|product|sandbox)\.[a-z][a-z0-9_]{0,62}$`); `null` when none.
- `dataset_level(PDO $pdo, int $datasetId): string` — `SELECT dl_level(:id)`.
- `require_level(PDO $pdo, string $ref, string $need): array` — `$need` ∈ `discover|read|read_unmasked|write|own`. Below `discover` (or none) →
  `respond_not_found()` (404 — the same nothing as a dataset that does not exist); below `$need` → 403 with the sentence *"You may not {verb} {key} — request
  access"* and in JSON `{"error": {"code": "not_allowed", "need": $need, "request_access": "/access/requests/new?dataset={id}&level=…"}}`. Returns the
  dataset row with `my_level` added. A stub (`dl_can_see_stub`) passes `discover` as `['is_stub' => true]` only for the dataset page's stub render.

## MCP tools this slice makes real (Phase 4 serves them; the shell's screens read the same SQL)
`app_roles`, `catalog_search` (the search box IS the records server's guarded search), `find_members`, `find_departments` (the pickers), `my_notifications`,
`find_keys`, `my_trail`, `popular_datasets` (the home's tile).

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` → `['products' => rows, 'recent' => rows, 'followed' => rows, 'popular' => rows, 'incidents' => rows, 'decisions' => ['requests' => n, 'proposals' => n], 'engineer' => ?['failed_loads' => n, 'late' => n, 'proposals' => n], 'admin' => ?['engine' => array, 'storage' => array, 'last_backup' => ?array], 'first_run' => bool]` — each key filled by the slice that owns it; Phase 2 fills `popular`, `first_run` and `decisions` (zeros) and leaves the rest `null` (the partial says "built by slice N")
- `bell_count(PDO, int $memberId): int` · `decisions_count(PDO, int $memberId): int` · `business_name(PDO): string`
- `search(PDO, string $q, array $filters, int $page): array{rows, total}` (over `dl_search`; `$filters` keys = the screen's params)
- `my_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $fields): array`
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $limit, int $page): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `find_my_tokens(PDO, int $memberId): array` (scope `mcp`) · `find_my_keys(PDO, int $memberId): array` (scope `api`, with `dl_key_calls_today`)
- `mint_token(PDO, int $memberId, string $label, ?int $days): array{id, secret}` · `revoke_token(PDO, int $memberId, int $id, bool $any): bool`
- `mint_key(PDO, int $memberId, string $label, ?int $perMinute, ?int $perDay, bool $allowSql, ?int $days): array{id, secret}` · `rotate_key(PDO, int $memberId, int $id): array{id, secret}` (`dl_key_rotate`) · `set_key_sql(PDO, int $memberId, int $id, bool $allow): void`
- `find_my_activity(PDO, int $memberId, int $page, array $filters): array` · `activity_sentence(array $row): string`
- `ask_assistant(PDO, int $memberId, string $utterance, array $context): array` (the kernel's chat endpoint, as Sales CRM's)
- `lake_query(array $request, int $timeoutS): array` · `lake_enqueue(PDO, string $kind, ?int $datasetId, ?int $refId, array $params, int $priority = 5): int` · `lake_ping(): array{writer, query}`

## Handlers (built)
`settings/prefs.php` (`prefs_save`), `notifications/read.php` (`notification_read`), `notifications/read-all.php` (`notifications_read_all`),
`settings/tokens/mint.php` (`token_mint`: scope `mcp`), `settings/tokens/revoke.php` (`token_revoke`), `settings/tokens/key-mint.php` (`key_mint`: scope `api`,
`allow_sql` refused without `sql.editor` — *"Ad-hoc SQL over the API needs the SQL editor"*), `settings/tokens/key-rotate.php` (`key_rotate`),
`settings/tokens/key-revoke.php` (`key_revoke`), `settings/tokens/key-sql.php` (`key_sql_switch`), `logout.php` (POST + CSRF; `member.sign_out`),
`assistant/ask.php` (`assistant.ask`: the length and the screen, never the words). Every handler: `dl_handler_begin()` → `require_right()` / own → `dl_guard()`
→ `log_activity()` → `emit_action_status()` → `dl_done()`. A secret is shown ONCE on the page that minted it and never in a log, a JSON answer after
that page, or a notification.

## Manifest rows claimed
Screens (6): `home`, `search`, `notifications`, `activity`, `settings`, `settings-tokens`
Actions (9): `prefs_save`, `notification_read`, `notifications_read_all`, `token_mint`, `token_revoke`, `key_mint`, `key_rotate`, `key_revoke`, `key_sql_switch`

Routes, not actions: `/sso`, `/sso/logout`, `/api/v1/health`, `/pick/`, the command bar's post to the kernel's chat endpoint, the switcher's feed. The
placeholders (every slice screen) call `render_nav_stub()`: the registry marks them `stub`; they are claimed by the slices that build them, never here.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.session_ended`, `screen.view` (every GET screen, the screen id), `settings.prefs`,
`notification.read`, `notification.read_all`, `token.mint`, `token.revoke`, `key.mint`, `key.rotate`, `key.revoke`, `key.sql_switch` (the key's id and label,
never its secret or hash), `assistant.ask`, `search.catalog` (the query cut at 120, the count, the stub count).

## Status vocabulary
The bell `badge text-bg-danger` with the unread count; the decisions badge `text-bg-warning`; certification `badge text-bg-success` "Certified" ·
`text-bg-info` "Promoted"; a stub card's lock icon; zone chips raw `secondary`, curated `primary`, product `success`, sandbox `light`. Ids: `header-search`,
`header-ask`, `header-user-name`, `bell`, `bell-count`, `tab-bar`, `home-products`, `home-popular`, `home-first-run-btn`, `search-result-{kind}-{id}`,
`record-picker`, `assistant-bar`, `token-list`, `key-list`, `key-{id}-sql`.

## Out of scope for this phase
Every dataset screen (the slices); the worker's real passes (stubs until their slices); the writer's job kinds (slices 2, 3, 5, 7, 8); the query service's
request kinds but `ping` (slices 2, 3, 7, 9); the lake's API routes but health (slice 9).

## Proof (`tests/phase2/run.sh`: scratch database `dl_dev2`, php -S on 9001, the fake kernel on 9002, the fake MaluDB + MaluMail on 9003, the writer and the query service on a scratch socket under `var/`; then the same under a real Apache vhost on 9007)
Sign-on for every fixture member with the right roles and the refusals of Phase 0 re-run; the home for a new person (one button), a Member (popular), an
engineer and the admin (their tiles' "built by slice N"); the search box finds a fixture dataset by its key's words, shows a confidential one as a stub
to a Member and nothing to an external; the bell counts and reads; prefs save and read back; a token minted once and revoked; a key minted with rates, its
SQL switch refused without `sql.editor` and allowed with it, rotated (the old keeps 24 h) and revoked; `require_level()` answers 404 below discover and
403 with the sentence below the need (a fixture route); `lake_ping()` answers both services `ok`, and `engine_unavailable` with the socket gone; a
queued job of an unbuilt kind is finished `failed` "not built until slice 2"; the switcher renders the fake kernel's applications; every placeholder
answers 200 to a person, 501 to JSON; the command bar's post reaches the fake kernel's chat endpoint and renders the reply; 375 (the tab bar, no
horizontal scroll) and 1280; no console errors; the installer's `plan` clean; the registry `--check`.

## Open questions
None — decisions taken in this spec: the keys are finished in the shell (the manifest's section is the shell's); the engine's two services are started
empty here so every slice proves against a running service; the home's tiles belong to the slices whose data they show.
