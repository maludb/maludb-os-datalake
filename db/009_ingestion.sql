-- 009: INGESTION — sources (Inventory's canonical), the landing store and uploads in parts (DocCloud's canonical file store), saved
-- mappings (Shareholder Intelligence's import_templates), LOADS and their rejects, and the writer's queue (design §6.1, §6.3).
--
-- THE ESTATE'S DATA MODEL:
--   * `sources`, `source_credentials`, `source_pulls` — Inventory db/009 canonical, VERBATIM as Shareholder Intelligence db/009 copied it;
--     `connector` checked against dl_connectors(); `role` WIDENED with 'internal_app' (a sibling through the kernel) and 'outside_db' (a
--     database the business owns elsewhere); `supplier_id` kept nullable with no FK (SI's precedent); `source_credentials.kind` WIDENED with
--     'database_password'.
--   * `blobs`, `upload_sessions`, `upload_parts` — DocCloud db/007 canonical. `blobs` verbatim + `pinned_by`, `pinned_at`, `purge_after`
--     appended (the landing window, D11). `upload_sessions`: DocCloud's `folder_id` (NOT NULL → folders), `document_id`, `version_id` and
--     `link_id` have no table here — kept as NULLABLE bigint WITHOUT a foreign key (the one loosening, recorded as SI's `supplier_id`
--     precedent); `source_id`, `target_dataset_id`, `blob_id`, `sniff`, `sniff_status` appended.
--   * `import_templates` — Shareholder Intelligence db/014 verbatim; `kind` widened with the lake's file kinds (the CRM's divergent copy is
--     recorded in the plugin's catalogue).
--   * `loads`, `load_rejects`, `lake_jobs` — new (`imports` of Spaces/CRM — an import into an application's own records with rollback — and
--     ProcessCore's `runs` compared and avoided).
BEGIN;

CREATE OR REPLACE FUNCTION dl_connectors() RETURNS text[] LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['upload', 'http_file', 'sftp_file', 'k7', 'postgres',            -- version 1 (D12)
                 'k37_feed',                                                       -- after the kernel's K37
                 'rest_json', 'maludb_activity', 'mysql', 's3_file'];              -- Extended: declared, not pulled in v1
$$;

-- reuse: Inventory db/009 canonical (SI's verbatim copy); connector by dl_connectors(); role widened
CREATE TABLE sources (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,
    connector             text NOT NULL CHECK (connector = ANY (dl_connectors())),
    role                  text NOT NULL DEFAULT 'public_data' CHECK (role IN ('supplier', 'reference', 'public_data', 'market_data', 'licensed', 'manual', 'internal_app', 'outside_db')),
    supplier_id           bigint,
    base_url              text,
    settings              jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings) = 'object'),   -- per connector: the file pattern, the K7 provider/tool/arguments/paging, the tables and watermark
    credential_id         bigint,
    schedule_minutes      integer CHECK (schedule_minutes IS NULL OR schedule_minutes >= 0),
    rate_per_second       numeric(4,2) NOT NULL DEFAULT 1 CHECK (rate_per_second > 0 AND rate_per_second <= 10),
    user_agent            text,
    robots_state          text NOT NULL DEFAULT 'unknown' CHECK (robots_state IN ('ok', 'blocked', 'unknown')),
    robots_checked_at     timestamptz,
    last_pull_id          bigint,
    last_ok_at            timestamptz,
    consecutive_failures  integer NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    backoff_until         timestamptz,
    paused_at             timestamptz,
    paused_reason         text,
    active                boolean NOT NULL DEFAULT true,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (role <> 'supplier' OR supplier_id IS NOT NULL)
);
CREATE INDEX sources_due_idx ON sources (active, paused_at, backoff_until);
CREATE TRIGGER sources_touch BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE source_credentials (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id   bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('api_key', 'oauth_client', 'basic', 'sftp_password', 'sftp_key', 'rsa_signing', 'bearer', 'database_password')),
    label       text NOT NULL,
    last4       text NOT NULL CHECK (length(last4) BETWEEN 1 AND 4),
    ciphertext  bytea NOT NULL,
    rotated_at  timestamptz,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER source_credentials_touch BEFORE UPDATE ON source_credentials FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE sources ADD CONSTRAINT sources_credential_fk FOREIGN KEY (credential_id) REFERENCES source_credentials(id) ON DELETE SET NULL;

CREATE TABLE source_pulls (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id         bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind              text NOT NULL DEFAULT 'scheduled' CHECK (kind IN ('scheduled', 'manual', 'search', 'probe')),
    started_at        timestamptz NOT NULL DEFAULT now(),
    finished_at       timestamptz,
    status            text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'partial', 'failed', 'blocked')),
    listings_seen     integer NOT NULL DEFAULT 0,          -- here: files or pages seen
    listings_new      integer NOT NULL DEFAULT 0,          -- here: files or pages landed
    listings_changed  integer NOT NULL DEFAULT 0,
    variants_changed  integer NOT NULL DEFAULT 0,
    listings_removed  integer NOT NULL DEFAULT 0,
    http_requests     integer NOT NULL DEFAULT 0,
    bytes             bigint NOT NULL DEFAULT 0,
    error             text CHECK (error IS NULL OR length(error) <= 200),
    policy            jsonb NOT NULL DEFAULT '{}'::jsonb,
    query             text,
    started_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (query IS NULL OR length(query) <= 120)
);
CREATE INDEX source_pulls_source_idx ON source_pulls (source_id, started_at DESC);
ALTER TABLE sources ADD CONSTRAINT sources_last_pull_fk FOREIGN KEY (last_pull_id) REFERENCES source_pulls(id) ON DELETE SET NULL;
ALTER TABLE datasets ADD CONSTRAINT datasets_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL;

