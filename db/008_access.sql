-- 008: WHO SEES WHAT — grants, masking policies, access requests, the break-glass, the trail of access changes, and THE WALLS (design §3).
-- The one place the rules of §3 are compiled for the query service is `dl_query_policy()` at the end of this file (design §6.5 step 2).
--
-- THE ESTATE'S DATA MODEL: `grants`, `masking_policies`, `access_requests`, `admin_reads` are new canonical (DocCloud's `folder_access` compared:
-- its principal kinds are copied — member, group, department, agent, application, everyone —, the target and the levels are the lake's);
-- `access_changes` is DocCloud db/008 verbatim with `folder_id` / `document_id` kept (nullable, unused here) and `dataset_id`, `zone`,
-- `tag_id`, `grant_id` APPENDED — its one-of CHECK widened to the lake's targets.
--
-- THE LEVELS (§3): none < discover < read (masked) < read_unmasked < write < own. A grant names a dataset, a zone or a tag (then every
-- dataset under it, present and future), a principal, a level, an optional ROW FILTER and an optional expiry. Grants are additive: the
-- effective level is the highest; the effective row predicate is NULL when any grant at read or above has none, else the OR of theirs.
-- A dataset's OWNER holds `own`, its STEWARD `read`; a sandbox dataset is its owner's alone. The admin DISCOVERS everything and reads rows
-- only through a live break-glass (`admin_reads`, an hour, logged, the owner notified). AN AGENT in a person's chat turn reads the
-- INTERSECTION (the lower level, both row predicates, the union of masks — dl_query_policy with p_on_behalf_of; K38).
--
-- THE SCALE RULE (DocCloud's): nothing here returns a caller's whole visible set; every function is per dataset, the principals once.
BEGIN;

CREATE OR REPLACE FUNCTION dl_level_rank(p text) RETURNS smallint LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p WHEN 'none' THEN 0 WHEN 'discover' THEN 1 WHEN 'read' THEN 2 WHEN 'read_unmasked' THEN 3 WHEN 'write' THEN 4 WHEN 'own' THEN 5 ELSE 0 END::smallint;
$$;
CREATE OR REPLACE FUNCTION dl_level_of_rank(p smallint) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT (ARRAY['none', 'discover', 'read', 'read_unmasked', 'write', 'own'])[p + 1];
$$;

-- new canonical: a level on a dataset, a zone or a tag for a principal
CREATE TABLE grants (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    target_kind     text NOT NULL CHECK (target_kind IN ('dataset', 'zone', 'tag')),
    dataset_id      bigint REFERENCES datasets(id) ON DELETE CASCADE,
    zone            text CHECK (zone IS NULL OR zone IN ('raw', 'curated', 'product')),          -- never 'sandbox': a sandbox is shared dataset by dataset
    tag_id          bigint REFERENCES tags(id) ON DELETE CASCADE,
    principal_kind  text NOT NULL CHECK (principal_kind IN ('member', 'group', 'department', 'agent', 'application', 'everyone')),
    principal_id    bigint,                                                   -- member / agent: members.id; group: groups.id; department: departments.id
    principal_ref   text,                                                     -- application: the consumer's catalog key
    level           text NOT NULL CHECK (level IN ('discover', 'read', 'read_unmasked', 'write', 'own')),
    row_filter      text CHECK (row_filter IS NULL OR length(row_filter) BETWEEN 1 AND 2000), -- a predicate over the dataset's columns; tokens {member_id}, {departments}, {groups}
    expires_at      timestamptz,
    reason          text,
    granted_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    revoked_at      timestamptz,
    revoked_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    access_request_id bigint,                                                 -- FK below
    CHECK ((target_kind = 'dataset') = (dataset_id IS NOT NULL)),
    CHECK ((target_kind = 'zone') = (zone IS NOT NULL)),
    CHECK ((target_kind = 'tag') = (tag_id IS NOT NULL)),
    CHECK ((principal_kind IN ('member', 'group', 'department', 'agent')) = (principal_id IS NOT NULL)),
    CHECK ((principal_kind = 'application') = (principal_ref IS NOT NULL AND principal_ref ~ '^[a-z0-9_]{1,60}$')),
    CHECK (principal_kind <> 'application' OR level IN ('discover', 'read')),  -- a sibling reads masked, never more (D14)
    CHECK (principal_kind <> 'everyone' OR level IN ('discover', 'read')),
    CHECK (row_filter IS NULL OR level IN ('read', 'read_unmasked'))
);
CREATE INDEX grants_dataset_idx ON grants (dataset_id) WHERE revoked_at IS NULL AND dataset_id IS NOT NULL;
CREATE INDEX grants_zone_idx ON grants (zone) WHERE revoked_at IS NULL AND zone IS NOT NULL;
CREATE INDEX grants_tag_idx ON grants (tag_id) WHERE revoked_at IS NULL AND tag_id IS NOT NULL;
CREATE INDEX grants_principal_idx ON grants (principal_kind, principal_id) WHERE revoked_at IS NULL;
CREATE INDEX grants_expiry_idx ON grants (expires_at) WHERE revoked_at IS NULL AND expires_at IS NOT NULL;

-- reuse: DocCloud db/008 verbatim; folder_id/document_id kept; the lake's targets appended; the one-of CHECK widened
CREATE TABLE access_changes (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    folder_id      bigint,
    document_id    bigint,
    principal_kind text NOT NULL,
    principal_id   bigint,
    principal_ref  text,
    old_level      text,
    new_level      text,                                                   -- NULL = the row was removed
    changed_by     bigint,
    changed_at     timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.8)
    dataset_id     bigint,                                                 -- no FK: the trail outlives the thing
    zone           text,
    tag_id         bigint,
    grant_id       bigint,
    row_filter     text,
    CHECK (num_nonnulls(folder_id, document_id, dataset_id, zone, tag_id) = 1)
);
CREATE INDEX access_changes_dataset_idx ON access_changes (dataset_id, changed_at DESC);
CREATE INDEX access_changes_zone_idx ON access_changes (zone, changed_at DESC) WHERE zone IS NOT NULL;
CREATE INDEX access_changes_tag_idx ON access_changes (tag_id, changed_at DESC) WHERE tag_id IS NOT NULL;

