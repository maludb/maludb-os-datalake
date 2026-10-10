-- 010: TRUST — contracts, checks and their results, incidents, and CERTIFICATION computed, earned and lost (design §6.4, D9).
-- New canonical (SI's `signal_rules` / `signals` compared: the same family — a rule raises a thing a person disposes of — about a
-- different subject; recorded as siblings in the plugin's catalogue).
--
-- The vocabulary is ODCS v3.2.0's (library metrics nullValues / missingValues / invalidValues / duplicateValues / rowCount; dimensions
-- accuracy, completeness, conformity, consistency, coverage, timeliness, uniqueness) with Great Expectations' and Soda's names mapped onto
-- it — nothing installed. A check's result keeps at most twenty failing KEYS, hashed when the key is personal, never a value.
BEGIN;

CREATE TABLE contracts (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id       bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    version          text NOT NULL CHECK (version ~ '^[0-9]+\.[0-9]+\.[0-9]+$'),
    status           text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'deprecated')),
    schema           jsonb NOT NULL CHECK (jsonb_typeof(schema) = 'array'),   -- [{name, logical_type, physical_type, required, unique, primary_key, classification, personal_kind, description, critical}]
    key              text[] NOT NULL DEFAULT '{}',
    ordering_column  text,
    sla              jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(sla) = 'object'),   -- {freshness: "P1D", retention: "P2Y", time_to_detect: "PT1H", time_to_notify: "PT1H", time_to_repair: "P1D"}
    description      jsonb NOT NULL DEFAULT '{}'::jsonb,                    -- ODCS description {purpose, usage, limitations} at publication
    breaking         boolean NOT NULL DEFAULT false,                         -- against the previous active version
    breaking_changes jsonb NOT NULL DEFAULT '[]'::jsonb,
    published_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    published_at     timestamptz,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    UNIQUE (dataset_id, version),
    CHECK ((status = 'draft') = (published_at IS NULL))
);
CREATE UNIQUE INDEX contracts_active_one ON contracts (dataset_id) WHERE status = 'active';
CREATE TRIGGER contracts_touch BEFORE UPDATE ON contracts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- A published contract is frozen (a change is a new version).
CREATE OR REPLACE FUNCTION dl_contracts_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF OLD.status <> 'draft' AND (NEW.schema IS DISTINCT FROM OLD.schema OR NEW.key IS DISTINCT FROM OLD.key OR NEW.sla IS DISTINCT FROM OLD.sla
                                   OR NEW.version IS DISTINCT FROM OLD.version) THEN
        RAISE EXCEPTION 'A published contract is frozen — draft a new version' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER contracts_guard BEFORE UPDATE ON contracts FOR EACH ROW EXECUTE FUNCTION dl_contracts_guard();

