-- 006: the vocabulary — TAGS (Help Desk's, with DocCloud's appended columns) and RECORD_TAGS (Sales CRM's polymorphic shape), the
-- GLOSSARY (new), and GROUPS (DocCloud's canonical, the estate's first group) — design §6.2, §3, §6.8.
--
-- THE ESTATE'S DATA MODEL: `tags` is Help Desk db/009 verbatim + DocCloud db/009's appended `kind`, `colour`, `created_by`,
-- `archived_at`; `record_tags` is Sales CRM db/006 verbatim with `record_type` widened to the lake's records (and SI's appended
-- `added_by`, `added_at`); `groups` / `group_members` are DocCloud db/006 verbatim (`member_level` kept — unused here: a group's level
-- is the grant's, db/008); `glossary_terms` is new (nothing in the estate is a controlled business vocabulary).
--
-- THE ENGINE'S ROLES (design §14): `datalake_lake` (the writer — owner of schema `ducklake`, DML on the engine's own tables) and
-- `datalake_query` (the query service — SELECT on `ducklake.*`, the policy function and the audit insert) are made here, NOLOGIN, because
-- the migrations below grant to them; `deploy/os-provision.sh` gives them a login and a password and makes schema `ducklake`.
BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'datalake_lake') THEN CREATE ROLE datalake_lake NOLOGIN; END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'datalake_query') THEN CREATE ROLE datalake_query NOLOGIN; END IF;
END$$;
GRANT USAGE ON SCHEMA public TO datalake_lake, datalake_query;

-- reuse: Help Desk db/009 verbatim + DocCloud's appended columns
CREATE TABLE tags (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug        text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9_-]{0,49}$'),
    label       text NOT NULL,
    usage_count integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    -- appended (DocCloud db/009)
    kind        text NOT NULL DEFAULT 'free' CHECK (kind IN ('managed', 'free')),
    colour      text CHECK (colour IS NULL OR colour ~ '^#[0-9a-f]{6}$'),
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at timestamptz
);
CREATE INDEX tags_label_trgm_idx ON tags USING gin (label gin_trgm_ops);

-- reuse: Sales CRM db/006 verbatim; record_type widened; SI's added_by/added_at appended
CREATE TABLE record_tags (
    record_type text NOT NULL CHECK (record_type IN ('dataset', 'column', 'saved_query', 'metric', 'source', 'glossary_term')),
    record_id   bigint NOT NULL,
    tag_id      bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (record_type, record_id, tag_id),
    -- appended (SI db/014)
    added_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    added_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX record_tags_tag_idx ON record_tags (tag_id);
CREATE OR REPLACE FUNCTION dl_record_tags_count() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN UPDATE tags SET usage_count = usage_count + 1 WHERE id = NEW.tag_id;
    ELSE UPDATE tags SET usage_count = GREATEST(usage_count - 1, 0) WHERE id = OLD.tag_id; END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_count AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION dl_record_tags_count();
CREATE OR REPLACE FUNCTION dl_tags_of(p_type text, p_id bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(array_agg(t.slug ORDER BY t.slug), '{}') FROM record_tags r JOIN tags t ON t.id = r.tag_id
     WHERE r.record_type = p_type AND r.record_id = p_id;
$$;

-- new: the business glossary (OpenMetadata's: a term starts as a draft and an engineer approves it; a parent is a category)
CREATE TABLE glossary_terms (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name               text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    definition         text NOT NULL CHECK (length(definition) BETWEEN 1 AND 4000),
    synonyms           text[] NOT NULL DEFAULT '{}',
    parent_id          bigint REFERENCES glossary_terms(id) ON DELETE SET NULL,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'deprecated')),
    steward_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_at        timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_id IS NULL OR parent_id <> id),
    CHECK ((status = 'approved') = (approved_at IS NOT NULL) OR status = 'deprecated')
);
CREATE UNIQUE INDEX glossary_terms_name_live ON glossary_terms (lower(name)) WHERE status <> 'deprecated';
CREATE INDEX glossary_terms_name_trgm_idx ON glossary_terms USING gin (name gin_trgm_ops);
CREATE TRIGGER glossary_terms_touch BEFORE UPDATE ON glossary_terms FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- reuse: DocCloud db/006 verbatim (member_level kept, unused here — a group's level is its grant's)
CREATE TABLE groups (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key             text NOT NULL UNIQUE CHECK (key ~ '^[a-z0-9][a-z0-9_-]{0,59}$'),
    name            text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    description     text,
    kind            text NOT NULL DEFAULT 'custom' CHECK (kind IN ('custom', 'department')),
    department_id   bigint REFERENCES departments(id) ON DELETE SET NULL,     -- the mirrored department (kind = department)
    member_level    text NOT NULL DEFAULT 'edit' CHECK (member_level IN ('view', 'edit')),
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at     timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'department') = (department_id IS NOT NULL))
);
CREATE UNIQUE INDEX groups_department_live ON groups (department_id) WHERE department_id IS NOT NULL AND archived_at IS NULL;
CREATE INDEX groups_name_trgm_idx ON groups USING gin (name gin_trgm_ops);
CREATE TRIGGER groups_touch BEFORE UPDATE ON groups FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE group_members (
    group_id    bigint NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    role        text NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'member')),
    added_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    joined_at   timestamptz NOT NULL DEFAULT now(),
    left_at     timestamptz,                                               -- dated, never erased
    PRIMARY KEY (group_id, member_id)
);
CREATE INDEX group_members_member_idx ON group_members (member_id) WHERE left_at IS NULL;
CREATE INDEX group_members_group_live_idx ON group_members (group_id) WHERE left_at IS NULL;

