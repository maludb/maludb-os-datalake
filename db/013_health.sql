-- 013: KEEPING IT HEALTHY — retention, ERASURE with its proof, maintenance, storage and backups (design §6.7, D11). All new.
--
-- An erasure REPORTS BEFORE IT DELETES: the writer scans every column whose personal kind matches an identifier's kind (and every dataset
-- lineage says was made from those), records what it found, and waits for an admin's approval (erasure.manage; an agent's erasure_run
-- pauses in the kernel first). Then it deletes in every zone, rewrites the files, expires every snapshot that held the rows, purges the
-- landing blobs of the loads that brought them, and writes the proof. Identifiers are kept HASHED once the erasure is done.
BEGIN;

CREATE TABLE retention_policies (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    zone          text CHECK (zone IS NULL OR zone IN ('raw', 'curated', 'product', 'sandbox')),
    dataset_id    bigint REFERENCES datasets(id) ON DELETE CASCADE,
    keep_days     integer NOT NULL CHECK (keep_days >= 1),
    by_column     text,                                               -- an event-time column; NULL = the load time (_dl_loaded_at)
    action        text NOT NULL DEFAULT 'delete' CHECK (action IN ('delete', 'archive')),
    enabled       boolean NOT NULL DEFAULT true,
    last_run_at   timestamptz,
    last_rows     bigint,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(zone, dataset_id) = 1)
);
CREATE UNIQUE INDEX retention_policies_zone_one ON retention_policies (zone) WHERE zone IS NOT NULL;
CREATE UNIQUE INDEX retention_policies_dataset_one ON retention_policies (dataset_id) WHERE dataset_id IS NOT NULL;
CREATE TRIGGER retention_policies_touch BEFORE UPDATE ON retention_policies FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- A dataset's effective retention: its own policy, else its zone's, else none.
CREATE OR REPLACE FUNCTION dl_retention_of(p_dataset_id bigint) RETURNS retention_policies
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT r.* FROM retention_policies r JOIN datasets d ON d.id = p_dataset_id
     WHERE r.enabled AND (r.dataset_id = d.id OR (r.dataset_id IS NULL AND r.zone = d.zone))
     ORDER BY (r.dataset_id IS NOT NULL) DESC LIMIT 1;
$$;

CREATE TABLE erasure_requests (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    identifiers      jsonb NOT NULL CHECK (jsonb_typeof(identifiers) = 'array' AND jsonb_array_length(identifiers) BETWEEN 1 AND 20),  -- [{kind, value}] — hashed once done
    identifiers_hashed boolean NOT NULL DEFAULT false,
    scope            text NOT NULL DEFAULT 'all' CHECK (scope IN ('all', 'zones', 'datasets')),
    scope_zones      text[] NOT NULL DEFAULT '{}',
    scope_dataset_ids bigint[] NOT NULL DEFAULT '{}',
    reason           text NOT NULL CHECK (length(reason) BETWEEN 5 AND 2000),
    requested_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_at      timestamptz,
    status           text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'scanning', 'awaiting_approval', 'erasing', 'expiring', 'done', 'failed', 'cancelled')),
    found            jsonb NOT NULL DEFAULT '{}'::jsonb,               -- {dataset key: {rows, columns, zone}} — the scan's report
    proof            jsonb NOT NULL DEFAULT '{}'::jsonb,               -- datasets, rows deleted, snapshots expired, files rewritten, blobs purged, step times, backups_clear_on
    error            text CHECK (error IS NULL OR length(error) <= 2000),
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    finished_at      timestamptz,
    CHECK (status NOT IN ('erasing', 'expiring', 'done') OR approved_at IS NOT NULL),
    CHECK (approved_by IS NULL OR approved_by IS DISTINCT FROM requested_by)
);
CREATE INDEX erasure_requests_status_idx ON erasure_requests (status) WHERE status NOT IN ('done', 'cancelled', 'failed');
CREATE TRIGGER erasure_requests_touch BEFORE UPDATE ON erasure_requests FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- An identifier's kind is a personal kind; the value is never logged (only its count reaches activity_log).
CREATE OR REPLACE FUNCTION dl_erasure_requests_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE e jsonb;
BEGIN
    IF NOT NEW.identifiers_hashed THEN
        FOR e IN SELECT * FROM jsonb_array_elements(NEW.identifiers) LOOP
            IF NOT (e ->> 'kind' = ANY (dl_personal_kinds())) OR COALESCE(length(e ->> 'value'), 0) < 1 THEN
                RAISE EXCEPTION 'An identifier is a personal kind and a value' USING ERRCODE = 'check_violation';
            END IF;
        END LOOP;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER erasure_requests_before BEFORE INSERT OR UPDATE OF identifiers ON erasure_requests FOR EACH ROW EXECUTE FUNCTION dl_erasure_requests_before();

