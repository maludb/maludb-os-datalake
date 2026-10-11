-- 026: slice 7 — transforms, products, metrics and lineage (docs/build-specs/transforms-products-lineage.md). Additive, and small: the spec planned no
-- migration, and the build found three things the schema did not give:
--   1. transform_delete needs DELETE on `transforms` — db/012 granted the application SELECT, INSERT and UPDATE. The runs (transform_runs) go with the row by
--      their foreign key (ON DELETE CASCADE, run with the owner of the referencing table), the lineage edges and lineage_runs lose their transform
--      (SET NULL) and stay as history; the output dataset STAYS. The application still cannot delete a run directly.
--   2. A transform whose SQL changes reads other inputs: the edge from an input it no longer reads must go (dl_lineage_edge only upserts and the writer holds
--      SELECT on lineage_edges alone). dl_lineage_edge_prune(transform, kept inputs) deletes the 'transform' edges of that transform from any other dataset —
--      the writer calls it after it has recorded the edges of the run. Only 'transform' edges, only that transform's.
--   3. A failed transform tells its owner ONCE A DAY (spec: dedupe per transform per day). dl_notify dedupes only a ten-minute window and the writer cannot
--      read `notifications`, so the rule is a function: dl_transform_failed_notify(transform, words) → 1 when the owner was told, 0 when already today.
BEGIN;

GRANT DELETE ON transforms TO datalake_rw;

CREATE OR REPLACE FUNCTION dl_lineage_edge_prune(p_transform_id bigint, p_keep_dataset_ids bigint[]) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM lineage_edges
     WHERE transform_id = p_transform_id AND via = 'transform' AND from_dataset_id IS NOT NULL AND NOT (from_dataset_id = ANY (COALESCE(p_keep_dataset_ids, '{}')));
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION dl_transform_failed_notify(p_transform_id bigint, p_words text) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t transforms%ROWTYPE; nid bigint;
BEGIN
    SELECT * INTO t FROM transforms WHERE id = p_transform_id;
    IF NOT FOUND OR t.owner_member_id IS NULL THEN RETURN 0; END IF;
    IF EXISTS (SELECT 1 FROM notifications n WHERE n.member_id = t.owner_member_id AND n.kind = 'load_failed' AND n.record_type = 'transform'
                  AND n.record_id = t.id AND n.created_at > now() - interval '1 day') THEN
        RETURN 0;
    END IF;
    nid := dl_notify(t.owner_member_id, 'load_failed', left('The transform ' || t.name || ' failed', 200), left(COALESCE(p_words, 'It could not run.'), 500), 'transform', t.id,
                      t.output_dataset_id, NULL, 'transform_failed:' || t.id || ':' || to_char(now(), 'YYYYMMDD'));
    RETURN CASE WHEN nid IS NULL THEN 0 ELSE 1 END;
END$$;

REVOKE ALL ON FUNCTION dl_lineage_edge_prune(bigint, bigint[]), dl_transform_failed_notify(bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_lineage_edge_prune(bigint, bigint[]), dl_transform_failed_notify(bigint, text) TO datalake_lake;

COMMIT;
