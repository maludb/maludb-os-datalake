-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "datalake" first). A RECORD'S HISTORY IS THIS TABLE (design §6.8); the engine keeps a second,
-- engine-level history of every write in DuckLake's own snapshot changes, and every read is a query_runs row (db/006+), but what a
-- person or an agent DID is here. `before`/`after` carry ids, keys, names, counts, kinds, statuses, byte sizes -- A ROW VALUE, A
-- PROFILE'S TOP VALUES, SQL TEXT, A FILTER, A CREDENTIAL, A TOKEN OR A FILE PATH IS NEVER HERE (design §6.8: a WHERE clause can hold
-- an email address); a key is named by its label.
--
-- THE ESTATE'S DATA MODEL (design §6.8): the contract table (memory.md / Consultant Tracking db/002) with token_id as Knowledge db/002
-- appended it (the key an API call ran under; Sales CRM carries it too), and the Data Lake's audit keys APPENDED: dataset_id (every
-- event about a dataset, a column, a load, a check, an incident, a grant, a query -- the dataset's TIMELINE is one index) and
-- query_run_id (the query run a `query.*` event or an export concerns). No FK: history outlives a record, and the tables they name
-- (datasets, query_runs) come in db/006+. `source` widens the contract's list with 'system' (design §2: the writer and the query
-- service act on no one's click) -- an enumeration widened, the shared-schema rule's allowed change.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application', 'system')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a grant to it, a group mirroring it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    token_id        bigint,                        -- appended (Knowledge db/002): the key (mcp_access_tokens.id) a token-API event ran under; its LABEL is in `after`, never the key
    -- appended (design §6.8): the Data Lake's audit keys (no FK — history outlives a record; the tables come in db/006+)
    dataset_id      bigint,                        -- the dataset the event concerns (its own event, a column's, a load's, a check's, an incident's, a grant's)
    query_run_id    bigint                         -- the query run a query.* event or an export concerns
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;
CREATE INDEX activity_log_dataset_idx  ON activity_log (dataset_id, occurred_at DESC) WHERE dataset_id IS NOT NULL;
CREATE INDEX activity_log_query_run_idx ON activity_log (query_run_id) WHERE query_run_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO datalake_rw;
REVOKE ALL ON activity_log FROM datalake_records_ro, datalake_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO datalake_rw;

COMMIT;
