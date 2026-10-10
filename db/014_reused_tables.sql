-- 014: the tables the estate already keeps — notes, notifications, the person's preferences, the outbox the worker sends, agent
-- dispatches, exports, worker passes — and the Data Steward's PROPOSALS (design §5, §6.8).
--
-- THE ESTATE'S DATA MODEL: `notes` is GL db/009 verbatim; `notifications`, `notification_prefs`, `notification_outbox` are Spaces db/012 =
-- DocCloud db/013 = Sales CRM db/013 = SI db/014 VERBATIM (member-only; the kind list ours; notifications append dataset_id, incident_id,
-- actor_member_id; prefs append digest); `agent_dispatches` is GL db/014 = CT db/013 verbatim (kinds ours; dataset_id, incident_id,
-- next_attempt_at appended); `exports`, `worker_passes` Spaces db/016 (+ 023's params), kinds and formats widened, the lake's columns
-- appended; `steward_proposals` takes DocCloud's `clerk_proposals` shape (an agent's proposal a person accepts, edits or dismisses).
BEGIN;

-- reuse: GL db/009 verbatim
CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

-- reuse: Spaces db/012 verbatim (member-only); kinds ours; dataset_id/incident_id/actor appended
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('check_failed', 'incident_assigned', 'freshness_missed', 'schema_changed', 'access_requested', 'access_decided',
                                               'certification_lost', 'deprecated', 'export_ready', 'load_failed', 'proposal', 'steward_note', 'admin_read',
                                               'erasure_done', 'mention', 'digest')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.8)
    dataset_id   bigint REFERENCES datasets(id) ON DELETE CASCADE,
    incident_id  bigint REFERENCES incidents(id) ON DELETE CASCADE,
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);
CREATE INDEX notifications_member_all_idx ON notifications (member_id, created_at DESC);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,
    kinds          text[] NOT NULL DEFAULT '{check_failed,incident_assigned,access_requested,access_decided,certification_lost,deprecated,export_ready,load_failed,admin_read,erasure_done,mention}',
    text_kinds     text[] NOT NULL DEFAULT '{incident_assigned}',
    updated_at     timestamptz NOT NULL DEFAULT now(),
    -- appended
    digest         boolean NOT NULL DEFAULT false
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);

-- One notification: the bell row, and the outbox rows the person's preferences ask for. THE WALL: no notice about a dataset the member may
-- not discover (a confidential dataset's name never reaches someone outside it by a bell).
CREATE OR REPLACE FUNCTION dl_notify(p_member_id bigint, p_kind text, p_title text, p_body text, p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL,
                                     p_dataset_id bigint DEFAULT NULL, p_actor bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; p notification_prefs%ROWTYPE; em citext; inc bigint;
BEGIN
    IF p_member_id IS NULL THEN RETURN NULL; END IF;
    IF p_dedupe IS NOT NULL AND (EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe)
                                 OR EXISTS (SELECT 1 FROM notifications n WHERE n.member_id = p_member_id AND n.kind = p_kind AND n.record_type IS NOT DISTINCT FROM p_record_type
                                               AND n.record_id IS NOT DISTINCT FROM p_record_id AND n.created_at > now() - interval '10 minutes')) THEN
        RETURN NULL;
    END IF;
    IF p_dataset_id IS NOT NULL AND dl_level_rank((SELECT level FROM dl_member_access(p_dataset_id, p_member_id))) < 1 THEN
        RETURN NULL;
    END IF;
    IF p_record_type = 'incident' THEN inc := p_record_id; END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id, dataset_id, incident_id, actor_member_id)
    VALUES (p_member_id, p_kind, p_title, p_body, p_record_type, p_record_id, p_dataset_id, inc, p_actor) RETURNING id INTO nid;
    SELECT * INTO p FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO p; END IF;
    SELECT email INTO em FROM members WHERE id = p_member_id AND member_kind = 'human';
    IF p.email_enabled AND p_kind = ANY (p.kinds) AND em IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, em, p_kind, p_record_type, p_record_id, p_dedupe, p_title, COALESCE(p_body, p_title)) ON CONFLICT DO NOTHING;
    END IF;
    IF p.text_enabled AND p_kind = ANY (p.text_kinds) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END, p_title,
                left(COALESCE(p_body, p_title), 300)) ON CONFLICT DO NOTHING;
    END IF;
    RETURN nid;
END$$;

