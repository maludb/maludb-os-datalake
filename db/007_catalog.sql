-- 007: THE CATALOG — datasets, their columns, the profiles the writer records on every load, schema versions, follows (design §6.2).
-- New canonical: "the estate's data catalog" (nothing in the estate has a datasets, schemas or columns table; Spaces' `databases` — a
-- Notion database inside a page — compared and not taken).
--
-- The rules the database keeps (design §3, §6.2):
--   * a dataset's KEY is `<zone>.<name>`, unique FOREVER — a retired dataset keeps its row, so a key is never reused (R1);
--   * a sandbox dataset is named `sandbox.m<owner id>_<name>` and is its owner's;
--   * TECHNICAL METADATA IS NEVER TYPED BY A PERSON: row_count, byte_size, file_count, last_snapshot_id, data_updated_at, the physical
--     types and the profiles are the writer's (role datalake_lake); the web application's role cannot set them (dl_datasets_guard);
--   * a dataset's CLASSIFICATION is derived — the highest of its live columns' and of its floor (a sandbox result keeps the highest
--     classification of what it was made from, §3 rule 6); HAS_PERSONAL_DATA is derived from its live columns;
--   * "data updated" (the last commit that changed rows) and "metadata updated" (the last change a person or an agent made to the
--     entry) are two dates (CKAN's).
BEGIN;

-- The classification order, once.
CREATE OR REPLACE FUNCTION dl_class_rank(p text) RETURNS smallint LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p WHEN 'public' THEN 0 WHEN 'internal' THEN 1 WHEN 'confidential' THEN 2 WHEN 'restricted' THEN 3 ELSE 1 END::smallint;
$$;
CREATE OR REPLACE FUNCTION dl_class_of_rank(p smallint) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT (ARRAY['public', 'internal', 'confidential', 'restricted'])[p + 1];
$$;
-- The personal kinds (design §3); a mask policy is keyed by them (db/008).
CREATE OR REPLACE FUNCTION dl_personal_kinds() RETURNS text[] LANGUAGE sql IMMUTABLE AS $$
    SELECT ARRAY['email', 'phone', 'name', 'address', 'national_id', 'payment_card', 'bank_account', 'birth_date', 'ip_address',
                 'member_id', 'free_text_personal', 'other'];
$$;

CREATE TABLE datasets (
    id                        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key                       text NOT NULL UNIQUE CHECK (key ~ '^(raw|curated|product|sandbox)\.[a-z][a-z0-9_]{0,62}$'),
    zone                      text NOT NULL CHECK (zone IN ('raw', 'curated', 'product', 'sandbox')),
    name                      text NOT NULL,                                  -- the part after the zone
    title                     text NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    description               text CHECK (description IS NULL OR length(description) <= 8000),
    description_source        text NOT NULL DEFAULT 'human' CHECK (description_source IN ('human', 'agent_proposed', 'accepted')),
    purpose                   text,                                           -- ODCS description.purpose
    usage                     text,                                           -- ODCS description.usage
    limitations               text,                                           -- ODCS description.limitations
    owner_member_id           bigint REFERENCES members(id) ON DELETE SET NULL,
    steward_member_id         bigint REFERENCES members(id) ON DELETE SET NULL,
    department_id             bigint REFERENCES departments(id) ON DELETE SET NULL,
    status                    text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'deprecated', 'retired')),
    deprecation_note          text,
    successor_id              bigint REFERENCES datasets(id) ON DELETE SET NULL,
    certification             text NOT NULL DEFAULT 'none' CHECK (certification IN ('none', 'promoted', 'certified')),
    certified_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    certified_at              timestamptz,
    certification_lost_reason text,
    certification_lost_at     timestamptz,
    tier                      text NOT NULL DEFAULT '2' CHECK (tier IN ('1', '2', '3', 'unused')),
    classification            text NOT NULL DEFAULT 'internal' CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),  -- derived
    classification_floor      text NOT NULL DEFAULT 'public' CHECK (classification_floor IN ('public', 'internal', 'confidential', 'restricted')),
    has_personal_data         boolean NOT NULL DEFAULT false,                -- derived
    source_id                 bigint,                                         -- FK in db/009
    contract_version          text,                                           -- the active contract's version (db/010)
    -- the engine's (the writer's alone)
    ducklake_schema           text,
    ducklake_table_id         bigint,
    row_count                 bigint CHECK (row_count IS NULL OR row_count >= 0),
    byte_size                 bigint CHECK (byte_size IS NULL OR byte_size >= 0),
    file_count                integer CHECK (file_count IS NULL OR file_count >= 0),
    last_snapshot_id          bigint,
    data_updated_at           timestamptz,
    -- the expectations
    freshness_warn_after      interval,
    freshness_error_after     interval,
    retention_days            integer CHECK (retention_days IS NULL OR retention_days >= 1),
    evolution_mode            text NOT NULL DEFAULT 'rescue' CHECK (evolution_mode IN ('add', 'rescue', 'fail')),
    dedupe_key                text[] NOT NULL DEFAULT '{}',
    ordering_column           text,
    -- the dates
    metadata_updated_at       timestamptz NOT NULL DEFAULT now(),
    created_by                bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                timestamptz NOT NULL DEFAULT now(),
    updated_at                timestamptz NOT NULL DEFAULT now(),
    retired_at                timestamptz,
    CHECK (key = zone || '.' || name),
    CHECK (zone <> 'sandbox' OR (owner_member_id IS NOT NULL AND name LIKE 'm' || owner_member_id || '\_%')),
    CHECK ((certification = 'certified') = (certified_at IS NOT NULL) OR certification = 'promoted'),
    CHECK (zone <> 'sandbox' OR certification = 'none'),
    CHECK ((status = 'retired') = (retired_at IS NOT NULL)),
    CHECK (freshness_error_after IS NULL OR freshness_warn_after IS NULL OR freshness_error_after >= freshness_warn_after),
    CHECK (successor_id IS NULL OR successor_id <> id)
);
CREATE INDEX datasets_zone_idx ON datasets (zone) WHERE status <> 'retired';
CREATE INDEX datasets_owner_idx ON datasets (owner_member_id);
CREATE INDEX datasets_steward_idx ON datasets (steward_member_id);
CREATE INDEX datasets_department_idx ON datasets (department_id);
CREATE INDEX datasets_title_trgm_idx ON datasets USING gin (title gin_trgm_ops);
CREATE INDEX datasets_key_trgm_idx ON datasets USING gin (key gin_trgm_ops);
CREATE INDEX datasets_certified_idx ON datasets (certification) WHERE certification <> 'none';
CREATE TRIGGER datasets_touch BEFORE UPDATE ON datasets FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- The engine's columns are the writer's; a person's change to the entry moves "metadata updated"; the zone and name are fixed.
CREATE OR REPLACE FUNCTION dl_datasets_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF current_user <> 'datalake_lake' AND current_user <> 'postgres' THEN       -- a person never types technical metadata (R2)
            NEW.row_count := NULL; NEW.byte_size := NULL; NEW.file_count := NULL; NEW.last_snapshot_id := NULL; NEW.data_updated_at := NULL;
            NEW.ducklake_table_id := NULL; NEW.ducklake_schema := NULL;
        END IF;
        NEW.classification := 'internal'; NEW.has_personal_data := false;          -- derived after the insert (dl_dataset_derive)
        NEW.certification := 'none'; NEW.certified_at := NULL; NEW.certified_by := NULL;
    END IF;
    IF TG_OP = 'UPDATE' THEN
        IF NEW.key <> OLD.key OR NEW.zone <> OLD.zone OR NEW.name <> OLD.name THEN
            RAISE EXCEPTION 'A dataset''s key never changes (make a new dataset and deprecate this one)' USING ERRCODE = 'check_violation';
        END IF;
        IF current_user <> 'datalake_lake' AND current_user <> 'postgres' AND (
               NEW.row_count IS DISTINCT FROM OLD.row_count OR NEW.byte_size IS DISTINCT FROM OLD.byte_size
            OR NEW.file_count IS DISTINCT FROM OLD.file_count OR NEW.last_snapshot_id IS DISTINCT FROM OLD.last_snapshot_id
            OR NEW.data_updated_at IS DISTINCT FROM OLD.data_updated_at OR NEW.ducklake_table_id IS DISTINCT FROM OLD.ducklake_table_id
            OR NEW.ducklake_schema IS DISTINCT FROM OLD.ducklake_schema) THEN
            RAISE EXCEPTION 'Technical metadata is the engine''s — it is recorded by the writer, never typed' USING ERRCODE = 'insufficient_privilege';
        END IF;
        IF NEW.classification IS DISTINCT FROM OLD.classification AND current_setting('app.dl_deriving', true) IS DISTINCT FROM 'on' THEN
            NEW.classification := OLD.classification;                      -- derived only (dl_dataset_derive)
        END IF;
        IF NEW.has_personal_data IS DISTINCT FROM OLD.has_personal_data AND current_setting('app.dl_deriving', true) IS DISTINCT FROM 'on' THEN
            NEW.has_personal_data := OLD.has_personal_data;
        END IF;
        IF (NEW.title, NEW.description, NEW.purpose, NEW.usage, NEW.limitations, NEW.owner_member_id, NEW.steward_member_id, NEW.department_id,
            NEW.status, NEW.deprecation_note, NEW.successor_id, NEW.certification, NEW.tier, NEW.freshness_warn_after, NEW.freshness_error_after,
            NEW.retention_days, NEW.evolution_mode, NEW.dedupe_key, NEW.ordering_column)
           IS DISTINCT FROM
           (OLD.title, OLD.description, OLD.purpose, OLD.usage, OLD.limitations, OLD.owner_member_id, OLD.steward_member_id, OLD.department_id,
            OLD.status, OLD.deprecation_note, OLD.successor_id, OLD.certification, OLD.tier, OLD.freshness_warn_after, OLD.freshness_error_after,
            OLD.retention_days, OLD.evolution_mode, OLD.dedupe_key, OLD.ordering_column) THEN
            NEW.metadata_updated_at := now();
        END IF;
        IF NEW.status = 'retired' AND OLD.status <> 'retired' THEN NEW.retired_at := now(); END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER datasets_guard BEFORE INSERT OR UPDATE ON datasets FOR EACH ROW EXECUTE FUNCTION dl_datasets_guard();

