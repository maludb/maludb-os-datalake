-- 016: WHO SEES WHAT through the read servers — the mcp_* views, the only thing the two read roles see (memory.md §1; design §3), and the
-- read functions the screens, the tools and the Steward share (design §7). Every WHERE clause IS the row rule; the servers never filter in
-- Python. The caller's cheap facts are scalar subqueries tested once per statement; the per-row wall is dl_can_discover() /
-- dl_level() — per dataset (THE SCALE RULE). Primary keys re-aliased <entity>_id. NEVER selected: a credential's ciphertext, a token's hash,
-- a blob's storage_path, an erasure's identifier values, an outbox body, a profile's raw values (the writer masks them before they land).
--
-- The kit's two functions the MCP servers call (mcp/db.py, mcp/server_common.py) are here as in every sibling: mcp_admit_agent(),
-- mcp_member_kind().
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now()
     WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO datalake_records_ro, datalake_activity_ro, datalake_rw;

CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL;
$$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO datalake_records_ro, datalake_activity_ro, datalake_rw;

-- ---------------------------------------------------------------------------------------------
-- The directory and the settings
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, m.status, m.capability, m.roles,
       dl_member_roles(m.id) AS roles_resolved, m.job_title, m.timezone,
       CASE WHEN NOT (SELECT m2.is_external FROM members m2 WHERE m2.id = app_current_member_id()) THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids
  FROM members m
 WHERE m.status = 'active' AND m.capability IS NOT NULL AND (SELECT dl_is_member_here())
   AND (NOT (SELECT m2.is_external FROM members m2 WHERE m2.id = app_current_member_id()) OR m.id = app_current_member_id());

CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at
  FROM departments d WHERE (SELECT dl_is_member_here());

CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.business_name, s.timezone, s.query_timeout_screen_s, s.query_timeout_agent_s, s.query_timeout_export_s, s.query_rows_shown, s.query_rows_download,
       s.query_result_bytes, s.query_concurrency_person, s.query_rate_agent_per_minute, s.landing_keep_days, s.snapshot_keep_days, s.query_text_days,
       s.audit_keep_days, s.backup_keep_days, s.sandbox_quota_bytes, s.max_upload_bytes, s.key_rate_per_minute, s.key_rate_per_day,
       s.certification_target, s.unused_after_days, s.updated_at
  FROM dl_settings s WHERE (SELECT dl_is_member_here());

CREATE OR REPLACE VIEW mcp_groups WITH (security_barrier = true) AS
SELECT g.id AS group_id, g.key, g.name, g.description, g.kind, g.department_id, g.archived_at,
       (SELECT count(*) FROM group_members gm WHERE gm.group_id = g.id AND gm.left_at IS NULL) AS member_count
  FROM groups g WHERE (SELECT dl_is_member_here()) AND NOT (SELECT m.is_external FROM members m WHERE m.id = app_current_member_id());

-- ---------------------------------------------------------------------------------------------
-- The catalog (a stub row for a confidential/restricted dataset the caller may not discover: name and owner only)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_datasets WITH (security_barrier = true) AS
SELECT d.id AS dataset_id, d.key, d.zone, d.title, d.description, d.description_source, d.purpose, d.usage, d.limitations,
       d.owner_member_id, d.steward_member_id, d.department_id, d.status, d.deprecation_note, d.successor_id, d.certification, d.certified_by, d.certified_at,
       d.certification_lost_reason, d.tier, d.classification, d.has_personal_data, d.source_id, d.contract_version, d.row_count, d.byte_size, d.file_count,
       d.last_snapshot_id, d.data_updated_at, d.metadata_updated_at, d.freshness_warn_after, d.freshness_error_after, d.retention_days, d.evolution_mode,
       d.dedupe_key, d.ordering_column, d.created_at, dl_tags_of('dataset', d.id) AS tags, dl_level(d.id) AS my_level, false AS is_stub,
       (SELECT count(*) FROM incidents i WHERE i.dataset_id = d.id AND i.status IN ('new', 'acknowledged', 'investigating')) AS open_incidents,
       dl_usage_30d(d.id) AS usage_30d
  FROM datasets d
 WHERE (SELECT dl_is_member_here()) AND dl_can_discover(d.id)
