# Build spec: contracts, promotion, checks, incidents and certification (slice 5 — THE THIRD EXEMPLAR)

Builds on `lake-engine.md` (§1 the writer's loop, §4 promotion and checks, §5 lineage — the `promote` edge, §7 the PHP worker's schedule, §8 statuses) and
`query-service.md` (§7 `preview` with `quarantine`). What exists at the end: **trust, computed** — a curated dataset's contract drafted from its raw input's
columns (types, required, unique, the key, the ordering column, the SLA, each column's source), published as a version (a breaking change only as a new major,
its impact shown first), downloadable as ODCS v3.2.0 YAML; promotion raw → curated by the writer (typed, deduplicated, failing rows quarantined with their reasons,
incremental over the change feed, or rebuilt whole); drift decided by a person; checks from templates on every load and on schedule; results that open one
incident per check and resolve it on a pass; the Steward woken for an error incident; certification earned by an engineer when the database says eligible and
**lost by itself** with the reason; the lake's quality home; the dataset page's **Quality** tab. **This slice is THE THIRD EXEMPLAR**: the pattern for a rule-driven
workflow where the database decides (a result → an incident → a badge lost → a notice → a dispatch) and PHP only renders and calls — slices 7 (metrics' and
transforms' badges), 8 (erasure's states) and 10 (proposals) copy it.

