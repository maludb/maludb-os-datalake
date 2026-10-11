-- Data Lake, slice 3 (the query service, the SQL editor, saved queries and exports — THE SECOND EXEMPLAR, 2026-10-11): what the one door and
-- its two writer jobs found the schema lacked (docs/build-specs/query-service-editor.md, query-service.md §2–§7). Additive: functions added
-- and one replaced, checks widened, grants.
--
-- 1. dl_query_admit() / dl_query_release() — the rate (an agent's queries a minute) and the concurrency (a person's queries in flight) admitted
--    in PostgreSQL BEFORE a run (query-service §4, decision 6): the service takes a SESSION advisory lock — one of query_concurrency_person
--    slots per caller — on its own connection and holds it for the run, so the limit holds across the instances; it releases the slot after
--    the audit. {admitted, slot} or {admitted: false, rule: rate | concurrency}. A slot held by ANY session (this one included — advisory
--    locks are reentrant within a session) is taken.
-- 2. dl_query_policy() — a dataset the caller may not even know exists answers `no_such_dataset` like one that does not exist (design §3
--    rule 2, phase1-consistency 16: "a hidden dataset never confirms itself"): `not_readable` + Request access stays for whoever sees its stub
--    (an insider holding a role here; never an external, never another's sandbox). db/018's version answered `not_readable` to everyone,
--    so a key typed in the editor confirmed it existed. Its body otherwise unchanged; dl_member_sees_stub() is db/018's stub rule for named
--    members (the policy runs as the query service, which has no session member).
-- 3. lake_jobs.kind and lineage_runs.job_kind admit `sandbox_save` (a person's result saved as their sandbox dataset — a writer job that
--    writes a dataset is a lineage run of its own); `export` is already a kind of both.
-- 4. notifications.kind admits `policy_fault`, and dl_query_policy_fault() tells the Lake admins ONCE A DAY per dataset that a compiled
--    policy could not be honoured (a row filter with a forbidden node — query-service §4, decision 9: a configuration fault, not the caller's).
BEGIN;

CREATE OR REPLACE FUNCTION dl_query_admit(p_caller bigint, p_source text, p_request_id text) RETURNS jsonb
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s dl_settings%ROWTYPE; kind text; n integer; slot integer;
BEGIN
    IF p_caller IS NULL THEN
        RETURN jsonb_build_object('admitted', true, 'slot', NULL);       -- a K7 consumer: its share's own limits (slice 9)
    END IF;
    SELECT * INTO s FROM dl_settings WHERE id = 1;
    SELECT member_kind INTO kind FROM members WHERE id = p_caller;
    IF kind = 'agent' THEN
        SELECT count(*) INTO n FROM query_runs
         WHERE caller_member_id = p_caller AND created_at > now() - interval '1 minute' AND source NOT IN ('export', 'transform');
        IF n >= s.query_rate_agent_per_minute THEN
            RETURN jsonb_build_object('admitted', false, 'rule', 'rate');
        END IF;
    END IF;
    -- a slot is free only when NO session holds it — this one included: PostgreSQL's advisory locks are reentrant within a session, so a
    -- slot this instance's connection still held (a release it missed) must count as taken, never be stacked
    FOR slot IN 0 .. s.query_concurrency_person - 1 LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_locks l WHERE l.locktype = 'advisory' AND l.objsubid = 2 AND l.granted
                          AND l.classid::bigint = (hashtext('dlq:' || p_caller)::bigint & 4294967295) AND l.objid::bigint = slot)
           AND pg_try_advisory_lock(hashtext('dlq:' || p_caller), slot) THEN
            RETURN jsonb_build_object('admitted', true, 'slot', slot);
        END IF;
    END LOOP;
    RETURN jsonb_build_object('admitted', false, 'rule', 'concurrency');
END$$;

CREATE OR REPLACE FUNCTION dl_query_release(p_caller bigint, p_slot integer) RETURNS boolean
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN p_caller IS NULL OR p_slot IS NULL THEN false ELSE pg_advisory_unlock(hashtext('dlq:' || p_caller), p_slot) END;
$$;

-- db/018's stub rule (dl_can_see_stub) for NAMED members: the query service compiles the policy with no session member.
CREATE OR REPLACE FUNCTION dl_member_sees_stub(p_dataset_id bigint, p_member bigint, p_on_behalf_of bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (
        SELECT 1 FROM datasets d
         WHERE d.id = p_dataset_id AND d.zone <> 'sandbox' AND d.status <> 'retired'
           AND EXISTS (SELECT 1 FROM members m WHERE m.id = p_member AND m.status = 'active' AND NOT m.is_external AND dl_member_here(m.id))
           AND (p_on_behalf_of IS NULL OR p_on_behalf_of = p_member
                OR EXISTS (SELECT 1 FROM members b WHERE b.id = p_on_behalf_of AND b.status = 'active' AND NOT b.is_external AND dl_member_here(b.id))));
$$;

CREATE OR REPLACE FUNCTION dl_query_policy(p_caller bigint, p_on_behalf_of bigint, p_keys text[], p_source text DEFAULT 'screen', p_ceiling text DEFAULT NULL)
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
        d := NULL;
        SELECT * INTO d FROM datasets WHERE key = k;
        IF d.id IS NULL OR d.status = 'retired' THEN
            refused := refused || jsonb_build_object(k, jsonb_build_object('rule', 'no_such_dataset', 'message', 'there is no dataset ' || k));
            CONTINUE;
        END IF;
        SELECT * INTO acc FROM dl_access_of(d.id, p_caller, p_on_behalf_of);
        IF dl_level_rank(acc.level) < 2 THEN
            refused := refused || jsonb_build_object(k, CASE
                WHEN dl_level_rank(acc.level) = 1
                    THEN jsonb_build_object('rule', 'discover_only', 'message', 'you may discover ' || k || ' but not read its rows — request access')
                WHEN dl_member_sees_stub(d.id, p_caller, p_on_behalf_of)
                    THEN jsonb_build_object('rule', 'not_readable', 'message', 'you may not read ' || k || ' — request access')
                ELSE jsonb_build_object('rule', 'no_such_dataset', 'message', 'there is no dataset ' || k) END);
            CONTINUE;
        END IF;
        IF p_ceiling = 'internal_unpersonal' AND (dl_class_rank(d.classification) > 1 OR d.has_personal_data) THEN
            refused := refused || jsonb_build_object(k, jsonb_build_object('rule', 'k38_pending',
                'message', k || ' is ' || d.classification || CASE WHEN d.has_personal_data THEN ' with personal data' ELSE '' END
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

ALTER TABLE lake_jobs DROP CONSTRAINT lake_jobs_kind_check;
ALTER TABLE lake_jobs ADD CONSTRAINT lake_jobs_kind_check
    CHECK (kind IN ('sniff', 'load', 'profile', 'promote', 'checks', 'transform', 'export', 'maintenance', 'erasure', 'rollback', 'backup_lock', 'retention',
                    'sandbox_save'));
ALTER TABLE lineage_runs DROP CONSTRAINT lineage_runs_job_kind_check;
ALTER TABLE lineage_runs ADD CONSTRAINT lineage_runs_job_kind_check
    CHECK (job_kind IN ('load', 'promote', 'transform', 'export', 'erasure', 'maintenance', 'query', 'rollback', 'sandbox_save'));

ALTER TABLE notifications DROP CONSTRAINT notifications_kind_check;
ALTER TABLE notifications ADD CONSTRAINT notifications_kind_check
    CHECK (kind IN ('check_failed', 'incident_assigned', 'freshness_missed', 'schema_changed', 'access_requested', 'access_decided',
                    'certification_lost', 'deprecated', 'export_ready', 'load_failed', 'proposal', 'steward_note', 'admin_read',
                    'erasure_done', 'mention', 'digest', 'policy_fault'));

-- A compiled policy the service could not honour: every Lake admin hears of it once a day per dataset (the dedupe key carries the day).
CREATE OR REPLACE FUNCTION dl_query_policy_fault(p_key text, p_rule_words text) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; m record; n integer := 0;
BEGIN
    SELECT * INTO d FROM datasets WHERE key = p_key;
    FOR m IN SELECT id FROM members WHERE status = 'active' AND member_kind = 'human' AND dl_member_has_right(id, 'settings.manage') LOOP
        IF dl_notify(m.id, 'policy_fault', 'A read of ' || p_key || ' was refused by its own policy',
                     'The lake could not honour the policy compiled for ' || p_key || ' (' || left(COALESCE(p_rule_words, 'its row filter'), 200)
                     || ') — the read was refused. Look at the dataset''s grants and row filters.',
                     'dataset', d.id, d.id, NULL, 'policy_fault:' || p_key || ':' || current_date || ':' || m.id) IS NOT NULL THEN
            n := n + 1;
        END IF;
    END LOOP;
    RETURN n;
END$$;

REVOKE ALL ON FUNCTION dl_query_admit(bigint, text, text), dl_query_release(bigint, integer), dl_member_sees_stub(bigint, bigint, bigint),
    dl_query_policy_fault(text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_query_admit(bigint, text, text), dl_query_release(bigint, integer), dl_query_policy_fault(text, text) TO datalake_query;
GRANT EXECUTE ON FUNCTION dl_member_sees_stub(bigint, bigint, bigint) TO datalake_query, datalake_rw, datalake_lake;
REVOKE ALL ON FUNCTION dl_query_policy(bigint, bigint, text[], text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_query_policy(bigint, bigint, text[], text, text) TO datalake_query, datalake_rw, datalake_lake;

COMMIT;
