-- 024: slice 5 — contracts, promotion, checks, incidents and certification (docs/build-specs/contracts-quality.md, THE THIRD EXEMPLAR).
-- Additive: what the screens and the engine need to ASK the database, never a rule moved into PHP.
--   1. dl_contract_breaking_preview(dataset, schema, key) — the breaking-change rule over a schema not saved yet (the contract form lists breaking
--      changes live); dl_contract_breaking_changes(draft) now answers through it, so the form and the publish can never disagree.
--   2. dl_quarantine_policy(caller, on_behalf_of, curated key, ceiling) — the quarantine of a curated dataset read through the query service
--      (divergence 4): `write` level on the curated dataset (the intersection for an agent acting for a person; the K38 ceiling), the active
--      contract's SOURCE columns (the quarantine's columns are the raw text), each masked as the curated column it feeds is masked for the caller,
--      refused when the caller's rows are filtered (a row filter names typed curated columns; the quarantine holds rows that did not type).
--   3. mcp_incidents appends the results that opened and resolved an incident (the incident page names them).
--   4. Grants: check_delete (DELETE on checks — the results go with them by the foreign key), the policy to the query service's role.
BEGIN;

-- 1. The breaking-change rule, over any schema (a draft's, or the form's before it is saved): a column removed or retyped against the ACTIVE
--    contract, the key changed. Empty = not breaking. db/010's dl_contract_breaking_changes(draft) answers through it.
CREATE OR REPLACE FUNCTION dl_contract_breaking_preview(p_dataset_id bigint, p_schema jsonb, p_key text[]) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH ac AS (SELECT c.* FROM contracts c WHERE c.dataset_id = p_dataset_id AND c.status = 'active'),
         old_cols AS (SELECT e ->> 'name' AS name, e ->> 'physical_type' AS t FROM ac, jsonb_array_elements(ac.schema) e),
         new_cols AS (SELECT e ->> 'name' AS name, e ->> 'physical_type' AS t FROM jsonb_array_elements(COALESCE(p_schema, '[]'::jsonb)) e)
    SELECT COALESCE(jsonb_agg(x), '[]'::jsonb) FROM (
        SELECT jsonb_build_object('column', o.name, 'kind', 'removed') AS x FROM old_cols o WHERE NOT EXISTS (SELECT 1 FROM new_cols n WHERE n.name = o.name)
        UNION ALL
        SELECT jsonb_build_object('column', o.name, 'kind', 'retyped', 'from', o.t, 'to', n.t) FROM old_cols o JOIN new_cols n ON n.name = o.name WHERE n.t IS DISTINCT FROM o.t
        UNION ALL
        SELECT jsonb_build_object('kind', 'key_changed', 'from', to_jsonb(ac.key), 'to', to_jsonb(COALESCE(p_key, '{}'::text[]))) FROM ac WHERE ac.key IS DISTINCT FROM COALESCE(p_key, '{}'::text[])
    ) s;
$$;

CREATE OR REPLACE FUNCTION dl_contract_breaking_changes(p_draft_id bigint) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT dl_contract_breaking_preview(d.dataset_id, d.schema, d.key) FROM contracts d WHERE d.id = p_draft_id), '[]'::jsonb);
$$;

-- 2. The quarantine's policy (query-service §7 `preview` with `quarantine: true`): the shape of dl_query_policy() for ONE key,
--    `quarantine.<name>`, compiled for the curated dataset `curated.<name>`. A refusal names the CURATED key (what the person asked for).
CREATE OR REPLACE FUNCTION dl_quarantine_policy(p_caller bigint, p_on_behalf_of bigint, p_key text, p_ceiling text DEFAULT NULL) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; acc record; base jsonb; k contracts%ROWTYPE; masks jsonb; qmasks jsonb := '{}'::jsonb; cols jsonb := '[]'::jsonb;
        e jsonb; src text; m text; qkey text;