UNION ALL
SELECT d.id, d.key, d.zone, d.title, NULL, NULL, NULL, NULL, NULL,
       d.owner_member_id, NULL, d.department_id, d.status, NULL, NULL, d.certification, NULL, NULL,
       NULL, NULL, d.classification, NULL, NULL, NULL, NULL, NULL, NULL,
       NULL, NULL, NULL, NULL, NULL, NULL, NULL,
       NULL, NULL, NULL, NULL, 'none', true,
       NULL, NULL
  FROM datasets d
 WHERE (SELECT dl_is_member_here()) AND d.status <> 'retired' AND NOT dl_can_discover(d.id) AND dl_can_see_stub(d.id);

CREATE OR REPLACE VIEW mcp_dataset_columns WITH (security_barrier = true) AS
SELECT c.id AS column_id, c.dataset_id, c.name, c.ordinal, c.physical_type, c.logical_type, c.nullable, c.is_key, c.is_system, c.business_name, c.description,
       c.description_source, c.classification, c.personal_kind, c.classified_by, c.classification_confirmed_at, c.proposed_personal_kind, c.proposed_confidence,
       c.glossary_term_id, c.critical, c.profile, c.profiled_at, c.removed_snapshot_id
  FROM dataset_columns c
 WHERE (SELECT dl_is_member_here()) AND dl_can_discover(c.dataset_id);

CREATE OR REPLACE VIEW mcp_column_profiles WITH (security_barrier = true) AS
SELECT p.id AS profile_id, p.dataset_id, p.column_name, p.snapshot_id, p.load_id, p.profile, p.profiled_at
  FROM column_profiles p WHERE (SELECT dl_is_member_here()) AND dl_can_discover(p.dataset_id);

CREATE OR REPLACE VIEW mcp_schema_versions WITH (security_barrier = true) AS
SELECT v.id AS schema_version_id, v.dataset_id, v.version, v.snapshot_id, v.columns, v.changes, v.breaking, v.detected_by, v.decision, v.decided_by, v.decided_at, v.created_at
  FROM schema_versions v WHERE (SELECT dl_is_member_here()) AND dl_can_discover(v.dataset_id);

CREATE OR REPLACE VIEW mcp_glossary_terms WITH (security_barrier = true) AS
SELECT t.id AS term_id, t.name, t.definition, t.synonyms, t.parent_id, t.status, t.steward_member_id, t.approved_at, t.updated_at,
       (SELECT count(*) FROM dataset_columns c WHERE c.glossary_term_id = t.id AND c.removed_snapshot_id IS NULL) AS column_count
  FROM glossary_terms t WHERE (SELECT dl_is_member_here());

CREATE OR REPLACE VIEW mcp_tags WITH (security_barrier = true) AS
SELECT t.id AS tag_id, t.slug, t.label, t.usage_count, t.kind, t.colour, t.archived_at FROM tags t WHERE (SELECT dl_is_member_here());

