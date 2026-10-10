-- 005: the application's SETTINGS (one row) and the KEY rules that read them (design §6.8 "dl_settings"; D7, D11, D14).
--
-- THE ESTATE'S DATA MODEL (design §6.8): dl_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1, updated_at) with
-- this application's columns: the query service's limits (D7), the windows of retention and the audit (D11), the quotas and the upload
-- ceiling, the writer's concurrency, the key rates (D14), the masking salt's id and the certification target. The key functions are
-- Knowledge db/011's = DocCloud db/005's = Sales CRM db/005's verbatim (dl_resolve_key, dl_rate_ok, dl_key_calls_today, dl_key_rotate,
-- dl_prune_key_usage) — here because they read the settings.
--
-- THE SERVICES READ THESE TOO: the query service (services/query/) takes its limits from this row at each request — a setting changed
-- on the admin screen holds from the next query, never needing a restart; the writer (services/lake/) its windows at each pass. The
-- environment's DL_QUERY_WORKERS / DL_QUERY_MEMORY / DL_WRITER_MEMORY may only LOWER what is here (the systemd units' MemoryMax is the
-- hard ceiling, design §6.5).
BEGIN;

CREATE TABLE dl_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    timezone                    text NOT NULL DEFAULT 'UTC',
    -- the query service (D7) — every one a ceiling the service enforces per request
    query_timeout_screen_s      integer NOT NULL DEFAULT 30  CHECK (query_timeout_screen_s BETWEEN 1 AND 600),        -- a screen: the editor, the preview, a saved query
    query_timeout_agent_s       integer NOT NULL DEFAULT 60  CHECK (query_timeout_agent_s BETWEEN 1 AND 600),         -- an agent (MCP) or the API (a key)
    query_timeout_export_s      integer NOT NULL DEFAULT 300 CHECK (query_timeout_export_s BETWEEN 1 AND 3600),       -- an export
    query_rows_shown            integer NOT NULL DEFAULT 1000   CHECK (query_rows_shown BETWEEN 10 AND 100000),       -- the display cap; a truncation notice beyond
    query_rows_download         integer NOT NULL DEFAULT 100000 CHECK (query_rows_download BETWEEN 100 AND 10000000), -- an export's ceiling
    query_result_bytes          bigint  NOT NULL DEFAULT 52428800 CHECK (query_result_bytes BETWEEN 1048576 AND 1073741824),  -- 50 MB a result
    query_workers               smallint NOT NULL DEFAULT 3 CHECK (query_workers BETWEEN 1 AND 32),                   -- the pool (systemd datalake-query@1..N)
    query_memory_mb             integer NOT NULL DEFAULT 1536 CHECK (query_memory_mb BETWEEN 256 AND 65536),          -- DuckDB memory_limit per worker (1.5 GB)
    query_threads               smallint NOT NULL DEFAULT 2 CHECK (query_threads BETWEEN 1 AND 64),
    query_temp_mb               integer NOT NULL DEFAULT 2048 CHECK (query_temp_mb BETWEEN 0 AND 1048576),            -- max_temp_directory_size per worker
    query_concurrency_person    smallint NOT NULL DEFAULT 2 CHECK (query_concurrency_person BETWEEN 1 AND 32),         -- queries in flight per person
    query_rate_agent_per_minute integer NOT NULL DEFAULT 30 CHECK (query_rate_agent_per_minute BETWEEN 1 AND 10000),   -- queries a minute per agent
    -- the writer (§6.1, §6.7)
    writer_concurrency          smallint NOT NULL DEFAULT 2 CHECK (writer_concurrency BETWEEN 1 AND 16),              -- datasets worked in parallel
    writer_memory_mb            integer NOT NULL DEFAULT 3072 CHECK (writer_memory_mb BETWEEN 256 AND 131072),
    maintenance_hour            smallint NOT NULL DEFAULT 2 CHECK (maintenance_hour BETWEEN 0 AND 23),                -- nightly at 02:30
    maintenance_minute          smallint NOT NULL DEFAULT 30 CHECK (maintenance_minute BETWEEN 0 AND 59),
    target_file_mb              integer NOT NULL DEFAULT 128 CHECK (target_file_mb BETWEEN 8 AND 2048),               -- merge adjacent small files toward this
    -- the windows (D11)
    landing_keep_days           integer NOT NULL DEFAULT 30  CHECK (landing_keep_days BETWEEN 0 AND 3650),           -- after the load succeeded; a pinned blob stays
    snapshot_keep_days          integer NOT NULL DEFAULT 30  CHECK (snapshot_keep_days BETWEEN 1 AND 3650),          -- the time-travel window
    query_text_days             integer NOT NULL DEFAULT 90  CHECK (query_text_days BETWEEN 1 AND 3650),             -- then query_runs.sql is nulled
    audit_keep_days             integer NOT NULL DEFAULT 730 CHECK (audit_keep_days BETWEEN 30 AND 36500),           -- query_runs rows: 2 years
    check_results_keep_days     integer NOT NULL DEFAULT 400 CHECK (check_results_keep_days BETWEEN 30 AND 36500),
    profiles_keep_days          integer NOT NULL DEFAULT 400 CHECK (profiles_keep_days BETWEEN 30 AND 36500),        -- column_profiles
    backup_keep_days            integer NOT NULL DEFAULT 14  CHECK (backup_keep_days BETWEEN 1 AND 3650),            -- stated in an erasure's proof
    -- quotas and ceilings
    sandbox_quota_bytes         bigint  NOT NULL DEFAULT 5368709120 CHECK (sandbox_quota_bytes BETWEEN 0 AND 1099511627776),   -- 5 GB a person
    max_upload_bytes            bigint  NOT NULL DEFAULT 5368709120 CHECK (max_upload_bytes BETWEEN 1048576 AND 1099511627776), -- 5 GB a file (8 MB parts)
    upload_part_bytes           integer NOT NULL DEFAULT 8388608 CHECK (upload_part_bytes BETWEEN 1048576 AND 67108864),        -- DocCloud's 8 MB
    upload_session_hours        integer NOT NULL DEFAULT 24 CHECK (upload_session_hours BETWEEN 1 AND 168),
    sniff_max_bytes             bigint  NOT NULL DEFAULT 1073741824 CHECK (sniff_max_bytes BETWEEN 1048576 AND 1099511627776),  -- the sniffer reads the whole file up to 1 GB
    -- keys (D14)
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),
    key_rate_per_day            integer NOT NULL DEFAULT 2000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),                 -- a query costs more than a file listing
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),
    -- masks (§3 rule 4): the id of the installation's masking salt — the salt itself is a root-written file the query service alone reads
    -- (DL_MASK_SALT_FILE), never a column; a new salt is a new id, and the hashes made under the old one stop joining
    mask_salt_id                text NOT NULL DEFAULT 'v1' CHECK (mask_salt_id ~ '^[a-z0-9_-]{1,32}$'),
    -- certification and coverage (§6.4, the Steward's report): the share of tier-1 and tier-2 datasets that should be described, owned,
    -- classified and checked — the coverage screen measures against it
    certification_target        numeric(4,3) NOT NULL DEFAULT 0.800 CHECK (certification_target BETWEEN 0 AND 1),
    steward_hour                smallint NOT NULL DEFAULT 6 CHECK (steward_hour BETWEEN 0 AND 23),                    -- D15: weekdays 06:40
    steward_minute              smallint NOT NULL DEFAULT 40 CHECK (steward_minute BETWEEN 0 AND 59),
    unused_after_days           integer NOT NULL DEFAULT 90 CHECK (unused_after_days BETWEEN 7 AND 3650),            -- Tier "unused"
    storage_dir                 text,                                                                                  -- NULL = DL_STORAGE_DIR from the environment
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO dl_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER dl_settings_touch BEFORE UPDATE ON dl_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION dl_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM dl_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION dl_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM dl_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION dl_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM dl_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dl_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, dl_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, dl_setting_int('key_rate_per_day')::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION dl_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label, its limits and its SQL switch, with last_used_at touched. Nothing for a
-- revoked, expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION dl_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer, allow_sql boolean)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day, t.allow_sql
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION dl_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION dl_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label, limits and SQL switch; the old one expires after the overlap (24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION dl_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := dl_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from, allow_sql)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id, o.allow_sql)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION dl_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, UPDATE ON dl_settings TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_setting_int(text), dl_setting_text(text), dl_setting_bool(text), dl_key_calls_today(bigint) TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_resolve_key(text), dl_rate_ok(bigint, timestamptz), dl_key_rotate(bigint, text), dl_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_resolve_key(text), dl_rate_ok(bigint, timestamptz), dl_key_rotate(bigint, text), dl_prune_key_usage() TO datalake_rw;

COMMIT;