Schema (db/007, 010, 012, 014, 016, 017, never modified): `contracts`, `dl_contract_breaking_changes()`, `dl_contract_publish()`, `checks`, `dl_checks_guard()` (an agent's
check is never blocking), `check_results`, `incidents`, `incident_notes`, `dl_check_result_record()`, `dl_certification_eligible()`, `dl_certify()`,
`dl_certification_recheck()`, `dl_freshness_due()` (db/010); `datasets` (`certification`, `certification_lost_reason`, `contract_version`, `dedupe_key`,
`ordering_column`, `freshness_*`), `schema_versions` (db/007); `dl_lineage_edge()`, `dl_impact()`, `dl_downstream_keys()` (db/012); `dl_notify()`, `agent_dispatches`,
`dl_incidents_dispatch()` (db/014); `mcp_contracts`, `mcp_checks`, `mcp_check_results`, `mcp_incidents`, `mcp_incident_notes`, `dl_dataset_health()` (db/016);
`references_dataset_id` (db/017 — a `referential` check's target). Engine (Phase 0): `services/lake/{promote,checks}.py`. **The database is the referee**: a
published contract frozen, a breaking change's major version, one active contract, one open incident per check, a pass resolving it, eligibility, the badge's loss,
an agent's check never blocking, the incident's dispatch.

## Divergences from the design
1. **A contract names its raw input in its schema** (ODCS's `transformSourceObjects`): each schema element carries `source` = `{raw key}.{column}`; every source names
   the same raw dataset (one raw input per curated dataset in v1 — two raw inputs is a transform's, slice 7). Publishing records the lineage edge raw → curated
   (`promote`); `promote_run` reads that raw dataset.
2. **Accepting drift drafts the contract's next version, never publishes it**: `drift_decide` accept → a draft with the change (an added column a minor version, a
   removed or retyped one a major) for an engineer to publish; reject → the decision recorded, the rescued values stay in `_dl_rescued`.
3. **Freshness is a check the worker keeps**: a dataset with `freshness_warn_after` set gets one `freshness` check (source `contract`, severity `error` when an error
   interval is set, else `warn`, never blocking) made by the worker's `freshness` pass the first time, and a result every hour.
4. **The quarantine is read through the query service** (`kind: preview`, `quarantine: true` — the curated dataset's policy applied to `lake.quarantine.{name}` plus
   `_dl_reason`), at `write` level.

## Who may do what
contract_draft_save: `contracts.manage` · contract_publish: `contracts.manage`, pauses for an agent (`other`) · promote_run, promote_rebuild: `promote.run` + `write`
on the curated dataset · drift_decide: `contracts.manage`, people · check_create: `checks.manage` (an agent with the action granted — the Steward — creates
non-blocking checks only) · check_update, check_disable: `checks.manage` · check_delete: `checks.manage`, pauses (`deletion`) · check_run: `checks.manage` or the
dataset's steward · incident_ack: `incidents.manage`, the steward or the owner · incident_assign: `incidents.manage` or the owner · incident_note: `discover` ·
incident_resolve: `incidents.manage` or the owner, people · incident_dismiss: `incidents.manage`, people · certify: `checks.manage` (level `promoted`: the owner),
pauses (`other`) · certify_remove: `checks.manage` or the owner.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `quality` | `/quality?severity=&department=` | three lists (at 375 one status list): **failing checks today** (dataset, check, value, threshold, since), **late datasets** (`dl_freshness_due`: key, warn/error, data updated, overdue), **open incidents** (title, severity, status, assignee, opened, downstream count) — every row a link |
| `incident-list` | `/incidents?status=&severity=&assignee=&dataset=` | incidents as a table: title, dataset, kind, severity, status, assignee, opened, resolved |
| `incident-view` | `/incidents/{id}` | the title, the dataset (link), the check and the result that opened it (value, threshold, rows failed, the failing keys — hashed when personal, shown at `read`), the downstream datasets (badged "upstream incident" on their pages while open), **the Steward's triage first** then the notes in order, the status line; Acknowledge · Assign (member picker) · Note · Resolve (resolution + root cause) · Dismiss (reason) — phone-first |
| `checks` | `/datasets/{ref}/checks` | a dataset's checks as a table: kind, column, params in words ("not null", "between 0 and 10,000", "row count within ±20 % of the last load"), dimension, severity, blocking, when, enabled, the last result and a 7-cell strip (the last seven results); Add check; Run all |
| `check-add` | `/datasets/{ref}/checks/new?kind=&column=` | templates as cards (not null, unique, accepted values, range, regex, row count, freshness, referential, schema, custom SQL); the chosen kind's fields: column (select of live columns), params (per kind: values list, min/max, pattern, min/max/change %, the referenced dataset and column — prefilled from the column's declared reference —, the custom SQL returning failing rows), dimension (defaulted by kind), severity, blocking (people; an agent's form hides it), when |
| `check-edit` | `/checks/{id}/edit` | the same fields; enable/disable |
| `contract-view` | `/datasets/{ref}/contract?version=` | the active contract (or a version): the schema as a table (name, logical and physical type, required, unique, key, source, description, classification), the key, the ordering column, the SLA in words, the versions (draft / active / deprecated) with their breaking changes; **Download ODCS** (`/datasets/{ref}/contract.odcs.yaml`); Edit draft; Publish (a draft) with the impact shown first (`dl_impact`: datasets, saved queries, metrics, consumers downstream) |
| `contract-edit` | `/datasets/{ref}/contract/edit` | the next version's draft: prefilled from the active contract, or from the raw input's columns (pick the raw dataset first); per column: name, logical type, physical type (`VARCHAR`, `BIGINT`, `DECIMAL(p,s)`, `DOUBLE`, `BOOLEAN`, `DATE`, `TIMESTAMP`, `TIMESTAMPTZ`), required, unique, primary key, source (the raw column), description; the key; the ordering column; the SLA (freshness, retention, time to detect, notify, repair); the version (suggested: patch, minor for an added column, major when `dl_contract_breaking_changes` is not empty); breaking changes listed live |
| `quarantine` | `/datasets/{ref}/quarantine` | the held rows (`write` level; masked for the caller's level): `_dl_reason` first, then the columns; filter by reason; the count per reason |

The dataset page's **Quality** tab (slice 1's `tab-quality.php`) is built here: `dl_dataset_health` — the health word and dot, freshness, the checks with their last
results and strips, open incidents (its own and upstream), **certification and what it lacks** ("Not eligible — it lacks: a steward, a blocking check"), Certify
(eligible, `checks.manage`), Remove certification, Run checks, Promote now / Rebuild (curated), and the header's health dot becomes real.

## Files (exactly these)
- Doors: `html/datasets/contract-odcs.php` (`/datasets/{ref}/contract.odcs.yaml`: `discover`; YAML written by `app/features/contracts/odcs.php`, no library)
- `html/quality/{index,promote,rebuild,drift,certify,uncertify}.php`, `html/quality/contracts/{view,form,save,publish}.php`, `html/quality/checks/{index,form,save,disable,delete,run}.php`,
  `html/quality/quarantine.php`, `html/incidents/{index,view,ack,assign,note,resolve,dismiss}.php`
- `app/features/contracts/{queries,present,write,odcs}.php`, `app/features/checks/{queries,present,write,templates}.php` (the kinds' params, their words, their
  default dimensions), `app/features/incidents/{queries,present,write}.php`, `app/features/quality/{queries,present}.php` (the home, health, promotion and certify calls)
- `app/views/quality/index.php`, `app/views/contracts/{view,form}.php`, `app/views/checks/{index,form}.php`, `app/views/checks/partials/{template-cards,params-*,strip}.php`,
  `app/views/incidents/{index,view}.php`, `app/views/incidents/partials/{triage,note,actions}.php`, `app/views/quality/quarantine.php`, `app/views/datasets/partials/tab-quality.php`
- `app/pickers.php` gains `check`, `incident`, `raw_column` (live columns of a raw dataset, `params.dataset`)
- **The writer** (lake-engine §4): `DISPATCH` gains `promote` and `checks`; `services/lake/jobs/quality.py` (`job_promote`: the active contract (with `source`s) →
  `promote.promote()` → curated + quarantine, one commit, the dataset's technical metadata and `data_updated_at`, then the dataset's `on_load` checks inline, the
  lineage run; `job_checks`: the dataset's enabled checks of the job's `when` (or one check) → `checks.run_checks()` → each result through `dl_check_result_record()`)
- **The query service**: `preview` honours `quarantine: true` (query-service §7)
- `bin/worker.php`: `worker_pass_freshness` (hourly: `dl_freshness_due()` → each dataset's `freshness` check made once, a result recorded through
  `dl_check_result_record()` — passed when no longer due), `worker_pass_schedules` — the checks' part (hourly and daily checks enqueued as `checks` jobs, once per
  window, by `lake_enqueue`)
- `tests/phase3/slice5/{run.sh,lib.php,contracts.php,promote.php,checks.php,incidents.php,certify.php,freshness.php,odcs.php,json.php,browser.mjs}`, `tests/phase3/slice5/quality_test.py`

## Query functions (signatures fixed)
- `find_contract(PDO, int $dataset, ?string $version): ?array` · `contract_versions(PDO, int $dataset): array` · `contract_draft_from(PDO, int $dataset, ?int $rawDataset): array` (the prefill) ·
  `contract_draft_save(PDO, int $dataset, array $f, int $by): int` (every `source` names one raw dataset; refused otherwise with *"A curated dataset promotes from one raw dataset — two inputs are a transform"*) ·
  `contract_publish(PDO, int $contract, int $by): array{version, breaking}` (`dl_contract_publish`, then `dl_lineage_edge(raw, NULL, curated, 'promote')`) · `contract_impact(PDO, int $dataset): array` (`dl_impact`) ·
  `odcs_yaml(array $contract, array $dataset): string`
- `promote(PDO, int $dataset, bool $rebuild, int $by): int` (the job id; refused without an active contract: *"Publish a contract first — promotion types by it"*) · `drift_decide(PDO, int $dataset, int $schemaVersion, bool $accept, int $by): ?int` (the new draft's id)
- `find_checks(PDO, array $filters): array` · `find_check(PDO, int $id): ?array` · `check_words(array $check): string` · `check_strip(PDO, int $check, int $n): array` ·
  `check_save(PDO, ?int $id, array $f, int $by): int` · `check_disable(PDO, int $id, bool $enable): void` · `check_delete(PDO, int $id): void` · `check_run(PDO, int $dataset, ?int $check, int $by): int` (the job id)
- `quality_home(PDO, int $member, array $filters): array{failing, late, incidents}` · `find_incidents(PDO, array $filters, int $page): array{rows, total}` · `find_incident(PDO, int $id): ?array` (with check, opening result, notes, triage first) ·
  `incident_ack(PDO, int $id, int $by)` · `incident_assign(PDO, int $id, int $assignee, int $by)` (the assignee told) · `incident_note(PDO, int $id, string $body, string $kind, int $by): int` ·
  `incident_resolve(PDO, int $id, string $resolution, ?string $rootCause, int $by)` · `incident_dismiss(PDO, int $id, string $reason, int $by)`
- `dataset_health(PDO, int $dataset): ?array` (`dl_dataset_health`) · `certify(PDO, int $dataset, string $level): void` (`dl_certify`) · `certify_remove(PDO, int $dataset, string $reason, int $by): void`
- `quarantine_rows(PDO, int $member, int $dataset, ?string $reason, int $page): array` (one `lake_query(['kind' => 'preview', 'quarantine' => true, …])`)
- Python: `job_promote(con, pg, job) -> dict` · `job_checks(con, pg, job) -> dict`

## MCP tools this slice makes real
`dataset_health`, `failing_checks`, `late_datasets`, `open_incidents` (the incident resolver), `get_incident`, `find_checks` (the check resolver), `get_contract`.

## Manifest rows claimed
Screens (9): `quality`, `incident-list`, `incident-view`, `checks`, `check-add`, `check-edit`, `contract-view`, `contract-edit`, `quarantine`
Actions (17): `contract_draft_save`, `contract_publish`, `promote_run`, `promote_rebuild`, `drift_decide`, `check_create`, `check_update`, `check_disable`, `check_delete`, `check_run`, `incident_ack`, `incident_assign`, `incident_note`, `incident_resolve`, `incident_dismiss`, `certify`, `certify_remove`

## What makes this the exemplar (the pattern later slices copy)
- **The database decides, PHP renders**: every state change of the workflow is one SQL function (`dl_check_result_record`, `dl_certify`, `dl_certification_recheck`,
  `dl_contract_publish`); a handler never computes eligibility, never opens or resolves an incident itself, never clears a badge by hand except through
  `certify_remove` with a reason.
- **What a person must decide stays a person's** (`people`): resolving and dismissing an incident, deciding drift; an agent notes, acknowledges and proposes.
- **A computed badge shows what it lacks**, in words, wherever it appears.
- **Every automatic change tells someone** (`dl_notify` from the database, deduped) and wakes the right agent (`agent_dispatches`).

## Activity log events
`contract.draft|publish` (the version, breaking count), `promote.run|rebuild|fail` (the job, rows promoted, quarantined, deduped — the writer's), `drift.decide`
(accepted or rejected, the change kinds), `check.create|update|disable|delete|run`, `check.result` (the writer: passed, value, rows failed — never failing values),
`incident.open|ack|assign|note|resolve|dismiss` (`incident.open` and the automatic resolution are the database's, mirrored by the writer's job), `certify.set|remove`
(the level, the reason's length), `certification.lost` (the missing list — the database's, mirrored).

## Status vocabulary
Health: green `success` "Healthy", amber `warning` "Needs a look", red `danger` "Failing". Contract: draft `secondary`, active `success`, deprecated `dark`;
breaking `danger` "Breaking". Check result: passed `success`, failed `danger` (error) / `warning` (warn), error `secondary` "Could not run". Incident: new `danger`,
acknowledged `warning`, investigating `info`, resolved `success`, dismissed `secondary`. Certification as slice 1. The strip: seven 10 px cells green/red/grey.
Ids: `quality-failing`, `quality-late`, `quality-incidents`, `incident-row-{id}`, `incident-{id}-ack`, `incident-{id}-resolve`, `check-row-{id}`, `check-strip-{id}`,
`check-form-field-{name}`, `contract-column-{n}-{field}`, `contract-breaking`, `contract-impact`, `quarantine-table`, `certify-btn`, `certification-missing`.

## Out of scope for this slice
Transforms into curated or product (7 — two raw inputs), the Steward's triage notes' writing (10 — the triage partial shows them when they exist), sources
scheduling their loads (6), retention (8).

## Proof (`tests/phase3/slice5/run.sh`: scratch `dl_dev5` + `dl_dev5_lake`, slice 2's fixtures loaded into raw, the writer and the query service on scratch sockets; php -S on 9001, the fake kernel on 9002; then a real Apache on 9007; Playwright; the registry `--check`)
1. A contract drafted from `raw.orders`' columns (types chosen, the key, the sources), published 1.0.0; the raw → curated edge recorded; a published contract's
   edit refused; dropping a column as 1.1.0 refused (breaking), as 2.0.0 published with the impact shown first; the ODCS YAML downloads and parses.
2. Promotion: typed rows in curated, a bad date quarantined with `cast:order_date`, duplicates collapsed latest-wins, a re-run changes nothing (idempotent),
   incremental after a new load (only the new rows), a rebuild whole; the quarantine screen shows the reason; promotion without a contract refused with its sentence.
3. Drift: a pending added column accepted → a 1.1.0 draft; a removed column accepted → a 2.0.0 draft; rejected → recorded; an agent's `drift_decide` 403 (`people`).
4. Checks: each template saved with its params in words; a blocking `not_null` failing quarantines rows at the next promotion; an agent's blocking check refused,
   its non-blocking one saved; a `referential` check prefilled from the column's reference; `check_run` enqueues and the results land.
5. Incidents: a failure opens one incident (a second failure keeps it), the owner and the steward told, the Steward dispatched (a fixture agent named "Steward"); ack,
   assign (the assignee told), note, resolve (people), dismiss; a pass resolves it citing the result.
6. Certification: an undocumented dataset says what it lacks on the Quality tab; made eligible, certified by an engineer, refused for a Member; a failing blocking
   check takes the badge with the reason and the followers told; `certify_remove` with a reason; an agent's `certify` pauses.
7. Freshness: a dataset with a 1-day warning, data 2 days old → the worker makes its `freshness` check and records a failure (an incident, `warn`); fresh data → passes.
8. Python `quality_test.py`: `job_promote` and `job_checks` against the scratch catalog (results through `dl_check_result_record`, lineage runs, idempotence).
9. JSON mode on every screen, door and handler; the nine screens and the Quality tab at 375 and 1280 (the incident page at 375 first) with no console errors;
   the registry `--check`: 9 screens built, 17 actions, 3 approvals (`contract_publish`, `check_delete`, `certify`) in step.

## Open questions
None — decisions taken in this spec: a contract names its one raw input through each column's `source`; accepting drift drafts the next version; freshness is a
worker-kept check made the first time it is due; the quarantine is read through the query service at `write` level; the ODCS YAML is written by our own small writer.