CREATE OR REPLACE VIEW mcp_dataset_follows WITH (security_barrier = true) AS
SELECT f.member_id, f.dataset_id, f.what, f.created_at FROM dataset_follows f WHERE f.member_id = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Ingestion (sources: never the credential; loads and their rejects at the dataset's level)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_sources WITH (security_barrier = true) AS
SELECT s.id AS source_id, s.name, s.connector, s.role, s.base_url, s.settings - 'password' - 'secret' AS settings, s.credential_id IS NOT NULL AS has_credential,
       (SELECT c.last4 FROM source_credentials c WHERE c.id = s.credential_id) AS credential_last4, s.schedule_minutes, s.last_pull_id, s.last_ok_at,
       s.consecutive_failures, s.backoff_until, s.paused_at, s.paused_reason, s.active, s.created_by, s.created_at, s.updated_at
  FROM sources s WHERE (SELECT dl_has_right('sources.manage')) OR (SELECT dl_is_admin());

CREATE OR REPLACE VIEW mcp_source_pulls WITH (security_barrier = true) AS
SELECT p.id AS pull_id, p.source_id, p.kind, p.started_at, p.finished_at, p.status, p.listings_seen AS items_seen, p.listings_new AS items_landed,
       p.http_requests, p.bytes, p.error, p.started_by
  FROM source_pulls p WHERE (SELECT dl_has_right('sources.manage')) OR (SELECT dl_is_admin());

CREATE OR REPLACE VIEW mcp_loads WITH (security_barrier = true) AS
SELECT l.id AS load_id, l.dataset_id, l.source_id, l.pull_id, l.upload_session_id, l.landing_sha256, l.mode, l.status, l.rows_read, l.rows_loaded, l.rows_rejected,
       l.rows_rescued, l.snapshot_id, l.schema_seen, l.checks_outcome, l.error, l.requested_by, l.queued_at, l.started_at, l.finished_at, l.rolled_back_at,
       l.rolled_back_by, l.landing_purged_at
  FROM loads l WHERE (SELECT dl_is_member_here()) AND dl_level_rank(dl_level(l.dataset_id)) >= 2;

CREATE OR REPLACE VIEW mcp_load_rejects WITH (security_barrier = true) AS
SELECT r.id AS reject_id, r.load_id, r.line, r.byte_position, r.column_name, r.error_type, r.message,
       CASE WHEN dl_level_rank(dl_level(l.dataset_id)) >= 4 THEN r.raw_line END AS raw_line
  FROM load_rejects r JOIN loads l ON l.id = r.load_id
 WHERE (SELECT dl_is_member_here()) AND dl_level_rank(dl_level(l.dataset_id)) >= 2;

CREATE OR REPLACE VIEW mcp_uploads WITH (security_barrier = true) AS
SELECT u.id AS upload_id, u.member_id, u.filename, u.byte_size, u.parts_total, u.parts_done, u.status, u.sniff_status, u.target_dataset_id, u.source_id,
       u.created_at, u.expires_at
  FROM upload_sessions u WHERE u.member_id = app_current_member_id() OR (SELECT dl_is_admin());

CREATE OR REPLACE VIEW mcp_import_templates WITH (security_barrier = true) AS
SELECT t.id AS template_id, t.name, t.kind, t.connector, t.mapping, t.created_by, t.updated_at FROM import_templates t WHERE (SELECT dl_is_member_here());

CREATE OR REPLACE VIEW mcp_lake_jobs WITH (security_barrier = true) AS
SELECT j.id AS job_id, j.kind, j.dataset_id, j.ref_id, j.priority, j.status, j.attempts, j.run_after, j.started_at, j.finished_at, j.error, j.requested_by, j.created_at
  FROM lake_jobs j WHERE (SELECT dl_is_admin()) OR (SELECT dl_has_right('loads.manage')) OR j.requested_by = app_current_member_id();

-- ---------------------------------------------------------------------------------------------
-- Trust
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_contracts WITH (security_barrier = true) AS
SELECT k.id AS contract_id, k.dataset_id, k.version, k.status, k.schema, k.key, k.ordering_column, k.sla, k.description, k.breaking, k.breaking_changes,
       k.published_by, k.published_at, k.created_at
  FROM contracts k WHERE (SELECT dl_is_member_here()) AND dl_can_discover(k.dataset_id);

CREATE OR REPLACE VIEW mcp_checks WITH (security_barrier = true) AS
SELECT k.id AS check_id, k.dataset_id, k.column_name, k.kind, k.params, k.dimension, k.severity, k.blocking, k.run_when, k.description, k.source, k.enabled,
       (SELECT jsonb_build_object('passed', r.passed, 'value', r.value, 'rows_failed', r.rows_failed, 'ran_at', r.ran_at) FROM check_results r
         WHERE r.check_id = k.id ORDER BY r.ran_at DESC, r.id DESC LIMIT 1) AS last_result
  FROM checks k WHERE (SELECT dl_is_member_here()) AND dl_can_discover(k.dataset_id);

CREATE OR REPLACE VIEW mcp_check_results WITH (security_barrier = true) AS
SELECT r.id AS result_id, r.check_id, r.dataset_id, r.load_id, r.snapshot_id, r.value, r.threshold, r.passed, r.rows_failed,
       CASE WHEN dl_level_rank(dl_level(r.dataset_id)) >= 2 THEN r.failing_keys ELSE '[]'::jsonb END AS failing_keys, r.took_ms, r.error, r.ran_at
  FROM check_results r WHERE (SELECT dl_is_member_here()) AND dl_can_discover(r.dataset_id);

CREATE OR REPLACE VIEW mcp_incidents WITH (security_barrier = true) AS
SELECT i.id AS incident_id, i.dataset_id, i.kind, i.check_id, i.load_id, i.severity, i.status, i.title, i.assignee_member_id, i.opened_at, i.acknowledged_at,
       i.resolved_at, i.root_cause, i.resolution, i.downstream, i.updated_at
  FROM incidents i WHERE (SELECT dl_is_member_here()) AND dl_can_discover(i.dataset_id);

CREATE OR REPLACE VIEW mcp_incident_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.incident_id, n.member_id, n.body, n.kind, n.created_at
  FROM incident_notes n JOIN incidents i ON i.id = n.incident_id WHERE (SELECT dl_is_member_here()) AND dl_can_discover(i.dataset_id);

-- ---------------------------------------------------------------------------------------------
-- Access (grants: the dataset's owner, a holder of grants.manage, or the grantee; requests: the requester, the decider)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_grants WITH (security_barrier = true) AS
SELECT g.id AS grant_id, g.target_kind, g.dataset_id, g.zone, g.tag_id, g.principal_kind, g.principal_id, g.principal_ref, g.level, g.row_filter, g.expires_at,
       g.reason, g.granted_by, g.created_at, g.revoked_at, g.revoked_by, g.access_request_id
  FROM grants g
 WHERE (SELECT dl_is_member_here())
   AND ((SELECT dl_has_right('grants.manage'))
        OR (g.principal_kind IN ('member', 'agent') AND g.principal_id = app_current_member_id())
        OR (g.dataset_id IS NOT NULL AND EXISTS (SELECT 1 FROM datasets d WHERE d.id = g.dataset_id AND d.owner_member_id = app_current_member_id())));

CREATE OR REPLACE VIEW mcp_access_requests WITH (security_barrier = true) AS
SELECT r.id AS request_id, r.requester_id, r.dataset_id, r.level, r.reason, r.wanted_until, r.drafted_by_agent, r.status, r.decided_by, r.decided_at, r.decision_note,
       r.grant_id, r.created_at
  FROM access_requests r
 WHERE (SELECT dl_is_member_here())
   AND (r.requester_id = app_current_member_id() OR (SELECT dl_has_right('grants.manage'))
        OR EXISTS (SELECT 1 FROM datasets d WHERE d.id = r.dataset_id AND d.owner_member_id = app_current_member_id()));

CREATE OR REPLACE VIEW mcp_masking_policies WITH (security_barrier = true) AS
SELECT p.applies_to, p.mask, p.note, p.updated_at FROM masking_policies p WHERE (SELECT dl_is_member_here());

CREATE OR REPLACE VIEW mcp_access_changes WITH (security_barrier = true) AS
SELECT a.id AS change_id, a.dataset_id, a.zone, a.tag_id, a.grant_id, a.principal_kind, a.principal_id, a.principal_ref, a.old_level, a.new_level, a.row_filter,
       a.changed_by, a.changed_at
  FROM access_changes a
 WHERE (SELECT dl_has_right('grants.manage'))
    OR (a.dataset_id IS NOT NULL AND EXISTS (SELECT 1 FROM datasets d WHERE d.id = a.dataset_id AND d.owner_member_id = app_current_member_id()));

-- ---------------------------------------------------------------------------------------------
-- Queries (the audit: a person their own; an owner the reads of their dataset; audit.read every row)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_query_runs WITH (security_barrier = true) AS
SELECT q.id AS query_run_id, q.caller_member_id, q.on_behalf_of, q.agent_run_id, q.request_id, q.source, q.consumer_app, q.key_label, q.saved_query_id, q.metric_id,
       q.sql_sha256,
       CASE WHEN q.caller_member_id = app_current_member_id() OR q.on_behalf_of = app_current_member_id() OR (SELECT dl_has_right('audit.read')) THEN q.sql END AS sql,
       q.as_of, q.datasets, q.dataset_ids, q.masked_columns, q.rows_returned, q.truncated, q.bytes_returned, q.ms, q.status, q.refusal_rule, q.created_at
  FROM query_runs q
 WHERE (SELECT dl_is_member_here())
   AND (q.caller_member_id = app_current_member_id() OR q.on_behalf_of = app_current_member_id() OR (SELECT dl_has_right('audit.read'))
        OR EXISTS (SELECT 1 FROM datasets d WHERE d.id = ANY (q.dataset_ids) AND d.owner_member_id = app_current_member_id()));

CREATE OR REPLACE VIEW mcp_saved_queries WITH (security_barrier = true) AS
SELECT q.id AS saved_query_id, q.owner_member_id, q.title, q.description, q.question, q.sql, q.parameters, q.dataset_ids, q.shared, q.verified_by, q.verified_at,
       q.unverified_reason, q.created_at, q.updated_at
  FROM saved_queries q
 WHERE q.deleted_at IS NULL AND (SELECT dl_is_member_here())
   AND (q.owner_member_id = app_current_member_id()
        OR (q.shared AND NOT EXISTS (SELECT 1 FROM unnest(q.dataset_ids) x WHERE dl_level_rank(dl_level(x)) < 2)));

CREATE OR REPLACE VIEW mcp_metrics WITH (security_barrier = true) AS
SELECT m.id AS metric_id, m.name, m.title, m.description, m.dataset_id, m.expression, m.time_column, m.grains, m.dimensions, m.filters, m.format, m.unit,
       m.owner_member_id, m.certified, m.certified_at, m.updated_at
  FROM metrics m WHERE m.retired_at IS NULL AND (SELECT dl_is_member_here()) AND dl_can_discover(m.dataset_id);

CREATE OR REPLACE VIEW mcp_dataset_usage WITH (security_barrier = true) AS
SELECT u.dataset_id, u.day, u.queries, u.people, u.agents, u.rows_read FROM dataset_usage_daily u WHERE (SELECT dl_is_member_here()) AND dl_can_discover(u.dataset_id);

-- ---------------------------------------------------------------------------------------------
-- Transforms and lineage (a node the caller may not discover is shown by the read functions as counted and unnamed)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_transforms WITH (security_barrier = true) AS
SELECT t.id AS transform_id, t.name, t.description, t.output_dataset_id, t.sql, t.input_dataset_ids, t.mode, t.schedule, t.version, t.owner_member_id, t.enabled,
       t.frozen_at, t.last_run_id, t.updated_at
  FROM transforms t WHERE (SELECT dl_is_member_here()) AND dl_can_discover(t.output_dataset_id);

CREATE OR REPLACE VIEW mcp_transform_runs WITH (security_barrier = true) AS
SELECT r.id AS transform_run_id, r.transform_id, r.version, r.status, r.input_snapshots, r.output_snapshot, r.rows_written, r.error, r.started_at, r.finished_at
  FROM transform_runs r JOIN transforms t ON t.id = r.transform_id WHERE (SELECT dl_is_member_here()) AND dl_can_discover(t.output_dataset_id);

CREATE OR REPLACE VIEW mcp_lineage_edges WITH (security_barrier = true) AS
SELECT e.id AS edge_id, e.from_dataset_id, e.from_source_id, e.to_dataset_id, e.via, e.transform_id, e.column_map, e.first_seen, e.last_seen
  FROM lineage_edges e
 WHERE (SELECT dl_is_member_here()) AND dl_can_discover(e.to_dataset_id) AND (e.from_dataset_id IS NULL OR dl_can_discover(e.from_dataset_id));

-- ---------------------------------------------------------------------------------------------
-- Health (the admin's; storage and maintenance also an engineer's); an erasure's identifier VALUES never — their kinds and count
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_erasure_requests WITH (security_barrier = true) AS
SELECT e.id AS erasure_id, (SELECT jsonb_agg(x ->> 'kind') FROM jsonb_array_elements(e.identifiers) x) AS identifier_kinds,
       jsonb_array_length(e.identifiers) AS identifier_count, e.scope, e.scope_zones, e.scope_dataset_ids, e.reason, e.requested_by, e.approved_by, e.approved_at,
       e.status, e.found, e.proof, e.error, e.created_at, e.finished_at
  FROM erasure_requests e WHERE (SELECT dl_has_right('erasure.manage'));

CREATE OR REPLACE VIEW mcp_retention_policies WITH (security_barrier = true) AS
SELECT r.id AS retention_id, r.zone, r.dataset_id, r.keep_days, r.by_column, r.action, r.enabled, r.last_run_at, r.last_rows
  FROM retention_policies r WHERE (SELECT dl_is_member_here()) AND (r.dataset_id IS NULL OR dl_can_discover(r.dataset_id));

CREATE OR REPLACE VIEW mcp_maintenance_runs WITH (security_barrier = true) AS
SELECT m.id AS maintenance_id, m.kind, m.status, m.inlined_flushed, m.files_merged, m.files_rewritten, m.snapshots_expired, m.files_deleted, m.bytes_freed,
       m.error, m.started_at, m.finished_at
  FROM maintenance_runs m WHERE (SELECT dl_is_admin()) OR (SELECT dl_has_right('maintenance.run'));

CREATE OR REPLACE VIEW mcp_backups WITH (security_barrier = true) AS
SELECT b.id AS backup_id, b.kind, b.status, b.catalog_bytes, b.files_bytes, b.snapshot_id, b.checks, b.error, b.started_at, b.finished_at, b.expires_at
  FROM backups b WHERE (SELECT dl_is_admin());

CREATE OR REPLACE VIEW mcp_storage_daily WITH (security_barrier = true) AS
SELECT s.day, s.dataset_id, s.zone, s.bytes, s.files, s.rows FROM storage_daily s
 WHERE (SELECT dl_is_admin()) OR (SELECT dl_has_right('maintenance.run'));

-- ---------------------------------------------------------------------------------------------
-- The person's own, the agents', the keys
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at, n.dataset_id, n.incident_id
  FROM notifications n WHERE n.member_id = app_current_member_id();

CREATE OR REPLACE VIEW mcp_exports WITH (security_barrier = true) AS
SELECT x.id AS export_id, x.kind, x.format, x.status, x.byte_size, x.item_count, x.row_count, x.truncated, x.masked, x.dataset_id, x.query_run_id, x.created_by,
       x.created_at, x.finished_at, x.expires_at, x.downloaded_at
  FROM exports x WHERE x.created_by = app_current_member_id() OR (SELECT dl_is_admin());

CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.member_id, n.body, n.created_at
  FROM notes n
 WHERE (SELECT dl_is_member_here())
   AND (n.record_type <> 'dataset' OR dl_can_discover(n.record_id))
   AND (n.record_type <> 'incident' OR EXISTS (SELECT 1 FROM incidents i WHERE i.id = n.record_id AND dl_can_discover(i.dataset_id)))
   AND (n.record_type <> 'saved_query' OR EXISTS (SELECT 1 FROM saved_queries q WHERE q.id = n.record_id AND (q.owner_member_id = app_current_member_id() OR q.shared)));

CREATE OR REPLACE VIEW mcp_steward_proposals WITH (security_barrier = true) AS
SELECT p.id AS proposal_id, p.kind, p.dataset_id, p.column_id, p.current_value, p.proposed_value, p.rationale, p.status, p.proposed_by, p.agent_run_id,
       p.decided_by, p.decided_at, p.decision_note, p.created_at
  FROM steward_proposals p
 WHERE (SELECT dl_is_member_here()) AND (p.dataset_id IS NULL OR dl_can_discover(p.dataset_id));

CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT a.id AS dispatch_id, a.record_type, a.record_id, a.agent_member_id, a.kind, a.via, a.status, a.reply_excerpt, a.attempts, a.created_at, a.answered_at,
       a.dataset_id, a.incident_id
  FROM agent_dispatches a WHERE (SELECT dl_is_admin());

CREATE OR REPLACE VIEW mcp_tokens WITH (security_barrier = true) AS
SELECT t.id AS key_id, t.member_id, t.label, t.scope, t.rate_per_minute, t.rate_per_day, t.allow_sql, t.rotated_from, dl_key_calls_today(t.id) AS calls_today,
       t.created_at, t.last_used_at, t.expires_at, t.revoked_at
  FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id() OR (SELECT dl_has_right('keys.all'));

CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT l.id AS log_id, l.occurred_at, l.actor_member_id, l.source, l.action, l.screen, l.entity_type, l.entity_id, l.after, l.request_id, l.agent_run_id,
       l.department_id, l.token_id, l.dataset_id, l.query_run_id
  FROM activity_log l
 WHERE (SELECT dl_is_member_here())
   AND (l.dataset_id IS NULL OR dl_can_discover(l.dataset_id))
   AND (l.query_run_id IS NULL OR l.actor_member_id = app_current_member_id() OR (SELECT dl_has_right('audit.read')));

-- ---------------------------------------------------------------------------------------------
-- The read functions the screens, the tools and the Steward share
-- ---------------------------------------------------------------------------------------------

-- T1: can I trust X — checks and last results, freshness, open incidents, certification and what it lacks.
CREATE OR REPLACE FUNCTION dl_dataset_health(p_dataset_id bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; fresh text;
BEGIN
    IF NOT dl_can_discover(p_dataset_id) THEN RETURN NULL; END IF;
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    fresh := CASE WHEN d.freshness_warn_after IS NULL THEN 'no expectation'
                  WHEN d.data_updated_at IS NULL THEN 'never loaded'
                  WHEN d.freshness_error_after IS NOT NULL AND d.data_updated_at < now() - d.freshness_error_after THEN 'late (error)'
                  WHEN d.data_updated_at < now() - d.freshness_warn_after THEN 'late (warn)' ELSE 'fresh' END;
    RETURN jsonb_build_object(
        'dataset', d.key, 'certification', d.certification, 'certification_missing', to_jsonb(dl_certification_eligible(d.id)),
        'certification_lost_reason', d.certification_lost_reason, 'freshness', fresh, 'data_updated_at', d.data_updated_at,
        'health', CASE WHEN EXISTS (SELECT 1 FROM incidents i WHERE i.dataset_id = d.id AND i.severity = 'error' AND i.status IN ('new', 'acknowledged', 'investigating')) THEN 'red'
                       WHEN fresh LIKE 'late%' OR EXISTS (SELECT 1 FROM incidents i WHERE i.dataset_id = d.id AND i.status IN ('new', 'acknowledged', 'investigating')) THEN 'amber'
                       ELSE 'green' END,
        'checks', (SELECT COALESCE(jsonb_agg(jsonb_build_object('check_id', k.id, 'kind', k.kind, 'column', k.column_name, 'blocking', k.blocking,
                                                                 'passed', r.passed, 'value', r.value, 'ran_at', r.ran_at) ORDER BY k.id), '[]'::jsonb)
                     FROM checks k LEFT JOIN LATERAL (SELECT * FROM check_results x WHERE x.check_id = k.id ORDER BY x.ran_at DESC, x.id DESC LIMIT 1) r ON true
                    WHERE k.dataset_id = d.id AND k.enabled),
        'open_incidents', (SELECT COALESCE(jsonb_agg(jsonb_build_object('incident_id', i.id, 'title', i.title, 'severity', i.severity, 'status', i.status,
                                                                         'opened_at', i.opened_at) ORDER BY i.opened_at DESC), '[]'::jsonb)
                             FROM incidents i WHERE i.dataset_id = d.id AND i.status IN ('new', 'acknowledged', 'investigating')),
        'upstream_incidents', (SELECT COALESCE(jsonb_agg(DISTINCT u.key), '[]'::jsonb) FROM dl_lineage(d.id, 'up', 10) l JOIN datasets u ON u.id = l.dataset_id
                                 WHERE EXISTS (SELECT 1 FROM incidents i WHERE i.dataset_id = u.id AND i.status IN ('new', 'acknowledged', 'investigating'))
                                   AND dl_can_discover(u.id)));
END$$;

-- C8: what the catalog lacks (an engineer's gaps list), in the order the field fixes it — tier 1 first.
CREATE OR REPLACE FUNCTION dl_catalog_gaps(p_limit integer DEFAULT 100) RETURNS TABLE (dataset_id bigint, key text, tier text, gaps text[])
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT d.id, d.key, d.tier,
           array_remove(ARRAY[
               CASE WHEN d.owner_member_id IS NULL THEN 'owner' END,
               CASE WHEN d.steward_member_id IS NULL THEN 'steward' END,
               CASE WHEN d.description IS NULL OR length(trim(d.description)) < 20 THEN 'description' END,
               CASE WHEN EXISTS (SELECT 1 FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND NOT c.is_system AND c.description IS NULL) THEN 'column descriptions' END,
               CASE WHEN EXISTS (SELECT 1 FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND c.proposed_personal_kind IS NOT NULL AND c.classification_confirmed_at IS NULL) THEN 'personal data to confirm' END,
               CASE WHEN NOT EXISTS (SELECT 1 FROM checks k WHERE k.dataset_id = d.id AND k.enabled) THEN 'checks' END,
               CASE WHEN d.freshness_warn_after IS NULL AND d.zone IN ('curated', 'product') THEN 'freshness expectation' END], NULL)
      FROM datasets d
     WHERE d.status IN ('draft', 'active') AND d.zone <> 'sandbox' AND dl_has_right('catalog.edit')
     ORDER BY d.tier, d.key
     LIMIT greatest(1, least(p_limit, 1000));
$$;

-- Coverage against the target (the coverage screen; the Steward's report): described / owned / classified / checked, by tier.
CREATE OR REPLACE FUNCTION dl_coverage() RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT jsonb_build_object('target', (SELECT certification_target FROM dl_settings WHERE id = 1),
        'by_tier', COALESCE(jsonb_object_agg(t.tier, t.v), '{}'::jsonb))
      FROM (SELECT d.tier, jsonb_build_object(
                'datasets', count(*),
                'described', round(avg((d.description IS NOT NULL AND length(trim(d.description)) >= 20)::int), 3),
                'owned', round(avg((d.owner_member_id IS NOT NULL)::int), 3),
                'classified', round(avg((NOT EXISTS (SELECT 1 FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL
                                                        AND c.proposed_personal_kind IS NOT NULL AND c.classification_confirmed_at IS NULL))::int), 3),
                'checked', round(avg((EXISTS (SELECT 1 FROM checks k WHERE k.dataset_id = d.id AND k.enabled))::int), 3),
                'certified', round(avg((d.certification = 'certified')::int), 3)) AS v
              FROM datasets d WHERE d.status IN ('draft', 'active') AND d.zone <> 'sandbox' GROUP BY d.tier) t;
$$;

-- The Steward's weekday report (§5): one read, names and counts and ids — never a row value.
CREATE OR REPLACE FUNCTION dl_steward_report() RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT jsonb_build_object(
        'undescribed', (SELECT COALESCE(jsonb_agg(jsonb_build_object('dataset_id', d.id, 'key', d.key, 'tier', d.tier) ORDER BY d.tier, d.key), '[]'::jsonb)
                          FROM datasets d WHERE d.status IN ('draft', 'active') AND d.zone <> 'sandbox'
                           AND (d.description IS NULL OR EXISTS (SELECT 1 FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND NOT c.is_system AND c.description IS NULL))),
        'personal_to_confirm', (SELECT COALESCE(jsonb_agg(jsonb_build_object('dataset_id', c.dataset_id, 'column_id', c.id, 'column', c.name, 'proposed', c.proposed_personal_kind,
                                                                              'confidence', c.proposed_confidence) ORDER BY c.dataset_id, c.ordinal), '[]'::jsonb)
                                  FROM dataset_columns c WHERE c.removed_snapshot_id IS NULL AND c.proposed_personal_kind IS NOT NULL AND c.classification_confirmed_at IS NULL),
        'no_owner', (SELECT COALESCE(jsonb_agg(d.key ORDER BY d.key), '[]'::jsonb) FROM datasets d WHERE d.owner_member_id IS NULL AND d.status IN ('draft', 'active')),
        'late', (SELECT COALESCE(jsonb_agg(jsonb_build_object('key', f.key, 'state', f.state)), '[]'::jsonb) FROM dl_freshness_due() f),
        'open_incidents', (SELECT COALESCE(jsonb_agg(jsonb_build_object('incident_id', i.id, 'key', d.key, 'title', i.title, 'severity', i.severity, 'status', i.status)
                                                     ORDER BY i.opened_at), '[]'::jsonb)
                             FROM incidents i JOIN datasets d ON d.id = i.dataset_id WHERE i.status IN ('new', 'acknowledged', 'investigating')),
        'drift', (SELECT COALESCE(jsonb_agg(jsonb_build_object('key', d.key, 'version', v.version, 'changes', v.changes)), '[]'::jsonb)
                    FROM schema_versions v JOIN datasets d ON d.id = v.dataset_id WHERE v.decision = 'pending'),
        'unused', (SELECT COALESCE(jsonb_agg(d.key ORDER BY d.key), '[]'::jsonb) FROM datasets d WHERE d.tier = 'unused' AND d.status = 'active'),
        'sandbox_for_promotion', (SELECT COALESCE(jsonb_agg(jsonb_build_object('key', d.key, 'usage_30d', dl_usage_30d(d.id))), '[]'::jsonb)
                                    FROM datasets d WHERE d.zone = 'sandbox' AND d.status = 'active' AND dl_usage_30d(d.id) >= 20),
        'coverage', dl_coverage(),
        'last_drill', (SELECT max(finished_at) FROM backups WHERE kind = 'drill' AND status = 'ok'));
$$;

GRANT SELECT ON mcp_members, mcp_departments, mcp_settings, mcp_groups, mcp_datasets, mcp_dataset_columns, mcp_column_profiles, mcp_schema_versions, mcp_glossary_terms,
    mcp_tags, mcp_dataset_follows, mcp_sources, mcp_source_pulls, mcp_loads, mcp_load_rejects, mcp_uploads, mcp_import_templates, mcp_lake_jobs, mcp_contracts,
    mcp_checks, mcp_check_results, mcp_incidents, mcp_incident_notes, mcp_grants, mcp_access_requests, mcp_masking_policies, mcp_access_changes, mcp_query_runs,
    mcp_saved_queries, mcp_metrics, mcp_dataset_usage, mcp_transforms, mcp_transform_runs, mcp_lineage_edges, mcp_erasure_requests, mcp_retention_policies,
    mcp_maintenance_runs, mcp_backups, mcp_storage_daily, mcp_notifications, mcp_exports, mcp_notes, mcp_steward_proposals, mcp_agent_dispatches, mcp_tokens,
    mcp_app_roles
    TO datalake_records_ro, datalake_rw;
GRANT SELECT ON mcp_activity_log, mcp_members, mcp_departments, mcp_datasets, mcp_query_runs, mcp_loads, mcp_incidents TO datalake_activity_ro;
GRANT EXECUTE ON FUNCTION dl_dataset_health(bigint), dl_catalog_gaps(integer), dl_coverage() TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_steward_report() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_steward_report() TO datalake_rw, datalake_records_ro;

COMMIT;
