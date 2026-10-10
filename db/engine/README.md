# db/engine/ — views over DuckLake's catalog

These files run AFTER DuckLake has made its own catalog tables in the schema `ducklake` (its first attach,
`services/bin/ducklake_init.py`), in order, once each — `deploy/os-provision.sh` step 4 applies them and records each in
`public.schema_migrations` as `engine/<file>`. They are the application's window on the engine's catalog: `dl_lake_*` views
(`security_barrier`) that give the web application and the records MCP what they may know — schemas, tables, columns, snapshots,
sizes, statistics — and **never select `encryption_key` or a file `path`** (design §6.8: catalog read access equals data access).

Rules: never alter or drop one of DuckLake's own `ducklake_*` tables; never grant anything on schema `ducklake` itself to
`datalake_rw`, `datalake_records_ro` or `datalake_activity_ro` (the views are owned by a role that may read the catalog and are
granted to them); a new view is a new numbered file, like `db/`.
