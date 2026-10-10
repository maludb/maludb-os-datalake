-- 015: SEARCH — the catalog's keyword index on Spaces' canonical `search_index` (design §6.2, R4).
--
-- THE ESTATE'S DATA MODEL: `search_index` is Spaces db/013 VERBATIM; `entity_kind` widened with the lake's entities (dataset, glossary_term,
-- saved_query, metric); `dataset_id`, `zone`, `classification`, `certification`, `owner_member_id`, `department_id`, `usage_30d` appended.
-- Weighting: title and key A · column names and business names, tags, glossary terms B · description and column descriptions C (Spaces'
-- `simple` + `unaccent`). Ranking: CERTIFIED first, then USAGE (queries in 30 days), then relevance — the field's (Amundsen, Metabase).
-- The wall is per row (dl_member_access for the caller), the scale rule kept: the index cuts first, the wall judges the page.
BEGIN;

-- reuse: Spaces db/013 verbatim; entity_kind widened; the lake's columns appended
CREATE TABLE search_index (
    entity_kind   text NOT NULL CHECK (entity_kind IN ('page', 'row', 'message', 'comment', 'dataset', 'glossary_term', 'saved_query', 'metric')),
    entity_uuid   uuid,
    entity_id     bigint,
    page_id       uuid,
    space_id      bigint,
    channel_id    bigint,
    author_member_id bigint,
    title         text,
    body          text,
    tsv           tsvector,
    has_link      boolean NOT NULL DEFAULT false,
    has_file      boolean NOT NULL DEFAULT false,
    occurred_at   timestamptz NOT NULL,
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((entity_uuid IS NULL) <> (entity_id IS NULL)),
    -- appended (design §6.2)
    dataset_id     bigint,
    zone           text,
    classification text,
    certification  text,
    owner_member_id bigint,
    department_id  bigint,
    usage_30d      integer NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX search_index_uuid ON search_index (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL;
CREATE UNIQUE INDEX search_index_id   ON search_index (entity_kind, entity_id) WHERE entity_id IS NOT NULL;
CREATE INDEX search_index_tsv  ON search_index USING gin (tsv);
CREATE INDEX search_index_time ON search_index (occurred_at DESC);
CREATE INDEX search_index_dataset ON search_index (dataset_id);
CREATE INDEX search_index_title_trgm ON search_index USING gin (title gin_trgm_ops);

CREATE OR REPLACE FUNCTION dl_search_tsv(p_a text, p_b text, p_c text) RETURNS tsvector LANGUAGE sql IMMUTABLE AS $$
    SELECT setweight(to_tsvector('simple', unaccent(COALESCE(p_a, ''))), 'A')
        || setweight(to_tsvector('simple', unaccent(COALESCE(p_b, ''))), 'B')
        || setweight(to_tsvector('simple', unaccent(left(COALESCE(p_c, ''), 200000))), 'C');
$$;

-- Index one dataset (and refresh its metrics' and saved queries' ranking columns): called by triggers and by the writer after a load.
-- A key is split on '.' and '_' so `curated.sales_orders` is found by "sales orders".
CREATE OR REPLACE FUNCTION dl_index_dataset(p_dataset_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d datasets%ROWTYPE; b text; c text;
BEGIN
    SELECT * INTO d FROM datasets WHERE id = p_dataset_id;
    IF d.id IS NULL OR d.status = 'retired' THEN
        DELETE FROM search_index WHERE entity_kind = 'dataset' AND entity_id = p_dataset_id;
        RETURN;
    END IF;
    SELECT string_agg(concat_ws(' ', regexp_replace(c2.name, '_', ' ', 'g'), c2.business_name, t.name), ' ' ORDER BY c2.ordinal)
      INTO b FROM dataset_columns c2 LEFT JOIN glossary_terms t ON t.id = c2.glossary_term_id
     WHERE c2.dataset_id = d.id AND c2.removed_snapshot_id IS NULL AND NOT c2.is_system;
    b := concat_ws(' ', b, array_to_string(dl_tags_of('dataset', d.id), ' '));
    SELECT string_agg(c2.description, ' ' ORDER BY c2.ordinal) INTO c FROM dataset_columns c2
     WHERE c2.dataset_id = d.id AND c2.removed_snapshot_id IS NULL AND c2.description IS NOT NULL;
    c := concat_ws(' ', d.description, d.purpose, d.usage, c);
    INSERT INTO search_index (entity_kind, entity_id, title, body, tsv, occurred_at, dataset_id, zone, classification, certification, owner_member_id,
                              department_id, usage_30d, author_member_id)
    VALUES ('dataset', d.id, d.title, concat_ws(' — ', d.description, c),
            dl_search_tsv(concat_ws(' ', d.title, regexp_replace(d.key, '[._]', ' ', 'g')), b, c),
            COALESCE(d.data_updated_at, d.created_at), d.id, d.zone, d.classification, d.certification, d.owner_member_id, d.department_id,
            dl_usage_30d(d.id), d.owner_member_id)
    ON CONFLICT (entity_kind, entity_id) WHERE entity_id IS NOT NULL DO UPDATE SET
        title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv, occurred_at = EXCLUDED.occurred_at, zone = EXCLUDED.zone,
        classification = EXCLUDED.classification, certification = EXCLUDED.certification, owner_member_id = EXCLUDED.owner_member_id,
        department_id = EXCLUDED.department_id, usage_30d = EXCLUDED.usage_30d, author_member_id = EXCLUDED.author_member_id, updated_at = now();
END$$;
CREATE OR REPLACE FUNCTION dl_index_glossary_term(p_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t glossary_terms%ROWTYPE;
BEGIN
    SELECT * INTO t FROM glossary_terms WHERE id = p_id;
    IF t.id IS NULL OR t.status = 'deprecated' THEN DELETE FROM search_index WHERE entity_kind = 'glossary_term' AND entity_id = p_id; RETURN; END IF;
    INSERT INTO search_index (entity_kind, entity_id, title, body, tsv, occurred_at, author_member_id)
    VALUES ('glossary_term', t.id, t.name, t.definition, dl_search_tsv(t.name, array_to_string(t.synonyms, ' '), t.definition), t.updated_at, t.steward_member_id)
    ON CONFLICT (entity_kind, entity_id) WHERE entity_id IS NOT NULL DO UPDATE SET title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv,
        occurred_at = EXCLUDED.occurred_at, author_member_id = EXCLUDED.author_member_id, updated_at = now();
END$$;
CREATE OR REPLACE FUNCTION dl_index_saved_query(p_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE q saved_queries%ROWTYPE;
BEGIN
    SELECT * INTO q FROM saved_queries WHERE id = p_id;
    IF q.id IS NULL OR q.deleted_at IS NOT NULL THEN DELETE FROM search_index WHERE entity_kind = 'saved_query' AND entity_id = p_id; RETURN; END IF;
    INSERT INTO search_index (entity_kind, entity_id, title, body, tsv, occurred_at, author_member_id, certification, owner_member_id)
    VALUES ('saved_query', q.id, q.title, concat_ws(' — ', q.question, q.description), dl_search_tsv(q.title, q.question, q.description), q.updated_at,
            q.owner_member_id, CASE WHEN q.verified_at IS NOT NULL THEN 'certified' ELSE 'none' END, q.owner_member_id)
    ON CONFLICT (entity_kind, entity_id) WHERE entity_id IS NOT NULL DO UPDATE SET title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv,
        occurred_at = EXCLUDED.occurred_at, certification = EXCLUDED.certification, owner_member_id = EXCLUDED.owner_member_id, updated_at = now();
END$$;
CREATE OR REPLACE FUNCTION dl_index_metric(p_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE m metrics%ROWTYPE;
BEGIN
    SELECT * INTO m FROM metrics WHERE id = p_id;
    IF m.id IS NULL OR m.retired_at IS NOT NULL THEN DELETE FROM search_index WHERE entity_kind = 'metric' AND entity_id = p_id; RETURN; END IF;
    INSERT INTO search_index (entity_kind, entity_id, title, body, tsv, occurred_at, dataset_id, certification, owner_member_id)
    VALUES ('metric', m.id, m.title, m.description, dl_search_tsv(concat_ws(' ', m.title, regexp_replace(m.name, '_', ' ', 'g')), array_to_string(m.dimensions, ' '), m.description),
            m.updated_at, m.dataset_id, CASE WHEN m.certified THEN 'certified' ELSE 'none' END, m.owner_member_id)
    ON CONFLICT (entity_kind, entity_id) WHERE entity_id IS NOT NULL DO UPDATE SET title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv,
        occurred_at = EXCLUDED.occurred_at, dataset_id = EXCLUDED.dataset_id, certification = EXCLUDED.certification, owner_member_id = EXCLUDED.owner_member_id, updated_at = now();
END$$;

CREATE OR REPLACE FUNCTION dl_search_datasets_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN PERFORM dl_index_dataset(COALESCE(NEW.id, OLD.id)); RETURN NULL; END$$;
CREATE TRIGGER datasets_search AFTER INSERT OR UPDATE OF title, description, purpose, usage, status, certification, classification, owner_member_id, department_id, data_updated_at
    ON datasets FOR EACH ROW EXECUTE FUNCTION dl_search_datasets_trg();
CREATE OR REPLACE FUNCTION dl_search_columns_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN PERFORM dl_index_dataset(COALESCE(NEW.dataset_id, OLD.dataset_id)); RETURN NULL; END$$;
CREATE TRIGGER dataset_columns_search AFTER INSERT OR DELETE OR UPDATE OF name, business_name, description, glossary_term_id, removed_snapshot_id
    ON dataset_columns FOR EACH ROW EXECUTE FUNCTION dl_search_columns_trg();
CREATE OR REPLACE FUNCTION dl_search_tags_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF COALESCE(NEW.record_type, OLD.record_type) = 'dataset' THEN PERFORM dl_index_dataset(COALESCE(NEW.record_id, OLD.record_id)); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER record_tags_search AFTER INSERT OR DELETE ON record_tags FOR EACH ROW EXECUTE FUNCTION dl_search_tags_trg();
CREATE OR REPLACE FUNCTION dl_search_terms_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN PERFORM dl_index_glossary_term(COALESCE(NEW.id, OLD.id)); RETURN NULL; END$$;
CREATE TRIGGER glossary_terms_search AFTER INSERT OR UPDATE ON glossary_terms FOR EACH ROW EXECUTE FUNCTION dl_search_terms_trg();
CREATE OR REPLACE FUNCTION dl_search_saved_queries_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN PERFORM dl_index_saved_query(COALESCE(NEW.id, OLD.id)); RETURN NULL; END$$;
CREATE TRIGGER saved_queries_search AFTER INSERT OR UPDATE ON saved_queries FOR EACH ROW EXECUTE FUNCTION dl_search_saved_queries_trg();
CREATE OR REPLACE FUNCTION dl_search_metrics_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN PERFORM dl_index_metric(COALESCE(NEW.id, OLD.id)); RETURN NULL; END$$;
CREATE TRIGGER metrics_search AFTER INSERT OR UPDATE ON metrics FOR EACH ROW EXECUTE FUNCTION dl_search_metrics_trg();

-- May the current member see this index row? A dataset: discover it (full) or a stub; a metric: discover its dataset; a saved query: own it,
-- or it is shared and every dataset it reads is readable; a glossary term: anyone holding a role here.
CREATE OR REPLACE FUNCTION dl_search_row_visible(p_kind text, p_id bigint, p_dataset_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); q saved_queries%ROWTYPE;
BEGIN
    IF NOT dl_member_here(me) THEN RETURN NULL; END IF;
    IF p_kind = 'dataset' THEN
        IF dl_can_discover(p_id) THEN RETURN 'full'; END IF;
        IF dl_can_see_stub(p_id) THEN RETURN 'stub'; END IF;
        RETURN NULL;
    ELSIF p_kind = 'metric' THEN
        RETURN CASE WHEN dl_can_discover(p_dataset_id) THEN 'full' END;
    ELSIF p_kind = 'saved_query' THEN
        SELECT * INTO q FROM saved_queries WHERE id = p_id;
        IF q.owner_member_id = me THEN RETURN 'full'; END IF;
        IF q.shared AND NOT EXISTS (SELECT 1 FROM unnest(q.dataset_ids) x WHERE dl_level_rank(dl_level(x)) < 2) THEN RETURN 'full'; END IF;
        RETURN NULL;
    ELSIF p_kind = 'glossary_term' THEN
        RETURN 'full';
    END IF;
    RETURN NULL;
END$$;

-- THE SEARCH (C1): the box. Filters {kind, zone, department, owner, tag, classification, certification ('certified'), personal (bool)}.
-- A stub's title is its key's dataset title and nothing else (no excerpt).
CREATE OR REPLACE FUNCTION dl_search(p_q text, p_filters jsonb DEFAULT '{}'::jsonb, p_limit integer DEFAULT 25, p_offset integer DEFAULT 0)
    RETURNS TABLE (entity_kind text, entity_id bigint, dataset_id bigint, title text, excerpt text, zone text, certification text, classification text,
                   owner_member_id bigint, usage_30d integer, visibility text, rank real, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
#variable_conflict use_column
DECLARE q tsquery; f jsonb := COALESCE(p_filters, '{}'::jsonb); v_tag bigint;
BEGIN
    IF p_q IS NULL OR btrim(p_q) = '' THEN q := NULL; ELSE q := websearch_to_tsquery('simple', unaccent(p_q)); END IF;
    IF f ->> 'tag' IS NOT NULL THEN SELECT t.id INTO v_tag FROM tags t WHERE t.slug = f ->> 'tag'; IF v_tag IS NULL THEN v_tag := -1; END IF; END IF;
    RETURN QUERY
    WITH hits AS (
        SELECT si.*, CASE WHEN q IS NULL THEN 0::real ELSE ts_rank(si.tsv, q) END AS r
          FROM search_index si
         WHERE si.entity_kind IN ('dataset', 'glossary_term', 'saved_query', 'metric')
           AND (q IS NULL OR si.tsv @@ q OR (length(btrim(p_q)) >= 3 AND si.title % p_q))
           AND (f ->> 'kind' IS NULL OR si.entity_kind = f ->> 'kind')
           AND (f ->> 'zone' IS NULL OR si.zone = f ->> 'zone')
           AND (f ->> 'department' IS NULL OR si.department_id = (f ->> 'department')::bigint)
           AND (f ->> 'owner' IS NULL OR si.owner_member_id = (f ->> 'owner')::bigint)
           AND (f ->> 'classification' IS NULL OR si.classification = f ->> 'classification')
           AND (f ->> 'certification' IS NULL OR si.certification = f ->> 'certification')
           AND (v_tag IS NULL OR EXISTS (SELECT 1 FROM record_tags rt WHERE rt.record_type = si.entity_kind AND rt.record_id = si.entity_id AND rt.tag_id = v_tag))
           AND (f ->> 'personal' IS NULL OR EXISTS (SELECT 1 FROM datasets d WHERE d.id = si.dataset_id AND d.has_personal_data = (f ->> 'personal')::boolean))
         ORDER BY (si.certification = 'certified') DESC, si.usage_30d DESC, r DESC
         LIMIT 2000                                                      -- the scale rule: cut by the index first, then the wall
    ), judged AS (
        SELECT h.*, dl_search_row_visible(h.entity_kind, h.entity_id, h.dataset_id) AS vis FROM hits h
    )
    SELECT j.entity_kind, j.entity_id, j.dataset_id,
           j.title,
           CASE WHEN j.vis = 'stub' THEN NULL WHEN q IS NULL THEN left(j.body, 200)
                ELSE ts_headline('simple', COALESCE(j.body, ''), q, 'MaxFragments=2, MaxWords=24, MinWords=8, StartSel=<mark>, StopSel=</mark>') END,
           j.zone, j.certification, CASE WHEN j.vis = 'stub' THEN NULL ELSE j.classification END, j.owner_member_id, j.usage_30d, j.vis, j.r,
           count(*) OVER ()
      FROM judged j
     WHERE j.vis IS NOT NULL
     ORDER BY (j.vis = 'full') DESC, (j.certification = 'certified') DESC, j.usage_30d DESC, j.r DESC, j.occurred_at DESC
     LIMIT greatest(1, least(p_limit, 100)) OFFSET greatest(0, p_offset);
END$$;

GRANT SELECT ON search_index TO datalake_rw;
GRANT EXECUTE ON FUNCTION dl_search_tsv(text, text, text), dl_search(text, jsonb, integer, integer), dl_search_row_visible(text, bigint, bigint)
    TO datalake_rw, datalake_records_ro, datalake_activity_ro;
REVOKE ALL ON FUNCTION dl_index_dataset(bigint), dl_index_glossary_term(bigint), dl_index_saved_query(bigint), dl_index_metric(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dl_index_dataset(bigint), dl_index_glossary_term(bigint), dl_index_saved_query(bigint), dl_index_metric(bigint) TO datalake_rw, datalake_lake;

COMMIT;
