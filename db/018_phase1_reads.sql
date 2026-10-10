-- 018: Phase 1 — the reads the tool surface names (docs/datalake-mcp-tool-surface.md) that db/006–017 did not have, the policy's final
-- shape for the query service (docs/build-specs/query-service.md §3), and the Steward's settings. Additive (two functions re-created with
-- a new signature, dropped first; their grants re-applied).
--
-- THE POLICY (query-service §3): every dataset entry now carries its `classification` and `has_personal`; every refusal is an object
-- {rule, message} — rule ∈ no_such_dataset · discover_only · not_readable · k38_pending · not_shared; and a CEILING: an agent's chat turn
-- before the kernel says who asked (K38) passes p_ceiling = 'internal_unpersonal' and every dataset above `internal` or with a personal
-- column is refused `k38_pending` — fail closed, never open (design §3 rule 8, D8).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- The Steward's settings (agents_settings_save, slice 10)
-- ---------------------------------------------------------------------------------------------
ALTER TABLE dl_settings ADD COLUMN steward_member_id bigint REFERENCES members(id) ON DELETE SET NULL;
ALTER TABLE dl_settings ADD COLUMN steward_proposals_per_day integer NOT NULL DEFAULT 20 CHECK (steward_proposals_per_day BETWEEN 1 AND 100);
-- The Steward: the agent the settings name, else the first active agent whose name says steward (db/014's rule).
CREATE OR REPLACE FUNCTION dl_steward_agent() RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(
        (SELECT m.id FROM dl_settings s JOIN members m ON m.id = s.steward_member_id AND m.member_kind = 'agent' AND m.status = 'active' WHERE s.id = 1),
        (SELECT id FROM members WHERE member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL AND display_name ILIKE '%steward%' ORDER BY id LIMIT 1));
$$;

-- ---------------------------------------------------------------------------------------------
-- THE POLICY — final shape
-- ---------------------------------------------------------------------------------------------
DROP FUNCTION dl_query_policy(bigint, bigint, text[], text);
CREATE FUNCTION dl_query_policy(p_caller bigint, p_on_behalf_of bigint, p_keys text[], p_source text DEFAULT 'screen', p_ceiling text DEFAULT NULL)
    RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; d datasets%ROWTYPE; acc record; ds jsonb := '{}'::jsonb; refused jsonb := '{}'::jsonb; lim jsonb;
        timeout_s integer; row_limit integer;
BEGIN
    IF p_source NOT IN ('screen', 'preview', 'mcp', 'agent', 'api', 'k7', 'export', 'transform') THEN
        RAISE EXCEPTION 'Unknown query source %', p_source USING ERRCODE = 'check_violation';
    END IF;
    IF p_ceiling IS NOT NULL AND p_ceiling <> 'internal_unpersonal' THEN
        RAISE EXCEPTION 'Unknown ceiling %', p_ceiling USING ERRCODE = 'check_violation';
    END IF;
    FOREACH k IN ARRAY COALESCE(p_keys, '{}') LOOP
        SELECT * INTO d FROM datasets WHERE key = k;
        IF d.id IS NULL OR d.status = 'retired' THEN
            refused := refused || jsonb_build_object(k, jsonb_build_object('rule', 'no_such_dataset', 'message', 'no such dataset'));
            CONTINUE;
        END IF;
        SELECT * INTO acc FROM dl_access_of(d.id, p_caller, p_on_behalf_of);
        IF dl_level_rank(acc.level) < 2 THEN
            refused := refused || jsonb_build_object(k, CASE WHEN dl_level_rank(acc.level) = 1
                THEN jsonb_build_object('rule', 'discover_only', 'message', 'you may discover this dataset but not read its rows — request access')
                ELSE jsonb_build_object('rule', 'not_readable', 'message', 'you may not read this dataset — request access') END);
            CONTINUE;
        END IF;
        IF p_ceiling = 'internal_unpersonal' AND (dl_class_rank(d.classification) > 1 OR d.has_personal_data) THEN
            refused := refused || jsonb_build_object(k, jsonb_build_object('rule', 'k38_pending',
                'message', 'this dataset is ' || d.classification || CASE WHEN d.has_personal_data THEN ' with personal data' ELSE '' END
                           || ' — an agent reads it for a person only once the kernel says who asked (K38); open it yourself or in the editor'));
            CONTINUE;
        END IF;
        ds := ds || jsonb_build_object(k, jsonb_build_object(
            'level', CASE WHEN dl_level_rank(acc.level) >= 3 THEN 'read_unmasked' ELSE 'read' END,
            'classification', d.classification,
            'has_personal', d.has_personal_data,
            'columns', (SELECT COALESCE(jsonb_agg(c.name ORDER BY c.ordinal), '[]'::jsonb) FROM dataset_columns c
                         WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL),
            'masks', dl_masks_for(d.id, acc.level),
            'row_filter', acc.row_filter));
    END LOOP;
    SELECT CASE WHEN p_source IN ('screen', 'preview') THEN s.query_timeout_screen_s WHEN p_source IN ('export', 'transform') THEN s.query_timeout_export_s
                ELSE s.query_timeout_agent_s END,
           CASE WHEN p_source = 'preview' THEN 100 WHEN p_source IN ('export', 'transform') THEN s.query_rows_download ELSE s.query_rows_shown END
      INTO timeout_s, row_limit FROM dl_settings s WHERE s.id = 1;
    SELECT jsonb_build_object('timeout_s', timeout_s, 'row_limit', row_limit, 'result_bytes', s.query_result_bytes,
                              'memory', s.query_memory_mb || 'MB', 'threads', s.query_threads, 'temp_mb', s.query_temp_mb)
      INTO lim FROM dl_settings s WHERE s.id = 1;
    RETURN jsonb_build_object('caller', p_caller, 'on_behalf_of', p_on_behalf_of, 'source', p_source, 'ceiling', p_ceiling,
                              'datasets', ds, 'refused', refused, 'limits', lim);
END$$;
REVOKE ALL ON FUNCTION dl_query_policy(bigint, bigint, text[], text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_query_policy(bigint, bigint, text[], text, text) TO datalake_query, datalake_rw, datalake_lake;

CREATE OR REPLACE FUNCTION dl_query_policy_app(p_app text, p_keys text[]) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; d datasets%ROWTYPE; lvl text; ds jsonb := '{}'::jsonb; refused jsonb := '{}'::jsonb; lim jsonb;
BEGIN
    FOREACH k IN ARRAY COALESCE(p_keys, '{}') LOOP
        SELECT * INTO d FROM datasets WHERE key = k;
        lvl := CASE WHEN d.id IS NULL THEN 'none' ELSE dl_application_level(d.id, p_app) END;
        IF dl_level_rank(lvl) < 2 THEN
            refused := refused || jsonb_build_object(k, jsonb_build_object('rule', 'not_shared', 'message', 'not shared with ' || p_app));
            CONTINUE;
        END IF;
        ds := ds || jsonb_build_object(k, jsonb_build_object('level', 'read', 'classification', d.classification, 'has_personal', d.has_personal_data,
            'columns', (SELECT COALESCE(jsonb_agg(c.name ORDER BY c.ordinal), '[]'::jsonb) FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL),
            'masks', dl_masks_for(d.id, 'read'), 'row_filter', NULL));
    END LOOP;
    SELECT jsonb_build_object('timeout_s', s.query_timeout_agent_s, 'row_limit', 500, 'result_bytes', 262144, 'memory', s.query_memory_mb || 'MB',
                              'threads', s.query_threads, 'temp_mb', s.query_temp_mb)
      INTO lim FROM dl_settings s WHERE s.id = 1;
    RETURN jsonb_build_object('caller', NULL, 'consumer_app', p_app, 'on_behalf_of', NULL, 'source', 'k7', 'datasets', ds, 'refused', refused, 'limits', lim);
END$$;

-- ---------------------------------------------------------------------------------------------
-- Usage (C7)
-- ---------------------------------------------------------------------------------------------
-- The people and agents who read a dataset most in N days (names only; the dataset must be discoverable).
CREATE OR REPLACE FUNCTION dl_dataset_frequent_users(p_dataset_id bigint, p_days integer DEFAULT 30, p_limit integer DEFAULT 10)
    RETURNS TABLE (member_id bigint, name text, member_kind text, queries bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT q.caller_member_id, m.display_name, m.member_kind, count(*)
      FROM query_runs q JOIN members m ON m.id = q.caller_member_id
     WHERE dl_can_discover(p_dataset_id) AND p_dataset_id = ANY (q.dataset_ids) AND q.status = 'ok'
       AND q.created_at >= now() - make_interval(days => GREATEST(p_days, 1)) AND q.source <> 'transform'
     GROUP BY q.caller_member_id, m.display_name, m.member_kind
     ORDER BY count(*) DESC, m.display_name LIMIT GREATEST(1, LEAST(p_limit, 50));
$$;
-- The datasets read together with this one (only those the caller may discover).
CREATE OR REPLACE FUNCTION dl_dataset_joined_with(p_dataset_id bigint, p_limit integer DEFAULT 10)
    RETURNS TABLE (dataset_id bigint, key text, times integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT j.other_id, d.key, j.times FROM dataset_joins j JOIN datasets d ON d.id = j.other_id
     WHERE j.dataset_id = p_dataset_id AND dl_can_discover(p_dataset_id) AND dl_can_discover(j.other_id)
     ORDER BY j.times DESC LIMIT GREATEST(1, LEAST(p_limit, 50));
$$;
-- The most used datasets (the home's tile), discoverable ones only.
CREATE OR REPLACE FUNCTION dl_popular_datasets(p_days integer DEFAULT 30, p_zone text DEFAULT NULL, p_limit integer DEFAULT 10)
    RETURNS TABLE (dataset_id bigint, key text, title text, certification text, queries bigint, people bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.key, d.title, d.certification, sum(u.queries)::bigint, sum(u.people)::bigint
      FROM dataset_usage_daily u JOIN datasets d ON d.id = u.dataset_id AND d.status = 'active'
     WHERE u.day >= (now() - make_interval(days => GREATEST(p_days, 1)))::date AND (p_zone IS NULL OR d.zone = p_zone)
     GROUP BY d.id, d.key, d.title, d.certification
    HAVING dl_can_discover(d.id)
     ORDER BY sum(u.queries) DESC, d.key LIMIT GREATEST(1, LEAST(p_limit, 50));
$$;

-- ---------------------------------------------------------------------------------------------
-- Access (S1, S2, S4, S5, S7)
-- ---------------------------------------------------------------------------------------------
-- WHO CAN READ X (S1): the owner, the steward and every live grant reaching the dataset, each with its level, filter and masks. The
-- dataset's owner or grants.manage; anyone else gets nothing.
CREATE OR REPLACE FUNCTION dl_access_list(p_dataset_id bigint)
    RETURNS TABLE (principal_kind text, principal_id bigint, principal_name text, level text, row_filter text, masked_columns integer,
                   via text, grant_id bigint, expires_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE d datasets%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL OR NOT (COALESCE(d.owner_member_id = me, false) OR dl_has_right('grants.manage')) THEN RETURN; END IF;
    RETURN QUERY
    SELECT 'member'::text, d.owner_member_id, (SELECT display_name FROM members WHERE id = d.owner_member_id), 'own'::text, NULL::text, 0, 'owner'::text, NULL::bigint, NULL::timestamptz
     WHERE d.owner_member_id IS NOT NULL
    UNION ALL
    SELECT 'member', d.steward_member_id, (SELECT display_name FROM members WHERE id = d.steward_member_id), 'read', NULL,
           (SELECT count(*)::int FROM jsonb_object_keys(dl_masks_for(d.id, 'read'))), 'steward', NULL, NULL
     WHERE d.steward_member_id IS NOT NULL AND d.zone <> 'sandbox'
    UNION ALL
    SELECT g.principal_kind, g.principal_id,
           COALESCE(gr.name, dep.name, mm.display_name, g.principal_ref, CASE WHEN g.principal_kind = 'everyone' THEN 'everyone inside' END),
           g.level, g.row_filter, (SELECT count(*)::int FROM jsonb_object_keys(dl_masks_for(d.id, g.level))),
           g.target_kind || CASE g.target_kind WHEN 'zone' THEN ' ' || g.zone WHEN 'tag' THEN ' ' || t.slug ELSE '' END, g.id, g.expires_at
      FROM grants g
      LEFT JOIN groups gr ON g.principal_kind = 'group' AND gr.id = g.principal_id
      LEFT JOIN departments dep ON g.principal_kind = 'department' AND dep.id = g.principal_id
      LEFT JOIN members mm ON g.principal_kind IN ('member', 'agent') AND mm.id = g.principal_id
      LEFT JOIN tags t ON t.id = g.tag_id
     WHERE g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at > now())
       AND ((g.target_kind = 'dataset' AND g.dataset_id = d.id)
            OR (g.target_kind = 'zone' AND g.zone = d.zone AND d.zone <> 'sandbox')
            OR (g.target_kind = 'tag' AND d.zone <> 'sandbox' AND EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'dataset' AND rt.record_id = d.id AND rt.tag_id = g.tag_id)));
END$$;

-- WHAT I MAY READ (S2): the candidates are the datasets I own or steward and those the grants to my principals reach (by dataset, zone or
-- tag) — never a scan of the whole catalog (the scale rule) — each judged by dl_member_access.
CREATE OR REPLACE FUNCTION dl_my_access(p_min_level text DEFAULT 'read', p_limit integer DEFAULT 50, p_offset integer DEFAULT 0)
    RETURNS TABLE (dataset_id bigint, key text, title text, level text, source text, row_filtered boolean, masked_columns integer, expires_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH me AS (SELECT app_current_member_id() AS id),
         p AS (SELECT pr.* FROM me CROSS JOIN LATERAL dl_principals_of(me.id) pr),
         g AS (SELECT gr.* FROM grants gr JOIN p ON p.kind = gr.principal_kind AND (gr.principal_kind = 'everyone' OR p.id = gr.principal_id)
                WHERE gr.revoked_at IS NULL AND (gr.expires_at IS NULL OR gr.expires_at > now())),
         cand AS (
            SELECT d.id FROM datasets d, me WHERE d.owner_member_id = me.id OR d.steward_member_id = me.id
            UNION SELECT g.dataset_id FROM g WHERE g.target_kind = 'dataset'
            UNION SELECT d.id FROM datasets d JOIN g ON g.target_kind = 'zone' AND g.zone = d.zone
            UNION SELECT rt.record_id FROM record_tags rt JOIN g ON g.target_kind = 'tag' AND g.tag_id = rt.tag_id WHERE rt.record_type = 'dataset'),
         judged AS (SELECT d.id, d.key, d.title, a.level, a.source, a.row_filter
                      FROM cand c JOIN datasets d ON d.id = c.id AND d.status <> 'retired'
                      CROSS JOIN me CROSS JOIN LATERAL dl_member_access(d.id, me.id) a)
    SELECT j.id, j.key, j.title, j.level, j.source, j.row_filter IS NOT NULL,
           (SELECT count(*)::int FROM jsonb_object_keys(dl_masks_for(j.id, j.level))),
           (SELECT gr.expires_at FROM grants gr WHERE j.source LIKE 'grant %' AND gr.id = substr(j.source, 7)::bigint)
      FROM judged j
     WHERE dl_level_rank(j.level) >= dl_level_rank(p_min_level)
     ORDER BY j.key LIMIT GREATEST(1, LEAST(p_limit, 200)) OFFSET GREATEST(0, p_offset);
$$;

-- WHO READ X (S4): from the audit — people, agents, applications and keys, with counts; never SQL. The owner or audit.read. With
-- p_personal_only, only runs that read a personal column (or every column, when the run could not tell).
CREATE OR REPLACE FUNCTION dl_dataset_readers(p_dataset_id bigint, p_from timestamptz DEFAULT now() - interval '30 days', p_to timestamptz DEFAULT now(),
                                              p_personal_only boolean DEFAULT false)
    RETURNS TABLE (caller_member_id bigint, name text, kind text, queries bigint, rows bigint, masked_reads bigint, unmasked_reads bigint, last_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE d datasets%ROWTYPE; me bigint := app_current_member_id(); pcols text[];
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL OR NOT (COALESCE(d.owner_member_id = me, false) OR dl_has_right('audit.read')) THEN RETURN; END IF;
    SELECT array_agg(c.name) INTO pcols FROM dataset_columns c WHERE c.dataset_id = d.id AND c.personal_kind IS NOT NULL AND c.removed_snapshot_id IS NULL;
    RETURN QUERY
    SELECT CASE WHEN q.consumer_app IS NOT NULL OR q.key_label IS NOT NULL THEN NULL ELSE q.caller_member_id END,
           COALESCE(q.consumer_app, q.key_label, m.display_name),
           CASE WHEN q.consumer_app IS NOT NULL THEN 'application' WHEN q.key_label IS NOT NULL THEN 'key' WHEN m.member_kind = 'agent' THEN 'agent' ELSE 'person' END,
           count(*), sum(q.rows_returned)::bigint,
           count(*) FILTER (WHERE q.masked_columns ? d.key), count(*) FILTER (WHERE NOT (q.masked_columns ? d.key)), max(q.created_at)
      FROM query_runs q LEFT JOIN members m ON m.id = q.caller_member_id
     WHERE p_dataset_id = ANY (q.dataset_ids) AND q.status = 'ok' AND q.created_at >= p_from AND q.created_at <= p_to
       AND (NOT p_personal_only OR NOT (q.columns_read ? d.key)
            OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(q.columns_read -> d.key) x WHERE x = ANY (COALESCE(pcols, '{}'))))
     GROUP BY 1, 2, 3
     ORDER BY count(*) DESC;
END$$;

-- WHO COULD READ X ON A DAY (S5): the grants live at that moment (created before, not revoked or expired by then), the owner now.
-- grants.manage. (A tag grant is judged by the dataset's tags now: a tag's removal is not dated.)
CREATE OR REPLACE FUNCTION dl_access_history(p_dataset_id bigint, p_at timestamptz)
    RETURNS TABLE (principal_kind text, principal_id bigint, principal_name text, level text, row_filter text, grant_id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE d datasets%ROWTYPE;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL OR NOT dl_has_right('grants.manage') THEN RETURN; END IF;
    RETURN QUERY
    SELECT 'member'::text, d.owner_member_id, (SELECT display_name FROM members WHERE id = d.owner_member_id), 'own'::text, NULL::text, NULL::bigint
     WHERE d.owner_member_id IS NOT NULL AND d.created_at <= p_at
    UNION ALL
    SELECT g.principal_kind, g.principal_id, COALESCE(gr.name, dep.name, mm.display_name, g.principal_ref, 'everyone inside'), g.level, g.row_filter, g.id
      FROM grants g
      LEFT JOIN groups gr ON g.principal_kind = 'group' AND gr.id = g.principal_id
      LEFT JOIN departments dep ON g.principal_kind = 'department' AND dep.id = g.principal_id
      LEFT JOIN members mm ON g.principal_kind IN ('member', 'agent') AND mm.id = g.principal_id
     WHERE g.created_at <= p_at AND (g.revoked_at IS NULL OR g.revoked_at > p_at) AND (g.expires_at IS NULL OR g.expires_at > p_at)
       AND ((g.target_kind = 'dataset' AND g.dataset_id = d.id)
            OR (g.target_kind = 'zone' AND g.zone = d.zone AND d.zone <> 'sandbox')
            OR (g.target_kind = 'tag' AND d.zone <> 'sandbox' AND EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'dataset' AND rt.record_id = d.id AND rt.tag_id = g.tag_id)));
END$$;

-- Grants that expire soon (S7) — grants.manage.
CREATE OR REPLACE FUNCTION dl_grants_expiring(p_days integer DEFAULT 7, p_limit integer DEFAULT 100)
    RETURNS TABLE (grant_id bigint, target text, principal text, level text, expires_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT g.id, COALESCE(d.key, 'zone ' || g.zone, 'tag ' || t.slug), g.principal_kind || COALESCE(' ' || COALESCE(gr.name, dep.name, mm.display_name, g.principal_ref), ''),
           g.level, g.expires_at
      FROM grants g LEFT JOIN datasets d ON d.id = g.dataset_id LEFT JOIN tags t ON t.id = g.tag_id
      LEFT JOIN groups gr ON g.principal_kind = 'group' AND gr.id = g.principal_id
      LEFT JOIN departments dep ON g.principal_kind = 'department' AND dep.id = g.principal_id
      LEFT JOIN members mm ON g.principal_kind IN ('member', 'agent') AND mm.id = g.principal_id
     WHERE dl_has_right('grants.manage') AND g.revoked_at IS NULL AND g.expires_at BETWEEN now() AND now() + make_interval(days => GREATEST(p_days, 1))
     ORDER BY g.expires_at LIMIT GREATEST(1, LEAST(p_limit, 500));
$$;
-- Dataset grants to a person or an agent who has not read the dataset in N days (S7) — grants.manage. (Zone, tag, group, department and
-- everyone grants are broad by design and are not "unused" — their reach is reviewed on the grants screen.)
CREATE OR REPLACE FUNCTION dl_grants_unused(p_days integer DEFAULT 90, p_limit integer DEFAULT 100)
    RETURNS TABLE (grant_id bigint, target text, principal text, level text, last_read_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT g.id, d.key, g.principal_kind || ' ' || mm.display_name, g.level,
           (SELECT max(q.created_at) FROM query_runs q WHERE q.caller_member_id = g.principal_id AND g.dataset_id = ANY (q.dataset_ids) AND q.status = 'ok')
      FROM grants g JOIN datasets d ON d.id = g.dataset_id JOIN members mm ON mm.id = g.principal_id
     WHERE dl_has_right('grants.manage') AND g.revoked_at IS NULL AND (g.expires_at IS NULL OR g.expires_at > now())
       AND g.target_kind = 'dataset' AND g.principal_kind IN ('member', 'agent') AND g.created_at < now() - make_interval(days => GREATEST(p_days, 1))
       AND NOT EXISTS (SELECT 1 FROM query_runs q WHERE q.caller_member_id = g.principal_id AND g.dataset_id = ANY (q.dataset_ids) AND q.status = 'ok'
                          AND q.created_at >= now() - make_interval(days => GREATEST(p_days, 1)))
     ORDER BY d.key LIMIT GREATEST(1, LEAST(p_limit, 500));
$$;

-- ---------------------------------------------------------------------------------------------
-- The engine's state (I6) — the admin's
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dl_engine_state() RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE snap jsonb := NULL;
BEGIN
    IF NOT dl_is_admin() THEN RETURN NULL; END IF;
    IF to_regclass('public.dl_lake_snapshots') IS NOT NULL THEN
        EXECUTE 'SELECT jsonb_build_object(''snapshot_id'', snapshot_id, ''snapshot_time'', snapshot_time) FROM dl_lake_snapshots ORDER BY snapshot_id DESC LIMIT 1' INTO snap;
    END IF;
    RETURN jsonb_build_object(
        'queue', jsonb_build_object(
            'queued', (SELECT count(*) FROM lake_jobs WHERE status = 'queued'),
            'running', (SELECT count(*) FROM lake_jobs WHERE status = 'running'),
            'failed_24h', (SELECT count(*) FROM lake_jobs WHERE status = 'failed' AND finished_at > now() - interval '24 hours')),
        'running', (SELECT COALESCE(jsonb_agg(jsonb_build_object('job_id', j.id, 'kind', j.kind, 'dataset_id', j.dataset_id, 'started_at', j.started_at) ORDER BY j.started_at), '[]'::jsonb)
                      FROM lake_jobs j WHERE j.status = 'running'),
        'queries_today', (SELECT jsonb_build_object('ok', count(*) FILTER (WHERE status = 'ok'), 'refused', count(*) FILTER (WHERE status = 'refused'),
                                                    'timeout', count(*) FILTER (WHERE status = 'timeout'), 'error', count(*) FILTER (WHERE status = 'error'))
                            FROM query_runs WHERE created_at >= date_trunc('day', now())),
        'last_snapshot', snap);
END$$;

-- ---------------------------------------------------------------------------------------------
-- The K7 share `datasets_index` (os.datalake-datasets/1): the products granted to the consumer (the kernel's token only)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dl_share_datasets_index(p_app text, p_since timestamptz DEFAULT NULL, p_limit integer DEFAULT 100, p_offset integer DEFAULT 0)
    RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH ds AS (
        SELECT d.* FROM datasets d
         WHERE d.status IN ('active', 'deprecated') AND d.zone <> 'sandbox' AND dl_level_rank(dl_application_level(d.id, p_app)) >= 1
           AND (p_since IS NULL OR d.metadata_updated_at > p_since OR d.data_updated_at > p_since)
         ORDER BY d.key LIMIT GREATEST(1, LEAST(p_limit, 200)) + 1 OFFSET GREATEST(0, p_offset))
    SELECT jsonb_build_object('schema', 'os.datalake-datasets/1', 'consumer', p_app,
        'datasets', COALESCE((SELECT jsonb_agg(jsonb_build_object('key', d.key, 'title', d.title, 'description', d.description,
                                   'as_of', d.data_updated_at, 'certification', d.certification, 'level', dl_application_level(d.id, p_app),
                                   'columns', (SELECT COALESCE(jsonb_agg(jsonb_build_object('name', c.name, 'logical_type', c.logical_type, 'description', c.description,
                                                                                             'masked', dl_masks_for(d.id, 'read') ? c.name) ORDER BY c.ordinal), '[]'::jsonb)
                                                 FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND NOT c.is_system))
                                 ORDER BY d.key)
                              FROM (SELECT * FROM ds ORDER BY key LIMIT GREATEST(1, LEAST(p_limit, 200))) d), '[]'::jsonb),
        'next_offset', CASE WHEN (SELECT count(*) FROM ds) > GREATEST(1, LEAST(p_limit, 200)) THEN GREATEST(0, p_offset) + GREATEST(1, LEAST(p_limit, 200)) END);
$$;

GRANT EXECUTE ON FUNCTION dl_steward_agent(), dl_dataset_frequent_users(bigint, integer, integer), dl_dataset_joined_with(bigint, integer),
    dl_popular_datasets(integer, text, integer), dl_access_list(bigint), dl_my_access(text, integer, integer),
    dl_dataset_readers(bigint, timestamptz, timestamptz, boolean), dl_access_history(bigint, timestamptz), dl_grants_expiring(integer, integer),
    dl_grants_unused(integer, integer), dl_engine_state()
    TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_share_datasets_index(text, timestamptz, integer, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_share_datasets_index(text, timestamptz, integer, integer) TO datalake_records_ro;

-- ---------------------------------------------------------------------------------------------
-- A STUB honours who an agent acts for (access-and-policies §1, decision 7): when app.on_behalf_of is set, the person must be eligible too —
-- an agent acting for an external never reveals a confidential dataset's name.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dl_can_see_stub(p_dataset_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (
        SELECT 1 FROM datasets d
         WHERE d.id = p_dataset_id AND d.zone <> 'sandbox' AND d.status <> 'retired'
           AND EXISTS (SELECT 1 FROM members m WHERE m.id = app_current_member_id() AND m.status = 'active' AND NOT m.is_external AND dl_member_here(m.id))
           AND (NULLIF(current_setting('app.on_behalf_of', true), '') IS NULL
                OR EXISTS (SELECT 1 FROM members b WHERE b.id = NULLIF(current_setting('app.on_behalf_of', true), '')::bigint
                              AND b.status = 'active' AND NOT b.is_external AND dl_member_here(b.id))));
$$;
-- Exports and transforms run in the writer with the query service's library: it writes their audit rows (query-service §1, decision 4).
GRANT EXECUTE ON FUNCTION dl_query_run_log(jsonb) TO datalake_lake;

COMMIT;
