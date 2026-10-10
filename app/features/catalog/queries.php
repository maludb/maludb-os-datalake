<?php
declare(strict_types=1);

/**
 * The catalog's reads (slice 1, the CRUD exemplar — docs/build-specs/catalog.md "Query functions"): datasets, their columns and profiles,
 * their usage and who follows them. EVERY row comes through a walled view (mcp_datasets, mcp_dataset_columns, mcp_column_profiles,
 * mcp_dataset_usage, mcp_dataset_follows, mcp_members, mcp_departments, mcp_tags, mcp_glossary_terms) or a function that applies the wall
 * (dl_dataset_health, dl_dataset_frequent_users, dl_dataset_joined_with, dl_masks_for); the engine's size through dl_lake_tables only —
 * never a ducklake.* table. PHP never re-implements a rule: a dataset the caller may not discover is simply not in the view, a stub row
 * says is_stub, a health colour is dl_dataset_health()'s. The same functions answer the screens' JSON and (Phase 4) the records tools
 * find_datasets, get_dataset, dataset_describe, dataset_columns, dataset_usage and profile_history — one SQL per question.
 */

const DATASET_PAGE = 24;
const DATASET_ZONES = ['raw', 'curated', 'product', 'sandbox'];
const DATASET_STATUSES = ['draft' => 'Draft', 'active' => 'Active', 'deprecated' => 'Deprecated', 'retired' => 'Retired'];
const DATASET_TIERS = ['1' => 'Tier 1 — critical', '2' => 'Tier 2', '3' => 'Tier 3', 'unused' => 'Unused'];
const DATASET_CLASSIFICATIONS = ['public', 'internal', 'confidential', 'restricted'];
/** The schema-evolution modes, one line each (design §6.3). */
const EVOLUTION_MODES = [
    'add'    => 'Add — a new column appears in raw and a proposal asks to add it to the contract.',
    'rescue' => 'Rescue (the default) — new fields go to _dl_rescued and a proposal is raised; nothing is dropped.',
    'fail'   => 'Fail — the load stops and opens an incident.',
];
/** What a follower hears about (dataset_follows.what; the notification kinds of db/014). */
const FOLLOW_KINDS = ['schema_changed' => 'its schema changes', 'check_failed' => 'a check fails', 'deprecated' => 'it is deprecated',
    'access_decided' => 'an access request is decided', 'certification_lost' => 'it loses its certification', 'load_failed' => 'a load fails'];
/** The eight tabs of the dataset page, in order, by anchor (catalog.md divergence 1): the frame is slice 1's; a tab's partial is its slice's. */
const DATASET_TABS = ['about' => 'About', 'columns' => 'Columns', 'preview' => 'Preview', 'quality' => 'Quality', 'lineage' => 'Lineage',
    'usage' => 'Usage', 'access' => 'Access', 'history' => 'History'];
/** A dataset's name (the part after the zone; db/007's CHECK on the key). */
const DATASET_NAME_RE = '/^[a-z][a-z0-9_]{0,62}$/';

/** Words as an ILIKE pattern (the % and _ a person types are literal). */
function like_pattern(string $q): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
}

/** A PHP list as a text[] literal for CAST(:x AS text[]). */
function text_array_literal(array $values): string
{
    return '{' . implode(',', array_map(static fn ($v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $v) . '"', $values)) . '}';
}

/**
 * The WHERE of find_datasets() over mcp_datasets: [sql, args]. Filters (the browse screen's, and the records tool's): q (key or title),
 * zone (one or a list), department, owner (an id, 'me' or 'none'), steward, tag (a slug), certification (none|promoted|certified|any),
 * status (one or a list), classification (one or a list), has_personal (bool), tier.
 */