-- Breaking against the active version: a column removed or retyped, the key changed (§6.4). Returns the list; empty = not breaking.
CREATE OR REPLACE FUNCTION dl_contract_breaking_changes(p_draft_id bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH dr AS (SELECT * FROM contracts WHERE id = p_draft_id),
         ac AS (SELECT c.* FROM contracts c JOIN dr ON dr.dataset_id = c.dataset_id WHERE c.status = 'active'),
         old_cols AS (SELECT e ->> 'name' AS name, e ->> 'physical_type' AS t FROM ac, jsonb_array_elements(ac.schema) e),
         new_cols AS (SELECT e ->> 'name' AS name, e ->> 'physical_type' AS t FROM dr, jsonb_array_elements(dr.schema) e)
    SELECT COALESCE(jsonb_agg(x), '[]'::jsonb) FROM (
        SELECT jsonb_build_object('column', o.name, 'kind', 'removed') AS x FROM old_cols o WHERE NOT EXISTS (SELECT 1 FROM new_cols n WHERE n.name = o.name)
        UNION ALL
        SELECT jsonb_build_object('column', o.name, 'kind', 'retyped', 'from', o.t, 'to', n.t) FROM old_cols o JOIN new_cols n ON n.name = o.name WHERE n.t IS DISTINCT FROM o.t
        UNION ALL
        SELECT jsonb_build_object('kind', 'key_changed', 'from', to_jsonb(ac.key), 'to', to_jsonb(dr.key)) FROM ac, dr WHERE ac.key IS DISTINCT FROM dr.key
    ) s;
$$;

-- Publish a draft: breaking is computed (a breaking change must be a new MAJOR version); the previous active is deprecated; the dataset
-- records the version. Pauses for an agent when breaking (contract_publish, §5) — the kernel's hook, before this is reached.
CREATE OR REPLACE FUNCTION dl_contract_publish(p_draft_id bigint) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE dr contracts%ROWTYPE; ac contracts%ROWTYPE; br jsonb; me bigint := app_current_member_id();
BEGIN
    SELECT * INTO dr FROM contracts WHERE id = p_draft_id FOR UPDATE;
    IF NOT FOUND OR dr.status <> 'draft' THEN RAISE EXCEPTION 'Only a draft is published' USING ERRCODE = 'check_violation'; END IF;
    IF NOT dl_has_right('contracts.manage') THEN RAISE EXCEPTION 'Publishing a contract is a Data engineer''s' USING ERRCODE = 'insufficient_privilege'; END IF;
    SELECT * INTO ac FROM contracts WHERE dataset_id = dr.dataset_id AND status = 'active' FOR UPDATE;
    br := dl_contract_breaking_changes(p_draft_id);
    IF ac.id IS NOT NULL AND jsonb_array_length(br) > 0
       AND split_part(dr.version, '.', 1)::int <= split_part(ac.version, '.', 1)::int THEN
        RAISE EXCEPTION 'A breaking change (%) is a new major version — % is not above %', br, dr.version, ac.version USING ERRCODE = 'check_violation';
    END IF;
    IF ac.id IS NOT NULL THEN UPDATE contracts SET status = 'deprecated' WHERE id = ac.id; END IF;
    UPDATE contracts SET status = 'active', published_by = me, published_at = now(), breaking = jsonb_array_length(br) > 0, breaking_changes = br
     WHERE id = dr.id;
    UPDATE datasets SET contract_version = dr.version, dedupe_key = dr.key, ordering_column = dr.ordering_column WHERE id = dr.dataset_id;
    PERFORM dl_certification_recheck(dr.dataset_id);
    RETURN jsonb_build_object('contract_id', dr.id, 'version', dr.version, 'breaking', br);
END$$;

CREATE TABLE checks (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id   bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    column_name  text,
    kind         text NOT NULL CHECK (kind IN ('not_null', 'unique', 'accepted_values', 'range', 'regex', 'row_count', 'freshness', 'referential', 'schema', 'custom_sql')),
    params       jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(params) = 'object'),   -- accepted_values {values}, range {min, max}, regex {pattern}, row_count {min, max, change_pct}, referential {dataset_key, column}, custom_sql {sql}
    dimension    text NOT NULL CHECK (dimension IN ('accuracy', 'completeness', 'conformity', 'consistency', 'coverage', 'timeliness', 'uniqueness', 'validity')),
    severity     text NOT NULL DEFAULT 'error' CHECK (severity IN ('warn', 'error')),
    blocking     boolean NOT NULL DEFAULT false,                         -- row-level: failing rows quarantined; table-level: the promotion held
    run_when     text NOT NULL DEFAULT 'on_load' CHECK (run_when IN ('on_load', 'hourly', 'daily')),
    description  text,
    source       text NOT NULL DEFAULT 'person' CHECK (source IN ('person', 'agent', 'template', 'contract')),
    enabled      boolean NOT NULL DEFAULT true,
    created_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (kind IN ('row_count', 'freshness', 'schema', 'custom_sql', 'referential') OR column_name IS NOT NULL),
    CHECK (kind <> 'custom_sql' OR (params ? 'sql' AND length(params ->> 'sql') <= 4000)),
    CHECK (NOT blocking OR severity = 'error')
);
CREATE INDEX checks_dataset_idx ON checks (dataset_id) WHERE enabled;
CREATE TRIGGER checks_touch BEFORE UPDATE ON checks FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
-- An agent creates only non-blocking checks (§5): a blocking one holds a promotion — a person's decision.
CREATE OR REPLACE FUNCTION dl_checks_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.blocking AND EXISTS (SELECT 1 FROM members WHERE id = app_current_member_id() AND member_kind = 'agent') THEN
        RAISE EXCEPTION 'An agent proposes a blocking check; a person makes it blocking' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF NEW.blocking AND NEW.source = 'agent' THEN NEW.blocking := false; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER checks_guard BEFORE INSERT OR UPDATE OF blocking ON checks FOR EACH ROW EXECUTE FUNCTION dl_checks_guard();

