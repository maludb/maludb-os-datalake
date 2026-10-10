-- engine/001: the views over DuckLake's catalog the web application and the read servers may use (design §6.8; tests/engine/FINDINGS.md
-- §4). Applied by deploy/os-provision.sh step 4, as postgres, AFTER DuckLake's first attach created its tables (ducklake_init.py) — never
-- by the plain migrations, because on a fresh database these tables do not exist yet.
--
-- `datalake_rw`, `datalake_records_ro` and `datalake_activity_ro` hold NOTHING on schema `ducklake`: SELECT on the catalog plus read
-- access to lake/ is every row (the per-file keys are in `ducklake_data_file.encryption_key`), the inlined-data tables are plain rows,
-- and the column statistics hold real values (a file's minimum email IS an email). These views, owned by postgres, select only what is
-- safe — sizes, counts, names, types, snapshot times and commit authors/messages — and the dataset wall applies (dl_can_discover).
-- NEVER selected here: encryption_key, path, path_is_relative, min_value, max_value, extra_stats, initial_default, default_value,
-- anything of ducklake_inlined_data_*, ducklake_metadata (it names the data path).
BEGIN;

-- The lake's live tables, by dataset key, with their sizes — for the catalog's engine fields and the storage screen.
CREATE OR REPLACE VIEW dl_lake_tables WITH (security_barrier = true) AS
SELECT d.id AS dataset_id, d.key, t.table_id, s.schema_name, t.table_name, t.begin_snapshot,
       ts.record_count, ts.file_size_bytes,
       (SELECT count(*) FROM ducklake.ducklake_data_file f WHERE f.table_id = t.table_id AND f.end_snapshot IS NULL) AS data_files,
       (SELECT count(*) FROM ducklake.ducklake_delete_file f WHERE f.table_id = t.table_id AND f.end_snapshot IS NULL) AS delete_files
  FROM ducklake.ducklake_table t
  JOIN ducklake.ducklake_schema s ON s.schema_id = t.schema_id AND s.end_snapshot IS NULL
  LEFT JOIN ducklake.ducklake_table_stats ts ON ts.table_id = t.table_id
  JOIN datasets d ON d.key = s.schema_name || '.' || t.table_name
 WHERE t.end_snapshot IS NULL AND (SELECT dl_is_member_here()) AND dl_can_discover(d.id);

-- A dataset's live columns as the engine knows them (types, nullability) — the History tab compares them with the contract.
CREATE OR REPLACE VIEW dl_lake_columns WITH (security_barrier = true) AS
SELECT d.id AS dataset_id, c.column_id, c.column_order, c.column_name, c.column_type, c.nulls_allowed, c.begin_snapshot
  FROM ducklake.ducklake_column c
  JOIN ducklake.ducklake_table t ON t.table_id = c.table_id AND t.end_snapshot IS NULL
  JOIN ducklake.ducklake_schema s ON s.schema_id = t.schema_id AND s.end_snapshot IS NULL
  JOIN datasets d ON d.key = s.schema_name || '.' || t.table_name
 WHERE c.end_snapshot IS NULL AND c.parent_column IS NULL AND (SELECT dl_is_member_here()) AND dl_can_discover(d.id);

-- The engine's own history: every commit with its author and message (the writer's `dl:<member>` and `load 4182`). A commit names no
-- row; it can name a dataset, so it is an engineer's and the admin's.
CREATE OR REPLACE VIEW dl_lake_snapshots WITH (security_barrier = true) AS
SELECT sn.snapshot_id, sn.snapshot_time, sn.schema_version, ch.changes_made, ch.author, ch.commit_message
  FROM ducklake.ducklake_snapshot sn
  LEFT JOIN ducklake.ducklake_snapshot_changes ch ON ch.snapshot_id = sn.snapshot_id
 WHERE (SELECT dl_is_admin()) OR (SELECT dl_has_right('loads.manage'));

GRANT SELECT ON dl_lake_tables, dl_lake_columns, dl_lake_snapshots TO datalake_rw, datalake_records_ro;

COMMIT;