function dataset_filter_sql(array $f): array
{
    $w = ['NOT is_stub'];
    $a = [];
    if (($f['q'] ?? '') !== '') {
        $w[] = '(key ILIKE :q OR title ILIKE :q)';
        $a['q'] = like_pattern((string) $f['q']);
    }
    $lists = ['zone' => 'zone', 'status' => 'status', 'classification' => 'classification'];
    foreach ($lists as $k => $col) {
        if (($f[$k] ?? null) !== null && $f[$k] !== [] && $f[$k] !== '') {
            $w[] = "$col = ANY (CAST(:$k AS text[]))";
            $a[$k] = text_array_literal((array) $f[$k]);
        }
    }
    if (($f['department'] ?? null) !== null) {
        $w[] = 'department_id = :department';
        $a['department'] = (int) $f['department'];
    }
    if (($f['owner'] ?? null) !== null) {
        if ($f['owner'] === 'me') {
            $w[] = 'owner_member_id = app_current_member_id()';
        } elseif ($f['owner'] === 'none') {
            $w[] = 'owner_member_id IS NULL';
        } else {
            $w[] = 'owner_member_id = :owner';
            $a['owner'] = (int) $f['owner'];
        }
    }
    if (($f['steward'] ?? null) !== null) {
        $w[] = 'steward_member_id = :steward';
        $a['steward'] = (int) $f['steward'];
    }
    if (($f['tag'] ?? null) !== null) {
        $w[] = 'CAST(:tag AS text) = ANY (tags)';
        $a['tag'] = (string) $f['tag'];
    }
    if (($f['certification'] ?? null) !== null) {
        if ($f['certification'] === 'any') {
            $w[] = "certification <> 'none'";
        } else {
            $w[] = 'certification = :certification';
            $a['certification'] = (string) $f['certification'];
        }
    }
    if (($f['has_personal'] ?? null) !== null) {
        $w[] = 'has_personal_data = ' . ($f['has_personal'] ? 'true' : 'false');
    }
    if (($f['tier'] ?? null) !== null) {
        $w[] = 'tier = :tier';
        $a['tier'] = (string) $f['tier'];
    }
    return [implode(' AND ', $w), $a];
}

/** The browse screen's filters from the query string — anything unknown dropped. */
function browse_filters_from_request(): array
{
    $f = [];
    $zone = request_string('zone');
    if (in_array($zone, DATASET_ZONES, true)) {
        $f['zone'] = $zone;
    }
    if (($d = request_integer('department')) !== null) {
        $f['department'] = $d;
    }
    $tag = request_string('tag');
    if (preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $tag)) {
        $f['tag'] = $tag;
    }
    $owner = request_string('owner');
    if ($owner === 'me' || $owner === 'none') {
        $f['owner'] = $owner;
    } elseif (ctype_digit($owner) && strlen($owner) <= 18) {
        $f['owner'] = (int) $owner;
    }
    $status = request_string('status');
    if (isset(DATASET_STATUSES[$status]) && $status !== 'retired') {
        $f['status'] = $status;
    }
    $q = mb_substr(trim(request_string('q')), 0, 120);
    if ($q !== '') {
        $f['q'] = $q;
    }
    return $f;
}

/**
 * Which datasets — one page, the visible set computed once (a MATERIALIZED pass over the wall), the health of the page's rows only.
 * Rows: the view's columns + owner_name, successor_key (when the caller may discover the successor) and health (green/amber/red).
 * Order: certified first, then promoted, deprecated last, then usage in 30 days, then the title (or $filters['sort']: key, updated, usage, rows).
 * $filters['limit'] (≤ 100) for the records tool; the screen pages by DATASET_PAGE. Stubs are never here (they appear in search).
 */
