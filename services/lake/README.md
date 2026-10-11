# The lake's writer — what each slice adds

`writer_main.py` is the writer service (`datalake-writer.service`, lake-engine.md §1): it claims `lake_jobs` with `dl_job_claim()`,
dispatches by kind and finishes with `dl_job_finish()`. **Phase 2 built it with an empty `DISPATCH`**: a job of a kind no slice has built
is finished `failed` at once with *"not built until slice N"* and no retry (`KIND_SLICE` names the slice).

From slice 2 (BUILT — uploads-loads.md "Built and proven") the handlers live in `services/lake/jobs/` — `jobs/__init__.py` holds
`DISPATCH = {kind: handler}` and `writer_main.py` merges it at start. Each handler is `job_<kind>(con, pg, job) -> dict`: `con` the job's
own DuckDB writer connection with the lake attached (opened by `writer_main.work()` with `memory = DL_WRITER_MEMORY / writer_concurrency`,
closed when the job ends — never a DuckDB transaction across jobs, FINDINGS §2; `None` for a handler marked `needs_lake = False`, like the
sniff), `pg` the job's own psycopg connection (autocommit, `dict_row`, the `datalake_lake` role, `app.member_id` = the job's requester so
what it enqueues and notifies carries them), `job` the claimed `lake_jobs` row; the answer becomes `lake_jobs.result`.
`jobs/frame.py` is what every kind shares: `JobRefused` (a refusal the job decided — failed at once, never retried), `safe_error` (the first
line, paths and quoted literals taken out — never a row value, SQL with literals, a path or a credential in an error or a result),
`lineage_start` / `lineage_end` (one OpenLineage run per job that writes a dataset), `log` (the writer's activity row: source `system`, the
requester as the actor, the dataset's id), `notify_followers`, `refresh_dataset` and `sync_columns` (the technical metadata only the writer
records). Any other exception is a fault: its safe first line is the job's `error` and the job is retried with back-off until `max_attempts`.

| Slice | Module | Kinds it adds to `DISPATCH` |
|---|---|---|
| 2 (uploads and loads) — BUILT | `jobs/ingest.py` (+ `jobs/frame.py`) | `sniff`, `load`, `profile`, `rollback` — and the `pg_notify('lake_jobs', kind)` in `dl_job_enqueue` (db/021) |
| 3 (the query service and exports) | `jobs/queries.py` | `export` |
| 5 (contracts and quality) | `jobs/quality.py` | `promote`, `checks` |
| 7 (transforms) | `jobs/transforms.py` | `transform` |
| 8 (health and erasure) | `jobs/health.py` | `maintenance`, `retention`, `erasure`, `backup_lock` |

The query service (`services/query/server.py --worker N`) is built the same way: `BUILT = {kind: handler}`, `ping` from Phase 2, `preview`
from slice 2 (its request's shape in `protocol.py`, the policy and the audit through `identity.py`), the other kinds by the slices of
query-service.md §7 — each copies `server._preview`.