CREATE TABLE check_results (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    check_id      bigint NOT NULL REFERENCES checks(id) ON DELETE CASCADE,
    dataset_id    bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    load_id       bigint REFERENCES loads(id) ON DELETE SET NULL,
    snapshot_id   bigint,
    value         numeric,                                                -- the measured metric (a null count, a row count, a freshness in hours)
    threshold     text,
    passed        boolean NOT NULL,
    rows_failed   bigint NOT NULL DEFAULT 0 CHECK (rows_failed >= 0),
    failing_keys  jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(failing_keys) = 'array' AND jsonb_array_length(failing_keys) <= 20),
    took_ms       integer,
    error         text CHECK (error IS NULL OR length(error) <= 500),    -- the check itself could not run
    ran_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX check_results_check_idx ON check_results (check_id, ran_at DESC);
CREATE INDEX check_results_dataset_idx ON check_results (dataset_id, ran_at DESC);
CREATE INDEX check_results_age_idx ON check_results (ran_at);

CREATE TABLE incidents (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id          bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    kind                text NOT NULL CHECK (kind IN ('check', 'freshness', 'load', 'schema')),
    check_id            bigint REFERENCES checks(id) ON DELETE SET NULL,
    load_id             bigint REFERENCES loads(id) ON DELETE SET NULL,
    opened_by_result_id bigint REFERENCES check_results(id) ON DELETE SET NULL,
    resolved_by_result_id bigint REFERENCES check_results(id) ON DELETE SET NULL,
    severity            text NOT NULL CHECK (severity IN ('warn', 'error')),
    status              text NOT NULL DEFAULT 'new' CHECK (status IN ('new', 'acknowledged', 'investigating', 'resolved', 'dismissed')),
    title               text NOT NULL,
    assignee_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    opened_at           timestamptz NOT NULL DEFAULT now(),
    acknowledged_at     timestamptz,
    resolved_at         timestamptz,
    root_cause          text,
    resolution          text,
    downstream          jsonb NOT NULL DEFAULT '[]'::jsonb,                  -- the dataset keys lineage says it reaches (db/012), badged while open
    updated_at          timestamptz NOT NULL DEFAULT now(),
    CHECK ((status IN ('resolved', 'dismissed')) = (resolved_at IS NOT NULL))
);
CREATE UNIQUE INDEX incidents_open_one ON incidents (dataset_id, kind, COALESCE(check_id, 0)) WHERE status IN ('new', 'acknowledged', 'investigating');
CREATE INDEX incidents_open_idx ON incidents (status, opened_at DESC) WHERE status IN ('new', 'acknowledged', 'investigating');
CREATE INDEX incidents_assignee_idx ON incidents (assignee_member_id) WHERE status IN ('new', 'acknowledged', 'investigating');
CREATE TRIGGER incidents_touch BEFORE UPDATE ON incidents FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE incident_notes (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    incident_id  bigint NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
    member_id    bigint REFERENCES members(id) ON DELETE SET NULL,
    body         text NOT NULL CHECK (length(body) BETWEEN 1 AND 8000),
    kind         text NOT NULL DEFAULT 'note' CHECK (kind IN ('note', 'triage', 'status')),   -- triage: the Steward's (§5)
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX incident_notes_incident_idx ON incident_notes (incident_id, created_at);

-- The writer records a result: a failure opens (or keeps) the incident for the dataset and check; a pass resolves it, citing the run (R20).
CREATE OR REPLACE FUNCTION dl_check_result_record(p_check_id bigint, p_load_id bigint, p_snapshot_id bigint, p_value numeric, p_threshold text,
                                                  p_passed boolean, p_rows_failed bigint, p_failing_keys jsonb, p_took_ms integer, p_error text DEFAULT NULL)
    RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c checks%ROWTYPE; rid bigint; inc bigint; d datasets%ROWTYPE; who bigint;
BEGIN
    SELECT * INTO c FROM checks WHERE id = p_check_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such check' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO d FROM datasets WHERE id = c.dataset_id;
    INSERT INTO check_results (check_id, dataset_id, load_id, snapshot_id, value, threshold, passed, rows_failed, failing_keys, took_ms, error)
    VALUES (c.id, c.dataset_id, p_load_id, p_snapshot_id, p_value, p_threshold, p_passed, COALESCE(p_rows_failed, 0),
            COALESCE(p_failing_keys, '[]'::jsonb), p_took_ms, p_error)
    RETURNING id INTO rid;
    SELECT id INTO inc FROM incidents WHERE dataset_id = c.dataset_id AND kind = CASE WHEN c.kind = 'freshness' THEN 'freshness' ELSE 'check' END
       AND COALESCE(check_id, 0) = c.id AND status IN ('new', 'acknowledged', 'investigating');
    IF NOT p_passed AND inc IS NULL THEN
        INSERT INTO incidents (dataset_id, kind, check_id, load_id, opened_by_result_id, severity, title, downstream, assignee_member_id)
        VALUES (c.dataset_id, CASE WHEN c.kind = 'freshness' THEN 'freshness' ELSE 'check' END, c.id, p_load_id, rid, c.severity,
                d.key || ': ' || c.kind || COALESCE(' on ' || c.column_name, '') || ' failed',
                COALESCE(dl_downstream_keys(c.dataset_id), '[]'::jsonb), COALESCE(d.steward_member_id, d.owner_member_id))
        RETURNING id INTO inc;
        FOR who IN SELECT DISTINCT x FROM unnest(ARRAY[d.owner_member_id, d.steward_member_id]) x WHERE x IS NOT NULL LOOP
            PERFORM dl_notify(who, 'check_failed', d.title || ': a check failed', d.key || ' — ' || c.kind || COALESCE(' on ' || c.column_name, ''),
                              'incident', inc, d.id, NULL, 'incident_opened:' || inc || ':' || who);
        END LOOP;
    ELSIF p_passed AND inc IS NOT NULL THEN
        UPDATE incidents SET status = 'resolved', resolved_at = now(), resolved_by_result_id = rid,
               resolution = COALESCE(resolution, 'A passing run resolved it (result ' || rid || ')') WHERE id = inc;
    END IF;
    PERFORM dl_certification_recheck(c.dataset_id);
    RETURN rid;
END$$;

-- CERTIFICATION (§6.4): what an eligible dataset has — an owner and a steward; a description and every live column described; at least one
-- enabled blocking check; every enabled check's latest result passing; fresh (not past freshness_error_after); an active contract; no open
-- incident of severity error. Returns the missing conditions; empty = eligible.
CREATE OR REPLACE FUNCTION dl_certification_eligible(p_dataset_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; missing text[] := '{}';
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL THEN RETURN ARRAY['no such dataset']; END IF;
    IF d.zone = 'sandbox' THEN RETURN ARRAY['a sandbox dataset is never certified']; END IF;
    IF d.owner_member_id IS NULL THEN missing := array_append(missing, 'an owner'); END IF;
    IF d.steward_member_id IS NULL THEN missing := array_append(missing, 'a steward'); END IF;
    IF d.description IS NULL OR length(trim(d.description)) < 20 THEN missing := array_append(missing, 'a description'); END IF;
    IF EXISTS (SELECT 1 FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND NOT c.is_system
                  AND (c.description IS NULL OR c.description_source = 'agent_proposed')) THEN
        missing := array_append(missing, 'every column described');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM checks k WHERE k.dataset_id = d.id AND k.enabled AND k.blocking) THEN missing := array_append(missing, 'a blocking check'); END IF;
    IF EXISTS (SELECT 1 FROM checks k WHERE k.dataset_id = d.id AND k.enabled
                  AND NOT COALESCE((SELECT r.passed FROM check_results r WHERE r.check_id = k.id ORDER BY r.ran_at DESC, r.id DESC LIMIT 1), false)) THEN
        missing := array_append(missing, 'every check passing');
    END IF;
    IF d.freshness_error_after IS NOT NULL AND (d.data_updated_at IS NULL OR d.data_updated_at < now() - d.freshness_error_after) THEN
        missing := array_append(missing, 'fresh data');
    END IF;
    IF NOT EXISTS (SELECT 1 FROM contracts k WHERE k.dataset_id = d.id AND k.status = 'active') THEN missing := array_append(missing, 'an active contract'); END IF;
    IF EXISTS (SELECT 1 FROM incidents i WHERE i.dataset_id = d.id AND i.severity = 'error' AND i.status IN ('new', 'acknowledged', 'investigating')) THEN
        missing := array_append(missing, 'no open error incident');
    END IF;
    IF d.status <> 'active' THEN missing := array_append(missing, 'status active'); END IF;
    RETURN missing;
END$$;

-- An engineer certifies an eligible dataset (an agent's certify pauses in the kernel first, §5); an owner may set `promoted`.
CREATE OR REPLACE FUNCTION dl_certify(p_dataset_id bigint, p_level text DEFAULT 'certified') RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE missing text[] := dl_certification_eligible(p_dataset_id); me bigint := app_current_member_id(); d datasets%ROWTYPE;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id FOR UPDATE;
    IF p_level = 'certified' AND NOT dl_has_right('checks.manage') THEN
        RAISE EXCEPTION 'Certifying is a Data engineer''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_level = 'promoted' AND NOT (COALESCE(d.owner_member_id = me, false) OR dl_has_right('checks.manage')) THEN
        RAISE EXCEPTION 'Promoting is the owner''s or a Data engineer''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF cardinality(missing) > 0 THEN
        RAISE EXCEPTION 'Not eligible — it lacks: %', array_to_string(missing, ', ') USING ERRCODE = 'check_violation';
    END IF;
    UPDATE datasets SET certification = p_level, certified_by = me, certified_at = CASE WHEN p_level = 'certified' THEN now() ELSE certified_at END,
           certification_lost_reason = NULL, certification_lost_at = NULL
     WHERE id = p_dataset_id;
END$$;

-- Certification is LOST BY ITSELF when a condition stops holding (§6.4) — the reason recorded, the followers told.
CREATE OR REPLACE FUNCTION dl_certification_recheck(p_dataset_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; missing text[]; f record;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL OR d.certification = 'none' THEN RETURN false; END IF;
    missing := dl_certification_eligible(p_dataset_id);
    IF cardinality(missing) = 0 THEN RETURN false; END IF;
    UPDATE datasets SET certification = 'none', certified_at = NULL, certification_lost_reason = 'lacks: ' || array_to_string(missing, ', '),
           certification_lost_at = now()
     WHERE id = p_dataset_id;
    FOR f IN SELECT member_id FROM dataset_follows WHERE dataset_id = p_dataset_id AND 'certification_lost' = ANY (what)
             UNION SELECT d.owner_member_id WHERE d.owner_member_id IS NOT NULL LOOP
        PERFORM dl_notify(f.member_id, 'certification_lost', d.title || ' lost its certification', 'It lacks: ' || array_to_string(missing, ', '),
                          'dataset', d.id, d.id, NULL, 'cert_lost:' || d.id || ':' || f.member_id || ':' || to_char(now(), 'YYYYMMDDHH24MI'));
    END LOOP;
    RETURN true;
END$$;
-- What a person changes on the entry can cost the badge too (a description emptied, an owner removed, a column undescribed).
CREATE OR REPLACE FUNCTION dl_datasets_recheck_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.certification <> 'none' THEN PERFORM dl_certification_recheck(NEW.id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER datasets_recheck_after AFTER UPDATE OF owner_member_id, steward_member_id, description, status, freshness_error_after, data_updated_at ON datasets
    FOR EACH ROW EXECUTE FUNCTION dl_datasets_recheck_after();
CREATE OR REPLACE FUNCTION dl_columns_recheck_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM dl_certification_recheck(NEW.dataset_id);
    RETURN NULL;
END$$;
CREATE TRIGGER dataset_columns_recheck_after AFTER INSERT OR UPDATE OF description, description_source, removed_snapshot_id ON dataset_columns
    FOR EACH ROW EXECUTE FUNCTION dl_columns_recheck_after();
CREATE OR REPLACE FUNCTION dl_incidents_recheck_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM dl_certification_recheck(NEW.dataset_id);
    RETURN NULL;
END$$;
CREATE TRIGGER incidents_recheck_after AFTER INSERT ON incidents FOR EACH ROW EXECUTE FUNCTION dl_incidents_recheck_after();

-- Datasets past their freshness expectation (the worker's hourly pass records a freshness result for each; R13).
CREATE OR REPLACE FUNCTION dl_freshness_due() RETURNS TABLE (dataset_id bigint, key text, state text, data_updated_at timestamptz, overdue interval)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.key,
           CASE WHEN d.freshness_error_after IS NOT NULL AND (d.data_updated_at IS NULL OR d.data_updated_at < now() - d.freshness_error_after) THEN 'error'
                ELSE 'warn' END,
           d.data_updated_at, now() - d.data_updated_at
      FROM datasets d
     WHERE d.status IN ('active', 'deprecated') AND d.freshness_warn_after IS NOT NULL
       AND (d.data_updated_at IS NULL OR d.data_updated_at < now() - d.freshness_warn_after);
$$;

GRANT SELECT, INSERT, UPDATE ON contracts, checks, incidents TO datalake_rw;
GRANT SELECT, INSERT ON incident_notes TO datalake_rw;
GRANT SELECT ON check_results TO datalake_rw;
GRANT SELECT ON contracts, checks TO datalake_lake;
GRANT SELECT, INSERT ON check_results TO datalake_lake;
GRANT DELETE ON check_results TO datalake_lake;
GRANT SELECT, UPDATE ON incidents TO datalake_lake;
GRANT EXECUTE ON FUNCTION dl_contract_breaking_changes(bigint), dl_certification_eligible(bigint), dl_freshness_due()
    TO datalake_rw, datalake_records_ro, datalake_activity_ro, datalake_lake;
REVOKE ALL ON FUNCTION dl_contract_publish(bigint), dl_certify(bigint, text), dl_certification_recheck(bigint),
    dl_check_result_record(bigint, bigint, bigint, numeric, text, boolean, bigint, jsonb, integer, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_contract_publish(bigint), dl_certify(bigint, text) TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_certification_recheck(bigint) TO datalake_rw, datalake_lake;
GRANT EXECUTE ON FUNCTION dl_check_result_record(bigint, bigint, bigint, numeric, text, boolean, bigint, jsonb, integer, text) TO datalake_lake, datalake_rw;

COMMIT;
