# Build spec: retention, erasure, maintenance and backups (slice 8 — replicates slices 1 and 2)

What exists at the end: retention by zone and by dataset, enforced nightly by the writer; the **erasure** of one person from every zone — drafted, scanned
(a report before anything is deleted), approved by another admin who acknowledges that time travel ends lake-wide, erased, expired, verified
independently, proved, sealed; the maintenance screen (storage by dataset and zone, sandbox quotas, the writer's queue, the passes) with maintenance,
snapshot expiry and landing purge on demand; the hourly flush of inlined rows; **backups** catalog-first under the writer's lock and the **restore drill**.
**Build every screen exactly like slice 1's datasets screens; every job exactly like slice 2's load job** (`dl_job_enqueue` → the writer's handler,
registered in the table slice 2 built → `dl_job_finish`), the engine calls being the ones `services/lake/{maintenance,erase,backup}.py` already prove
(`tests/engine/run.sh`; lake-engine §6).

Schema (db/005, 009, 011, 013, 014, 016, 017, never modified): `dl_settings` (the windows: `landing_keep_days`, `snapshot_keep_days`, `query_text_days`, `audit_keep_days`,
`check_results_keep_days`, `profiles_keep_days`, `backup_keep_days`, `maintenance_hour/minute`, `sandbox_quota_bytes`, `inline_flush_minutes` (db/017)); `blobs`
(`purge_after`, `pinned_*`), `loads.landing_purged_at`, `lake_jobs`, `dl_job_enqueue()`, `dl_job_claim()` (a `backup_lock` runs alone), `dl_job_finish()` (db/009);
`dl_query_runs_prune()` (db/011); `retention_policies`, `dl_retention_of()`, `erasure_requests`, `dl_erasure_targets()` (db/017 — references followed),
`dl_erasure_approve(id, time_travel_acknowledged)` (db/017), `dl_erasure_seal()`, `maintenance_runs`, `storage_daily`, `backups`, `dl_storage_by_dataset()`, `dl_sandbox_usage()`
(db/013); `dl_notify()` (db/014); `mcp_erasure_requests`, `mcp_retention_policies`, `mcp_maintenance_runs`, `mcp_backups`, `mcp_storage_daily`, `mcp_lake_jobs` (db/016);
`dl_lake_tables` (db/engine/001). No migration.

## Divergences from the design
1. **`archive` retention is Extended**: v1 deletes only; `retention_set` with `action = archive` is refused with `archive_extended` (the column stays for later).
2. **The audit's windows are pruned by the writer's `retention` job** (`datalake_lake` holds DELETE on `check_results` and `column_profiles`; the web role does not), and
   `dl_query_runs_prune()` by the PHP worker (granted to `datalake_rw`).
3. **The hourly flush and the nightly storage census are `maintenance` jobs** with `params.scope` = `flush` / `all` / a dataset key — no new job kind. **Backups and
   drills are `backup_lock` jobs** with `params.op` = `backup` / `drill` — so nothing else runs beside them (`dl_job_claim`).
4. **Backups are started by a systemd timer this slice adds**, `deploy/datalake-backup.{service,timer}` (02:00 nightly, before the 02:30 maintenance), running
   `php bin/backup.php` (enqueue only) as `www-data`; this slice lists both files in `maludb-os.json` `services`. The destination is `DL_BACKUP_DIR`, else
   `DL_STORAGE_DIR/backup`; backups older than `backup_keep_days` are removed by the handler after a good backup.
5. **The drill restores into a database of its own**, `<DB_NAME>_drill`, made by `deploy/os-provision.sh` (this slice adds the step: created when missing,
   owned by `datalake_lake`, schema `ducklake`; its passfile line added to `/etc/datalake/lake.pgpass`; `DL_DRILL_DB_DSN` written to `config/.env` with
   `passfile=` — never a password) and a data directory `DL_STORAGE_DIR/tmp/drill`, emptied after. Its fixed queries: the row count of every dataset the
   backup's manifest names at the dump's snapshot, compared with the manifest.
6. **"Awaiting your approval" is a `mention` notification** to every holder of `erasure.manage` but the requester (`dl_notify`, dedupe per erasure per member).

## Who may do what
retention_set / retention_remove: `retention.manage` (retention_set pauses for an agent, `deletion`) · erasure_create / erasure_scan / erasure_cancel: `erasure.manage`,
people only · erasure_approve: `erasure.manage` and not its requester (db/017 refuses), pauses for an agent (`deletion`) · maintenance_run: `maintenance.run` ·
snapshot_expire_now: `maintenance.run`, pauses (`deletion`) · landing_purge / landing_pin: `loads.manage` (purge pauses, `deletion`) · drill_run: `maintenance.run`, people only.
The screens: `retention.manage` (retention), `erasure.manage` (erasures), `maintenance.run` (maintenance, backups).

## Screens
`retention` `/admin/retention` (two tables — by zone, by dataset (the picker to add): keep days, by column, last run, rows deleted; Remove; beside them the windows of `dl_settings`
read-only with a link to `admin-settings` (slice 10)) · `erasure-list` `/admin/erasures` (rows: kinds and count of identifiers — never values —, reason, requester, approver, status,
dates; New erasure) · `erasure-add` `/admin/erasures/new` (identifiers as rows of kind (the personal kinds) + value, up to 20; the reason; the scope all / zones / datasets (the
picker); Save then Scan) · `erasure-view` `/admin/erasures/{id}` (the status strip draft → scanning → awaiting_approval → erasing → expiring → done; **the scan's report**: per
dataset the rows found, the zone, the column and `via` (column / reference / lineage), the datasets downstream that lose rows, the loads whose landing files will be purged
"not replayable"; **the warning box** "approving ends time travel before now for every dataset in the lake" with the acknowledgement checkbox; Approve (another admin) / Cancel; while
running, the status partial polled every 5 s; when done, **the proof**: datasets, rows deleted, snapshots expired, files rewritten, blobs purged, each step's time, "backups clear on
<date>") · `maintenance` `/admin/maintenance` (storage by dataset and zone (`dl_storage_by_dataset`, the largest first), sandbox quotas (`dl_sandbox_usage` per member, near-quota
first), the writer's queue (`mcp_lake_jobs`: queued, running, failed in 24 h), the last 20 maintenance runs; Run maintenance now · Expire snapshots older than… · per load in the landing
list Purge / Pin) · `backups` `/admin/backups` (the last 30 backups and drills: status, sizes, snapshot, checks, expiry; Run a drill).

