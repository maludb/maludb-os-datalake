-- 011: USING THE DATA — the audit of every read (query_runs), saved and VERIFIED queries, METRICS, and the usage the catalog ranks by
-- (design §6.5, §6.2). All new.
--
-- `query_runs` is written by the query service alone, through dl_query_run_log() (SECURITY DEFINER): one row per request — refusals and
-- timeouts included —, the SQL text kept query_text_days then nulled (dl_query_runs_prune), the row kept audit_keep_days (D11). The activity
-- log gets `query.run` with ids and counts, never the SQL or a value.
BEGIN;

CREATE TABLE query_runs (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    caller_member_id bigint NOT NULL,                                       -- no FK: the audit outlives a member
    on_behalf_of     bigint,                                                -- the person an agent acted for (K38)
    agent_run_id     bigint,
    request_id       text,
    source           text NOT NULL CHECK (source IN ('screen', 'preview', 'mcp', 'agent', 'api', 'k7', 'export', 'transform')),
    consumer_app     text,                                                  -- a K7 consumer's catalog key
    key_label        text,                                                  -- an API key's label, never the key
    saved_query_id   bigint,
    metric_id        bigint,
    sql_sha256       text NOT NULL CHECK (sql_sha256 ~ '^[0-9a-f]{64}$'),
    sql              text,                                                  -- nulled after query_text_days
    as_of            text,                                                  -- a snapshot or a time, when asked
    datasets         text[] NOT NULL DEFAULT '{}',                          -- the keys read
    dataset_ids      bigint[] NOT NULL DEFAULT '{}',
    columns_read     jsonb NOT NULL DEFAULT '{}'::jsonb,                    -- {key: [columns]} — when the service can tell
    masked_columns   jsonb NOT NULL DEFAULT '{}'::jsonb,                    -- {key: {column: mask}}
    row_filters      jsonb NOT NULL DEFAULT '{}'::jsonb,                    -- {key: predicate} as applied
    rows_returned    bigint NOT NULL DEFAULT 0 CHECK (rows_returned >= 0),
    truncated        boolean NOT NULL DEFAULT false,
    bytes_returned   bigint NOT NULL DEFAULT 0 CHECK (bytes_returned >= 0),
    ms               integer NOT NULL DEFAULT 0 CHECK (ms >= 0),
    status           text NOT NULL CHECK (status IN ('ok', 'refused', 'timeout', 'error', 'cancelled')),
    refusal_rule     text,
    error            text CHECK (error IS NULL OR length(error) <= 500),
    worker           text,
    created_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX query_runs_caller_idx ON query_runs (caller_member_id, created_at DESC);
CREATE INDEX query_runs_behalf_idx ON query_runs (on_behalf_of, created_at DESC) WHERE on_behalf_of IS NOT NULL;
CREATE INDEX query_runs_datasets_idx ON query_runs USING gin (dataset_ids);
CREATE INDEX query_runs_time_idx ON query_runs (created_at);
CREATE INDEX query_runs_status_idx ON query_runs (status, created_at DESC) WHERE status <> 'ok';
CREATE INDEX query_runs_agent_run_idx ON query_runs (agent_run_id) WHERE agent_run_id IS NOT NULL;

-- The query service's one write (role datalake_query): the audit row. Returns its id (the answer's query_run_id).
CREATE OR REPLACE FUNCTION dl_query_run_log(p jsonb) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE rid bigint; keys text[];
BEGIN
    keys := COALESCE(ARRAY(SELECT jsonb_array_elements_text(p -> 'datasets')), '{}');
    INSERT INTO query_runs (caller_member_id, on_behalf_of, agent_run_id, request_id, source, consumer_app, key_label, saved_query_id, metric_id,
                            sql_sha256, sql, as_of, datasets, dataset_ids, columns_read, masked_columns, row_filters, rows_returned, truncated,
                            bytes_returned, ms, status, refusal_rule, error, worker)
    VALUES ((p ->> 'caller')::bigint, (p ->> 'on_behalf_of')::bigint, (p ->> 'agent_run_id')::bigint, p ->> 'request_id', p ->> 'source',
            p ->> 'consumer_app', p ->> 'key_label', (p ->> 'saved_query_id')::bigint, (p ->> 'metric_id')::bigint,
            p ->> 'sql_sha256', p ->> 'sql', p ->> 'as_of', keys,
            ARRAY(SELECT d.id FROM datasets d WHERE d.key = ANY (keys) ORDER BY d.id),
            COALESCE(p -> 'columns_read', '{}'::jsonb), COALESCE(p -> 'masked_columns', '{}'::jsonb), COALESCE(p -> 'row_filters', '{}'::jsonb),
            COALESCE((p ->> 'rows_returned')::bigint, 0), COALESCE((p ->> 'truncated')::boolean, false), COALESCE((p ->> 'bytes_returned')::bigint, 0),
            COALESCE((p ->> 'ms')::integer, 0), p ->> 'status', p ->> 'refusal_rule', left(p ->> 'error', 500), p ->> 'worker')
    RETURNING id INTO rid;
    RETURN rid;