BEGIN
    base := dl_query_policy(p_caller, p_on_behalf_of, ARRAY[]::text[], 'preview', p_ceiling);       -- the limits for a preview (and the source checked)
    SELECT * INTO d FROM datasets WHERE key = p_key;
    IF d.id IS NULL OR d.status = 'retired' OR d.zone <> 'curated' THEN
        RETURN base || jsonb_build_object('refused', jsonb_build_object(p_key, jsonb_build_object('rule', 'no_such_dataset',
            'message', 'there is no curated dataset ' || p_key)));
    END IF;
    SELECT * INTO acc FROM dl_access_of(d.id, p_caller, p_on_behalf_of);
    IF dl_level_rank(acc.level) < 2 THEN
        RETURN base || jsonb_build_object('refused', jsonb_build_object(p_key, CASE
            WHEN dl_level_rank(acc.level) = 1 OR dl_member_sees_stub(d.id, p_caller, p_on_behalf_of)
                THEN jsonb_build_object('rule', 'not_readable', 'message', 'the quarantine of ' || p_key || ' is read by those who may write it')
            ELSE jsonb_build_object('rule', 'no_such_dataset', 'message', 'there is no curated dataset ' || p_key) END));
    END IF;
    IF dl_level_rank(acc.level) < 4 THEN
        RETURN base || jsonb_build_object('refused', jsonb_build_object(p_key, jsonb_build_object('rule', 'not_readable',
            'message', 'the quarantine of ' || p_key || ' holds rows as they arrived — it is read by those who may write the dataset')));
    END IF;
    IF p_ceiling = 'internal_unpersonal' AND (dl_class_rank(d.classification) > 1 OR d.has_personal_data) THEN
        RETURN base || jsonb_build_object('refused', jsonb_build_object(p_key, jsonb_build_object('rule', 'k38_pending',
            'message', p_key || ' is ' || d.classification || CASE WHEN d.has_personal_data THEN ' with personal data' ELSE '' END
                       || ' — an agent reads its quarantine for a person only once the kernel says who asked (K38)')));
    END IF;
    IF acc.row_filter IS NOT NULL THEN
        RETURN base || jsonb_build_object('refused', jsonb_build_object(p_key, jsonb_build_object('rule', 'not_readable',
            'message', 'your rows of ' || p_key || ' are filtered — its quarantine is read by those who read it whole')));
    END IF;
    SELECT * INTO k FROM contracts WHERE dataset_id = d.id AND status = 'active';
    IF k.id IS NULL THEN                                                     -- never promoted: nothing held back
        RETURN base || jsonb_build_object('refused', jsonb_build_object(p_key, jsonb_build_object('rule', 'no_contract',
            'message', p_key || ' has no active contract — nothing has been promoted, nothing held back')));
    END IF;
    masks := dl_masks_for(d.id, acc.level);
    FOR e IN SELECT x FROM jsonb_array_elements(k.schema) x LOOP
        src := split_part(e ->> 'source', '.', 3);
        IF src IS NULL OR src = '' THEN src := e ->> 'name'; END IF;
        IF NOT cols @> to_jsonb(ARRAY[src]) THEN cols := cols || to_jsonb(src); END IF;
        m := masks ->> (e ->> 'name');
        IF m IS NOT NULL AND NOT qmasks ? src THEN qmasks := qmasks || jsonb_build_object(src, m); END IF;
    END LOOP;
    cols := cols || '["_dl_load_id", "_dl_row", "_dl_loaded_at", "_dl_reason", "_dl_quarantined_at"]'::jsonb;
    qkey := 'quarantine.' || d.name;
    RETURN base || jsonb_build_object('curated', p_key, 'refused', '{}'::jsonb,
        'datasets', jsonb_build_object(qkey, jsonb_build_object('level', 'read_unmasked', 'classification', d.classification,
            'has_personal', d.has_personal_data, 'columns', cols, 'masks', qmasks, 'row_filter', NULL)));
END$$;

-- 3. The incident page names the results that opened and resolved it (appended, as views are changed).
CREATE OR REPLACE VIEW mcp_incidents WITH (security_barrier = true) AS
SELECT i.id AS incident_id, i.dataset_id, i.kind, i.check_id, i.load_id, i.severity, i.status, i.title, i.assignee_member_id, i.opened_at, i.acknowledged_at,
       i.resolved_at, i.root_cause, i.resolution, i.downstream, i.updated_at, i.opened_by_result_id, i.resolved_by_result_id
  FROM incidents i WHERE (SELECT dl_is_member_here()) AND dl_can_discover(i.dataset_id);

-- 4. Grants.
GRANT DELETE ON checks TO datalake_rw;
REVOKE ALL ON FUNCTION dl_quarantine_policy(bigint, bigint, text, text), dl_contract_breaking_preview(bigint, jsonb, text[]) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_quarantine_policy(bigint, bigint, text, text) TO datalake_query, datalake_rw;
GRANT EXECUTE ON FUNCTION dl_contract_breaking_preview(bigint, jsonb, text[]) TO datalake_rw, datalake_records_ro, datalake_lake;
GRANT SELECT ON mcp_incidents TO datalake_rw, datalake_records_ro, datalake_activity_ro;

COMMIT;