-- The `postgres` connector never points at this tenant's own databases (§0.5 rule 4): a source whose host is local and whose database
-- name is one of this cluster's is refused by name.
CREATE OR REPLACE FUNCTION dl_sources_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE host text := lower(COALESCE(NEW.settings ->> 'host', '')); db text := NEW.settings ->> 'dbname';
BEGIN
    IF NEW.connector = 'postgres' THEN
        IF db IS NULL THEN RAISE EXCEPTION 'A postgres source names its database (settings.dbname)' USING ERRCODE = 'check_violation'; END IF;
        IF (host IN ('', 'localhost', '127.0.0.1', '::1') OR host LIKE '/%') AND EXISTS (SELECT 1 FROM pg_database WHERE datname = db) THEN
            RAISE EXCEPTION 'A postgres source reads a database the business owns elsewhere — never one of this server''s (another application''s or the kernel''s is reached only through the kernel)'
                USING ERRCODE = 'check_violation';
        END IF;
        NEW.role := 'outside_db';
    ELSIF NEW.connector IN ('k7', 'k37_feed') THEN
        IF NEW.settings ->> 'provider' IS NULL THEN RAISE EXCEPTION 'A K7 source names its provider and tool' USING ERRCODE = 'check_violation'; END IF;
        NEW.role := 'internal_app';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER sources_guard BEFORE INSERT OR UPDATE OF connector, settings ON sources FOR EACH ROW EXECUTE FUNCTION dl_sources_guard();

-- reuse: DocCloud db/007 verbatim + the landing window appended
CREATE TABLE blobs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sha256        text NOT NULL UNIQUE CHECK (sha256 ~ '^[0-9a-f]{64}$'),
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    storage_path  text NOT NULL,                                       -- relative to DL_STORAGE_DIR: landing/<aa>/<bb>/<sha256>
    ref_count     integer NOT NULL DEFAULT 0 CHECK (ref_count >= 0),  -- loads pointing here (trigger)
    created_at    timestamptz NOT NULL DEFAULT now(),
    deleted_at    timestamptz,                                         -- purged: the worker unlinked the file
    -- appended (design §6.1, D11)
    pinned_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    pinned_at     timestamptz,
    purge_after   timestamptz                                          -- landing_keep_days after the last good load; NULL = not yet loaded
);
CREATE INDEX blobs_free_idx ON blobs (ref_count) WHERE ref_count = 0 AND deleted_at IS NULL;
CREATE INDEX blobs_purge_idx ON blobs (purge_after) WHERE deleted_at IS NULL AND pinned_at IS NULL AND purge_after IS NOT NULL;

