# Build spec: the catalog (slice 1 — the CRUD exemplar)

What exists at the end: the lake's catalog as cards and full pages — browse by zone, department and tag; **the dataset page** with its header and
the eight tabs by anchor (About, Columns, Preview, Quality, Lineage, Usage, Access, History — the tabs whose data a later slice makes render that
slice's "not built yet" panel, the deliverable here being the page's frame and its About, Columns, Usage and History); a stub page for a dataset
the caller may only see the name of; the edit forms (describe, owner, steward, department, tier, freshness, evolution mode); deprecate and retire;
follow; a column's page and its edit form (meaning, glossary term, reference); notes on a dataset; the glossary (terms, categories, draft and
approved) and the tag vocabulary; the search index caught up by the worker. **This slice is the CRUD exemplar**: every later record screen copies
its files, its pages' shape, its handler's order (`dl_handler_begin()` → `require_right()` → `require_level()` → `dl_guard()` → `log_activity()` →
`emit_action_status()` → `dl_done()`) and its proof.

Schema (db/006, 007, 008, 014, 015, 016, 017, 018, never modified): `tags`, `record_tags`, `dl_tags_of()`, `glossary_terms`, `groups`, `group_members` (db/006);
`datasets`, `dataset_columns`, `column_profiles`, `schema_versions`, `dataset_follows`, `dl_datasets_guard()` (technical metadata the writer's alone),
`dl_dataset_derive()`, `dl_class_rank()` (db/007); `dl_level()`, `dl_can_discover()`, `dl_can_see_stub()`, `dl_masks_for()` (db/008); `notes`, `dl_notify()`
(db/014); `search_index`, `dl_index_dataset()`, `dl_index_glossary_term()`, `dl_search()` (db/015); `dl_certification_eligible()` (db/010); `dl_usage_30d()`,
`dataset_usage_daily`, `dataset_joins` (db/011); `dl_lineage()` (db/012, the History tab's one hop is slice 7's); `mcp_datasets`, `mcp_dataset_columns`,
`mcp_glossary_terms`, `mcp_tags`, `mcp_dataset_follows`, `mcp_notes`, `mcp_activity_log`, `mcp_dataset_usage` (db/016); `references_dataset_id`,
`references_column` (db/017); `dl_dataset_frequent_users()`, `dl_dataset_joined_with()` (db/018); `dl_lake_tables`, `dl_lake_columns` (db/engine/001).
**The database is the referee**: the key's form and its uniqueness forever, the sandbox name rule, the zone and name never changing, the derived
classification and personal flag, technical metadata the writer's, the metadata date, the certification's automatic loss.

## Divergences from the design
1. **One page, eight tabs, built by the slices that own their data.** The page's frame and the About, Columns, Usage and History tabs are this
   slice's; Preview is slice 2's, Quality slice 5's, Lineage slice 7's, Access slice 4's — each a partial `app/views/datasets/partials/tab-{name}.php`
   that here renders `tab-pending.php` ("Built by slice N") and that the owning slice replaces. Never a link to a 404.
2. **`dataset_create` makes curated and product datasets only** (the manifest's row): a raw dataset is made by a load or a source (slices 2, 6), a sandbox
   one by its owner's upload or save (slices 2, 3). The form refuses `raw` and `sandbox` with *"A raw dataset is made by a load; a sandbox one by its
   owner"*.
3. **`column_update` never touches a column's name, type, classification or personal kind**: those are the engine's (the name and type) or slice 4's
   (`column_classify`, `personal_confirm`). The column form shows them read-only with the action that changes them.

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| dataset_create | `catalog.edit` | — |
| dataset_update, dataset_tag, dataset_untag, column_update | — | the owner, the steward, or `catalog.edit` (and `discover` on the dataset) |
| dataset_deprecate | — | the owner or `catalog.edit` |
| dataset_retire | — | a sandbox dataset: its owner (`own`); else `catalog.edit`; pauses for an agent (`deletion`) |
| dataset_follow / dataset_unfollow | `follow.use` | `discover` / own |
| note_add | — | `discover` on the record's dataset (an incident's or a saved query's notes are rendered by slices 5 and 3 with this same handler) |
| note_delete | — | the note's author or `catalog.edit` |
| term_create, term_update, term_deprecate, tag_create, tag_update, tag_archive | `catalog.edit` | — |
| term_approve | `catalog.edit` | a person (`people`) |

## Screens (cards for the lists — the owner's rule; 375 px first for the reading screens)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `browse` | `/browse?zone=&department=&tag=&owner=&status=` | three ways in as chips (Zones · Departments · Tags), then `RecordCard`s from `find_datasets()`: title, key, zone chip, certification badge, the health dot (green/amber/red — amber and red from slice 5's data, green until then), owner avatar, "data updated" (relative), rows; deprecated cards greyed with "use {successor}"; paged 24; stubs never listed here (they appear in search) |
| `dataset-view` | `/datasets/{ref}?tab=` (`{ref}` = id or key) | **the header**: crumbs (zone › department › key), title, the certification badge (or "lost: {reason}" for 30 days), the health dot, owner and steward (links to their trail), "Data updated {t}" and "Metadata updated {t}" as two lines, rows · size (from `dl_lake_tables` when the engine has the table, else the dataset's columns), and the buttons Query this (slice 3's link, greyed until built) · Request access (shown below `read`) · Follow/Unfollow · ⋯ (Edit · Deprecate · Retire · Tag). **Tabs** (by anchor, every pane server-rendered, `?tab=` chooses): **About** (description with its source — "drafted by the Steward, accepted by {name}" —, purpose / usage / limitations, tags with the inline editor, glossary terms of its columns, the contract's version and key (slice 5 fills the link), the deprecation note and successor), **Columns** (a table at 1280, stacked cards at 375: name (link to `column-view`), business name, logical type, description, classification badge, personal kind badge ("proposed" when unconfirmed), null % and distinct % from `profile`, the reference "→ {key}.{column}"), **Preview** (slice 2), **Quality** (slice 5), **Lineage** (slice 7), **Usage** (frequent users, queries a day for 30 days as a 30-cell strip, datasets read with it — `dataset_usage` — zeros until slice 3 records runs), **Access** (slice 4), **History** (the activity rows about it — `record_history` — and the schema versions — `schema_history` —, newest first, paged 50); notes (the panel below the tabs); **a stub** renders only the title, key, owner, department and Request access |
| `dataset-add` | `/datasets/new?zone=&name=` | `page-header-form`; zone (curated or product), name (live preview of the key, the database's refusal named on the field), title, description, owner (picker, the caller by default), steward (picker), department (picker), tier |
| `dataset-edit` | `/datasets/{ref}/edit` | title, description (8000), purpose, usage, limitations, owner, steward, department (pickers), tier, freshness warn and error after (an interval: a number + hours/days), evolution mode (add / rescue / fail with one line each), status (draft / active), the deprecation section (note + successor picker — a separate action, `dataset_deprecate`) |
| `column-view` | `/datasets/{ref}/columns/{column}` | the column's meaning, business name, types, classification and personal kind (read-only with "change" linking slice 4's forms), the glossary term, critical, the reference, the latest profile (null %, distinct, min/max masked when personal, top values masked when personal or confidential) and the profile over time as a small table (`profile_history` is slice 2's tool; this page reads `column_profiles` through `mcp_column_profiles`) |
| `column-edit` | `/datasets/{ref}/columns/{column}/edit` | business name, description (4000), glossary term (picker), critical, references: dataset (picker) + column (select of that dataset's live columns, loaded on change) |
| `glossary` | `/glossary?q=&status=` | terms as cards grouped by category (parent): name, status badge, the first line of the definition, column count |
| `term-view` | `/glossary/{id}` | definition, synonyms, category, steward, status; the columns that carry it (dataset key + column, discoverable only); Approve (draft, `catalog.edit`) |
| `term-add` / `term-edit` | `/glossary/new?name=&parent=` · `/glossary/{id}/edit` | name, definition (4000), synonyms (a tag-like input), category (term picker), steward (member picker) |
| `tags` | `/tags` | managed and free tags as chips with counts and colours; create; edit label, kind, colour; archive (a tag carrying a grant warns "grants on this tag stay until revoked") |

## Files (exactly these)
- `html/browse.php`, `html/datasets/{view,form,save,tag,untag,deprecate,retire,follow,unfollow}.php`, `html/datasets/columns/{view,form,save}.php`,
  `html/notes/{add,delete}.php`, `html/glossary/{index,view,form,save,approve,deprecate}.php`, `html/tags/{index,save,archive}.php`
- `app/features/catalog/{queries,present,write}.php` (datasets, columns, follows), `app/features/glossary/{queries,present,write}.php`,
  `app/features/tags/{queries,write}.php`, `app/features/notes/{queries,write}.php`, `app/features/history/{queries,present}.php` (the History tab and `record_history`'s SQL)
- `app/views/catalog/{browse,dataset,dataset-stub,dataset-form,column,column-form}.php`, `app/views/datasets/partials/{header,tab-about,tab-columns,tab-preview,tab-quality,tab-lineage,tab-usage,tab-access,tab-history,tab-pending,notes-panel,tag-editor,usage-strip}.php`,
  `app/views/glossary/{index,view,form}.php`, `app/views/tags/index.php`
- `app/pickers.php` gains `dataset` (discoverable, by key or title — stubs excluded), `term`, `tag`, `steward` (members holding `catalog.edit` or owning a dataset)
- `bin/worker.php`: `worker_pass_search_catch_up` made real (`dl_index_dataset` for datasets whose `metadata_updated_at` or `data_updated_at` passed their
  `search_index.updated_at`, ≤ `$limit` a pass)
- `assets/js/tag-editor.js`; `tests/phase3/slice1/{run.sh,lib.php,catalog.php,columns.php,glossary.php,tags.php,walls.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_datasets(PDO, array $filters, int $page): array{rows, total}` (over `mcp_datasets`; filters = the screen's) · `find_dataset(PDO, int $id): ?array` (the view's row + `tags`, `terms`, `contract_version`, `engine` from `dl_lake_tables`)
- `dataset_columns(PDO, int $datasetId, bool $removed, bool $system): array` · `find_column(PDO, int $datasetId, string $nameOrId): ?array` · `column_profile_history(PDO, int $datasetId, string $column, int $limit): array`
- `dataset_usage(PDO, int $datasetId, int $days): array{daily, frequent_users, joined_with}` · `dataset_history(PDO, int $datasetId, int $page): array{rows, total}` (activity rows + schema versions merged by time)
- `find_terms(PDO, array $filters, int $page): array` · `find_term(PDO, int $id): ?array` · `term_columns(PDO, int $termId): array` · `find_tags(PDO, ?string $q, ?string $kind): array`
- `datasets_for_pick(PDO, string $q, array $params, int $page): array` · `terms_for_pick(…)` · `tags_for_pick(…)` · `stewards_for_pick(…)`
- writes: `dataset_save(PDO, ?int $id, array $f, int $by): int` · `dataset_set_tag(PDO, int $id, string $slug, bool $on, int $by): void` (a new slug makes a `free` tag) ·
  `dataset_deprecate(PDO, int $id, string $note, ?int $successor): void` · `dataset_retire(PDO, int $id): void` · `dataset_follow(PDO, int $id, int $member, array $what): void` ·
  `dataset_unfollow(PDO, int $id, int $member): void` · `column_save(PDO, int $columnId, array $f): void` · `note_add(PDO, string $kind, int $id, string $body, int $by): int` ·
  `note_delete(PDO, int $id): void` · `term_save(PDO, ?int $id, array $f, int $by): int` · `term_approve(PDO, int $id, int $by): void` · `term_deprecate(PDO, int $id): void` ·
  `tag_save(PDO, ?int $id, array $f, int $by): int` · `tag_archive(PDO, int $id): void`

## MCP tools this slice makes real
`find_datasets` (the dataset resolver), `get_dataset`, `dataset_describe`, `dataset_columns`, `glossary` (the term resolver), `get_term`, `find_tags` (the tag resolver), `dataset_usage`, `record_history`, `recent_activity`, `activity_search`, `actor_timeline`.

## Manifest rows claimed
Screens (11): `browse`, `dataset-view`, `dataset-add`, `dataset-edit`, `column-view`, `column-edit`, `glossary`, `term-view`, `term-add`, `term-edit`, `tags`
Actions (18): `dataset_create`, `dataset_update`, `dataset_tag`, `dataset_untag`, `dataset_deprecate`, `dataset_retire`, `dataset_follow`, `dataset_unfollow`, `column_update`, `note_add`, `note_delete`, `term_create`, `term_update`, `term_approve`, `term_deprecate`, `tag_create`, `tag_update`, `tag_archive`

## Activity log events
`dataset.create|update|tag|untag|deprecate|retire|follow|unfollow`, `column.update`, `note.add|delete`, `term.create|update|approve|deprecate`, `tag.create|update|archive`,
`screen.view` with `dataset_id` on every dataset page. Every row about a dataset carries `dataset_id`; `before`/`after` carry the changed field NAMES and
short values (a title, a level, a tier) — never a description's text over 200 characters, never a note's body (its length only).

## Status vocabulary
Zone chips: raw `secondary`, curated `primary`, product `success`, sandbox `light`. Status: draft `secondary` "Draft", active none, deprecated `warning`
"Deprecated", retired `dark` "Retired". Certification: `success` "Certified", `info` "Promoted", lost `outline-danger` "Certification lost". Classification:
public `light`, internal `secondary`, confidential `warning`, restricted `danger`; personal kind `danger` with the kind ("email"), "proposed" `outline-danger`.
Health dot: green / amber / red with the word beside it. Ids: `browse-card-{id}`, `dataset-header`, `dataset-tab-{name}`, `dataset-form-field-{name}`,
`column-row-{id}`, `column-form-field-{name}`, `term-card-{id}`, `tag-chip-{slug}`, `notes-panel`, `note-{id}`, `follow-btn`.

## Out of scope for this slice
Preview and the loads (2), the editor and query runs (3), access, grants, classification changes (4), contracts, checks, incidents and certifying (5),
sources (6), lineage, transforms and metrics (7), retention (8). The writer is not called here: the catalog is PostgreSQL's.

## Proof (`tests/phase3/slice1/run.sh`: scratch database `dl_dev1`; php -S on 9001, the fake kernel on 9002, the fake MaluDB/MaluMail on 9003; then a real Apache on 9007; Playwright; the registry `--check`)
1. A curated dataset made from the form gets its key; the same key again is refused on the `name` field with the database's sentence; `raw` and `sandbox`
   refused with the divergence's sentence; a retired key refused for ever.
2. The page by id and by key; the eight tabs render (four built, four "Built by slice N"); a stub for a Member on a confidential dataset (title, owner, Request
   access — no description, no columns); nothing (404) for an external; the admin discovers it.
3. Edit as the owner, the steward and an engineer; a Member who is neither is 403 with the sentence; "Metadata updated" moves, "Data updated" does not; a
   typed row count is impossible (the form has no field; a forged POST field is ignored and the database refuses it).
4. Tags: apply makes a free tag, untag, the count; archive warns when the tag carries a grant (a fixture grant).
5. Deprecate with a note and a successor (the card greyed, "use {successor}"); retire a sandbox dataset as its owner; an agent's `dataset_retire` pauses (`deletion`).
6. Follow and unfollow; a follower's notification fixture (dl_notify) reaches the bell.
7. A column's meaning, term and reference saved; its name and type refused (not in the form; forged → ignored); the reference's column list follows the dataset.
8. Notes: add, delete by the author; another Member's delete 403.
9. Glossary: create (draft), approve (a person; an agent 403 `people`), deprecate; the term's columns list only discoverable datasets.
10. The worker's `search_catch_up` indexes a changed dataset; the search box finds it by the new title.
11. JSON mode on every screen and handler; the eleven screens at 375 and 1280 with no console errors; JavaScript off.
12. Registry `--check`: 11 screens built, 18 actions, 1 approval (`dataset_retire`) in step.

## Open questions
None — decisions taken in this spec: the dataset page's frame owns all eight tabs and the later slices replace their partials; `dataset_create` makes curated
and product datasets only; `column_update` never changes a name, a type or a classification; the steward picker offers members holding `catalog.edit` or
owning a dataset.

## Built and proven (2026-10-10)
The catalog stands. **Browse** shows the lake by zone, department and tag (three rows of chips with counts of what the person may discover)
and the datasets as cards — title, key, zone, certification, the health dot from `dl_dataset_health()`, owner, rows, "data updated", usage —
a deprecated card greyed with "use {successor}", paged 24, never a stub. **The dataset page** (`/datasets/{id|key}`, `?tab=` or
`/datasets/{key}/{tab}`) is the header (crumbs zone › department › key, status, certification or "Certification lost: {reason}" for 30
days, classification and personal data, the health dot, owner and steward, "Data updated" and "Metadata updated" as two lines, rows · size
· files from `dl_lake_tables` when the engine has the table) and its buttons (Query this — greyed until `/sql` is built, then live by
itself —, Request access below `read`, Follow / Unfollow, ⋯ Edit · Tag · Deprecate · Retire); then the **eight tabs**, every pane
server-rendered: About, Columns (a table at 1280, cards at 375), Usage (the 30-cell strip, who reads it most, read together with) and
History (activity and schema versions merged, paged 50) built; Preview, Quality, Lineage and Access each `tab-pending.php` naming slice 2,
5, 7 and 4; the notes panel below. **A stub** (a Member on a confidential dataset) is the title, key, owner, department and Request access —
nothing else, on any tab. The dataset's add and edit forms, the deprecation form, a column's page (its meaning, type, classification and
personal kind read-only, the term, the reference, the latest profile and the profile over time, made safe) and its edit form (the reference
a dataset picker + a select of its live columns reloaded on change), the glossary (cards by category), a term's page with Approve and
Deprecate, its form, and the tag vocabulary with its own make / change / archive form. The eighteen actions write through
`app/features/*/write.php`; the twelve tools' SQL is in `app/features/{catalog,glossary,tags,history}/queries.php` (Phase 4 ports it).
`worker_pass_search_catch_up` is real. The registry reads **17 screens and 27 actions built, 14 stubs**.

**Proven** by `tests/phase3/slice1/run.sh` (scratch `dl_dev1` provisioned with the engine): **229 checks under php -S and the same 229 under
a real Apache** serving the rendered vhost — catalog 65, columns 28, tags 24, glossary 31, walls 24, json 21, browser 36 (the eleven screens
at 375 × 740 and 1280 × 800: 200, the screen stamped, no sideways scroll, no console error, a screenshot each in `/tmp/dl-shots/slice1`;
tabs in place and as links without JavaScript; the ⋯ menu with and without; HTMX from a card; the record picker; a plain form POST) — plus
the server log scanned clean, the registry `--check` and the schema proof (181). Re-run green: Phase 2 (**324 under php -S, 329 under
Apache**, the suite now following the placeholders still owed), Phase 0's kit (83) and schema (181), Phase 1's claims (61); the installer's
`plan` reads clean (59 steps, 7 notes, 27 built actions).

**Decisions taken in the build** (the spec left them open, or the code showed better):
1. **`require_dataset($pdo, $ref, $need)`** (`app/lake/gate.php`) is the gate for every handler and every record screen of a dataset:
   `require_level()` with a stub refused as the same 404. Only `html/datasets/view.php` calls `require_level()` at `discover` to render a
   stub — a handler that called `require_level()` would have acted on a dataset its caller may only know exists.
2. **The edit rule** is `may_describe_dataset()` / `require_describe()` (`app/features/catalog/queries.php`): level `own` (the owner, or an
   `own` grant), the steward by id, or `catalog.edit`; refused "Only the owner of {key}, its steward or a data engineer may change its
   description." Deprecate: `own` or `catalog.edit`. Retire: a sandbox dataset `own` only, any other `catalog.edit`.
3. **`dataset_update` takes `status`** (draft or active — the edit screen's field; the manifest row gained it): active from deprecated
   clears the note and the successor — dataset_deprecate's undo.
4. **Freshness** is "6 hours" / "2 days" for an agent and number + unit on the form, stored canonically (whole days as days), 1 hour to 365
   days; fail-after shorter than warn-after is named on its field in PHP (the database's CHECK stays the referee, its text is no sentence).
5. **Logs**: a description, purpose, usage or limitations over 200 characters by its length (≤ 200 its text); a column's and a term's
   description, a note's body, a deprecation's note always by length; a tag by its slug; `before`/`after` only the changed fields.
6. **The owner's and steward's links** open the datasets they keep (`/browse?owner=`): another person's trail is `actor_timeline`'s,
   for `catalog.edit`, with no screen in the manifest.
7. **Deprecate tells the followers** who asked to hear `deprecated` — `notify_followers()` over `dl_notify()` (the wall per follower, the
   actor not told); that is the proof's "follower's notification".
8. **Retire** lands on Browse (a sandbox dataset on `/browse?owner=me`); a retired dataset is nothing to everyone, the admin included
   (db/008), and leaves the search index.
9. **Tags**: `dataset_tag`'s `tag` is an id, a slug or words (slugged: "Quarter End" → `quarter-end`); a new slug is a FREE tag labelled
   as typed; an archived tag is refused in words; `tag_create` is `managed` by default; a slug never changes.
10. **No JavaScript needed**: the ⋯ menu is a `<details>`; a tab is a link to `?tab=` carrying `data-bs-toggle="tab"` (Bootstrap switches
    in place); Retire and Deprecate confirm with `hx-confirm` (the sanctioned exemption). `assets/js/tag-editor.js` only previews a tag's
    slug and a term's synonyms as chips.
11. **Profiles are made safe by `present_profile()`** for the page and the JSON alike: min, max and top values masked when the column is
    personal (confirmed or proposed — even an old profile written raw before the column was confirmed), the top values withheld when
    confidential or restricted, min and max withheld when restricted.
12. **One pass over the wall per question**: Browse computes the visible set once (`MATERIALIZED`) and the health of the page's 24 rows
    only; the facets are one more pass.
13. **The home's followed tile** is built (`HOME_TILE_SLICES` named slice 1): `my_followed_datasets()`; the recent half still names slice 3.
14. **History** leaves out `screen.view` and `search.catalog`; actors are named from the mirror (a person who has left keeps a name), as My
    activity does. `log_screen_view()` (`app/activity.php`) gained `$opts` so every dataset page logs with its `dataset_id`.
15. **A column's URL** takes its name or its id; the search catch-up also catches glossary terms.
16. **Files beyond the list**: `app/views/catalog/key-preview.php` (the name field's live key, Pattern A) and
    `app/views/catalog/ref-columns.php` (the reference's select, Pattern A); the routes in `deploy/apache-datalake.conf` and
    `tests/dev_router.php`; the nav's labels and the layout's screen → menu map; the Phase 2 suite made to follow what is still owed.

**Found and fixed in slice 1:**
- **db/020**: `mcp_dataset_columns` lacked db/017's `references_dataset_id` and `references_column`; `mcp_datasets` lacked
  `certification_lost_at`; a taken key had no sentence — **`dl_dataset_key_taken(key)`**, the database's words for a taken or retired key,
  named on the `name` field (a FUNCTION: the first try, a BEFORE INSERT trigger that raised, defeated Phase 2's `INSERT … ON CONFLICT (key)
  DO NOTHING` — every later upsert would have broken); `dataset_follows` had no UPDATE for following again (`GRANT UPDATE (what)`).
- **The vhost's dataset-key rule took `/datasets/save.php` for a key under Apache** (any `x.y` matched; php -S never saw it, a real file
  is served first there): a key now names its zone (`raw|curated|product|sandbox`) in both vhost blocks and the dev router.
- **`bin/build_action_registry.php`** could not resolve a nested record (`/datasets/{id}/columns/{column}[/edit]`) and, once
  `datasets/form.php` existed, resolved `contract-edit` (`/datasets/{id}/contract/edit`) to it: interior ids are now dropped as the vhost
  does, and a page's edit form is left for its slice to name.

**What the exemplar fixes for slices 2–10** (copy these, by file):
- **A write handler**: `html/datasets/save.php` (create and update in one file: `dl_handler_begin()` → `require_right()` /
  `require_dataset()` + the rule → the `dl_text` / `dl_ref` / `dl_choice` / `dl_yes` fields with `$errors` → `dl_refuse_fields()` →
  `dl_guard()` around a transaction that writes, logs and commits → `dl_done()`); the small ones `tag.php`, `deprecate.php`, `retire.php`,
  `follow.php`; one handler for every record's notes, `html/notes/{add,delete}.php`.
- **A screen**: `html/datasets/view.php` (gate → `find_*` → `$may` → `log_screen_view(…, ['entity_type', 'entity_id'])` → JSON through
  `present_*` → the notices map → `render_screen()` with `datasetKey`); a form `html/datasets/form.php` with its Pattern A fragment.
- **The dataset page's tabs**: replace your `app/views/datasets/partials/tab-{preview|access|quality|lineage}.php` and keep its data
  (`d`, `columns`, `usage`, `history`, `may`, `here`); `dataset_tab_built()` and the JSON's `tabs` flip by themselves. A record screen of
  a dataset: `app/views/catalog/column.php`.
- **Reads**: walled views only, one SQL per question, presenters that whitelist (`app/features/catalog/{queries,present}.php`); a picker
  entry delegates to its `*_for_pick()` (`app/pickers.php`).
- **The proof**: `tests/phase3/slice1/` — `lib.php` (`actor`, `post_json`, `get_json`, `post_form`, `run_tag`, fixtures as postgres),
  `run.sh` (both modes, the server log scanned, the registry `--check`), `browser.mjs` (the screens at both widths, JavaScript off).
