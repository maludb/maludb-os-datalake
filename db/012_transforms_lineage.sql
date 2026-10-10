-- 012: TRANSFORMS, PRODUCTS and LINEAGE (design §6.6). All new canonical ("the estate's lineage": nothing in the estate records what was made
-- from what; Sales CRM's `pipelines`, ProcessCore's `runs` and Knowledge's `builds` compared and their names avoided).
--
-- A transform runs with its owner's read level FROZEN AT SAVE (the policy compiled by dl_query_policy for its inputs, stored on the row) —
-- so a product never quietly widens what it exposes when someone's access changes later. Lineage is recorded by the machine on every load,
-- promotion, transform, export, erasure and maintenance pass, in OpenLineage's shape (run / job / dataset, facets; a DuckLake snapshot id is
-- a dataset's `version`).
BEGIN;

CREATE TABLE transforms (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name               text NOT NULL UNIQUE CHECK (name ~ '^[a-z][a-z0-9_]{0,62}$'),
    description        text,
    output_dataset_id  bigint NOT NULL UNIQUE REFERENCES datasets(id) ON DELETE CASCADE,   -- one transform makes one dataset
    sql                text NOT NULL CHECK (length(sql) BETWEEN 1 AND 50000),           -- one SELECT over dataset keys (the allow-list's dialect)
    sql_sha256         text NOT NULL DEFAULT '',
    input_dataset_ids  bigint[] NOT NULL DEFAULT '{}',                                  -- from the parse (the query service)
    mode               text NOT NULL DEFAULT 'replace' CHECK (mode IN ('replace', 'incremental_merge')),
    schedule           text,                                                             -- a cron expression, or 'after_inputs'
    version            integer NOT NULL DEFAULT 1 CHECK (version >= 1),
    owner_member_id    bigint NOT NULL REFERENCES members(id) ON DELETE RESTRICT,
    frozen_policy      jsonb,                                                            -- dl_query_policy(owner, NULL, inputs, 'transform') at save
    frozen_at          timestamptz,
    enabled            boolean NOT NULL DEFAULT true,
    last_run_id        bigint,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (schedule IS NULL OR schedule = 'after_inputs' OR schedule ~ '^[0-9*/,\- ]+$')
);
CREATE TRIGGER transforms_touch BEFORE UPDATE ON transforms FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- A transform writes a curated, product or sandbox dataset — never raw (raw is what a source lands); a sandbox transform writes its owner's
-- sandbox; a change of SQL is a new version and refreezes the policy (the handler calls dl_transform_freeze).
CREATE OR REPLACE FUNCTION dl_transforms_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = NEW.output_dataset_id;
    IF d.zone = 'raw' THEN RAISE EXCEPTION 'A transform writes a curated, product or sandbox dataset, never raw' USING ERRCODE = 'check_violation'; END IF;
    IF d.zone = 'sandbox' AND d.owner_member_id <> NEW.owner_member_id THEN
        RAISE EXCEPTION 'A sandbox transform writes its owner''s own sandbox' USING ERRCODE = 'check_violation';
    END IF;
    NEW.sql_sha256 := encode(sha256(convert_to(NEW.sql, 'UTF8')), 'hex');
    IF TG_OP = 'UPDATE' AND NEW.sql_sha256 <> OLD.sql_sha256 THEN
        NEW.version := OLD.version + 1;
        NEW.frozen_policy := NULL; NEW.frozen_at := NULL;          -- must be refrozen before it runs
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER transforms_before BEFORE INSERT OR UPDATE ON transforms FOR EACH ROW EXECUTE FUNCTION dl_transforms_before();
-- Freeze the owner's read level over the inputs; refused when the owner may not read one of them.
CREATE OR REPLACE FUNCTION dl_transform_freeze(p_transform_id bigint, p_input_keys text[]) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t transforms%ROWTYPE; pol jsonb;
BEGIN
    SELECT * INTO t FROM transforms WHERE id = p_transform_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transform' USING ERRCODE = 'no_data_found'; END IF;
    pol := dl_query_policy(t.owner_member_id, NULL, p_input_keys, 'transform');
    IF pol -> 'refused' <> '{}'::jsonb THEN
        RAISE EXCEPTION 'The owner may not read: %', (SELECT string_agg(k, ', ') FROM jsonb_object_keys(pol -> 'refused') k) USING ERRCODE = 'insufficient_privilege';
    END IF;
    UPDATE transforms SET frozen_policy = pol, frozen_at = now(),
           input_dataset_ids = ARRAY(SELECT d.id FROM datasets d WHERE d.key = ANY (p_input_keys) ORDER BY d.id)
     WHERE id = p_transform_id;
    -- a SANDBOX output keeps the highest classification of what it was made from (§3 rule 6: no laundering a mask by saving a result);
    -- a curated or product output is classified by its own columns (a product that drops the personal columns is not confidential)
    UPDATE datasets SET classification_floor = dl_class_of_rank((SELECT COALESCE(max(dl_class_rank(i.classification)), 0)::smallint
                                                                   FROM datasets i WHERE i.key = ANY (p_input_keys)))
     WHERE id = t.output_dataset_id AND zone = 'sandbox';
    RETURN pol;
END$$;

CREATE TABLE transform_runs (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transform_id    bigint NOT NULL REFERENCES transforms(id) ON DELETE CASCADE,
    version         integer NOT NULL,
    status          text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'ok', 'failed', 'no_op')),
    input_snapshots jsonb NOT NULL DEFAULT '{}'::jsonb,           -- {key: snapshot_id}
    output_snapshot bigint,
    rows_written    bigint NOT NULL DEFAULT 0,
    error           text CHECK (error IS NULL OR length(error) <= 2000),
    requested_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    started_at      timestamptz,
    finished_at     timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX transform_runs_transform_idx ON transform_runs (transform_id, created_at DESC);
ALTER TABLE transforms ADD CONSTRAINT transforms_last_run_fk FOREIGN KEY (last_run_id) REFERENCES transform_runs(id) ON DELETE SET NULL;

-- OpenLineage's RunEvent, kept (R15)
CREATE TABLE lineage_runs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    run_uuid      uuid NOT NULL,                                 -- UUIDv7 (the writer's)
    job_kind      text NOT NULL CHECK (job_kind IN ('load', 'promote', 'transform', 'export', 'erasure', 'maintenance', 'query')),
    job_name      text NOT NULL,                                 -- 'load.raw_crm_deals', 'transform.monthly_revenue'
    event         text NOT NULL CHECK (event IN ('START', 'RUNNING', 'COMPLETE', 'ABORT', 'FAIL', 'OTHER')),
    event_time    timestamptz NOT NULL DEFAULT now(),
    inputs        jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(inputs) = 'array'),    -- [{namespace, name, version, facets}]
    outputs       jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(outputs) = 'array'),
    facets        jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(facets) = 'object'),   -- sql, nominalTime, errorMessage, dataQualityMetrics, inputStatistics, outputStatistics
    actor_member_id bigint,
    agent_run_id  bigint,
    load_id       bigint REFERENCES loads(id) ON DELETE SET NULL,
    transform_run_id bigint REFERENCES transform_runs(id) ON DELETE SET NULL,
    UNIQUE (run_uuid, event)
);
CREATE INDEX lineage_runs_time_idx ON lineage_runs (event_time DESC);
CREATE INDEX lineage_runs_job_idx ON lineage_runs (job_kind, job_name, event_time DESC);