-- reuse: DocCloud db/007; folder_id/document_id/version_id/link_id kept nullable without a foreign key; the lake's columns appended
CREATE TABLE upload_sessions (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    folder_id      bigint,
    document_id    bigint,
    link_id        bigint,
    filename       text NOT NULL,
    mime_hint      text,
    byte_size      bigint NOT NULL CHECK (byte_size >= 0),
    part_bytes     integer NOT NULL CHECK (part_bytes BETWEEN 1048576 AND 67108864),
    parts_total    integer NOT NULL CHECK (parts_total >= 1),
    parts_done     integer NOT NULL DEFAULT 0 CHECK (parts_done >= 0),
    sha256_claimed text CHECK (sha256_claimed IS NULL OR sha256_claimed ~ '^[0-9a-f]{64}$'),
    status         text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'assembling', 'done', 'failed', 'expired')),
    version_id     bigint,
    error          text,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    expires_at     timestamptz NOT NULL DEFAULT now() + interval '24 hours',
    CHECK (parts_done <= parts_total),
    -- appended (design §6.3)
    source_id         bigint REFERENCES sources(id) ON DELETE SET NULL,
    target_dataset_id bigint REFERENCES datasets(id) ON DELETE SET NULL,
    blob_id           bigint REFERENCES blobs(id) ON DELETE SET NULL,
    sniff             jsonb,                                            -- the sniffer's proposal: format, encoding, delimiter, sheets, columns with types and personal-kind proposals, 50 rows
    sniff_status      text NOT NULL DEFAULT 'none' CHECK (sniff_status IN ('none', 'queued', 'done', 'failed'))
);
CREATE INDEX upload_sessions_member_idx ON upload_sessions (member_id, status);
CREATE INDEX upload_sessions_expiry_idx ON upload_sessions (expires_at) WHERE status IN ('open', 'assembling');
CREATE TRIGGER upload_sessions_touch BEFORE UPDATE ON upload_sessions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE upload_parts (
    session_id   bigint NOT NULL REFERENCES upload_sessions(id) ON DELETE CASCADE,
    part_no      integer NOT NULL CHECK (part_no >= 1),
    byte_size    integer NOT NULL CHECK (byte_size >= 0),
    received_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (session_id, part_no)
);

-- reuse: Shareholder Intelligence db/014 verbatim; kind widened with the lake's file kinds
CREATE TABLE import_templates (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name        text NOT NULL UNIQUE,
    kind        text NOT NULL CHECK (kind IN ('spr', 'nobo', 'register', 'prices_csv', 'holders_csv', 'csv', 'xlsx', 'json', 'parquet')),
    connector   text NOT NULL DEFAULT 'generic_mapped',
    mapping     jsonb NOT NULL CHECK (jsonb_typeof(mapping) = 'object'),           -- {"sheet": "Sales", "header_row": 1, "columns": {"Order ID": {"name": "order_id", "type": "VARCHAR", "personal_kind": null}}, "dedupe_key": ["order_id"]}
    sample      jsonb,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER import_templates_touch BEFORE UPDATE ON import_templates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- new: one batch landed into a raw dataset (§6.3). Idempotent by its key — the same file (sha256) or the same pull page into the same
-- dataset is a no-op that says so.
CREATE TABLE loads (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id            bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    source_id             bigint REFERENCES sources(id) ON DELETE SET NULL,
    pull_id               bigint REFERENCES source_pulls(id) ON DELETE SET NULL,
    upload_session_id     bigint REFERENCES upload_sessions(id) ON DELETE SET NULL,
    blob_id               bigint REFERENCES blobs(id) ON DELETE SET NULL,
    landing_sha256        text CHECK (landing_sha256 IS NULL OR landing_sha256 ~ '^[0-9a-f]{64}$'),
    page_cursor           text,                                                -- a K7 page's or a feed's cursor
    template_id           bigint REFERENCES import_templates(id) ON DELETE SET NULL,
    mode                  text NOT NULL DEFAULT 'append' CHECK (mode IN ('append', 'replace')),
    idempotency_key       text NOT NULL UNIQUE,                                -- '<dataset>:<sha256>' or '<dataset>:<source>:<cursor>'
    status                text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'loaded', 'no_op', 'failed', 'rolled_back')),
    rows_read             bigint NOT NULL DEFAULT 0 CHECK (rows_read >= 0),
    rows_loaded           bigint NOT NULL DEFAULT 0 CHECK (rows_loaded >= 0),
    rows_rejected         bigint NOT NULL DEFAULT 0 CHECK (rows_rejected >= 0),
    rows_rescued          bigint NOT NULL DEFAULT 0 CHECK (rows_rescued >= 0),
    snapshot_id           bigint,
    schema_seen           jsonb,
    checks_outcome        text NOT NULL DEFAULT 'none' CHECK (checks_outcome IN ('none', 'passed', 'warned', 'failed')),
    error                 text CHECK (error IS NULL OR length(error) <= 2000),
    requested_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    agent_run_id          bigint,
    queued_at             timestamptz NOT NULL DEFAULT now(),
    started_at            timestamptz,
    finished_at           timestamptz,
    rolled_back_at        timestamptz,
    rolled_back_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    rollback_snapshot_id  bigint,
    landing_purged_at     timestamptz,                                         -- "landing purged — not replayable"
    CHECK ((status = 'rolled_back') = (rolled_back_at IS NOT NULL)),
    CHECK (rows_loaded + rows_rejected <= rows_read OR status IN ('queued', 'running'))
);
CREATE INDEX loads_dataset_idx ON loads (dataset_id, queued_at DESC);
CREATE INDEX loads_status_idx ON loads (status) WHERE status IN ('queued', 'running', 'failed');
CREATE INDEX loads_blob_idx ON loads (blob_id);
ALTER TABLE column_profiles ADD CONSTRAINT column_profiles_load_fk FOREIGN KEY (load_id) REFERENCES loads(id) ON DELETE SET NULL;