-- DocCloud's functions, prefix dl_: a member's live groups; a department group follows its department (the directory sync's last step)
CREATE OR REPLACE FUNCTION dl_member_group_ids(p_member_id bigint) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT gm.group_id FROM group_members gm JOIN groups g ON g.id = gm.group_id AND g.archived_at IS NULL
     WHERE gm.member_id = p_member_id AND gm.left_at IS NULL;
$$;
CREATE OR REPLACE FUNCTION dl_group_sync_department(p_group_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE g groups%ROWTYPE; n integer := 0; k integer;
BEGIN
    SELECT * INTO g FROM groups WHERE id = p_group_id AND kind = 'department';
    IF NOT FOUND THEN RETURN 0; END IF;
    INSERT INTO group_members (group_id, member_id, role, added_by)
    SELECT g.id, dm.member_id,
           CASE WHEN EXISTS (SELECT 1 FROM departments d WHERE d.id = g.department_id AND d.manager_member_id = dm.member_id) THEN 'owner' ELSE 'member' END,
           NULL
      FROM department_members dm JOIN members m ON m.id = dm.member_id AND m.status = 'active'
     WHERE dm.department_id = g.department_id AND dm.left_at IS NULL
    ON CONFLICT (group_id, member_id) DO UPDATE SET left_at = NULL,
        joined_at = CASE WHEN group_members.left_at IS NULL THEN group_members.joined_at ELSE now() END,
        role = EXCLUDED.role;
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    UPDATE group_members gm SET left_at = now()
     WHERE gm.group_id = g.id AND gm.left_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM department_members dm JOIN members m ON m.id = dm.member_id AND m.status = 'active'
                        WHERE dm.department_id = g.department_id AND dm.member_id = gm.member_id AND dm.left_at IS NULL);
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    RETURN n;
END$$;
CREATE OR REPLACE FUNCTION dl_groups_sync_departments() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0;
BEGIN
    FOR r IN SELECT id FROM groups WHERE kind = 'department' AND archived_at IS NULL LOOP
        n := n + dl_group_sync_department(r.id);
    END LOOP;
    RETURN n;
END$$;
CREATE OR REPLACE FUNCTION dl_groups_after_insert() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.kind = 'department' THEN PERFORM dl_group_sync_department(NEW.id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER groups_after_insert AFTER INSERT ON groups FOR EACH ROW EXECUTE FUNCTION dl_groups_after_insert();

GRANT SELECT, INSERT, UPDATE, DELETE ON tags, record_tags, glossary_terms, groups, group_members TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_tags_of(text, bigint), dl_member_group_ids(bigint) TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_group_sync_department(bigint), dl_groups_sync_departments() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_group_sync_department(bigint), dl_groups_sync_departments() TO datalake_rw;

COMMIT;