END$$;

-- The windows: the SQL text after query_text_days, the row after audit_keep_days (D11) — the worker's nightly pass.
CREATE OR REPLACE FUNCTION dl_query_runs_prune() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s dl_settings%ROWTYPE; n integer; k integer;
BEGIN
    SELECT * INTO s FROM dl_settings WHERE id = 1;
    UPDATE query_runs SET sql = NULL WHERE sql IS NOT NULL AND created_at < now() - make_interval(days => s.query_text_days);
    GET DIAGNOSTICS n = ROW_COUNT;
    DELETE FROM query_runs WHERE created_at < now() - make_interval(days => s.audit_keep_days);
    GET DIAGNOSTICS k = ROW_COUNT;
    RETURN n + k;
END$$;

-- Saved queries; VERIFIED by an engineer (queries.verify) — the badge drops BY ITSELF when the SQL changes (Metabase's, D9).
CREATE TABLE saved_queries (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    owner_member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    title                text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    description          text,
    question             text,                                              -- the plain-language question it answers (the expert's matching)
    sql                  text NOT NULL CHECK (length(sql) BETWEEN 1 AND 20000),
    sql_sha256           text NOT NULL DEFAULT '',
    parameters           jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(parameters) = 'array'),   -- [{name, type, default, required}]
    dataset_ids          bigint[] NOT NULL DEFAULT '{}',                    -- the datasets it reads (set by the query service's parse)
    shared               boolean NOT NULL DEFAULT false,                    -- shared: every Member who may read its datasets may run it
    verified_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    verified_at          timestamptz,
    verified_sql_sha256  text,
    unverified_reason    text,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now(),
    deleted_at           timestamptz,
    CHECK ((verified_at IS NULL) = (verified_sql_sha256 IS NULL))
);
CREATE INDEX saved_queries_owner_idx ON saved_queries (owner_member_id) WHERE deleted_at IS NULL;
CREATE INDEX saved_queries_verified_idx ON saved_queries (verified_at) WHERE verified_at IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX saved_queries_datasets_idx ON saved_queries USING gin (dataset_ids);
CREATE INDEX saved_queries_title_trgm_idx ON saved_queries USING gin (title gin_trgm_ops);
CREATE TRIGGER saved_queries_touch BEFORE UPDATE ON saved_queries FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION dl_saved_queries_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    NEW.sql_sha256 := encode(sha256(convert_to(NEW.sql, 'UTF8')), 'hex');
    IF NEW.verified_at IS NOT NULL AND NEW.verified_sql_sha256 IS DISTINCT FROM NEW.sql_sha256 THEN
        NEW.verified_at := NULL; NEW.verified_by := NULL; NEW.verified_sql_sha256 := NULL;
        NEW.unverified_reason := 'the SQL changed after it was verified';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER saved_queries_before BEFORE INSERT OR UPDATE ON saved_queries FOR EACH ROW EXECUTE FUNCTION dl_saved_queries_before();
CREATE OR REPLACE FUNCTION dl_saved_query_verify(p_id bigint, p_verify boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE q saved_queries%ROWTYPE;
BEGIN
    IF NOT dl_has_right('queries.verify') THEN RAISE EXCEPTION 'Verifying a query is a Data engineer''s' USING ERRCODE = 'insufficient_privilege'; END IF;
    SELECT * INTO q FROM saved_queries WHERE id = p_id AND deleted_at IS NULL FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such saved query' USING ERRCODE = 'no_data_found'; END IF;
    IF p_verify THEN
        UPDATE saved_queries SET verified_by = app_current_member_id(), verified_at = now(), verified_sql_sha256 = q.sql_sha256, unverified_reason = NULL WHERE id = p_id;
    ELSE
        UPDATE saved_queries SET verified_by = NULL, verified_at = NULL, verified_sql_sha256 = NULL, unverified_reason = 'unverified by a person' WHERE id = p_id;
    END IF;
END$$;

-- METRICS — a small semantic layer (§6.5, D13): an aggregate defined once over a product, cut by the dimensions it allows.
CREATE TABLE metrics (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name             text NOT NULL UNIQUE CHECK (name ~ '^[a-z][a-z0-9_]{0,62}$'),
    title            text NOT NULL,
    description      text,
    dataset_id       bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    expression       text NOT NULL CHECK (length(expression) BETWEEN 1 AND 1000),   -- an aggregate: sum(amount), count(DISTINCT customer_id)
    time_column      text,
    grains           text[] NOT NULL DEFAULT '{month}' CHECK (grains <@ ARRAY['day', 'week', 'month', 'quarter', 'year']::text[]),
    dimensions       text[] NOT NULL DEFAULT '{}',
    filters          text,                                                -- a fixed predicate
    format           text NOT NULL DEFAULT 'number' CHECK (format IN ('number', 'currency', 'percent', 'integer')),
    unit             text,
    owner_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    certified        boolean NOT NULL DEFAULT false,
    certified_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    certified_at     timestamptz,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    retired_at       timestamptz,
    CHECK (certified = (certified_at IS NOT NULL))
);
CREATE INDEX metrics_dataset_idx ON metrics (dataset_id) WHERE retired_at IS NULL;
CREATE INDEX metrics_title_trgm_idx ON metrics USING gin (title gin_trgm_ops);
CREATE TRIGGER metrics_touch BEFORE UPDATE ON metrics FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- A metric is over a product; changing its definition drops its certification (like a verified query's).
CREATE OR REPLACE FUNCTION dl_metrics_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF (SELECT zone FROM datasets WHERE id = NEW.dataset_id) <> 'product' THEN
        RAISE EXCEPTION 'A metric is defined over a product dataset' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.certified AND (NEW.expression, NEW.time_column, NEW.filters, NEW.dataset_id, NEW.dimensions)
                                              IS DISTINCT FROM (OLD.expression, OLD.time_column, OLD.filters, OLD.dataset_id, OLD.dimensions) THEN
        NEW.certified := false; NEW.certified_at := NULL; NEW.certified_by := NULL;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER metrics_before BEFORE INSERT OR UPDATE ON metrics FOR EACH ROW EXECUTE FUNCTION dl_metrics_before();

-- USAGE (R7): rolled up nightly from query_runs — the popularity in search, "frequent users", Tier "unused", the datasets read together.
CREATE TABLE dataset_usage_daily (
    dataset_id      bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    day             date NOT NULL,
    queries         integer NOT NULL DEFAULT 0,
    people          integer NOT NULL DEFAULT 0,
    agents          integer NOT NULL DEFAULT 0,
    rows_read       bigint NOT NULL DEFAULT 0,
    PRIMARY KEY (dataset_id, day)
);
CREATE TABLE dataset_joins (
    dataset_id      bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    other_id        bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    times           integer NOT NULL DEFAULT 0,
    last_at         timestamptz NOT NULL,
    PRIMARY KEY (dataset_id, other_id),
    CHECK (dataset_id <> other_id)
);
CREATE OR REPLACE FUNCTION dl_usage_rollup(p_day date DEFAULT (now() - interval '1 day')::date) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    INSERT INTO dataset_usage_daily (dataset_id, day, queries, people, agents, rows_read)
    SELECT ds, p_day, count(*), count(DISTINCT q.caller_member_id) FILTER (WHERE m.member_kind IS DISTINCT FROM 'agent'),
           count(DISTINCT q.caller_member_id) FILTER (WHERE m.member_kind = 'agent'), sum(q.rows_returned)
      FROM query_runs q CROSS JOIN LATERAL unnest(q.dataset_ids) ds LEFT JOIN members m ON m.id = q.caller_member_id
     WHERE q.status = 'ok' AND q.created_at >= p_day AND q.created_at < p_day + 1 AND q.source <> 'transform'
       AND EXISTS (SELECT 1 FROM datasets d WHERE d.id = ds)
     GROUP BY ds
    ON CONFLICT (dataset_id, day) DO UPDATE SET queries = EXCLUDED.queries, people = EXCLUDED.people, agents = EXCLUDED.agents, rows_read = EXCLUDED.rows_read;
    GET DIAGNOSTICS n = ROW_COUNT;
    INSERT INTO dataset_joins (dataset_id, other_id, times, last_at)
    SELECT a, b, count(*), max(q.created_at)
      FROM query_runs q CROSS JOIN LATERAL unnest(q.dataset_ids) a CROSS JOIN LATERAL unnest(q.dataset_ids) b
     WHERE q.status = 'ok' AND q.created_at >= p_day AND q.created_at < p_day + 1 AND a <> b
       AND EXISTS (SELECT 1 FROM datasets d WHERE d.id = a) AND EXISTS (SELECT 1 FROM datasets d WHERE d.id = b)
     GROUP BY a, b
    ON CONFLICT (dataset_id, other_id) DO UPDATE SET times = dataset_joins.times + EXCLUDED.times, last_at = GREATEST(dataset_joins.last_at, EXCLUDED.last_at);
    -- Tier "unused": no query for unused_after_days (and it is not new); a used dataset leaves "unused" for tier 3
    UPDATE datasets d SET tier = 'unused'
     WHERE d.tier <> 'unused' AND d.status = 'active' AND d.created_at < now() - make_interval(days => (SELECT unused_after_days FROM dl_settings WHERE id = 1))
       AND NOT EXISTS (SELECT 1 FROM dataset_usage_daily u WHERE u.dataset_id = d.id
                        AND u.day >= (now() - make_interval(days => (SELECT unused_after_days FROM dl_settings WHERE id = 1)))::date);
    UPDATE datasets d SET tier = '3'
     WHERE d.tier = 'unused' AND EXISTS (SELECT 1 FROM dataset_usage_daily u WHERE u.dataset_id = d.id AND u.day >= (now() - interval '7 days')::date);
    RETURN n;
END$$;
-- The usage a search ranks by: queries in the last 30 days.
CREATE OR REPLACE FUNCTION dl_usage_30d(p_dataset_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(sum(queries), 0)::integer FROM dataset_usage_daily WHERE dataset_id = p_dataset_id AND day >= (now() - interval '30 days')::date;
$$;

GRANT SELECT ON query_runs TO datalake_rw;
GRANT SELECT, INSERT, UPDATE ON saved_queries, metrics TO datalake_rw;
GRANT SELECT ON dataset_usage_daily, dataset_joins TO datalake_rw;
GRANT SELECT ON saved_queries, metrics TO datalake_lake;   -- the query service reads no application table: the caller resolves a saved query's SQL
GRANT EXECUTE ON FUNCTION dl_usage_30d(bigint) TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_query_run_log(jsonb), dl_query_runs_prune(), dl_saved_query_verify(bigint, boolean), dl_usage_rollup(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_query_run_log(jsonb) TO datalake_query, datalake_rw;
GRANT EXECUTE ON FUNCTION dl_query_runs_prune(), dl_usage_rollup(date) TO datalake_rw, datalake_lake;
GRANT EXECUTE ON FUNCTION dl_saved_query_verify(bigint, boolean) TO datalake_rw;

COMMIT;