-- A blob is referenced by the loads that landed it; a good load starts its landing window.
CREATE OR REPLACE FUNCTION dl_loads_blob_count() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP IN ('INSERT', 'UPDATE') AND NEW.blob_id IS NOT NULL AND (TG_OP = 'INSERT' OR OLD.blob_id IS DISTINCT FROM NEW.blob_id) THEN
        UPDATE blobs SET ref_count = ref_count + 1 WHERE id = NEW.blob_id;
    END IF;
    IF TG_OP IN ('DELETE', 'UPDATE') AND OLD.blob_id IS NOT NULL AND (TG_OP = 'DELETE' OR OLD.blob_id IS DISTINCT FROM NEW.blob_id) THEN
        UPDATE blobs SET ref_count = GREATEST(ref_count - 1, 0) WHERE id = OLD.blob_id;
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.status = 'loaded' AND OLD.status <> 'loaded' AND NEW.blob_id IS NOT NULL THEN
        UPDATE blobs SET purge_after = now() + make_interval(days => (SELECT landing_keep_days FROM dl_settings WHERE id = 1)) WHERE id = NEW.blob_id;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER loads_blob_count AFTER INSERT OR UPDATE OF blob_id, status OR DELETE ON loads FOR EACH ROW EXECUTE FUNCTION dl_loads_blob_count();

-- new: the rows a parser refused, with their line and error (DuckDB's store_rejects). A raw line can hold personal data: it is read at the
-- dataset's write level only, purged with the landing window, and in scope of erasure.
CREATE TABLE load_rejects (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    load_id        bigint NOT NULL REFERENCES loads(id) ON DELETE CASCADE,
    line           bigint,
    byte_position  bigint,
    column_name    text,
    error_type     text NOT NULL,                                         -- CAST, MISSING COLUMNS, TOO MANY COLUMNS, UNQUOTED VALUE, LINE SIZE OVER MAXIMUM, INVALID ENCODING
    message        text CHECK (message IS NULL OR length(message) <= 500),
    raw_line       text CHECK (raw_line IS NULL OR length(raw_line) <= 2000),
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX load_rejects_load_idx ON load_rejects (load_id, line);

-- new: the writer's queue (§6.1) — one job at a time per dataset, several datasets in parallel up to writer_concurrency
CREATE TABLE lake_jobs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('sniff', 'load', 'profile', 'promote', 'checks', 'transform', 'export', 'maintenance', 'erasure', 'rollback', 'backup_lock', 'retention')),
    dataset_id    bigint REFERENCES datasets(id) ON DELETE CASCADE,
    ref_id        bigint,                                                 -- the load, transform, export, erasure, upload session … the job works
    params        jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(params) = 'object'),
    priority      smallint NOT NULL DEFAULT 5 CHECK (priority BETWEEN 0 AND 9),       -- 0 first
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed', 'cancelled')),
    attempts      smallint NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    max_attempts  smallint NOT NULL DEFAULT 3 CHECK (max_attempts BETWEEN 1 AND 10),
    run_after     timestamptz NOT NULL DEFAULT now(),
    locked_by     text,
    started_at    timestamptz,
    finished_at   timestamptz,
    error         text CHECK (error IS NULL OR length(error) <= 2000),
    result        jsonb,
    requested_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX lake_jobs_queue_idx ON lake_jobs (status, priority, run_after) WHERE status = 'queued';
CREATE INDEX lake_jobs_dataset_running_idx ON lake_jobs (dataset_id) WHERE status = 'running';
CREATE INDEX lake_jobs_ref_idx ON lake_jobs (kind, ref_id);

-- Enqueue a job (the web application, the worker, the MCP actions); one queued job of a kind per dataset and reference.
CREATE OR REPLACE FUNCTION dl_job_enqueue(p_kind text, p_dataset_id bigint, p_ref_id bigint DEFAULT NULL, p_params jsonb DEFAULT '{}'::jsonb,
                                          p_priority smallint DEFAULT 5) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE jid bigint;
