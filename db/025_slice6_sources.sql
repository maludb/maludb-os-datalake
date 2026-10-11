-- 025: slice 6 — sources and pulls (docs/build-specs/sources-pulls.md). Additive, and small: the spec planned no migration, and the build found the one
-- thing the schema did not give the application — the right to DELETE a source (db/009 granted datalake_rw SELECT, INSERT and UPDATE on `sources`, and DELETE
-- only on source_credentials).
--   source_delete removes the `sources` row. Its pulls (source_pulls), its sealed credential (source_credentials) and its lineage edges (lineage_edges) go with
--   it by their foreign keys (ON DELETE CASCADE); the datasets it fed STAY (datasets.source_id, loads.source_id and loads.pull_id are SET NULL, and
--   upload_sessions.source_id); the foreign keys' own actions run with the referencing tables' owner, so the application's role needs no more than this.
--   The application's role may NOT delete a pull directly (the pulls are the ledger of what was fetched) and the writer never deletes a source.
BEGIN;
GRANT DELETE ON sources TO datalake_rw;
COMMIT;