CREATE OR REPLACE FUNCTION dl_grants_trail() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO access_changes (principal_kind, principal_id, principal_ref, old_level, new_level, changed_by, dataset_id, zone, tag_id, grant_id, row_filter)
        VALUES (NEW.principal_kind, NEW.principal_id, NEW.principal_ref, NULL, NEW.level, COALESCE(NEW.granted_by, app_current_member_id()),
                NEW.dataset_id, NEW.zone, NEW.tag_id, NEW.id, NEW.row_filter);
    ELSIF NEW.revoked_at IS NOT NULL AND OLD.revoked_at IS NULL THEN
        INSERT INTO access_changes (principal_kind, principal_id, principal_ref, old_level, new_level, changed_by, dataset_id, zone, tag_id, grant_id, row_filter)
        VALUES (NEW.principal_kind, NEW.principal_id, NEW.principal_ref, OLD.level, NULL, COALESCE(NEW.revoked_by, app_current_member_id()),
                NEW.dataset_id, NEW.zone, NEW.tag_id, NEW.id, NULL);
    ELSIF NEW.level IS DISTINCT FROM OLD.level OR NEW.row_filter IS DISTINCT FROM OLD.row_filter THEN
        INSERT INTO access_changes (principal_kind, principal_id, principal_ref, old_level, new_level, changed_by, dataset_id, zone, tag_id, grant_id, row_filter)
        VALUES (NEW.principal_kind, NEW.principal_id, NEW.principal_ref, OLD.level, NEW.level, app_current_member_id(),
                NEW.dataset_id, NEW.zone, NEW.tag_id, NEW.id, NEW.row_filter);
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER grants_trail AFTER INSERT OR UPDATE ON grants FOR EACH ROW EXECUTE FUNCTION dl_grants_trail();
-- A grant is revoked, never deleted (the trail and "who could read this on June 3rd" need it).
CREATE OR REPLACE FUNCTION dl_grants_no_delete() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF current_user <> 'postgres' THEN RAISE EXCEPTION 'A grant is revoked, never deleted' USING ERRCODE = 'check_violation'; END IF;
    RETURN OLD;
END$$;
CREATE TRIGGER grants_no_delete BEFORE DELETE ON grants FOR EACH ROW EXECUTE FUNCTION dl_grants_no_delete();

