# The lake's writer — what each slice adds

`writer_main.py` is the writer service (`datalake-writer.service`, lake-engine.md §1): it claims `lake_jobs` with `dl_job_claim()`,
dispatches by kind and finishes with `dl_job_finish()`. **Phase 2 built it with an empty `DISPATCH`**: a job of a kind no slice has built
is finished `failed` at once with *"not built until slice N"* and no retry (`KIND_SLICE` names the slice).

From slice 2 the handlers live in `services/lake/jobs/` — `jobs/__init__.py` holds `DISPATCH = {kind: handler}` and `writer_main.py`
merges it at start. Each handler is `job_<kind>(pg, job) -> dict`: `pg` is the job's own psycopg connection (autocommit, `dict_row`, the
`datalake_lake` role), `job` the claimed `lake_jobs` row; the answer becomes `lake_jobs.result`. A handler opens its own DuckDB writer
connection (`engine.writer_connection(cfg)`) and closes it before it returns — never a DuckDB transaction across jobs (FINDINGS §2). An
exception's first line (≤ 2,000 characters, never a row value, SQL with literals, a path or a credential) is the job's `error`; the job
is retried with back-off until `max_attempts`.

| Slice | Module | Kinds it adds to `DISPATCH` |
|---|---|---|
| 2 (uploads and loads) | `jobs/ingest.py` | `sniff`, `load`, `profile`, `rollback` — and the `pg_notify('lake_jobs', kind)` in `dl_job_enqueue` (its migration) |
| 3 (the query service and exports) | `jobs/queries.py` | `export` |
| 5 (contracts and quality) | `jobs/quality.py` | `promote`, `checks` |
| 7 (transforms) | `jobs/transforms.py` | `transform` |
| 8 (health and erasure) | `jobs/health.py` | `maintenance`, `retention`, `erasure`, `backup_lock` |

The query service (`services/query/server.py --worker N`) is built the same way: `BUILT = {kind: handler}`, `ping` from Phase 2,
the other kinds by the slices of query-service.md §7.
