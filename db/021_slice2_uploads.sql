-- Data Lake, slice 2 (uploads and loads — THE FIRST EXEMPLAR, 2026-10-10): what the writer's job loop and the uploads found the schema
-- lacked (docs/build-specs/uploads-loads.md, lake-engine.md §1–§3). Additive: a function replaced, a column and a view's columns appended, a
-- check widened, grants.
--
-- 1. dl_job_enqueue() wakes the writer: pg_notify('lake_jobs', kind) after the insert (lake-engine §1 decision 2) — the writer LISTENs.
-- 2. loads.mapping — the confirmed mapping a load ran with (the format's read options, the sheet, the header row, each source column's name,
--    type and personal kind, the dedupe key): a replay (load_queue) re-reads the same file the same way, and a source's loads (slice 6) carry
--    theirs. Never a value of the file.
-- 3. The writer writes its own activity rows (source 'system', the requester as the actor — the exemplar's rule): INSERT on activity_log; it
--    reads a member's kind (the commit's author) and a dataset's followers (the notices of drift and failed loads).
-- 4. lineage_runs.job_kind admits 'rollback' (a load rolled back is a run that writes the dataset — OpenLineage's run of its own).
-- 5. dl_incident_open_load(): a load the dataset's drift rule refused (evolution_mode fail) opens a `load` incident — the writer holds no
--    INSERT on incidents; this one function opens one (or answers the one already open).
-- 6. mcp_loads appends the file's name and size, the blob, the template and the rollback's snapshot (the load page), and the CAST rejects'
--    count (a typed sandbox load keeps a row whose cell did not cast — NULL — and says so).
BEGIN;

CREATE OR REPLACE FUNCTION dl_job_enqueue(p_kind text, p_dataset_id bigint, p_ref_id bigint DEFAULT NULL, p_params jsonb DEFAULT '{}'::jsonb,
                                          p_priority smallint DEFAULT 5) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE jid bigint;
BEGIN
    SELECT id INTO jid FROM lake_jobs WHERE kind = p_kind AND dataset_id IS NOT DISTINCT FROM p_dataset_id AND ref_id IS NOT DISTINCT FROM p_ref_id AND status = 'queued';
    IF jid IS NOT NULL THEN RETURN jid; END IF;
    INSERT INTO lake_jobs (kind, dataset_id, ref_id, params, priority, requested_by)
    VALUES (p_kind, p_dataset_id, p_ref_id, COALESCE(p_params, '{}'::jsonb), p_priority, app_current_member_id()) RETURNING id INTO jid;
    PERFORM pg_notify('lake_jobs', p_kind);
    RETURN jid;
END$$;

ALTER TABLE loads ADD COLUMN mapping jsonb CHECK (mapping IS NULL OR jsonb_typeof(mapping) = 'object');

GRANT INSERT ON activity_log TO datalake_lake;
-- the writer names a commit's author by kind (dl:m<member> or dl:a<agent>) and tells a dataset's followers (dl_notify judges each one's wall)
GRANT SELECT (id, member_kind) ON members TO datalake_lake;
GRANT SELECT ON dataset_follows TO datalake_lake;

ALTER TABLE lineage_runs DROP CONSTRAINT lineage_runs_job_kind_check;
ALTER TABLE lineage_runs ADD CONSTRAINT lineage_runs_job_kind_check
    CHECK (job_kind IN ('load', 'promote', 'transform', 'export', 'erasure', 'maintenance', 'query', 'rollback'));

CREATE OR REPLACE FUNCTION dl_incident_open_load(p_dataset_id bigint, p_load_id bigint, p_title text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE iid bigint;
BEGIN
    SELECT id INTO iid FROM incidents WHERE dataset_id = p_dataset_id AND kind = 'load' AND check_id IS NULL
                                        AND status IN ('new', 'acknowledged', 'investigating');
    IF iid IS NOT NULL THEN RETURN iid; END IF;
    INSERT INTO incidents (dataset_id, kind, load_id, severity, title)
    VALUES (p_dataset_id, 'load', p_load_id, 'error', left(p_title, 200)) RETURNING id INTO iid;
    RETURN iid;
END$$;
REVOKE ALL ON FUNCTION dl_incident_open_load(bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_incident_open_load(bigint, bigint, text) TO datalake_lake;

CREATE OR REPLACE VIEW mcp_loads WITH (security_barrier = true) AS
SELECT l.id AS load_id, l.dataset_id, l.source_id, l.pull_id, l.upload_session_id, l.landing_sha256, l.mode, l.status, l.rows_read, l.rows_loaded, l.rows_rejected,
       l.rows_rescued, l.snapshot_id, l.schema_seen, l.checks_outcome, l.error, l.requested_by, l.queued_at, l.started_at, l.finished_at, l.rolled_back_at,
       l.rolled_back_by, l.landing_purged_at,
       -- appended (slice 2)
       l.blob_id, l.template_id, l.rollback_snapshot_id,
       (SELECT u.filename FROM upload_sessions u WHERE u.id = l.upload_session_id) AS file_name,
       (SELECT b.byte_size FROM blobs b WHERE b.id = l.blob_id) AS file_bytes,
       (SELECT count(*) FROM load_rejects r WHERE r.load_id = l.id AND r.error_type = 'CAST') AS cast_rejects
  FROM loads l WHERE (SELECT dl_is_member_here()) AND dl_level_rank(dl_level(l.dataset_id)) >= 2;

COMMIT;