-- new canonical: how a personal kind or a classification is masked below read_unmasked (§3 rule 4). Seeded; the admin changes them.
CREATE TABLE masking_policies (
    applies_to  text PRIMARY KEY CHECK (applies_to = ANY (dl_personal_kinds()) OR applies_to IN ('confidential', 'restricted', 'rescued')),
    mask        text NOT NULL CHECK (mask IN ('full', 'partial_email', 'partial', 'hash', 'null', 'year_only')),
    note        text,
    updated_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER masking_policies_touch BEFORE UPDATE ON masking_policies FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
INSERT INTO masking_policies (applies_to, mask, note) VALUES
    ('email', 'partial_email', 'j***@example.com — the domain stays'),
    ('phone', 'partial', 'the last two digits stay'),
    ('name', 'partial', 'the first letter stays'),
    ('address', 'full', NULL),
    ('national_id', 'null', NULL),
    ('payment_card', 'partial', 'the last four digits stay'),
    ('bank_account', 'partial', 'the last four digits stay'),
    ('birth_date', 'year_only', NULL),
    ('ip_address', 'hash', 'a keyed hash — joins still work'),
    ('member_id', 'hash', 'a keyed hash — joins still work'),
    ('free_text_personal', 'null', NULL),
    ('other', 'full', NULL),
    ('confidential', 'full', 'a confidential column with no personal kind'),
    ('restricted', 'null', 'a restricted column, always null below read_unmasked'),
    ('rescued', 'null', '_dl_rescued: fields no contract has classified yet');

-- new canonical: a person asks for a level on a dataset; its owner (or grants.manage) decides; an approval makes a grant
CREATE TABLE access_requests (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    requester_id     bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    dataset_id       bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    level            text NOT NULL CHECK (level IN ('discover', 'read', 'read_unmasked', 'write')),
    reason           text NOT NULL CHECK (length(reason) BETWEEN 3 AND 2000),
    wanted_until     date,
    drafted_by_agent bigint REFERENCES members(id) ON DELETE SET NULL,      -- the expert drafted it for the requester (§5)
    status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'denied', 'withdrawn', 'expired')),
    decided_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at       timestamptz,
    decision_note    text,
    grant_id         bigint REFERENCES grants(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((status IN ('pending')) = (decided_at IS NULL) OR status IN ('withdrawn', 'expired')),
    CHECK (status <> 'approved' OR grant_id IS NOT NULL)
);
CREATE UNIQUE INDEX access_requests_pending_one ON access_requests (requester_id, dataset_id) WHERE status = 'pending';
CREATE INDEX access_requests_dataset_idx ON access_requests (dataset_id, status);
ALTER TABLE grants ADD CONSTRAINT grants_access_request_fk FOREIGN KEY (access_request_id) REFERENCES access_requests(id) ON DELETE SET NULL;

-- new: the admin's break-glass — rows of one dataset, with a reason, for an hour (§3 rule 7)
CREATE TABLE admin_reads (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    dataset_id  bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    reason      text NOT NULL CHECK (length(reason) BETWEEN 10 AND 2000),
    started_at  timestamptz NOT NULL DEFAULT now(),
    expires_at  timestamptz NOT NULL DEFAULT now() + interval '1 hour',
    CHECK (expires_at > started_at AND expires_at <= started_at + interval '8 hours')
);
CREATE INDEX admin_reads_live_idx ON admin_reads (member_id, dataset_id, expires_at);

-- ---------------------------------------------------------------------------------------------
-- THE WALLS
-- ---------------------------------------------------------------------------------------------