CREATE TABLE dataset_columns (
    id                            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id                    bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    name                          text NOT NULL CHECK (name ~ '^[A-Za-z_][A-Za-z0-9_]{0,62}$'),
    ordinal                       integer NOT NULL CHECK (ordinal >= 0),
    physical_type                 text,                                       -- the engine's (DuckDB's type name)
    logical_type                  text CHECK (logical_type IS NULL OR logical_type IN ('string', 'integer', 'number', 'boolean', 'date', 'timestamp', 'object', 'array')),  -- ODCS
    nullable                      boolean NOT NULL DEFAULT true,
    is_key                        boolean NOT NULL DEFAULT false,
    is_system                     boolean NOT NULL DEFAULT false,             -- _dl_* provenance columns
    business_name                 text,
    description                   text CHECK (description IS NULL OR length(description) <= 4000),
    description_source            text NOT NULL DEFAULT 'human' CHECK (description_source IN ('human', 'agent_proposed', 'accepted')),
    classification                text NOT NULL DEFAULT 'internal' CHECK (classification IN ('public', 'internal', 'confidential', 'restricted')),
    personal_kind                 text CHECK (personal_kind IS NULL OR personal_kind = ANY (dl_personal_kinds())),
    classified_by                 text NOT NULL DEFAULT 'rule' CHECK (classified_by IN ('person', 'rule', 'agent')),
    classification_confirmed_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    classification_confirmed_at   timestamptz,
    proposed_personal_kind        text CHECK (proposed_personal_kind IS NULL OR proposed_personal_kind = ANY (dl_personal_kinds())),  -- the classifier's, awaiting a person
    proposed_confidence           numeric(4,3) CHECK (proposed_confidence IS NULL OR proposed_confidence BETWEEN 0 AND 1),
    glossary_term_id              bigint REFERENCES glossary_terms(id) ON DELETE SET NULL,
    critical                      boolean NOT NULL DEFAULT false,             -- ODCS criticalDataElement
    first_snapshot_id             bigint,
    removed_snapshot_id           bigint,                                     -- NULL = live
    profile                       jsonb,                                      -- the latest (§6.2); personal top values masked by the writer
    profiled_at                   timestamptz,
    created_at                    timestamptz NOT NULL DEFAULT now(),
    updated_at                    timestamptz NOT NULL DEFAULT now(),
    CHECK ((classification_confirmed_at IS NULL) = (classification_confirmed_by IS NULL))
);
CREATE UNIQUE INDEX dataset_columns_live_name ON dataset_columns (dataset_id, lower(name)) WHERE removed_snapshot_id IS NULL;
CREATE INDEX dataset_columns_dataset_idx ON dataset_columns (dataset_id, ordinal);
CREATE INDEX dataset_columns_personal_idx ON dataset_columns (personal_kind) WHERE personal_kind IS NOT NULL AND removed_snapshot_id IS NULL;
CREATE INDEX dataset_columns_proposed_idx ON dataset_columns (dataset_id) WHERE proposed_personal_kind IS NOT NULL AND classification_confirmed_at IS NULL;
CREATE INDEX dataset_columns_term_idx ON dataset_columns (glossary_term_id) WHERE glossary_term_id IS NOT NULL;
CREATE INDEX dataset_columns_name_trgm_idx ON dataset_columns USING gin (name gin_trgm_ops);
CREATE TRIGGER dataset_columns_touch BEFORE UPDATE ON dataset_columns FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A personal column is at least confidential (masked below read_unmasked, §3 rule 4); a column's engine fields are the writer's.
CREATE OR REPLACE FUNCTION dl_dataset_columns_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.personal_kind IS NOT NULL AND dl_class_rank(NEW.classification) < 2 THEN NEW.classification := 'confidential'; END IF;
    IF TG_OP = 'UPDATE' AND current_user <> 'datalake_lake' AND current_user <> 'postgres' AND (
           NEW.physical_type IS DISTINCT FROM OLD.physical_type OR NEW.profile IS DISTINCT FROM OLD.profile
        OR NEW.first_snapshot_id IS DISTINCT FROM OLD.first_snapshot_id OR NEW.removed_snapshot_id IS DISTINCT FROM OLD.removed_snapshot_id
        OR NEW.ordinal IS DISTINCT FROM OLD.ordinal OR NEW.name IS DISTINCT FROM OLD.name OR NEW.is_system IS DISTINCT FROM OLD.is_system) THEN
        RAISE EXCEPTION 'A column''s name, type and profile are the engine''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER dataset_columns_guard BEFORE INSERT OR UPDATE ON dataset_columns FOR EACH ROW EXECUTE FUNCTION dl_dataset_columns_guard();