-- reuse: GL db/014 = CT db/013 verbatim; kinds ours; dataset_id/incident_id/next_attempt_at appended
CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('incident', 'check_failed', 'load_failed', 'drift', 'proposal', 'ask', 'duty_proposal')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §5)
    dataset_id       bigint REFERENCES datasets(id) ON DELETE SET NULL,
    incident_id      bigint REFERENCES incidents(id) ON DELETE SET NULL,
    next_attempt_at  timestamptz
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);

-- The Data Steward (the agent named in the settings' agents page; until then the first active agent whose name says steward).
CREATE OR REPLACE FUNCTION dl_steward_agent() RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT id FROM members WHERE member_kind = 'agent' AND status = 'active' AND capability IS NOT NULL AND display_name ILIKE '%steward%' ORDER BY id LIMIT 1;
$$;
-- A blocking failure on a curated or product dataset wakes the Steward to triage (§5 (c)); one dispatch per incident.
CREATE OR REPLACE FUNCTION dl_incidents_dispatch() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE agent bigint := dl_steward_agent();
BEGIN
    IF agent IS NOT NULL AND NEW.severity = 'error' THEN
        INSERT INTO agent_dispatches (record_type, record_id, agent_member_id, kind, via, dataset_id, incident_id, detail)
        VALUES ('incident', NEW.id, agent, 'incident', 'chat', NEW.dataset_id, NEW.id, NEW.title);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER incidents_dispatch AFTER INSERT ON incidents FOR EACH ROW EXECUTE FUNCTION dl_incidents_dispatch();

-- reuse: Spaces db/016 (+023 params); kinds and formats widened; the lake's columns appended
CREATE TABLE exports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('page', 'space', 'channel', 'database', 'all', 'result', 'dataset', 'contract', 'lineage')),
    format        text NOT NULL CHECK (format IN ('md', 'html', 'csv', 'json', 'zip', 'pdf', 'xlsx', 'parquet', 'yaml')),
    page_id       uuid,
    space_id      bigint,
    channel_id    bigint,
    include_subpages boolean NOT NULL DEFAULT true,
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    storage_path  text,
    byte_size     bigint,
    item_count    integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    expires_at    timestamptz NOT NULL DEFAULT now() + interval '7 days',
    downloaded_at timestamptz,
    -- appended (Spaces db/023; design §6.5)
    params        jsonb NOT NULL DEFAULT '{}'::jsonb,
    dataset_id    bigint REFERENCES datasets(id) ON DELETE SET NULL,
    query_run_id  bigint REFERENCES query_runs(id) ON DELETE SET NULL,
    sql_sha256    text,
    row_count     bigint,
    truncated     boolean NOT NULL DEFAULT false,
    masked        boolean NOT NULL DEFAULT false,                   -- some columns were masked for the person who made it
    on_behalf_of  bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX exports_queue_idx ON exports (status, created_at) WHERE status = 'queued';
CREATE INDEX exports_member_idx ON exports (created_by, created_at DESC);
CREATE INDEX exports_expiry_idx ON exports (expires_at) WHERE storage_path IS NOT NULL;

CREATE TABLE worker_passes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    steps         jsonb NOT NULL DEFAULT '{}'::jsonb,
    error         text
);

-- The Data Steward's proposals (DocCloud's clerk_proposals shape): each accepted, edited or dismissed by a person with the diff shown.
CREATE TABLE steward_proposals (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind             text NOT NULL CHECK (kind IN ('description', 'column_description', 'personal_data', 'owner', 'check', 'drift', 'unused', 'promotion', 'coverage', 'glossary_link')),
    dataset_id       bigint REFERENCES datasets(id) ON DELETE CASCADE,
    column_id        bigint REFERENCES dataset_columns(id) ON DELETE CASCADE,
    current_value    jsonb,                                            -- what is there now
    proposed_value   jsonb NOT NULL,                                   -- what the Steward proposes
    rationale        text CHECK (rationale IS NULL OR length(rationale) <= 4000),   -- from schema, profile and lineage — never a personal value
    status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'accepted', 'edited', 'dismissed', 'superseded')),
    proposed_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    agent_run_id     bigint,
    decided_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at       timestamptz,
    decision_note    text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((status IN ('pending', 'superseded')) = (decided_at IS NULL) OR status = 'superseded')
);
CREATE UNIQUE INDEX steward_proposals_pending_one ON steward_proposals (kind, COALESCE(dataset_id, 0), COALESCE(column_id, 0)) WHERE status = 'pending';
CREATE INDEX steward_proposals_dataset_idx ON steward_proposals (dataset_id, status);