-- A member's principals (DocCloud's dl_my_principals, per member): themselves (member, and agent when one), their live groups, their
-- departments (not for an external), everyone (not for an external).
CREATE OR REPLACE FUNCTION dl_principals_of(p_member_id bigint) RETURNS TABLE (kind text, id bigint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT 'member'::text, m.id FROM members m WHERE m.id = p_member_id AND m.status = 'active'
    UNION ALL SELECT 'agent', m.id FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.member_kind = 'agent'
    UNION ALL SELECT 'group', g FROM dl_member_group_ids(p_member_id) g
    UNION ALL SELECT 'department', dm.department_id FROM department_members dm JOIN members m ON m.id = dm.member_id
               WHERE dm.member_id = p_member_id AND dm.left_at IS NULL AND m.status = 'active' AND NOT m.is_external
    UNION ALL SELECT 'everyone', NULL::bigint FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND NOT m.is_external;
$$;

-- Does the member hold a role here at all (a live grant on this application)?
CREATE OR REPLACE FUNCTION dl_member_here(p_member_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT cardinality(dl_member_roles(p_member_id)) > 0;
$$;

-- A row-filter template bound to a member's attributes: {member_id}, {departments}, {groups} become literals (design §6.5: the query
-- service receives literals only).
CREATE OR REPLACE FUNCTION dl_bind_row_filter(p_filter text, p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN p_filter IS NULL THEN NULL ELSE
        replace(replace(replace(p_filter,
            '{member_id}', p_member_id::text),
            '{departments}', 'ARRAY[' || COALESCE((SELECT string_agg(dm.department_id::text, ',' ORDER BY dm.department_id)
                                                     FROM department_members dm WHERE dm.member_id = p_member_id AND dm.left_at IS NULL), '') || ']::BIGINT[]'),
            '{groups}', 'ARRAY[' || COALESCE((SELECT string_agg(g::text, ',' ORDER BY g) FROM dl_member_group_ids(p_member_id) g), '') || ']::BIGINT[]')
    END;
$$;

-- The effective level and row predicate of ONE member on ONE dataset (no intersection; see dl_access_of for an agent acting for a person).
-- Returns (level, row_filter bound, source) where source names what gave the level: owner / steward / grant <id> / break_glass / catalog.
CREATE OR REPLACE FUNCTION dl_member_access(p_dataset_id bigint, p_member_id bigint)
    RETURNS TABLE (level text, row_filter text, source text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; m members%ROWTYPE; best smallint := 0; src text := NULL; unfiltered boolean := false; filters text[] := '{}';
        g record; here boolean;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    SELECT * INTO m FROM members WHERE id = p_member_id;
    IF d.id IS NULL OR m.id IS NULL OR m.status <> 'active' OR d.status = 'retired' THEN
        RETURN QUERY SELECT 'none'::text, NULL::text, NULL::text; RETURN;
    END IF;
    here := dl_member_here(p_member_id);
    IF NOT here THEN RETURN QUERY SELECT 'none'::text, NULL::text, NULL::text; RETURN; END IF;
    -- the owner owns; the steward reads (masked)
    IF d.owner_member_id = p_member_id THEN RETURN QUERY SELECT 'own'::text, NULL::text, 'owner'::text; RETURN; END IF;
    IF d.zone = 'sandbox' THEN
        -- a sandbox dataset: its owner's alone, unless shared by a dataset grant (rule 6)
        NULL;
    ELSIF d.steward_member_id = p_member_id THEN
        best := 2; src := 'steward'; unfiltered := true;
    END IF;
    -- the admin's live break-glass
    IF EXISTS (SELECT 1 FROM admin_reads a WHERE a.member_id = p_member_id AND a.dataset_id = d.id AND a.expires_at > now())
       AND 'admin' = ANY (dl_member_roles(p_member_id)) THEN
        IF best < 3 THEN best := 3; src := 'break_glass'; END IF;
        unfiltered := true;
    END IF;
    -- the grants that reach this dataset (by the dataset, its zone, its tags) for one of the member's principals
    FOR g IN
        SELECT gr.id, gr.level, gr.row_filter
          FROM grants gr
          JOIN dl_principals_of(p_member_id) p ON p.kind = gr.principal_kind AND (gr.principal_kind = 'everyone' OR p.id = gr.principal_id)
         WHERE gr.revoked_at IS NULL AND (gr.expires_at IS NULL OR gr.expires_at > now())
           AND (   (gr.target_kind = 'dataset' AND gr.dataset_id = d.id)
                OR (gr.target_kind = 'zone' AND gr.zone = d.zone AND d.zone <> 'sandbox')
                OR (gr.target_kind = 'tag' AND d.zone <> 'sandbox'
                    AND EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'dataset' AND rt.record_id = d.id AND rt.tag_id = gr.tag_id)))
    LOOP
        IF dl_level_rank(g.level) > best THEN best := dl_level_rank(g.level); src := 'grant ' || g.id; END IF;
        IF dl_level_rank(g.level) >= 2 THEN
            IF g.row_filter IS NULL THEN unfiltered := true;
            ELSE filters := filters || ('(' || dl_bind_row_filter(g.row_filter, p_member_id) || ')'); END IF;
        END IF;
    END LOOP;
    -- the catalog: an insider discovers public and internal datasets outside the sandbox (rule 2)
    IF best < 1 AND d.zone <> 'sandbox' AND NOT m.is_external AND dl_class_rank(d.classification) <= 1 THEN best := 1; src := 'catalog'; END IF;
    -- the admin discovers every entry (rule 7)
    IF best < 1 AND 'admin' = ANY (dl_member_roles(p_member_id)) THEN best := 1; src := 'admin'; END IF;
    RETURN QUERY SELECT dl_level_of_rank(best),
                        CASE WHEN best < 2 OR unfiltered OR cardinality(filters) = 0 THEN NULL ELSE array_to_string(filters, ' OR ') END,
                        src;
END$$;

-- The effective access of a caller — and, when an agent acts for a person (p_on_behalf_of), the INTERSECTION: the lower level, both
-- predicates (AND), so a chat turn never reads more than the person may (§3 rule 8, D8).
CREATE OR REPLACE FUNCTION dl_access_of(p_dataset_id bigint, p_caller bigint, p_on_behalf_of bigint DEFAULT NULL)
    RETURNS TABLE (level text, row_filter text, source text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE a record; b record;
BEGIN
    SELECT * INTO a FROM dl_member_access(p_dataset_id, p_caller);
    IF p_on_behalf_of IS NULL OR p_on_behalf_of = p_caller THEN
        RETURN QUERY SELECT a.level, a.row_filter, a.source; RETURN;
    END IF;
    SELECT * INTO b FROM dl_member_access(p_dataset_id, p_on_behalf_of);
    RETURN QUERY SELECT dl_level_of_rank(LEAST(dl_level_rank(a.level), dl_level_rank(b.level))),
                        CASE WHEN a.row_filter IS NULL THEN b.row_filter
                             WHEN b.row_filter IS NULL THEN a.row_filter
                             ELSE '(' || a.row_filter || ') AND (' || b.row_filter || ')' END,
                        'intersection: ' || COALESCE(a.source, 'none') || ' ∩ ' || COALESCE(b.source, 'none');
END$$;

-- The current member's level (the web application's and the MCP servers' gate); app.on_behalf_of set by the run-facts gate (K38).
CREATE OR REPLACE FUNCTION dl_level(p_dataset_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT level FROM dl_access_of(p_dataset_id, app_current_member_id(), NULLIF(current_setting('app.on_behalf_of', true), '')::bigint);
$$;
-- May the current member see the entry (full metadata)? (rule 2)
CREATE OR REPLACE FUNCTION dl_can_discover(p_dataset_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT dl_level_rank(dl_level(p_dataset_id)) >= 1;
$$;
-- A stub: the name, the owner, "request access" — every insider holding a role here sees one for a confidential or restricted dataset
-- outside the sandbox that they may not discover (rule 2; R36). Never for an external, never a sandbox dataset.
CREATE OR REPLACE FUNCTION dl_can_see_stub(p_dataset_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM datasets d JOIN members m ON m.id = app_current_member_id()
                    WHERE d.id = p_dataset_id AND d.zone <> 'sandbox' AND d.status <> 'retired' AND m.status = 'active' AND NOT m.is_external
                      AND dl_member_here(m.id));
$$;

-- The masks a level implies on a dataset's columns (rule 4): below read_unmasked every personal column by its kind, every confidential
-- column by 'confidential', every restricted column null, `_dl_rescued` null; at read_unmasked and above nothing.
CREATE OR REPLACE FUNCTION dl_masks_for(p_dataset_id bigint, p_level text) RETURNS jsonb
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE WHEN dl_level_rank(p_level) >= 3 THEN '{}'::jsonb ELSE COALESCE(jsonb_object_agg(c.name, mp.mask), '{}'::jsonb) END
      FROM dataset_columns c
      JOIN masking_policies mp ON mp.applies_to = CASE
                                     WHEN c.name = '_dl_rescued' THEN 'rescued'
                                     WHEN c.classification = 'restricted' THEN 'restricted'
                                     WHEN c.personal_kind IS NOT NULL THEN c.personal_kind
                                     WHEN c.classification = 'confidential' THEN 'confidential' END
     WHERE c.dataset_id = p_dataset_id AND c.removed_snapshot_id IS NULL;
$$;

-- THE POLICY (design §6.5 step 2) — the one place §3 is compiled for the query service. For the named dataset keys, the caller's (and,
-- for an agent acting for a person, the intersection's) level, the columns, the masks and the bound row predicate; the keys the caller
-- may not read are listed under "refused" with the reason; the limits for the source. The shape is the query service's contract
-- (services/query/, tests/engine/): {caller, on_behalf_of, datasets: {key: {level, columns, masks, row_filter}}, refused: {key: reason},
-- limits: {timeout_s, row_limit, result_bytes, memory, threads}}. The masking salt is NOT here — the query service reads it from its file.
-- Callable by the query service's role and the application's; it trusts its caller ids (the PHP session or the run-facts gate set them).
CREATE OR REPLACE FUNCTION dl_query_policy(p_caller bigint, p_on_behalf_of bigint, p_keys text[], p_source text DEFAULT 'screen') RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; d datasets%ROWTYPE; acc record; ds jsonb := '{}'::jsonb; refused jsonb := '{}'::jsonb; lim jsonb;
        timeout_s integer; row_limit integer;
BEGIN
    IF p_source NOT IN ('screen', 'preview', 'mcp', 'agent', 'api', 'k7', 'export', 'transform') THEN
        RAISE EXCEPTION 'Unknown query source %', p_source USING ERRCODE = 'check_violation';
    END IF;
    FOREACH k IN ARRAY COALESCE(p_keys, '{}') LOOP
        SELECT * INTO d FROM datasets WHERE key = k;
        IF d.id IS NULL OR d.status = 'retired' THEN
            refused := refused || jsonb_build_object(k, 'no such dataset');
            CONTINUE;
        END IF;
        SELECT * INTO acc FROM dl_access_of(d.id, p_caller, p_on_behalf_of);
        IF dl_level_rank(acc.level) < 2 THEN
            refused := refused || jsonb_build_object(k, CASE WHEN dl_level_rank(acc.level) = 1 THEN 'you may discover this dataset but not read its rows — request access'
                                                             ELSE 'you may not read this dataset — request access' END);
            CONTINUE;
        END IF;
        ds := ds || jsonb_build_object(k, jsonb_build_object(
            'level', CASE WHEN dl_level_rank(acc.level) >= 3 THEN 'read_unmasked' ELSE 'read' END,
            'columns', (SELECT COALESCE(jsonb_agg(c.name ORDER BY c.ordinal), '[]'::jsonb) FROM dataset_columns c
                         WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL),
            'masks', dl_masks_for(d.id, acc.level),
            'row_filter', acc.row_filter));
    END LOOP;
    SELECT CASE WHEN p_source IN ('screen', 'preview') THEN s.query_timeout_screen_s WHEN p_source = 'export' THEN s.query_timeout_export_s
                WHEN p_source = 'transform' THEN s.query_timeout_export_s ELSE s.query_timeout_agent_s END,
           CASE WHEN p_source = 'preview' THEN 100 WHEN p_source IN ('export', 'transform') THEN s.query_rows_download ELSE s.query_rows_shown END
      INTO timeout_s, row_limit FROM dl_settings s WHERE s.id = 1;
    SELECT jsonb_build_object('timeout_s', timeout_s, 'row_limit', row_limit, 'result_bytes', s.query_result_bytes,
                              'memory', s.query_memory_mb || 'MB', 'threads', s.query_threads, 'temp_mb', s.query_temp_mb)
      INTO lim FROM dl_settings s WHERE s.id = 1;
    RETURN jsonb_build_object('caller', p_caller, 'on_behalf_of', p_on_behalf_of, 'source', p_source,
                              'datasets', ds, 'refused', refused, 'limits', lim);
END$$;

-- A SIBLING APPLICATION (a K7 consumer, D14): its level on a dataset is the highest of the grants naming it as an `application` principal
-- (on the dataset, its zone or its tags; never a sandbox) — discover or read, masked as for a Member, never a row filter of a person.
CREATE OR REPLACE FUNCTION dl_application_level(p_dataset_id bigint, p_app text) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT dl_level_of_rank(COALESCE(max(dl_level_rank(gr.level)), 0)::smallint)
      FROM grants gr JOIN datasets d ON d.id = p_dataset_id AND d.status <> 'retired' AND d.zone <> 'sandbox'
     WHERE gr.principal_kind = 'application' AND gr.principal_ref = p_app AND gr.revoked_at IS NULL AND (gr.expires_at IS NULL OR gr.expires_at > now())
       AND ((gr.target_kind = 'dataset' AND gr.dataset_id = d.id) OR (gr.target_kind = 'zone' AND gr.zone = d.zone)
            OR (gr.target_kind = 'tag' AND EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'dataset' AND rt.record_id = d.id AND rt.tag_id = gr.tag_id)));
$$;
-- The policy for a K7 consumer (the shares product_rows / metric_value): the same shape as dl_query_policy, source 'k7'.
CREATE OR REPLACE FUNCTION dl_query_policy_app(p_app text, p_keys text[]) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE k text; d datasets%ROWTYPE; lvl text; ds jsonb := '{}'::jsonb; refused jsonb := '{}'::jsonb; lim jsonb;
BEGIN
    FOREACH k IN ARRAY COALESCE(p_keys, '{}') LOOP
        SELECT * INTO d FROM datasets WHERE key = k;
        lvl := CASE WHEN d.id IS NULL THEN 'none' ELSE dl_application_level(d.id, p_app) END;
        IF dl_level_rank(lvl) < 2 THEN
            refused := refused || jsonb_build_object(k, 'not shared with ' || p_app);
            CONTINUE;
        END IF;
        ds := ds || jsonb_build_object(k, jsonb_build_object('level', 'read',
            'columns', (SELECT COALESCE(jsonb_agg(c.name ORDER BY c.ordinal), '[]'::jsonb) FROM dataset_columns c WHERE c.dataset_id = d.id AND c.removed_snapshot_id IS NULL),
            'masks', dl_masks_for(d.id, 'read'), 'row_filter', NULL));
    END LOOP;
    SELECT jsonb_build_object('timeout_s', s.query_timeout_agent_s, 'row_limit', 500, 'result_bytes', 262144, 'memory', s.query_memory_mb || 'MB',
                              'threads', s.query_threads, 'temp_mb', s.query_temp_mb)
      INTO lim FROM dl_settings s WHERE s.id = 1;
    RETURN jsonb_build_object('caller', NULL, 'consumer_app', p_app, 'on_behalf_of', NULL, 'source', 'k7', 'datasets', ds, 'refused', refused, 'limits', lim);
END$$;

-- WHY a member has the access they have (S1; the Access tab's explanation): every grant that reaches them, the owner/steward rows, the
-- break-glass, the catalog rule, and the masks that follow.
CREATE OR REPLACE FUNCTION dl_access_explain(p_dataset_id bigint, p_member_id bigint) RETURNS jsonb
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; acc record; reasons jsonb := '[]'::jsonb;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL THEN RETURN NULL; END IF;
    SELECT * INTO acc FROM dl_member_access(p_dataset_id, p_member_id);
    IF d.owner_member_id = p_member_id THEN reasons := reasons || jsonb_build_array(jsonb_build_object('because', 'owner', 'level', 'own')); END IF;
    IF d.steward_member_id = p_member_id THEN reasons := reasons || jsonb_build_array(jsonb_build_object('because', 'steward', 'level', 'read')); END IF;
    reasons := reasons || COALESCE((
        SELECT jsonb_agg(jsonb_build_object('because', 'grant', 'grant_id', gr.id, 'target', gr.target_kind,
                                            'target_name', COALESCE(d2.key, gr.zone, t.slug), 'via', gr.principal_kind,
                                            'via_name', COALESCE(g2.name, dep.name, mm.display_name, gr.principal_ref, 'everyone'),
                                            'level', gr.level, 'row_filter', gr.row_filter, 'expires_at', gr.expires_at) ORDER BY gr.id)
          FROM grants gr
          JOIN dl_principals_of(p_member_id) p ON p.kind = gr.principal_kind AND (gr.principal_kind = 'everyone' OR p.id = gr.principal_id)
          LEFT JOIN datasets d2 ON d2.id = gr.dataset_id
          LEFT JOIN tags t ON t.id = gr.tag_id
          LEFT JOIN groups g2 ON gr.principal_kind = 'group' AND g2.id = gr.principal_id
          LEFT JOIN departments dep ON gr.principal_kind = 'department' AND dep.id = gr.principal_id
          LEFT JOIN members mm ON gr.principal_kind IN ('member', 'agent') AND mm.id = gr.principal_id
         WHERE gr.revoked_at IS NULL AND (gr.expires_at IS NULL OR gr.expires_at > now())
           AND ((gr.target_kind = 'dataset' AND gr.dataset_id = d.id)
                OR (gr.target_kind = 'zone' AND gr.zone = d.zone AND d.zone <> 'sandbox')
                OR (gr.target_kind = 'tag' AND d.zone <> 'sandbox'
                    AND EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = 'dataset' AND rt.record_id = d.id AND rt.tag_id = gr.tag_id)))), '[]'::jsonb);
    IF acc.source IN ('catalog', 'admin', 'break_glass') THEN reasons := reasons || jsonb_build_array(jsonb_build_object('because', acc.source, 'level', acc.level)); END IF;
    RETURN jsonb_build_object('dataset', d.key, 'member_id', p_member_id, 'level', acc.level, 'source', acc.source,
                              'row_filter', acc.row_filter, 'masked_columns', (SELECT COALESCE(jsonb_agg(x ORDER BY x), '[]'::jsonb) FROM jsonb_object_keys(dl_masks_for(d.id, acc.level)) x),
                              'reasons', reasons);
END$$;

-- Decide an access request: the dataset's owner, or a holder of grants.manage; an approval makes the grant (an agent's decision pauses
-- in the kernel before it reaches here — access_decide is external_send, §5).
CREATE OR REPLACE FUNCTION dl_access_decide(p_request_id bigint, p_approve boolean, p_level text DEFAULT NULL, p_until timestamptz DEFAULT NULL,
                                            p_note text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r access_requests%ROWTYPE; d datasets%ROWTYPE; me bigint := app_current_member_id(); gid bigint;
BEGIN
    SELECT * INTO r FROM access_requests WHERE id = p_request_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such request' USING ERRCODE = 'no_data_found'; END IF;
    IF r.status <> 'pending' THEN RAISE EXCEPTION 'The request was already %', r.status USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO d FROM datasets WHERE id = r.dataset_id;
    IF NOT (COALESCE(d.owner_member_id = me, false) OR dl_has_right('grants.manage')) THEN
        RAISE EXCEPTION 'Deciding access is the dataset''s owner''s or a Lake admin''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF me = r.requester_id THEN RAISE EXCEPTION 'A request is decided by someone other than its requester' USING ERRCODE = 'check_violation'; END IF;
    IF p_approve THEN
        INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, expires_at, reason, granted_by, access_request_id)
        VALUES ('dataset', d.id, (SELECT CASE WHEN member_kind = 'agent' THEN 'agent' ELSE 'member' END FROM members WHERE id = r.requester_id),
                r.requester_id, COALESCE(p_level, r.level), COALESCE(p_until, r.wanted_until::timestamptz), COALESCE(p_note, r.reason), me, r.id)
        RETURNING id INTO gid;
        UPDATE access_requests SET status = 'approved', decided_by = me, decided_at = now(), decision_note = p_note, grant_id = gid WHERE id = r.id;
    ELSE
        IF p_note IS NULL OR length(p_note) < 3 THEN RAISE EXCEPTION 'A denial says why' USING ERRCODE = 'check_violation'; END IF;
        UPDATE access_requests SET status = 'denied', decided_by = me, decided_at = now(), decision_note = p_note WHERE id = r.id;
    END IF;
    RETURN gid;
END$$;

-- The admin's break-glass: a reason, an hour, the owner told (the notification is db/014's dl_notify, called by the handler).
CREATE OR REPLACE FUNCTION dl_admin_read_open(p_dataset_id bigint, p_reason text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); rid bigint;
BEGIN
    IF NOT dl_has_right('lake.admin_read') THEN RAISE EXCEPTION 'Reading rows by break-glass is the Lake admin''s' USING ERRCODE = 'insufficient_privilege'; END IF;
    INSERT INTO admin_reads (member_id, dataset_id, reason) VALUES (me, p_dataset_id, p_reason) RETURNING id INTO rid;
    RETURN rid;
END$$;

GRANT SELECT, INSERT, UPDATE ON grants, access_requests TO datalake_rw;
GRANT SELECT, INSERT ON access_changes, admin_reads TO datalake_rw;
GRANT SELECT, UPDATE ON masking_policies TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_level_rank(text), dl_level_of_rank(smallint), dl_principals_of(bigint), dl_member_here(bigint), dl_bind_row_filter(text, bigint),
    dl_member_access(bigint, bigint), dl_access_of(bigint, bigint, bigint), dl_level(bigint), dl_can_discover(bigint), dl_can_see_stub(bigint),
    dl_masks_for(bigint, text), dl_access_explain(bigint, bigint)
    TO datalake_rw, datalake_records_ro, datalake_activity_ro, datalake_lake, datalake_query;
REVOKE ALL ON FUNCTION dl_query_policy(bigint, bigint, text[], text), dl_access_decide(bigint, boolean, text, timestamptz, text), dl_admin_read_open(bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_query_policy(bigint, bigint, text[], text) TO datalake_query, datalake_rw, datalake_lake;
GRANT EXECUTE ON FUNCTION dl_application_level(bigint, text) TO datalake_rw, datalake_records_ro, datalake_query;
REVOKE ALL ON FUNCTION dl_query_policy_app(text, text[]) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_query_policy_app(text, text[]) TO datalake_query, datalake_records_ro, datalake_rw;
GRANT EXECUTE ON FUNCTION dl_access_decide(bigint, boolean, text, timestamptz, text), dl_admin_read_open(bigint, text) TO datalake_rw;

COMMIT;
