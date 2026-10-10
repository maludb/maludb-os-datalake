-- 017: what the engine proof found (tests/engine/FINDINGS.md §7, §4, §9) — Phase 0, 2026-10-10. Additive.
--
-- 1. ERASURE FOLLOWS REFERENCES. The proof caught what the scan missed: a subject's orders, keyed by `customer_id` with no email, stayed
--    in the lake. A column may now declare the dataset (and column) it references — set from the contract or a referential check, or by
--    an engineer — and dl_erasure_targets() follows those references from every directly matched dataset (via = 'reference'): the writer
--    collects the subject's key values in the referenced dataset and deletes the referencing rows. verify() stays independent of the scan.
-- 2. ERASURE ENDS TIME TRAVEL LAKE-WIDE. Expiring the snapshots that held the rows expires them for every dataset (a snapshot is the
--    whole lake's state). An approval now says it knows (`time_travel_acknowledged`), and the screen says it before the button.
-- 3. SMALL LOADS ARE PLAIN TEXT UNTIL FLUSHED. DuckLake's inlined batches (≤ 10 rows) live as ordinary PostgreSQL rows — unencrypted,
--    in WAL and base backups — until flushed: the worker flushes every `inline_flush_minutes` (60), not only at night.
BEGIN;

ALTER TABLE dataset_columns ADD COLUMN references_dataset_id bigint REFERENCES datasets(id) ON DELETE SET NULL;
ALTER TABLE dataset_columns ADD COLUMN references_column text CHECK (references_column IS NULL OR references_column ~ '^[A-Za-z_][A-Za-z0-9_]{0,62}$');
ALTER TABLE dataset_columns ADD CONSTRAINT dataset_columns_reference_pair CHECK ((references_dataset_id IS NULL) = (references_column IS NULL));
CREATE INDEX dataset_columns_references_idx ON dataset_columns (references_dataset_id) WHERE references_dataset_id IS NOT NULL;

ALTER TABLE dl_settings ADD COLUMN inline_flush_minutes integer NOT NULL DEFAULT 60 CHECK (inline_flush_minutes BETWEEN 5 AND 1440);

ALTER TABLE erasure_requests ADD COLUMN time_travel_acknowledged boolean NOT NULL DEFAULT false;

-- The scan's input, now with the references followed (and the referenced key named so the writer can collect the subject's values).
DROP FUNCTION dl_erasure_targets(bigint);
CREATE FUNCTION dl_erasure_targets(p_request_id bigint)
    RETURNS TABLE (dataset_id bigint, key text, zone text, column_name text, personal_kind text, via text,
                   references_dataset_id bigint, references_column text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    WITH r AS (SELECT * FROM erasure_requests WHERE id = p_request_id),
         kinds AS (SELECT DISTINCT e ->> 'kind' AS kind FROM r, jsonb_array_elements(r.identifiers) e),
         direct AS (
            SELECT d.id, d.key, d.zone, c.name, c.personal_kind
              FROM datasets d JOIN dataset_columns c ON c.dataset_id = d.id AND c.removed_snapshot_id IS NULL
              JOIN kinds k ON k.kind = c.personal_kind, r
             WHERE d.status <> 'retired'
               AND (r.scope = 'all' OR (r.scope = 'zones' AND d.zone = ANY (r.scope_zones)) OR (r.scope = 'datasets' AND d.id = ANY (r.scope_dataset_ids)))),
         referencing AS (
            SELECT d.id, d.key, d.zone, c.name, c.references_dataset_id, c.references_column
              FROM dataset_columns c JOIN datasets d ON d.id = c.dataset_id AND d.status <> 'retired'
             WHERE c.removed_snapshot_id IS NULL AND c.references_dataset_id IN (SELECT id FROM direct)),
         derived AS (
            SELECT DISTINCT l.dataset_id FROM (SELECT id FROM direct UNION SELECT id FROM referencing) x
             CROSS JOIN LATERAL dl_lineage(x.id, 'down', 10) l
             WHERE l.dataset_id IS NOT NULL AND l.dataset_id NOT IN (SELECT id FROM direct) AND l.dataset_id NOT IN (SELECT id FROM referencing))
    SELECT id, key, zone, name, personal_kind, 'column', NULL::bigint, NULL::text FROM direct
    UNION ALL
    SELECT id, key, zone, name, NULL, 'reference', references_dataset_id, references_column FROM referencing
    UNION ALL
    SELECT d.id, d.key, d.zone, c.name, c.personal_kind, 'lineage', NULL, NULL
      FROM derived x JOIN datasets d ON d.id = x.dataset_id
      JOIN dataset_columns c ON c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND c.personal_kind IN (SELECT kind FROM kinds);
$$;
REVOKE ALL ON FUNCTION dl_erasure_targets(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_erasure_targets(bigint) TO datalake_lake, datalake_rw;

-- Approval now acknowledges that time travel before now ends for every dataset.
DROP FUNCTION dl_erasure_approve(bigint);
CREATE FUNCTION dl_erasure_approve(p_request_id bigint, p_time_travel_acknowledged boolean) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r erasure_requests%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    IF NOT dl_has_right('erasure.manage') THEN RAISE EXCEPTION 'Approving an erasure is the Lake admin''s' USING ERRCODE = 'insufficient_privilege'; END IF;
    SELECT * INTO r FROM erasure_requests WHERE id = p_request_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such erasure' USING ERRCODE = 'no_data_found'; END IF;
    IF r.status <> 'awaiting_approval' THEN RAISE EXCEPTION 'An erasure is approved after its scan reports (it is %)', r.status USING ERRCODE = 'check_violation'; END IF;
    IF r.requested_by = me THEN RAISE EXCEPTION 'An erasure is approved by someone other than its requester' USING ERRCODE = 'check_violation'; END IF;
    IF NOT COALESCE(p_time_travel_acknowledged, false) THEN
        RAISE EXCEPTION 'An erasure ends time travel before now for EVERY dataset (a snapshot is the whole lake) — acknowledge it to approve'
            USING ERRCODE = 'check_violation';
    END IF;
    UPDATE erasure_requests SET status = 'erasing', approved_by = me, approved_at = now(), time_travel_acknowledged = true WHERE id = p_request_id;
    PERFORM dl_job_enqueue('erasure', NULL, p_request_id, '{"phase": "erase"}'::jsonb, 1::smallint);
END$$;
REVOKE ALL ON FUNCTION dl_erasure_approve(bigint, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_erasure_approve(bigint, boolean) TO datalake_rw;

COMMIT;