-- Decide a proposal. Accepting a description writes it with description_source 'accepted'; accepting a column's personal kind confirms it;
-- accepting an owner sets it. 'edited' takes the person's value instead. The decider is a person (the dataset's owner, steward, or an
-- engineer); the agent never decides its own.
CREATE OR REPLACE FUNCTION dl_steward_proposal_decide(p_id bigint, p_decision text, p_value jsonb DEFAULT NULL, p_note text DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sp steward_proposals%ROWTYPE; me bigint := app_current_member_id(); d datasets%ROWTYPE; v jsonb;
BEGIN
    IF p_decision NOT IN ('accepted', 'edited', 'dismissed') THEN RAISE EXCEPTION 'A proposal is accepted, edited or dismissed' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO sp FROM steward_proposals WHERE id = p_id FOR UPDATE;
    IF NOT FOUND OR sp.status <> 'pending' THEN RAISE EXCEPTION 'No such pending proposal' USING ERRCODE = 'no_data_found'; END IF;
    IF EXISTS (SELECT 1 FROM members WHERE id = me AND member_kind = 'agent') THEN
        RAISE EXCEPTION 'A person decides a Steward''s proposal' USING ERRCODE = 'insufficient_privilege';
    END IF;
    SELECT * INTO d FROM datasets WHERE id = sp.dataset_id;
    IF NOT (dl_has_right('catalog.edit') OR COALESCE(me = d.owner_member_id, false) OR COALESCE(me = d.steward_member_id, false)) THEN   -- NULL-safe: a dataset with no steward
        RAISE EXCEPTION 'A proposal is decided by the dataset''s owner, its steward or an engineer' USING ERRCODE = 'insufficient_privilege';
    END IF;
    v := CASE WHEN p_decision = 'edited' THEN p_value ELSE sp.proposed_value END;
    IF p_decision IN ('accepted', 'edited') THEN
        IF sp.kind = 'description' THEN
            UPDATE datasets SET description = v ->> 'description', description_source = 'accepted',
                   purpose = COALESCE(v ->> 'purpose', purpose), usage = COALESCE(v ->> 'usage', usage), limitations = COALESCE(v ->> 'limitations', limitations)
             WHERE id = sp.dataset_id;
        ELSIF sp.kind = 'column_description' THEN
            UPDATE dataset_columns SET description = v ->> 'description', business_name = COALESCE(v ->> 'business_name', business_name), description_source = 'accepted'
             WHERE id = sp.column_id;
        ELSIF sp.kind = 'personal_data' THEN
            PERFORM dl_column_confirm_personal(sp.column_id, (v ->> 'personal_kind') IS NOT NULL, v ->> 'personal_kind');
        ELSIF sp.kind = 'owner' THEN
            UPDATE datasets SET owner_member_id = (v ->> 'owner_member_id')::bigint,
                   steward_member_id = COALESCE((v ->> 'steward_member_id')::bigint, steward_member_id) WHERE id = sp.dataset_id;
        ELSIF sp.kind = 'glossary_link' THEN
            UPDATE dataset_columns SET glossary_term_id = (v ->> 'glossary_term_id')::bigint WHERE id = sp.column_id;
        END IF;                                                          -- check / drift / unused / promotion / coverage: the handler acts
    END IF;
    UPDATE steward_proposals SET status = p_decision, decided_by = me, decided_at = now(), decision_note = p_note,
           proposed_value = CASE WHEN p_decision = 'edited' THEN v ELSE proposed_value END
     WHERE id = p_id;
END$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON notes, notifications, notification_prefs, notification_outbox, agent_dispatches, exports, worker_passes TO datalake_rw;
GRANT SELECT, INSERT, UPDATE ON steward_proposals TO datalake_rw;
GRANT SELECT, UPDATE ON exports TO datalake_lake;
GRANT EXECUTE ON FUNCTION dl_steward_agent() TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_notify(bigint, text, text, text, text, bigint, bigint, bigint, text), dl_steward_proposal_decide(bigint, text, jsonb, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_notify(bigint, text, text, text, text, bigint, bigint, bigint, text) TO datalake_rw, datalake_lake;
GRANT EXECUTE ON FUNCTION dl_steward_proposal_decide(bigint, text, jsonb, text) TO datalake_rw;

COMMIT;