-- The graph: from a source or a dataset to a dataset, via a load, a promotion, a transform or an export; column_map from sqlglot's lineage.
CREATE TABLE lineage_edges (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    from_dataset_id bigint REFERENCES datasets(id) ON DELETE CASCADE,
    from_source_id  bigint REFERENCES sources(id) ON DELETE CASCADE,
    to_dataset_id   bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    via             text NOT NULL CHECK (via IN ('load', 'promote', 'transform', 'export')),
    transform_id    bigint REFERENCES transforms(id) ON DELETE SET NULL,
    column_map      jsonb NOT NULL DEFAULT '{}'::jsonb,           -- {output_column: [{dataset, column, kind DIRECT|INDIRECT}]}
    first_seen      timestamptz NOT NULL DEFAULT now(),
    last_seen       timestamptz NOT NULL DEFAULT now(),
    CHECK (num_nonnulls(from_dataset_id, from_source_id) = 1),
    CHECK (from_dataset_id IS NULL OR from_dataset_id <> to_dataset_id)
);
CREATE UNIQUE INDEX lineage_edges_dataset_one ON lineage_edges (from_dataset_id, to_dataset_id, via) WHERE from_dataset_id IS NOT NULL;
CREATE UNIQUE INDEX lineage_edges_source_one ON lineage_edges (from_source_id, to_dataset_id, via) WHERE from_source_id IS NOT NULL;
CREATE INDEX lineage_edges_to_idx ON lineage_edges (to_dataset_id);