function find_datasets(PDO $pdo, array $filters, int $page): array
{
    [$where, $args] = dataset_filter_sql($filters);
    $limit = max(1, min(100, (int) ($filters['limit'] ?? DATASET_PAGE)));
    $page = max(1, $page);
    $order = match ($filters['sort'] ?? '') {
        'key'     => 'key',
        'updated' => 'data_updated_at DESC NULLS LAST, metadata_updated_at DESC, key',
        'usage'   => 'usage_30d DESC, key',
        'rows'    => 'row_count DESC NULLS LAST, key',
        default   => "CASE certification WHEN 'certified' THEN 0 WHEN 'promoted' THEN 1 ELSE 2 END, (status = 'deprecated'), usage_30d DESC, title, key",
    };
    $st = $pdo->prepare("WITH v AS MATERIALIZED (SELECT * FROM mcp_datasets WHERE $where),
                              p AS (SELECT v.*, row_number() OVER (ORDER BY $order) AS rn, count(*) OVER () AS total_rows FROM v)
                         SELECT p.*, m.display_name AS owner_name,
                                (SELECT s.key FROM mcp_datasets s WHERE s.dataset_id = p.successor_id AND NOT s.is_stub) AS successor_key,
                                COALESCE(dl_dataset_health(p.dataset_id) ->> 'health', 'green') AS health
                           FROM p LEFT JOIN mcp_members m ON m.member_id = p.owner_member_id
                          WHERE p.rn > :off AND p.rn <= :off + :lim
                          ORDER BY p.rn");
    $st->execute($args + ['off' => ($page - 1) * $limit, 'lim' => $limit]);
    $rows = $st->fetchAll();
    $total = $rows === [] ? (int) (one_value($pdo, "SELECT count(*) FROM mcp_datasets WHERE $where", $args) ?? 0) : (int) $rows[0]['total_rows'];
    foreach ($rows as &$r) {
        $r['tags'] = pg_text_array((string) $r['tags']);
        unset($r['rn'], $r['total_rows']);
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total];
}

/** The browse screen's three ways in, over what the caller may discover: zones, departments and tags with their counts (one pass over the wall). */
function browse_facets(PDO $pdo): array
{
    $zones = array_fill_keys(DATASET_ZONES, 0);
    $depts = [];
    $tags = [];
    foreach ($pdo->query('SELECT zone, department_id, tags FROM mcp_datasets WHERE NOT is_stub')->fetchAll() as $r) {
        $zones[$r['zone']] = ($zones[$r['zone']] ?? 0) + 1;
        if ($r['department_id'] !== null) {
            $depts[(int) $r['department_id']] = ($depts[(int) $r['department_id']] ?? 0) + 1;
        }
        foreach (pg_text_array((string) $r['tags']) as $slug) {
            $tags[$slug] = ($tags[$slug] ?? 0) + 1;
        }
    }
    $departments = [];
    if ($depts !== []) {
        $st = $pdo->prepare('SELECT department_id, name FROM mcp_departments WHERE department_id = ANY (CAST(:ids AS bigint[])) ORDER BY name');
        $st->execute(['ids' => '{' . implode(',', array_keys($depts)) . '}']);
        foreach ($st->fetchAll() as $d) {
            $departments[] = ['department_id' => (int) $d['department_id'], 'name' => $d['name'], 'count' => $depts[(int) $d['department_id']]];
        }
    }
    arsort($tags);
    $tagRows = [];
    if ($tags !== []) {
        $st = $pdo->prepare('SELECT slug, label, colour, kind FROM mcp_tags WHERE slug = ANY (CAST(:s AS text[]))');
        $st->execute(['s' => text_array_literal(array_keys($tags))]);
        $meta = [];
        foreach ($st->fetchAll() as $t) {
            $meta[$t['slug']] = $t;
        }
        foreach (array_slice($tags, 0, 24, true) as $slug => $n) {
            $tagRows[] = ['slug' => (string) $slug, 'label' => $meta[$slug]['label'] ?? (string) $slug, 'colour' => $meta[$slug]['colour'] ?? null, 'count' => $n];
        }
    }
    return ['zones' => $zones, 'departments' => $departments, 'tags' => $tagRows];
}

/**
 * One dataset the caller may discover (the gate has passed): the view's row + owner_name, steward_name, department_name, successor
 * {id, key, title} (when discoverable), health (dl_dataset_health's whole answer), tags [{slug, label, colour, kind}], terms (the glossary
 * terms of its live columns), contract_version, engine {record_count, file_size_bytes, data_files} from dl_lake_tables when the engine has
 * the table (else null), accepted_by (the person who accepted the Steward's description). Null for a stub or nothing.
 */
function find_dataset(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare("SELECT v.*, m.display_name AS owner_name, s.display_name AS steward_name, dp.name AS department_name,
                                (SELECT jsonb_build_object('id', x.dataset_id, 'key', x.key, 'title', x.title) FROM mcp_datasets x
                                  WHERE x.dataset_id = v.successor_id AND NOT x.is_stub) AS successor,
                                dl_dataset_health(v.dataset_id) AS health,
                                EXTRACT(epoch FROM v.freshness_warn_after) AS freshness_warn_seconds, EXTRACT(epoch FROM v.freshness_error_after) AS freshness_error_seconds
                           FROM mcp_datasets v
                           LEFT JOIN mcp_members m ON m.member_id = v.owner_member_id
                           LEFT JOIN mcp_members s ON s.member_id = v.steward_member_id
                           LEFT JOIN mcp_departments dp ON dp.department_id = v.department_id
                          WHERE v.dataset_id = :id AND NOT v.is_stub");
    $st->execute(['id' => $id]);
    $d = $st->fetch();
    if ($d === false) {
        return null;
    }
    $d['tags'] = pg_text_array((string) $d['tags']);
    $d['successor'] = $d['successor'] === null ? null : json_decode((string) $d['successor'], true);
    $d['health'] = json_decode((string) ($d['health'] ?? 'null'), true) ?: ['health' => 'green', 'certification_missing' => []];
    $d['tag_rows'] = dataset_tag_rows($pdo, $d['tags']);
    $st = $pdo->prepare('SELECT DISTINCT t.term_id, t.name, t.status FROM mcp_dataset_columns c JOIN mcp_glossary_terms t ON t.term_id = c.glossary_term_id
                          WHERE c.dataset_id = :id AND c.removed_snapshot_id IS NULL ORDER BY t.name');
    $st->execute(['id' => $id]);
    $d['terms'] = $st->fetchAll();
    $d['engine'] = dataset_engine($pdo, $id);
    $d['accepted_by'] = $d['description_source'] === 'accepted'
        ? one_value($pdo, "SELECT m.display_name FROM mcp_steward_proposals p JOIN mcp_members m ON m.member_id = p.decided_by
                            WHERE p.dataset_id = :id AND p.kind = 'description' AND p.status IN ('accepted', 'edited') ORDER BY p.decided_at DESC LIMIT 1", ['id' => $id])
        : null;
    return $d;
}

/** A stub (dl_can_see_stub): its title, key, zone, owner's name, department and certification — nothing else (the view's stub row). */
function find_dataset_stub(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT v.dataset_id, v.key, v.zone, v.title, v.owner_member_id, v.department_id, v.certification, m.display_name AS owner_name, dp.name AS department_name
                           FROM mcp_datasets v LEFT JOIN mcp_members m ON m.member_id = v.owner_member_id LEFT JOIN mcp_departments dp ON dp.department_id = v.department_id
                          WHERE v.dataset_id = :id AND v.is_stub');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** The tags of a dataset with their labels and colours, in slug order. */
function dataset_tag_rows(PDO $pdo, array $slugs): array
{
    if ($slugs === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT tag_id, slug, label, colour, kind, archived_at FROM mcp_tags WHERE slug = ANY (CAST(:s AS text[])) ORDER BY slug');
    $st->execute(['s' => text_array_literal($slugs)]);
    return $st->fetchAll();
}

/** The engine's own count and size of the dataset's live table (dl_lake_tables, db/engine/001 — absent until the engine's first attach), or null. */
function dataset_engine(PDO $pdo, int $id): ?array
{
    static $has = null;
    $has ??= one_value($pdo, "SELECT to_regclass('public.dl_lake_tables') IS NOT NULL") === true;
    if (!$has) {
        return null;
    }
    $st = $pdo->prepare('SELECT record_count, file_size_bytes, data_files FROM dl_lake_tables WHERE dataset_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : ['record_count' => $r['record_count'] === null ? null : (int) $r['record_count'],
        'file_size_bytes' => $r['file_size_bytes'] === null ? null : (int) $r['file_size_bytes'], 'data_files' => (int) $r['data_files']];
}

/** A dataset's columns (mcp_dataset_columns): live ones unless $removed, without the _dl_* provenance unless $system; + term_name and references_key. */
function dataset_columns(PDO $pdo, int $datasetId, bool $removed, bool $system): array
{
    $st = $pdo->prepare('SELECT c.*, t.name AS term_name,
                                (SELECT x.key FROM mcp_datasets x WHERE x.dataset_id = c.references_dataset_id AND NOT x.is_stub) AS references_key
                           FROM mcp_dataset_columns c LEFT JOIN mcp_glossary_terms t ON t.term_id = c.glossary_term_id
                          WHERE c.dataset_id = :d AND (CAST(:removed AS boolean) OR c.removed_snapshot_id IS NULL) AND (CAST(:system AS boolean) OR NOT c.is_system)
                          ORDER BY (c.removed_snapshot_id IS NOT NULL), c.ordinal, c.name');
    $st->execute(['d' => $datasetId, 'removed' => $removed ? 't' : 'f', 'system' => $system ? 't' : 'f']);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['profile'] = $r['profile'] === null ? null : json_decode((string) $r['profile'], true);
    }
    unset($r);
    return $rows;
}

/** One column of a dataset by its id or its name (a live column first; a removed one by id only). */
function find_column(PDO $pdo, int $datasetId, string $nameOrId): ?array
{
    $byId = ctype_digit($nameOrId) && strlen($nameOrId) <= 18;
    if (!$byId && !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/', $nameOrId)) {
        return null;
    }
    $st = $pdo->prepare('SELECT c.*, t.name AS term_name, t.status AS term_status,
                                (SELECT x.key FROM mcp_datasets x WHERE x.dataset_id = c.references_dataset_id AND NOT x.is_stub) AS references_key
                           FROM mcp_dataset_columns c LEFT JOIN mcp_glossary_terms t ON t.term_id = c.glossary_term_id
                          WHERE c.dataset_id = :d AND ' . ($byId ? 'c.column_id = :c' : 'lower(c.name) = lower(:c) AND c.removed_snapshot_id IS NULL'));
    $st->execute(['d' => $datasetId, 'c' => $byId ? (int) $nameOrId : $nameOrId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['profile'] = $r['profile'] === null ? null : json_decode((string) $r['profile'], true);
    return $r;
}

/** A column's profile over time (mcp_column_profiles — every load's), newest first, ≤ $limit (≤ 400). */
function column_profile_history(PDO $pdo, int $datasetId, string $column, int $limit): array
{
    $st = $pdo->prepare('SELECT profile_id, snapshot_id, load_id, profile, profiled_at FROM mcp_column_profiles
                          WHERE dataset_id = :d AND lower(column_name) = lower(:c) ORDER BY profiled_at DESC, profile_id DESC LIMIT ' . max(1, min(400, $limit)));
    $st->execute(['d' => $datasetId, 'c' => $column]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['profile'] = json_decode((string) $r['profile'], true) ?: [];
    }
    unset($r);
    return $rows;
}

/** The live columns of a dataset the caller may discover, for the reference's select (names only). */
function column_names_for_pick(PDO $pdo, int $datasetId): array
{
    $st = $pdo->prepare('SELECT name FROM mcp_dataset_columns WHERE dataset_id = :d AND removed_snapshot_id IS NULL AND NOT is_system ORDER BY ordinal, name');
    $st->execute(['d' => $datasetId]);
    return array_column($st->fetchAll(), 'name');
}

/**
 * Who uses a dataset and how much: daily [{day, queries, people, agents, rows_read}] for $days (every day present, zeros where nothing ran —
 * the 30-cell strip), frequent_users [{member_id, name, member_kind, queries}], joined_with [{dataset_id, key, times}]. Zeros until slice 3
 * records query runs; the wall is the view's and the functions'.
 */
function dataset_usage(PDO $pdo, int $datasetId, int $days): array
{
    $days = max(1, min(400, $days));
    $st = $pdo->prepare("SELECT g.day::date AS day, COALESCE(u.queries, 0) AS queries, COALESCE(u.people, 0) AS people, COALESCE(u.agents, 0) AS agents,
                                COALESCE(u.rows_read, 0) AS rows_read
                           FROM generate_series(current_date - (:n - 1), current_date, interval '1 day') g(day)
                           LEFT JOIN mcp_dataset_usage u ON u.dataset_id = :d AND u.day = g.day::date
                          ORDER BY g.day");
    $st->execute(['n' => $days, 'd' => $datasetId]);
    $daily = array_map(static fn (array $r): array => ['day' => $r['day'], 'queries' => (int) $r['queries'], 'people' => (int) $r['people'],
        'agents' => (int) $r['agents'], 'rows_read' => (int) $r['rows_read']], $st->fetchAll());
    $st = $pdo->prepare('SELECT member_id, name, member_kind, queries FROM dl_dataset_frequent_users(:d, :n, 10)');
    $st->execute(['d' => $datasetId, 'n' => $days]);
    $users = array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'], 'name' => $r['name'], 'member_kind' => $r['member_kind'],
        'queries' => (int) $r['queries']], $st->fetchAll());
    $st = $pdo->prepare('SELECT dataset_id, key, times FROM dl_dataset_joined_with(:d, 10)');
    $st->execute(['d' => $datasetId]);
    $joined = array_map(static fn (array $r): array => ['dataset_id' => (int) $r['dataset_id'], 'key' => $r['key'], 'times' => (int) $r['times']], $st->fetchAll());
    return ['daily' => $daily, 'frequent_users' => $users, 'joined_with' => $joined];
}

/** The caller's follow of a dataset ({what[], created_at}) or null. */
function my_follow(PDO $pdo, int $datasetId): ?array
{
    $st = $pdo->prepare('SELECT what, created_at FROM mcp_dataset_follows WHERE dataset_id = :d');
    $st->execute(['d' => $datasetId]);
    $r = $st->fetch();
    return $r === false ? null : ['what' => pg_text_array((string) $r['what']), 'created_at' => $r['created_at']];
}

/** The datasets the caller follows (the home's tile): discoverable ones only, newest follow first. */
function my_followed_datasets(PDO $pdo, int $limit = 6): array
{
    $st = $pdo->prepare('SELECT v.dataset_id, v.key, v.title, v.zone, v.certification FROM mcp_dataset_follows f JOIN mcp_datasets v ON v.dataset_id = f.dataset_id AND NOT v.is_stub
                          ORDER BY f.created_at DESC LIMIT ' . max(1, min(50, $limit)));
    $st->execute();
    return $st->fetchAll();
}

/**
 * dataset_describe — what an agent reads aloud or cites: purpose, usage, limitations, the columns with their meaning and whether they are
 * masked FOR THE CALLER (dl_masks_for at the caller's level — the intersection under K38), owner and steward by name, as-of, certification
 * and what it lacks. Null when the caller may not discover it.
 */
function dataset_describe(PDO $pdo, int $id): ?array
{
    $d = find_dataset($pdo, $id);
    if ($d === null) {
        return null;
    }
    $masks = json_decode((string) (one_value($pdo, 'SELECT dl_masks_for(:id, :lvl)', ['id' => $id, 'lvl' => (string) $d['my_level']]) ?? '{}'), true) ?: [];
    $cols = [];
    foreach (dataset_columns($pdo, $id, false, false) as $c) {
        $cols[] = ['name' => $c['name'], 'business_name' => $c['business_name'], 'logical_type' => $c['logical_type'], 'description' => $c['description'],
                   'personal_kind' => $c['personal_kind'], 'masked_for_you' => isset($masks[$c['name']])];
    }
    return ['key' => $d['key'], 'title' => $d['title'], 'description' => $d['description'], 'purpose' => $d['purpose'], 'usage' => $d['usage'],
            'limitations' => $d['limitations'], 'owner' => $d['owner_name'], 'steward' => $d['steward_name'], 'as_of' => json_ts($d['data_updated_at']),
            'certification' => $d['certification'], 'certification_missing' => array_values((array) ($d['health']['certification_missing'] ?? [])),
            'my_level' => $d['my_level'], 'columns' => $cols];
}

/** May the caller describe this dataset (dataset_update, tag, untag, column_update)? Its owner (level own), its steward, or catalog.edit. */
function may_describe_dataset(array $d): bool
{
    return ($d['my_level'] ?? '') === 'own' || ((int) ($d['steward_member_id'] ?? 0) !== 0 && (int) $d['steward_member_id'] === (int) current_member_id())
        || has_right('catalog.edit');
}

/** The refusal when the caller may not describe it, in words. */
function require_describe(array $d): void
{
    if (!may_describe_dataset($d)) {
        refuse(403, 'Only the owner of ' . $d['key'] . ', its steward or a data engineer may change its description.');
    }
}

/** An interval as [number, unit] for the form (hours below a day, else days); [null, 'hours'] for none. */
function interval_parts(?string $seconds): array
{
    if ($seconds === null || $seconds === '') {
        return [null, 'hours'];
    }
    $s = (int) round((float) $seconds);
    if ($s > 0 && $s % 86400 === 0) {
        return [intdiv($s, 86400), 'days'];
    }
    return [max(1, (int) round($s / 3600)), 'hours'];
}

/** An interval in words: "6 hours", "2 days". */
function interval_words(?string $seconds): ?string
{
    if ($seconds === null || $seconds === '') {
        return null;
    }
    [$n, $u] = interval_parts($seconds);
    return $n . ' ' . ($n === 1 ? rtrim($u, 's') : $u);
}

/** The picker's page of datasets: discoverable ones (never a stub), by key or title. Params: `except` (a dataset left out — not its own successor), `zone_id` unused. */
function datasets_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "NOT is_stub AND status <> 'retired' AND (:q = '' OR key ILIKE :like OR title ILIKE :like)";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    if (($params['except'] ?? null) !== null) {
        $where .= ' AND dataset_id <> :except';
        $args['except'] = (int) $params['except'];
    }
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_datasets WHERE $where",
        "SELECT dataset_id, key, title, zone, status, certification FROM mcp_datasets WHERE $where
          ORDER BY CASE certification WHEN 'certified' THEN 0 WHEN 'promoted' THEN 1 ELSE 2 END, (status = 'deprecated'), title, dataset_id", $args, $page);
    $r['rows'] = array_map(static fn (array $d): array => ['id' => (int) $d['dataset_id'], 'label' => (string) $d['title'], 'detail' => (string) $d['key'],
        'badge' => $d['status'] === 'deprecated' ? 'Deprecated' : ($d['certification'] === 'certified' ? 'Certified' : $d['zone']),
        'color' => $d['status'] === 'deprecated' ? 'warning' : ($d['certification'] === 'certified' ? 'success' : zone_color((string) $d['zone']))], $r['rows']);
    return $r;
}

/** The picker's page of stewards: members holding catalog.edit or owning a dataset the caller may discover (catalog.md's decision). */
function stewards_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "(:q = '' OR m.display_name ILIKE :like OR m.job_title ILIKE :like)
              AND (dl_member_has_right(m.member_id, 'catalog.edit') OR EXISTS (SELECT 1 FROM mcp_datasets d WHERE d.owner_member_id = m.member_id AND NOT d.is_stub))";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_members m WHERE $where",
        "SELECT m.member_id, m.display_name, m.job_title, m.is_agent, dl_member_has_right(m.member_id, 'catalog.edit') AS engineer FROM mcp_members m WHERE $where
          ORDER BY m.is_agent, m.display_name, m.member_id", $args, $page);
    $r['rows'] = array_map(static fn (array $m): array => ['id' => (int) $m['member_id'], 'label' => (string) $m['display_name'],
        'detail' => (string) ($m['job_title'] ?? ''), 'badge' => $m['is_agent'] ? 'Agent' : ($m['engineer'] ? 'Engineer' : 'Owner'),
        'color' => $m['is_agent'] ? 'info' : 'secondary'], $r['rows']);
    return $r;
}