BEGIN
    SELECT id INTO jid FROM lake_jobs WHERE kind = p_kind AND dataset_id IS NOT DISTINCT FROM p_dataset_id AND ref_id IS NOT DISTINCT FROM p_ref_id AND status = 'queued';
    IF jid IS NOT NULL THEN RETURN jid; END IF;
    INSERT INTO lake_jobs (kind, dataset_id, ref_id, params, priority, requested_by)
    VALUES (p_kind, p_dataset_id, p_ref_id, COALESCE(p_params, '{}'::jsonb), p_priority, app_current_member_id()) RETURNING id INTO jid;
    RETURN jid;
END$$;

-- The writer claims the next job: never a second running job on the same dataset; a backup_lock runs alone (no maintenance beside it).
CREATE OR REPLACE FUNCTION dl_job_claim(p_worker text) RETURNS lake_jobs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE j lake_jobs%ROWTYPE; cap smallint;
BEGIN
    SELECT writer_concurrency INTO cap FROM dl_settings WHERE id = 1;
    IF (SELECT count(*) FROM lake_jobs WHERE status = 'running') >= cap THEN RETURN NULL; END IF;
    IF EXISTS (SELECT 1 FROM lake_jobs WHERE status = 'running' AND kind = 'backup_lock') THEN RETURN NULL; END IF;
    SELECT * INTO j FROM lake_jobs q
     WHERE q.status = 'queued' AND q.run_after <= now()
       AND (q.dataset_id IS NULL OR NOT EXISTS (SELECT 1 FROM lake_jobs r WHERE r.status = 'running' AND r.dataset_id = q.dataset_id))
       AND (q.kind <> 'maintenance' OR NOT EXISTS (SELECT 1 FROM lake_jobs r WHERE r.status = 'running' AND r.kind = 'backup_lock'))
       AND (q.kind <> 'backup_lock' OR NOT EXISTS (SELECT 1 FROM lake_jobs r WHERE r.status = 'running'))
     ORDER BY q.priority, q.run_after, q.id
     LIMIT 1 FOR UPDATE SKIP LOCKED;
    IF j.id IS NULL THEN RETURN NULL; END IF;
    UPDATE lake_jobs SET status = 'running', locked_by = p_worker, started_at = now(), attempts = attempts + 1 WHERE id = j.id RETURNING * INTO j;
    RETURN j;
END$$;

-- The writer finishes a job: done, or failed (retried with back-off until max_attempts).
CREATE OR REPLACE FUNCTION dl_job_finish(p_job_id bigint, p_ok boolean, p_result jsonb DEFAULT NULL, p_error text DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE j lake_jobs%ROWTYPE;
BEGIN
    SELECT * INTO j FROM lake_jobs WHERE id = p_job_id FOR UPDATE;
    IF NOT FOUND THEN RETURN; END IF;
    IF p_ok THEN
        UPDATE lake_jobs SET status = 'done', finished_at = now(), result = p_result, error = NULL WHERE id = p_job_id;
    ELSIF j.attempts < j.max_attempts THEN
        UPDATE lake_jobs SET status = 'queued', locked_by = NULL, run_after = now() + make_interval(mins => (2 ^ j.attempts)::integer), error = left(p_error, 2000) WHERE id = p_job_id;
    ELSE
        UPDATE lake_jobs SET status = 'failed', finished_at = now(), error = left(p_error, 2000), result = p_result WHERE id = p_job_id;
    END IF;
END$$;

GRANT SELECT, INSERT, UPDATE ON sources, source_credentials, source_pulls, blobs, upload_sessions, upload_parts, import_templates, loads TO datalake_rw;
GRANT DELETE ON source_credentials, upload_parts, import_templates TO datalake_rw;
GRANT SELECT ON load_rejects, lake_jobs TO datalake_rw;
GRANT SELECT, INSERT, UPDATE ON sources, source_pulls, blobs, upload_sessions, loads, load_rejects, lake_jobs TO datalake_lake;
GRANT SELECT ON source_credentials, import_templates TO datalake_lake;
GRANT DELETE ON load_rejects TO datalake_lake;
GRANT EXECUTE ON FUNCTION dl_connectors() TO datalake_rw, datalake_records_ro, datalake_activity_ro, datalake_lake;
REVOKE ALL ON FUNCTION dl_job_enqueue(text, bigint, bigint, jsonb, smallint), dl_job_claim(text), dl_job_finish(bigint, boolean, jsonb, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_job_enqueue(text, bigint, bigint, jsonb, smallint) TO datalake_rw, datalake_lake;
GRANT EXECUTE ON FUNCTION dl_job_claim(text), dl_job_finish(bigint, boolean, jsonb, text) TO datalake_lake;

COMMIT;