-- Record an edge (the writer, after every load, promotion and transform): first_seen kept, last_seen moved.
CREATE OR REPLACE FUNCTION dl_lineage_edge(p_from_dataset bigint, p_from_source bigint, p_to bigint, p_via text, p_transform bigint DEFAULT NULL,
                                           p_column_map jsonb DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_from_dataset IS NOT NULL THEN
        INSERT INTO lineage_edges (from_dataset_id, to_dataset_id, via, transform_id, column_map)
        VALUES (p_from_dataset, p_to, p_via, p_transform, COALESCE(p_column_map, '{}'::jsonb))
        ON CONFLICT (from_dataset_id, to_dataset_id, via) WHERE from_dataset_id IS NOT NULL
        DO UPDATE SET last_seen = now(), column_map = COALESCE(p_column_map, lineage_edges.column_map), transform_id = COALESCE(p_transform, lineage_edges.transform_id);
    ELSE
        INSERT INTO lineage_edges (from_source_id, to_dataset_id, via)
        VALUES (p_from_source, p_to, p_via)
        ON CONFLICT (from_source_id, to_dataset_id, via) WHERE from_source_id IS NOT NULL DO UPDATE SET last_seen = now();
    END IF;
END$$;

-- Walk the graph from a dataset, up (its inputs, to the sources) or down (what was made from it), to a depth (≤ 10). Each node once,
-- at its nearest depth. The caller's walls are applied by the readers (a node the caller may not discover is counted, unnamed).
CREATE OR REPLACE FUNCTION dl_lineage(p_dataset_id bigint, p_direction text DEFAULT 'up', p_depth integer DEFAULT 3)
    RETURNS TABLE (dataset_id bigint, source_id bigint, depth integer, via text, parent_dataset_id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH RECURSIVE walk(dataset_id, source_id, depth, via, parent_dataset_id, path) AS (
        SELECT CASE WHEN p_direction = 'up' THEN e.from_dataset_id ELSE e.to_dataset_id END,
               CASE WHEN p_direction = 'up' THEN e.from_source_id END,
               1, e.via, p_dataset_id, ARRAY[p_dataset_id]
          FROM lineage_edges e
         WHERE (p_direction = 'up' AND e.to_dataset_id = p_dataset_id) OR (p_direction = 'down' AND e.from_dataset_id = p_dataset_id)
        UNION ALL
        SELECT CASE WHEN p_direction = 'up' THEN e.from_dataset_id ELSE e.to_dataset_id END,
               CASE WHEN p_direction = 'up' THEN e.from_source_id END,
               w.depth + 1, e.via, w.dataset_id, w.path || w.dataset_id
          FROM walk w JOIN lineage_edges e
            ON (p_direction = 'up' AND e.to_dataset_id = w.dataset_id) OR (p_direction = 'down' AND e.from_dataset_id = w.dataset_id)
         WHERE w.dataset_id IS NOT NULL AND w.depth < LEAST(GREATEST(p_depth, 1), 10) AND NOT (w.dataset_id = ANY (w.path))
    )
    SELECT DISTINCT ON (COALESCE(dataset_id, -source_id)) dataset_id, source_id, depth, via, parent_dataset_id
      FROM walk ORDER BY COALESCE(dataset_id, -source_id), depth;
$$;
-- The keys downstream (an incident's reach, R20)
CREATE OR REPLACE FUNCTION dl_downstream_keys(p_dataset_id bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(jsonb_agg(d.key ORDER BY l.depth, d.key), '[]'::jsonb) FROM dl_lineage(p_dataset_id, 'down', 10) l JOIN datasets d ON d.id = l.dataset_id;
$$;
-- IMPACT (T5): every dataset, saved query, metric and K7 consumer downstream — shown before a breaking contract change is published.
CREATE OR REPLACE FUNCTION dl_impact(p_dataset_id bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH down AS (SELECT p_dataset_id AS id UNION SELECT l.dataset_id FROM dl_lineage(p_dataset_id, 'down', 10) l WHERE l.dataset_id IS NOT NULL)
    SELECT jsonb_build_object(
        'datasets', (SELECT COALESCE(jsonb_agg(jsonb_build_object('id', d.id, 'key', d.key) ORDER BY d.key), '[]'::jsonb) FROM datasets d WHERE d.id IN (SELECT id FROM down) AND d.id <> p_dataset_id),
        'saved_queries', (SELECT COALESCE(jsonb_agg(jsonb_build_object('id', q.id, 'title', q.title, 'verified', q.verified_at IS NOT NULL) ORDER BY q.id), '[]'::jsonb)
                            FROM saved_queries q WHERE q.deleted_at IS NULL AND q.dataset_ids && ARRAY(SELECT id FROM down)),
        'metrics', (SELECT COALESCE(jsonb_agg(jsonb_build_object('id', m.id, 'name', m.name, 'certified', m.certified) ORDER BY m.name), '[]'::jsonb)
                      FROM metrics m WHERE m.retired_at IS NULL AND m.dataset_id IN (SELECT id FROM down)),
        'consumers', (SELECT COALESCE(jsonb_agg(DISTINCT g.principal_ref), '[]'::jsonb) FROM grants g
                        WHERE g.principal_kind = 'application' AND g.revoked_at IS NULL AND g.target_kind = 'dataset' AND g.dataset_id IN (SELECT id FROM down)),
        'transforms', (SELECT COALESCE(jsonb_agg(jsonb_build_object('id', t.id, 'name', t.name) ORDER BY t.name), '[]'::jsonb)
                         FROM transforms t WHERE t.input_dataset_ids && ARRAY(SELECT id FROM down)));
$$;

GRANT SELECT, INSERT, UPDATE ON transforms, transform_runs TO datalake_rw;
GRANT SELECT ON lineage_runs, lineage_edges TO datalake_rw;
GRANT SELECT, UPDATE ON transforms TO datalake_lake;
GRANT SELECT, INSERT, UPDATE ON transform_runs, lineage_runs TO datalake_lake;
GRANT SELECT ON lineage_edges TO datalake_lake;
GRANT EXECUTE ON FUNCTION dl_lineage(bigint, text, integer), dl_downstream_keys(bigint), dl_impact(bigint)
    TO datalake_rw, datalake_records_ro, datalake_activity_ro, datalake_lake;
REVOKE ALL ON FUNCTION dl_transform_freeze(bigint, text[]), dl_lineage_edge(bigint, bigint, bigint, text, bigint, jsonb) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_transform_freeze(bigint, text[]) TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_lineage_edge(bigint, bigint, bigint, text, bigint, jsonb) TO datalake_lake, datalake_rw;

COMMIT;
