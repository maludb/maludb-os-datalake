-- Data Lake, slice 1 — the catalog (2026-10-10): what the CRUD exemplar found the schema lacked (docs/build-specs/catalog.md "Built and
-- proven"). Additive: two views gain columns at their end (grants kept by CREATE OR REPLACE), one function is new.
--
-- 1. mcp_dataset_columns appends references_dataset_id and references_column: db/017 added the two columns to the table and never to the
--    view, and the tool surface's `dataset_columns` and the Columns tab name them ("→ {key}.{column}").
-- 2. mcp_datasets appends certification_lost_at: the dataset page shows "Certification lost: {reason}" for 30 days after the loss, and
--    the view carried the reason without its date (NULL in the stub branch — a stub shows no certification history).
-- 3. A TAKEN KEY IN WORDS. The unique index on datasets.key answers "duplicate key value violates unique constraint" — never a sentence a
--    person can act on. dl_dataset_key_taken(key) is the database's sentence for a taken key — and for a retired dataset's key, which is
--    never reused (R1) — or NULL when it is free; the create handler names it on the `name` field when the index refuses, and the form's
--    key preview asks it as the name is typed. It names no title and no owner: the key is all the person typed. (A function, not a
--    BEFORE INSERT trigger: a trigger that raised would defeat every INSERT … ON CONFLICT (key) a later slice or the writer writes.)
-- 4. dataset_follow changes what a follower hears about (following again): db/007 granted the application SELECT, INSERT and DELETE on
--    dataset_follows and no UPDATE, so the upsert was refused. UPDATE of the one column `what` is granted — nothing else of a follow changes.
BEGIN;

CREATE OR REPLACE VIEW mcp_dataset_columns WITH (security_barrier = true) AS
SELECT c.id AS column_id, c.dataset_id, c.name, c.ordinal, c.physical_type, c.logical_type, c.nullable, c.is_key, c.is_system, c.business_name, c.description,
       c.description_source, c.classification, c.personal_kind, c.classified_by, c.classification_confirmed_at, c.proposed_personal_kind, c.proposed_confidence,
       c.glossary_term_id, c.critical, c.profile, c.profiled_at, c.removed_snapshot_id,
       c.references_dataset_id, c.references_column
  FROM dataset_columns c
 WHERE (SELECT dl_is_member_here()) AND dl_can_discover(c.dataset_id);

CREATE OR REPLACE VIEW mcp_datasets WITH (security_barrier = true) AS
SELECT d.id AS dataset_id, d.key, d.zone, d.title, d.description, d.description_source, d.purpose, d.usage, d.limitations,
       d.owner_member_id, d.steward_member_id, d.department_id, d.status, d.deprecation_note, d.successor_id, d.certification, d.certified_by, d.certified_at,
       d.certification_lost_reason, d.tier, d.classification, d.has_personal_data, d.source_id, d.contract_version, d.row_count, d.byte_size, d.file_count,
       d.last_snapshot_id, d.data_updated_at, d.metadata_updated_at, d.freshness_warn_after, d.freshness_error_after, d.retention_days, d.evolution_mode,
       d.dedupe_key, d.ordering_column, d.created_at, dl_tags_of('dataset', d.id) AS tags, dl_level(d.id) AS my_level, false AS is_stub,
       (SELECT count(*) FROM incidents i WHERE i.dataset_id = d.id AND i.status IN ('new', 'acknowledged', 'investigating')) AS open_incidents,
       dl_usage_30d(d.id) AS usage_30d,
       d.certification_lost_at
  FROM datasets d
 WHERE (SELECT dl_is_member_here()) AND dl_can_discover(d.id)
UNION ALL
SELECT d.id, d.key, d.zone, d.title, NULL, NULL, NULL, NULL, NULL,
       d.owner_member_id, NULL, d.department_id, d.status, NULL, NULL, d.certification, NULL, NULL,
       NULL, NULL, d.classification, NULL, NULL, NULL, NULL, NULL, NULL,
       NULL, NULL, NULL, NULL, NULL, NULL, NULL,
       NULL, NULL, NULL, NULL, 'none', true,
       NULL, NULL,
       NULL
  FROM datasets d
 WHERE (SELECT dl_is_member_here()) AND d.status <> 'retired' AND NOT dl_can_discover(d.id) AND dl_can_see_stub(d.id);

-- A taken key in words (SECURITY DEFINER: the key may belong to a dataset the person cannot see — the sentence names only the key).
CREATE OR REPLACE FUNCTION dl_dataset_key_taken(p_key text) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN d.status = 'retired' THEN p_key || ' belonged to a retired dataset — a key is never reused; choose another name'
                ELSE p_key || ' is taken — choose another name' END
      FROM datasets d WHERE d.key = p_key;
$$;
REVOKE ALL ON FUNCTION dl_dataset_key_taken(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_dataset_key_taken(text) TO datalake_rw, datalake_records_ro;

GRANT UPDATE (what) ON dataset_follows TO datalake_rw;

COMMIT;