-- WHERE TO LOOK (the writer's scan input): every live column whose personal kind is one of the identifiers' kinds, in scope; plus the
-- datasets lineage says were made from those (searched by every column of the same kind there too).
CREATE OR REPLACE FUNCTION dl_erasure_targets(p_request_id bigint) RETURNS TABLE (dataset_id bigint, key text, zone text, column_name text, personal_kind text, via text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH r AS (SELECT * FROM erasure_requests WHERE id = p_request_id),
         kinds AS (SELECT DISTINCT e ->> 'kind' AS kind FROM r, jsonb_array_elements(r.identifiers) e),
         direct AS (
            SELECT d.id, d.key, d.zone, c.name, c.personal_kind
              FROM datasets d JOIN dataset_columns c ON c.dataset_id = d.id AND c.removed_snapshot_id IS NULL
              JOIN kinds k ON k.kind = c.personal_kind, r
             WHERE d.status <> 'retired'
               AND (r.scope = 'all' OR (r.scope = 'zones' AND d.zone = ANY (r.scope_zones)) OR (r.scope = 'datasets' AND d.id = ANY (r.scope_dataset_ids)))),
         derived AS (
            SELECT DISTINCT l.dataset_id FROM (SELECT DISTINCT id FROM direct) x CROSS JOIN LATERAL dl_lineage(x.id, 'down', 10) l
             WHERE l.dataset_id IS NOT NULL AND l.dataset_id NOT IN (SELECT id FROM direct))
    SELECT id, key, zone, name, personal_kind, 'column' FROM direct
    UNION ALL
    SELECT d.id, d.key, d.zone, c.name, c.personal_kind, 'lineage'
      FROM derived x JOIN datasets d ON d.id = x.dataset_id
      JOIN dataset_columns c ON c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND c.personal_kind IN (SELECT kind FROM kinds);
$$;

-- Approve a scanned erasure (erasure.manage; never its requester).
CREATE OR REPLACE FUNCTION dl_erasure_approve(p_request_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r erasure_requests%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    IF NOT dl_has_right('erasure.manage') THEN RAISE EXCEPTION 'Approving an erasure is the Lake admin''s' USING ERRCODE = 'insufficient_privilege'; END IF;
    SELECT * INTO r FROM erasure_requests WHERE id = p_request_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such erasure' USING ERRCODE = 'no_data_found'; END IF;
    IF r.status <> 'awaiting_approval' THEN RAISE EXCEPTION 'An erasure is approved after its scan reports (it is %)', r.status USING ERRCODE = 'check_violation'; END IF;
    IF r.requested_by = me THEN RAISE EXCEPTION 'An erasure is approved by someone other than its requester' USING ERRCODE = 'check_violation'; END IF;
    UPDATE erasure_requests SET status = 'erasing', approved_by = me, approved_at = now() WHERE id = p_request_id;
    PERFORM dl_job_enqueue('erasure', NULL, p_request_id, '{"phase": "erase"}'::jsonb, 1::smallint);
END$$;
-- Once done, the identifiers are kept only as hashes (the proof names what was erased without keeping it).
CREATE OR REPLACE FUNCTION dl_erasure_seal(p_request_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE erasure_requests
       SET identifiers = (SELECT jsonb_agg(jsonb_build_object('kind', e ->> 'kind', 'sha256', encode(sha256(convert_to(e ->> 'value', 'UTF8')), 'hex')))
                            FROM jsonb_array_elements(identifiers) e),
           identifiers_hashed = true
     WHERE id = p_request_id AND status = 'done' AND NOT identifiers_hashed;
END$$;

CREATE TABLE maintenance_runs (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind               text NOT NULL DEFAULT 'nightly' CHECK (kind IN ('nightly', 'on_demand', 'after_erasure')),
    status             text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'failed')),
    inlined_flushed    bigint NOT NULL DEFAULT 0,
    files_merged       integer NOT NULL DEFAULT 0,
    files_rewritten    integer NOT NULL DEFAULT 0,
    snapshots_expired  integer NOT NULL DEFAULT 0,
    files_deleted      integer NOT NULL DEFAULT 0,
    bytes_freed        bigint NOT NULL DEFAULT 0,
    detail             jsonb NOT NULL DEFAULT '{}'::jsonb,
    error              text CHECK (error IS NULL OR length(error) <= 2000),
    started_at         timestamptz NOT NULL DEFAULT now(),
    finished_at        timestamptz
);
CREATE INDEX maintenance_runs_time_idx ON maintenance_runs (started_at DESC);

CREATE TABLE storage_daily (
    day          date NOT NULL,
    dataset_id   bigint REFERENCES datasets(id) ON DELETE CASCADE,
    zone         text NOT NULL,
    bytes        bigint NOT NULL DEFAULT 0,
    files        integer NOT NULL DEFAULT 0,
    rows         bigint NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX storage_daily_one ON storage_daily (day, COALESCE(dataset_id, 0), zone);

CREATE TABLE backups (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind             text NOT NULL DEFAULT 'backup' CHECK (kind IN ('backup', 'drill')),
    status           text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'failed')),
    target           text,                                              -- the directory (no credentials)
    catalog_bytes    bigint,
    files_bytes      bigint,
    snapshot_id      bigint,                                            -- the lake's latest snapshot at the catalog dump
    checks           jsonb NOT NULL DEFAULT '{}'::jsonb,                -- a drill's fixed queries and their answers
    error            text CHECK (error IS NULL OR length(error) <= 2000),
    started_at       timestamptz NOT NULL DEFAULT now(),
    finished_at      timestamptz,
    expires_at       timestamptz
);
CREATE INDEX backups_time_idx ON backups (kind, started_at DESC);

-- Storage per dataset and zone today (the admin's screen; I4).
CREATE OR REPLACE FUNCTION dl_storage_by_dataset() RETURNS TABLE (dataset_id bigint, key text, zone text, bytes bigint, files integer, rows bigint, sandbox_owner bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.key, d.zone, COALESCE(d.byte_size, 0), COALESCE(d.file_count, 0), COALESCE(d.row_count, 0),
           CASE WHEN d.zone = 'sandbox' THEN d.owner_member_id END
      FROM datasets d WHERE d.status <> 'retired' ORDER BY COALESCE(d.byte_size, 0) DESC;
$$;
-- A person's sandbox against its quota (Q5).
CREATE OR REPLACE FUNCTION dl_sandbox_usage(p_member_id bigint) RETURNS TABLE (used_bytes bigint, quota_bytes bigint, datasets integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(sum(d.byte_size), 0)::bigint, (SELECT sandbox_quota_bytes FROM dl_settings WHERE id = 1), count(*)::integer
      FROM datasets d WHERE d.zone = 'sandbox' AND d.owner_member_id = p_member_id AND d.status <> 'retired';
$$;

GRANT SELECT, INSERT, UPDATE ON retention_policies, erasure_requests TO datalake_rw;
GRANT SELECT ON maintenance_runs, storage_daily, backups TO datalake_rw;
GRANT SELECT ON retention_policies TO datalake_lake;
GRANT SELECT, UPDATE ON erasure_requests TO datalake_lake;
GRANT SELECT, INSERT, UPDATE ON maintenance_runs, storage_daily, backups TO datalake_lake;
GRANT EXECUTE ON FUNCTION dl_retention_of(bigint), dl_storage_by_dataset(), dl_sandbox_usage(bigint) TO datalake_rw, datalake_records_ro, datalake_activity_ro, datalake_lake;
REVOKE ALL ON FUNCTION dl_erasure_targets(bigint), dl_erasure_approve(bigint), dl_erasure_seal(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_erasure_targets(bigint) TO datalake_lake, datalake_rw;
GRANT EXECUTE ON FUNCTION dl_erasure_approve(bigint) TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_erasure_seal(bigint) TO datalake_lake, datalake_rw;

COMMIT;