## The writer's handlers (`services/lake/jobs/{retention,erasure,maintenance,backup}.py`, registered in slice 2's handler table — lake-engine §1, §6)
- **retention** (`params.dataset` or `params.scope = 'audit'`): for a dataset — `DELETE FROM <key> WHERE <by_column or _dl_loaded_at> < now() - keep_days` in one commit
  (author `dl:retention`, message `retention <key>`), `retention_policies.last_run_at`, `last_rows`; for `audit` — `check_results` older than `check_results_keep_days`, `column_profiles`
  older than `profiles_keep_days`.
- **erasure** (`params.phase`): `scan` — `dl_erasure_targets(id)` → `erase.scan(con, identifiers, personal_map, follow = the reference rows)` → `found` → status `awaiting_approval`
  → the `mention` notices (§ Divergences 6); `erase` — `erase.erase(con, targets, author 'dl:erasure', request_id, lake_dsn, landing_paths = the affected loads' blobs)` → status
  `expiring` → `maintenance.run(con, snapshot_keep_days = 0)` and `erase.vacuum_inlined` → `erase.verify(con, needles = the identifiers' values, data_path, lake_dsn,
  landing_paths)` → **a verify that finds anything fails the erasure** (`failed`, the finding's location — never the value — in `error`, the admins told) → else `proof` (the
  handler's dictionary + `backups_clear_on = now() + backup_keep_days`) → status `done`, `finished_at` → `dl_erasure_seal(id)` → the affected loads' `landing_purged_at` and their
  blobs' `deleted_at` → `dl_notify(requester and approver, 'erasure_done', …)`.
- **maintenance** (`params.scope`): `flush` — `ducklake_flush_inlined_data` for every table with inlined rows; `all` / a key — `maintenance.run(con, author 'dl:maintenance',
  snapshot_keep_days = the setting, target_file_size = target_file_mb)`, then the storage census (one `storage_daily` row per dataset and zone for today, the datasets' engine fields
  refreshed), a `maintenance_runs` row with the counts; `params.expire_older_than` (from `snapshot_expire_now`) — expire and clean up older than that time, kind `on_demand`.
- **backup** (`params.op`): `backup` — `backup.backup(cfg, dest/<YYYYmmdd-HHMM>)` → a `backups` row (`catalog_bytes`, `files_bytes`, `snapshot_id`, `expires_at`) → remove older
  backups; `drill` — `backup.restore_drill(cfg, the newest ok backup, restore_dsn = DL_DRILL_DB_DSN, restore_data_dir = DL_STORAGE_DIR/tmp/drill, queries)` → a `backups` row kind
  `drill` with `checks`; a tampered file → `failed` with `corrupt`.

## The worker (`bin/worker.php` — this slice's passes `retention` and `maintenance`; `upload_sessions`, `exports` and the rest are other slices')
`retention`: once a night after `maintenance_hour:maintenance_minute` (a mark file per day, Inventory's housekeeping pattern) — one `retention` job per dataset with an effective
policy (`dl_retention_of`), one `retention` job with `scope audit`, `dl_query_runs_prune()`; **every pass**: the landing purge — blobs with `purge_after < now()`, not pinned, not
deleted, and no load still `queued`/`running` on them: unlink `DL_STORAGE_DIR/<storage_path>`, `deleted_at`, the loads' `landing_purged_at` (at most `--limit` a pass).
`maintenance`: the nightly `maintenance` job `scope all` at `maintenance_hour:maintenance_minute`; a `maintenance` job `scope flush` every `inline_flush_minutes` (the last flush's
`maintenance_runs.started_at` decides); never while a `backup_lock` job is queued or running.

## Files (exactly these)
`html/admin/retention/{index,save,remove}.php`, `html/admin/erasures/{index,form,view,status,save,scan,approve,cancel}.php`, `html/admin/maintenance/{index,run,expire,landing-purge,landing-pin}.php`,
`html/admin/backups/{index,drill}.php`; `app/features/retention/{queries,present,write}.php`, `app/features/erasures/{queries,present,write}.php`, `app/features/maintenance/{queries,present,write}.php`,
`app/features/backups/{queries,present,write}.php`; `app/views/admin/{retention,erasures,erasure-form,erasure-view,maintenance,backups}.php` + `app/views/admin/partials/{erasure-report,erasure-proof,erasure-status,storage-table,quota-table,queue-table,maintenance-runs,backup-rows}.php`;
`services/lake/jobs/{retention,erasure,maintenance,backup}.py`; `bin/backup.php`; `bin/worker.php` (`retention`, `maintenance`); `deploy/datalake-backup.{service,timer}`; `deploy/os-provision.sh` (the drill
database step); `config/.env.example` (`DL_DRILL_DB_DSN`); `maludb-os.json` (`services` + the two backup units; `env.optional` + `DL_DRILL_DB_DSN`); `tests/phase3/slice8/{run.sh,lib.php,retention.php,erasure.php,maintenance.php,backups.php,json.php,browser.mjs}`.

## Query functions (signatures fixed)
`find_retention(PDO): array{zones, datasets}` · `find_erasures(PDO, array $f, int $page): array` · `find_erasure(PDO, int $id): ?array` (kinds and count, never values, outside the writer) · `erasure_report(array $found): array` ·
`storage_by_dataset(PDO, ?string $zone, int $limit): array` · `sandbox_quotas(PDO): array` · `writer_queue(PDO): array{queued, running, failed_24h, rows}` · `maintenance_runs(PDO, int $limit): array` · `landing_loads(PDO, int $page): array` ·
`find_backups(PDO, ?string $kind, int $limit): array` · writes: `retention_set(PDO, ?int $id, array $f, int $by): int` · `retention_remove(PDO, int $id, int $by)` · `erasure_create(PDO, array $identifiers, string $reason, array $scope, int $by): int` ·
`erasure_scan(PDO, int $id, int $by): int` (the job) · `erasure_approve(PDO, int $id, bool $ack, int $by)` (`dl_erasure_approve`) · `erasure_cancel(PDO, int $id, int $by)` · `maintenance_queue(PDO, array $params, int $by): int` ·
`landing_purge(PDO, int $load, int $by): int` (blobs purged) · `landing_pin(PDO, int $load, bool $pin, int $by)` · `drill_queue(PDO, int $by): int` · the worker: `worker_pass_retention`, `worker_pass_maintenance`

## MCP tools this slice makes real
`get_erasure` (the erasure resolver), `erasure_targets`, `storage_by_dataset`, `quota_state`, `maintenance_state`, `backup_state`.

## Manifest rows claimed
Screens (6): `retention`, `erasure-list`, `erasure-add`, `erasure-view`, `maintenance`, `backups`
Actions (11): `retention_set`, `retention_remove`, `erasure_create`, `erasure_scan`, `erasure_approve`, `erasure_cancel`, `maintenance_run`, `snapshot_expire_now`, `landing_purge`, `landing_pin`, `drill_run`

## Activity log events
`retention.set|remove`, `retention.run` (`{dataset, rows_deleted}`, source `system`), `erasure.request` (`{identifier_kinds[], count, scope}` — **never a value**), `erasure.scan`, `erasure.scanned`
(`{datasets, rows}`), `erasure.approve`, `erasure.cancel`, `erasure.done` (`{datasets, rows, snapshots_expired, files_rewritten, blobs_purged}`), `erasure.failed`, `maintenance.run`, `maintenance.done`
(the run's counts), `snapshot.expire`, `landing.purge|pin|unpin`, `landing.purged_auto` (the worker's count), `backup.run`, `backup.done` (sizes, snapshot), `backup.drill`, `backup.drill_done` (`{ok, checks}`).

## Status vocabulary
Erasure: draft `light`, scanning `primary`, awaiting_approval `warning`, erasing `primary`, expiring `primary`, done `success`, failed `danger`, cancelled `secondary`. Maintenance and backup: running
`primary`, ok `success`, failed `danger`. Job: queued `secondary`, running `primary`, done `success`, failed `danger`, cancelled `dark`. Ids: `retention-zones`, `retention-datasets`, `retention-form`,
`erasure-list`, `erasure-{id}`, `erasure-form`, `erasure-form-identifier-{n}`, `erasure-report`, `erasure-ack`, `erasure-{id}-approve`, `erasure-proof`, `storage-table`, `quota-table`, `queue-table`,
`maintenance-run`, `maintenance-runs`, `backups-table`, `backup-{id}`, `drill-run`.

## Out of scope for this slice
`archive` retention (Extended); the windows' editing (slice 10's `settings_save`); offsite copies of backups (the owner's — `DL_BACKUP_DIR` may be a mount); PostgreSQL's own base backups and WAL
(the host's).

## Proof (`tests/phase3/slice8/run.sh`: scratch `dl_dev8`, a scratch lake and a scratch drill database through `tests/engine/setup_scratch.sh`'s pattern; the writer started for the run; ports 9001–9007)
A dataset retention deletes the old rows in one snapshot and wins over its zone's; `archive` is refused; the audit's windows prune; the landing purge removes a blob past its window and marks its
loads not replayable, a pinned one stays; an erasure of one email: the scan reports the email column, the referencing dataset and the lineage, nothing is deleted before approval, the requester
cannot approve, an approval without the acknowledgement is refused, an agent's approval pauses, the run erases, expires, verifies (decrypting every file) and proves, the identifiers are sealed as
hashes, the loads are not replayable, the requester and the approver are told; a planted copy the scan missed makes the verify fail the erasure; maintenance flushes inlined rows and merges files;
`snapshot_expire_now` by an agent pauses; a backup is taken under the lock (a maintenance job queued meanwhile waits), the drill restores into the drill database and its counts equal the manifest's,
a tampered file fails the drill as `corrupt`; JSON mode; 375 and 1280; `php bin/build_action_registry.php --check` (6 screens, 11 actions built, 4 approvals).

## Open questions
None — decisions taken: archive Extended (§ Divergences 1); the audit pruned by the writer (2); flush and census as maintenance jobs, backup and drill as `backup_lock` jobs (3); the backup timer and
`bin/backup.php` (4); the drill database made by the provision script (5); `mention` for "awaiting your approval" (6).
