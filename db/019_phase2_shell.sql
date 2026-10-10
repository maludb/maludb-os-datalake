-- Data Lake, Phase 2 (2026-10-10) — what the shell found the schema lacked (docs/build-specs/sso-shell.md).
--
-- My activity (screen `activity`) reads the trail through mcp_activity_log, the view that applies the wall to a row about a dataset or a
-- query run (db/016). db/016 granted it to the activity server's role only; the application's own role reads it too, so the screen shows
-- exactly what the activity server would — never activity_log directly. (Sales CRM found the same in its Phase 2, its db/018.)
BEGIN;

GRANT SELECT ON mcp_activity_log TO datalake_rw;

COMMIT;