-- The derived fields: the dataset's classification and personal flag follow its live columns and its floor.
CREATE OR REPLACE FUNCTION dl_dataset_derive(p_dataset_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r smallint; p boolean;
BEGIN
    SELECT GREATEST(COALESCE(max(dl_class_rank(c.classification)), 1), dl_class_rank(d.classification_floor)),
           COALESCE(bool_or(c.personal_kind IS NOT NULL), false)
      INTO r, p
      FROM datasets d LEFT JOIN dataset_columns c ON c.dataset_id = d.id AND c.removed_snapshot_id IS NULL AND NOT c.is_system
     WHERE d.id = p_dataset_id GROUP BY d.classification_floor;
    PERFORM set_config('app.dl_deriving', 'on', true);
    UPDATE datasets SET classification = dl_class_of_rank(r), has_personal_data = p
     WHERE id = p_dataset_id AND (classification IS DISTINCT FROM dl_class_of_rank(r) OR has_personal_data IS DISTINCT FROM p);
    PERFORM set_config('app.dl_deriving', 'off', true);
END$$;
CREATE OR REPLACE FUNCTION dl_dataset_columns_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM dl_dataset_derive(COALESCE(NEW.dataset_id, OLD.dataset_id));
    RETURN NULL;
END$$;
CREATE TRIGGER dataset_columns_after AFTER INSERT OR DELETE OR UPDATE OF classification, personal_kind, removed_snapshot_id ON dataset_columns
    FOR EACH ROW EXECUTE FUNCTION dl_dataset_columns_after();
CREATE OR REPLACE FUNCTION dl_datasets_floor_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM dl_dataset_derive(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER datasets_floor_after AFTER INSERT OR UPDATE OF classification_floor ON datasets FOR EACH ROW EXECUTE FUNCTION dl_datasets_floor_after();

-- A person confirms (or rejects) the classifier's proposal: the column takes the kind (and is at least confidential), or not.
CREATE OR REPLACE FUNCTION dl_column_confirm_personal(p_column_id bigint, p_accept boolean, p_kind text DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c dataset_columns%ROWTYPE; me bigint := app_current_member_id();
BEGIN
    SELECT * INTO c FROM dataset_columns WHERE id = p_column_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such column' USING ERRCODE = 'no_data_found'; END IF;
    IF NOT dl_has_right('catalog.edit') AND NOT EXISTS (SELECT 1 FROM datasets d WHERE d.id = c.dataset_id AND me IN (d.owner_member_id, d.steward_member_id)) THEN
        RAISE EXCEPTION 'Confirming personal data is the dataset''s owner''s, its steward''s or an engineer''s' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF p_accept THEN
        UPDATE dataset_columns SET personal_kind = COALESCE(p_kind, c.proposed_personal_kind), classified_by = 'person',
               classification_confirmed_by = me, classification_confirmed_at = now(), proposed_personal_kind = NULL, proposed_confidence = NULL
         WHERE id = p_column_id;
    ELSE
        UPDATE dataset_columns SET personal_kind = NULL, classified_by = 'person', classification_confirmed_by = me,
               classification_confirmed_at = now(), proposed_personal_kind = NULL, proposed_confidence = NULL
         WHERE id = p_column_id;
    END IF;
END$$;

-- Every load's profile, kept for drift (R5, R14); top values masked by the writer when the column is personal or confidential.
CREATE TABLE column_profiles (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id   bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    column_name  text NOT NULL,
    snapshot_id  bigint,
    load_id      bigint,                                                     -- FK in db/009
    profile      jsonb NOT NULL CHECK (jsonb_typeof(profile) = 'object'),
    profiled_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX column_profiles_dataset_idx ON column_profiles (dataset_id, column_name, profiled_at DESC);
CREATE INDEX column_profiles_age_idx ON column_profiles (profiled_at);

-- Schema history and drift (R14, R18): the writer records what it saw; a person decides a proposal (add a column to the contract, accept a
-- retype) — never applied silently (R50).
CREATE TABLE schema_versions (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dataset_id    bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    version       integer NOT NULL CHECK (version >= 1),
    snapshot_id   bigint,
    columns       jsonb NOT NULL CHECK (jsonb_typeof(columns) = 'array'),   -- [{name, physical_type, nullable}]
    changes       jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(changes) = 'array'),   -- [{column, kind added|removed|retyped|renamed, from, to}]
    breaking      boolean NOT NULL DEFAULT false,
    detected_by   text NOT NULL CHECK (detected_by IN ('load', 'contract', 'person', 'transform')),
    decision      text NOT NULL DEFAULT 'none' CHECK (decision IN ('none', 'pending', 'accepted', 'rejected')),
    decided_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at    timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (dataset_id, version)
);
CREATE INDEX schema_versions_pending_idx ON schema_versions (dataset_id) WHERE decision = 'pending';

-- A person follows a dataset to hear of what changes (§6.2).
CREATE TABLE dataset_follows (
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    dataset_id  bigint NOT NULL REFERENCES datasets(id) ON DELETE CASCADE,
    what        text[] NOT NULL DEFAULT '{schema_changed,check_failed,deprecated,access_decided,certification_lost,load_failed}',
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (member_id, dataset_id)
);
CREATE INDEX dataset_follows_dataset_idx ON dataset_follows (dataset_id);

GRANT SELECT, INSERT, UPDATE ON datasets, dataset_columns, schema_versions TO datalake_rw;
GRANT SELECT ON column_profiles TO datalake_rw;
GRANT SELECT, INSERT, DELETE ON dataset_follows TO datalake_rw;
GRANT SELECT, INSERT, UPDATE ON datasets, dataset_columns, column_profiles, schema_versions TO datalake_lake;
GRANT DELETE ON column_profiles TO datalake_lake;
GRANT EXECUTE ON FUNCTION dl_class_rank(text), dl_class_of_rank(smallint), dl_personal_kinds() TO datalake_rw, datalake_records_ro, datalake_activity_ro, datalake_lake, datalake_query;
REVOKE ALL ON FUNCTION dl_dataset_derive(bigint), dl_column_confirm_personal(bigint, boolean, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_dataset_derive(bigint) TO datalake_rw, datalake_lake;
GRANT EXECUTE ON FUNCTION dl_column_confirm_personal(bigint, boolean, text) TO datalake_rw;

COMMIT;
