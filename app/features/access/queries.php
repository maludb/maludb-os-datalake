<?php
declare(strict_types=1);

/**
 * Access — what I may read and why, requests, grants (docs/build-specs/access-policies.md; access-and-policies.md §1–§4). Every rule is the
 * database's (db/008, db/018, db/022, db/023): these functions ASK — dl_my_access, dl_access_list, dl_access_explain, dl_member_access,
 * dl_masks_for, dl_bind_row_filter, dl_grant_via — and read the walled views (mcp_grants, mcp_access_requests, mcp_datasets); they never
 * decide a level. The tools this slice makes real (my_access, access_list, access_explain, find_access_requests, find_grants,
 * dataset_readers, access_history, grants_expiring, grants_unused) call the same functions (Phase 4 ports them).
 */

const ACCESS_PAGE = 25;
const ACCESS_LEVELS = ['discover', 'read', 'read_unmasked', 'write', 'own'];
const REQUEST_LEVELS = ['discover', 'read', 'read_unmasked', 'write'];
const REQUEST_BOXES = ['mine' => 'Mine', 'to_decide' => 'To decide', 'history' => 'History'];
const PRINCIPAL_KINDS = ['member' => 'A person', 'group' => 'A group', 'department' => 'A department', 'agent' => 'An agent', 'everyone' => 'Everyone inside'];
/** The levels the database admits for a principal kind (db/008's CHECKs: everyone and an application at most read). */
const PRINCIPAL_LEVELS = ['member' => ACCESS_LEVELS, 'group' => ACCESS_LEVELS, 'department' => ACCESS_LEVELS, 'agent' => ACCESS_LEVELS, 'everyone' => ['discover', 'read']];
const GRANT_ZONES = ['raw', 'curated', 'product'];
const ROW_FILTER_TOKENS = ['{member_id}', '{departments}', '{groups}'];
/** The principal picker's kind as the picker endpoint forwards it (an integer param): member 0, agent 1, group 2, department 3. */
const PRINCIPAL_PICK_KIND = ['member' => 0, 'agent' => 1, 'group' => 2, 'department' => 3];

/** Words for a level ("read unmasked"). */
function level_words(string $level): string
{
    return ['none' => 'no access', 'discover' => 'discover', 'read' => 'read (masked)', 'read_unmasked' => 'read unmasked', 'write' => 'write', 'own' => 'own'][$level] ?? $level;
}

/** What I may read (screen `my-access`; dl_my_access — the candidates are only what reaches me, judged one by one). $level: the least level shown. */
function my_access(PDO $pdo, int $member, ?string $level, int $page): array
{
    $level = in_array($level, ACCESS_LEVELS, true) ? $level : 'read';
    $page = max(1, $page);
    $st = $pdo->prepare("SELECT a.dataset_id, a.key, a.title, a.level, a.source, a.row_filtered, a.masked_columns, a.expires_at,
                                CASE WHEN a.source LIKE 'grant %' THEN dl_grant_via(substr(a.source, 7)::bigint) END AS via,
                                d.zone, d.classification, d.has_personal_data, d.certification
                           FROM dl_my_access(:l, :lim, :off) a JOIN mcp_datasets d ON d.dataset_id = a.dataset_id AND NOT d.is_stub
                          ORDER BY a.key");
    $st->execute(['l' => $level, 'lim' => ACCESS_PAGE, 'off' => ($page - 1) * ACCESS_PAGE]);
    $rows = $st->fetchAll();
    $total = (int) one_value($pdo, 'SELECT count(*) FROM dl_my_access(:l, 200, 0)', ['l' => $level]);
    unset($member);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'level' => $level];
}

/** "Through what" in words for a my_access row: owner, steward, break-glass, the catalog, the admin's discovery, or the grant's principal. */
function access_source_words(array $r): string
{
    $src = (string) ($r['source'] ?? '');
    return match (true) {
        $src === 'owner' => 'you own it',
        $src === 'steward' => 'you are its steward',
        $src === 'break_glass' => 'your break-glass',
        $src === 'catalog' => 'the catalog (anyone inside may discover it)',
        $src === 'admin' => 'Lake admin (discovers every dataset)',
        str_starts_with($src, 'grant ') => 'a grant to ' . (string) ($r['via'] ?? 'you'),
        default => $src,
    };
}

/**
 * The dataset page's Access tab: the owner and grants.manage see who reads it (dl_access_list — principal, level, filter, masked columns, via);
 * anyone else who discovers it sees why they have what they have (dl_access_explain, as sentences). ['mode' => list|explain, 'rows'?, 'explain'?]
 */
function access_tab(PDO $pdo, int $member, int $dataset): array
{
    $level = dataset_level($pdo, $dataset);
    if ($level === 'own' || has_right('grants.manage')) {
        $st = $pdo->prepare('SELECT * FROM dl_access_list(:d)');
        $st->execute(['d' => $dataset]);
        $rows = $st->fetchAll();
        if ($rows !== []) {
            return ['mode' => 'list', 'level' => $level, 'rows' => $rows, 'explain' => access_explain($pdo, $member, $dataset)];
        }
    }
    return ['mode' => 'explain', 'level' => $level, 'explain' => access_explain($pdo, $member, $dataset)];
}

/** dl_access_explain(dataset, member) decoded — the reasons, the level, the masked columns, the row filter (bound). */
function access_explain(PDO $pdo, int $member, int $dataset): array
{
    $j = one_value($pdo, 'SELECT dl_access_explain(:d, :m)::text', ['d' => $dataset, 'm' => $member]);
    return json_decode((string) ($j ?? '{}'), true) ?: [];
}

/** The reasons as sentences ("You read this dataset masked because: …"): [headline, [reason sentences], masks sentence?, filter sentence?]. */
function access_explain_sentences(array $explain): array
{
    $level = (string) ($explain['level'] ?? 'none');
    $masked = array_values(array_map('strval', $explain['masked_columns'] ?? []));
    $head = match ($level) {
        'own' => 'You own this dataset',
        'write' => 'You may change this dataset',
        'read_unmasked' => 'You read this dataset unmasked',
        'read' => $masked === [] ? 'You read this dataset' : 'You read this dataset masked',
        'discover' => 'You may see this dataset in the catalog but not read its rows',
        default => 'You have no access to this dataset',
    };
    $reasons = [];
    foreach ($explain['reasons'] ?? [] as $r) {
        $reasons[] = match ((string) ($r['because'] ?? '')) {
            'owner' => 'you are its owner → own',
            'steward' => 'you are its steward → read',
            'grant' => (in_array($r['via'] ?? '', ['member', 'agent'], true) ? 'a grant to you'
                : (($r['via'] ?? '') === 'department' ? 'member of ' . ($r['via_name'] ?? 'a department')
                : (($r['via'] ?? '') === 'group' ? 'member of the group ' . ($r['via_name'] ?? '') : 'everyone inside')))
                . ' → a grant on ' . (($r['target'] ?? '') === 'dataset' ? 'this dataset' : (($r['target'] ?? '') === 'zone' ? 'the ' . ($r['target_name'] ?? '') . ' zone' : 'the tag ' . ($r['target_name'] ?? '')))
                . ' → ' . level_words((string) ($r['level'] ?? '')) . (($r['row_filter'] ?? null) !== null ? ', rows filtered' : '')
                . (($r['expires_at'] ?? null) !== null ? ' (until ' . format_date(substr((string) $r['expires_at'], 0, 10)) . ')' : ''),
            'catalog' => 'anyone inside may discover a public or internal dataset → discover',
            'admin' => 'a Lake admin discovers every dataset → discover',
            'break_glass' => 'your break-glass is open → read unmasked for the hour',
            default => (string) ($r['because'] ?? ''),
        };
    }
    $out = ['headline' => $head . ($reasons === [] ? '.' : ' because:'), 'reasons' => $reasons, 'masks' => null, 'filter' => null];
    if ($masked !== [] && dl_level_rank($level) >= DL_LEVELS['read']) {
        $out['masks'] = implode(', ', $masked) . ($masked === [$masked[0]] ? ' is' : ' are') . ' masked for you.';
    }
    if (($explain['row_filter'] ?? null) !== null && dl_level_rank($level) >= DL_LEVELS['read']) {
        $out['filter'] = 'You read only the rows your grants name (rows filtered).';
    }
    return $out;
}

/** The columns of a dataset a level masks: [column => mask] (dl_masks_for). */
function masks_at(PDO $pdo, int $dataset, string $level): array
{
    return json_decode((string) (one_value($pdo, 'SELECT dl_masks_for(:d, :l)::text', ['d' => $dataset, 'l' => $level]) ?? '{}'), true) ?: [];
}

// ---- requests -------------------------------------------------------------------------------------------------------------------

const REQUEST_SELECT = "SELECT r.request_id, r.requester_id, r.dataset_id, r.level, r.reason, r.wanted_until, r.drafted_by_agent, r.status, r.decided_by,
                               r.decided_at, r.decision_note, r.grant_id, r.created_at, d.key, d.title, d.is_stub, d.owner_member_id,
                               rq.display_name AS requester_name, ag.display_name AS drafted_by_name, dc.display_name AS decided_by_name,
                               (SELECT a.level FROM dl_member_access(r.dataset_id, r.requester_id) a) AS requester_level
                          FROM mcp_access_requests r
                          JOIN mcp_datasets d ON d.dataset_id = r.dataset_id
                          LEFT JOIN mcp_members rq ON rq.member_id = r.requester_id
                          LEFT JOIN mcp_members ag ON ag.member_id = r.drafted_by_agent
                          LEFT JOIN mcp_members dc ON dc.member_id = r.decided_by";

/**
 * Requests in a box (screen `requests`): mine — the ones I asked (or an agent drafted for me); to_decide — pending, not mine, that I may decide
 * (mcp_access_requests shows me those of my datasets, or all for grants.manage); history — decided, withdrawn or expired, that I may see.
 * $filters: dataset (id). ['rows', 'total', 'page'].
 */
function find_access_requests(PDO $pdo, int $member, string $box, array $filters, int $page): array
{
    $where = match ($box) {
        'to_decide' => "r.status = 'pending' AND r.requester_id <> :me",
        'history' => "r.status <> 'pending'",
        default => 'r.requester_id = :me',
    };
    $args = $box === 'history' ? [] : ['me' => $member];
    if (!empty($filters['dataset'])) {
        $where .= ' AND r.dataset_id = :ds';
        $args['ds'] = (int) $filters['dataset'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'approved', 'denied', 'withdrawn', 'expired'], true)) {
        $where .= ' AND r.status = :st';
        $args['st'] = $filters['status'];
    }
    $page = max(1, $page);
    $order = $box === 'mine' ? "(r.status = 'pending') DESC, r.created_at DESC" : ($box === 'to_decide' ? 'r.created_at' : 'COALESCE(r.decided_at, r.created_at) DESC');
    $st = $pdo->prepare(REQUEST_SELECT . " WHERE $where ORDER BY $order, r.request_id DESC LIMIT " . ACCESS_PAGE . ' OFFSET ' . (($page - 1) * ACCESS_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT count(*) FROM mcp_access_requests r WHERE $where");
    $st->execute($args);
    return ['rows' => $rows, 'total' => (int) $st->fetchColumn(), 'page' => $page];
}

/** One request I may see, or null. */
function find_access_request(PDO $pdo, int $id): ?array
{
    return one_row($pdo, REQUEST_SELECT . ' WHERE r.request_id = :id', ['id' => $id]);
}

/** The count of requests waiting for me (the badge on "To decide"). */
function requests_to_decide_count(PDO $pdo, int $member): int
{
    return (int) one_value($pdo, "SELECT count(*) FROM mcp_access_requests WHERE status = 'pending' AND requester_id <> :me", ['me' => $member]);
}

/** My pending request on a dataset, or null ("a request already waits"). */
function my_pending_request(PDO $pdo, int $member, int $dataset): ?array
{
    return one_row($pdo, "SELECT request_id, level, created_at FROM mcp_access_requests WHERE requester_id = :me AND dataset_id = :d AND status = 'pending'",
        ['me' => $member, 'd' => $dataset]);
}

/** What approving would give: the level, how many columns stay masked at it, whether rows stay filtered (an approval's grant has no filter). */
function request_impact(PDO $pdo, int $request, ?string $level): array
{
    $r = find_access_request($pdo, $request);
    if ($r === null) {
        return ['level' => $level, 'masked_count' => 0, 'row_filtered' => false];
    }
    $level = in_array($level, REQUEST_LEVELS, true) ? $level : (string) $r['level'];
    return ['level' => $level, 'masked_count' => count(masks_at($pdo, (int) $r['dataset_id'], $level)), 'row_filtered' => false];
}

/** May I decide this request? The dataset's owner or grants.manage — never its requester (the database says so again in dl_access_decide). */
function may_decide_request(array $r, int $me): bool
{
    return $r['status'] === 'pending' && (int) $r['requester_id'] !== $me
        && ((int) ($r['owner_member_id'] ?? 0) === $me || has_right('grants.manage'));
}

// ---- grants ---------------------------------------------------------------------------------------------------------------------

const GRANT_SELECT = "SELECT g.grant_id, g.target_kind, g.dataset_id, g.zone, g.tag_id, g.principal_kind, g.principal_id, g.principal_ref, g.level, g.row_filter,
                             g.expires_at, g.reason, g.granted_by, g.created_at, g.revoked_at, g.revoked_by, g.access_request_id,
                             d.key AS dataset_key, d.title AS dataset_title, d.owner_member_id AS dataset_owner_id, t.slug AS tag_slug, t.label AS tag_label,
                             COALESCE(pm.display_name, pg.name, pd.name, g.principal_ref, CASE WHEN g.principal_kind = 'everyone' THEN 'Everyone inside' END) AS principal_name,
                             gb.display_name AS granted_by_name, rb.display_name AS revoked_by_name
                        FROM mcp_grants g
                        LEFT JOIN mcp_datasets d ON d.dataset_id = g.dataset_id
                        LEFT JOIN mcp_tags t ON t.tag_id = g.tag_id
                        LEFT JOIN mcp_members pm ON g.principal_kind IN ('member', 'agent') AND pm.member_id = g.principal_id
                        LEFT JOIN mcp_groups pg ON g.principal_kind = 'group' AND pg.group_id = g.principal_id
                        LEFT JOIN mcp_departments pd ON g.principal_kind = 'department' AND pd.department_id = g.principal_id
                        LEFT JOIN mcp_members gb ON gb.member_id = g.granted_by
                        LEFT JOIN mcp_members rb ON rb.member_id = g.revoked_by";

/** "member:26", "group:3", "everyone" → [kind, id|null], or null when it names nothing. */
function parse_principal(string $p): ?array
{
    if ($p === 'everyone') {
        return ['everyone', null];
    }
    if (preg_match('/^(member|group|department|agent):([0-9]{1,18})$/', $p, $m)) {
        return [$m[1], (int) $m[2]];
    }
    return null;
}

/**
 * Grants I may see (screen `grants`; mcp_grants: grants.manage every grant, a dataset's owner the grants on it, a grantee their own).
 * $filters: dataset (id), zone, tag (id), principal ("kind:id" | everyone), include_revoked. A ledger: newest first.
 */
function find_grants(PDO $pdo, array $filters, int $page): array
{
    $where = ['true'];
    $args = [];
    if (!empty($filters['dataset'])) {
        $where[] = "g.target_kind = 'dataset' AND g.dataset_id = :ds";
        $args['ds'] = (int) $filters['dataset'];
    }
    if (!empty($filters['zone']) && in_array($filters['zone'], GRANT_ZONES, true)) {
        $where[] = "g.target_kind = 'zone' AND g.zone = :zone";
        $args['zone'] = $filters['zone'];
    }
    if (!empty($filters['tag'])) {
        $where[] = "g.target_kind = 'tag' AND g.tag_id = :tag";
        $args['tag'] = (int) $filters['tag'];
    }
    if (!empty($filters['principal']) && ($p = parse_principal((string) $filters['principal'])) !== null) {
        $where[] = 'g.principal_kind = :pk' . ($p[1] === null ? '' : ' AND g.principal_id = :pid');
        $args['pk'] = $p[0];
        if ($p[1] !== null) {
            $args['pid'] = $p[1];
        }
    }
    if (empty($filters['include_revoked'])) {
        $where[] = 'g.revoked_at IS NULL';
    }
    $w = implode(' AND ', $where);
    $page = max(1, $page);
    $st = $pdo->prepare(GRANT_SELECT . " WHERE $w ORDER BY (g.revoked_at IS NOT NULL), g.created_at DESC, g.grant_id DESC LIMIT " . ACCESS_PAGE . ' OFFSET ' . (($page - 1) * ACCESS_PAGE));
    $st->execute($args);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT count(*) FROM mcp_grants g WHERE $w");
    $st->execute($args);
    return ['rows' => $rows, 'total' => (int) $st->fetchColumn(), 'page' => $page];
}

/** One grant I may see, or null. */
function find_grant(PDO $pdo, int $id): ?array
{
    return one_row($pdo, GRANT_SELECT . ' WHERE g.grant_id = :id', ['id' => $id]);
}

/** May I set or revoke grants on this target? A dataset: its owner (level own) or grants.manage; a zone or a tag: grants.manage only (★14). */
function may_grant_on(PDO $pdo, string $targetKind, ?int $datasetId): bool
{
    if (has_right('grants.manage')) {
        return true;
    }
    return $targetKind === 'dataset' && $datasetId !== null && dataset_level($pdo, $datasetId) === 'own';
}

/** A grant's target in words: the dataset's key, "zone curated", "tag finance". */
function grant_target_words(array $g): string
{
    return match ((string) $g['target_kind']) {
        'dataset' => (string) ($g['dataset_key'] ?? ('dataset ' . $g['dataset_id'])),
        'zone' => 'zone ' . $g['zone'],
        default => 'tag ' . ($g['tag_slug'] ?? $g['tag_id']),
    };
}

/**
 * A grant's row filter checked BEFORE it is saved (access-policies divergence 1; ★13): the tokens bound for the granter by the database
 * (dl_bind_row_filter), then the query service's node-by-node check and DuckDB's bind against the dataset's columns. ['ok', 'bound'?, 'error'?]
 * — an engine that cannot answer is "not checked": the grant is not saved (fail closed).
 */
function row_filter_check(int $member, string $datasetKey, string $template): array
{
    $pdo = db();
    $d = dataset_by_ref($pdo, $datasetKey);
    if ($d === null) {
        return ['ok' => false, 'error' => 'No such dataset.'];
    }
    $bound = (string) one_value($pdo, 'SELECT dl_bind_row_filter(:f, :m)', ['f' => $template, 'm' => $member]);
    $cols = array_map('strval', $pdo->query('SELECT name FROM mcp_dataset_columns WHERE dataset_id = ' . (int) $d['id'] . ' AND removed_snapshot_id IS NULL ORDER BY ordinal')->fetchAll(PDO::FETCH_COLUMN));
    $a = lake_check_row_filter((string) $d['key'], $bound, $cols);
    if (($a['status'] ?? '') !== 'ok') {
        return ['ok' => false, 'bound' => $bound, 'error' => 'The lake could not check the filter just now (' . lake_refusal_message($a) . ') — it is not saved unchecked.'];
    }
    if (empty($a['valid'])) {
        return ['ok' => false, 'bound' => $bound, 'error' => 'A row filter uses the dataset\'s columns, literals and comparisons only — ' . (string) ($a['message'] ?? 'it does not.'),
                'checked_against' => $a['checked_against'] ?? null];
    }
    return ['ok' => true, 'bound' => $bound, 'checked_against' => $a['checked_against'] ?? null];
}

/** The live columns of a dataset I may discover (the row filter's helper list). */
function dataset_column_names(PDO $pdo, int $dataset): array
{
    $st = $pdo->prepare('SELECT name, logical_type FROM mcp_dataset_columns WHERE dataset_id = :d AND removed_snapshot_id IS NULL AND NOT is_system ORDER BY ordinal');
    $st->execute(['d' => $dataset]);
    return $st->fetchAll();
}

// ---- the audit's reads (the tool surface: dataset_readers, access_history, grants_expiring, grants_unused) ----------------------------

/** Who read X (dl_dataset_readers — the owner or audit.read; never SQL). */
function dataset_readers(PDO $pdo, int $dataset, int $days = 30, bool $personalOnly = false): array
{
    $st = $pdo->prepare("SELECT * FROM dl_dataset_readers(:d, now() - make_interval(days => :n), now(), :p)");
    $st->execute(['d' => $dataset, 'n' => max(1, min(365, $days)), 'p' => $personalOnly ? 't' : 'f']);
    return $st->fetchAll();
}

/** Who could read X on a day (dl_access_history — grants.manage). */
function access_history(PDO $pdo, int $dataset, string $at): array
{
    $st = $pdo->prepare('SELECT * FROM dl_access_history(:d, CAST(:at AS timestamptz))');
    $st->execute(['d' => $dataset, 'at' => $at]);
    return $st->fetchAll();
}

/** Grants ending within N days (dl_grants_expiring — grants.manage). */
function grants_expiring(PDO $pdo, int $days = 7): array
{
    $st = $pdo->prepare('SELECT * FROM dl_grants_expiring(:n, 100)');
    $st->execute(['n' => max(1, min(365, $days))]);
    return $st->fetchAll();
}

/** Dataset grants to a person or an agent unused for N days (dl_grants_unused — grants.manage). */
function grants_unused(PDO $pdo, int $days = 90): array
{
    $st = $pdo->prepare('SELECT * FROM dl_grants_unused(:n, 100)');
    $st->execute(['n' => max(1, min(3650, $days))]);
    return $st->fetchAll();
}

// ---- pickers ----------------------------------------------------------------------------------------------------------------------

/** The principal picker (params.kind required: member — people; agent; group; department). */
function principals_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $kind = (string) ($params['kind'] ?? '');
    $like = picker_like($q);
    return match ($kind) {
        'member', 'agent' => (static function () use ($pdo, $q, $like, $kind, $page): array {
            $where = "is_agent = :ag AND (:q = '' OR display_name ILIKE :like OR email ILIKE :like OR job_title ILIKE :like)";
            $args = ['ag' => $kind === 'agent' ? 't' : 'f', 'q' => $q, 'like' => $like];
            $r = picker_page($pdo, "SELECT count(*) FROM mcp_members WHERE $where",
                "SELECT member_id, display_name, job_title, is_external FROM mcp_members WHERE $where ORDER BY display_name, member_id", $args, $page);
            $r['rows'] = array_map(static fn (array $m): array => ['id' => (int) $m['member_id'], 'label' => (string) $m['display_name'],
                'detail' => (string) ($m['job_title'] ?? ''), 'badge' => $kind === 'agent' ? 'Agent' : ($m['is_external'] ? 'External' : null),
                'color' => $kind === 'agent' ? 'info' : 'warning'], $r['rows']);
            return $r;
        })(),
        'group' => groups_for_pick($pdo, $q, [], $page),
        'department' => (static function () use ($pdo, $q, $like, $page): array {
            $where = "archived_at IS NULL AND (:q = '' OR name ILIKE :like)";
            $r = picker_page($pdo, "SELECT count(*) FROM mcp_departments WHERE $where",
                "SELECT department_id, name FROM mcp_departments WHERE $where ORDER BY name, department_id", ['q' => $q, 'like' => $like], $page);
            $r['rows'] = array_map(static fn (array $d): array => ['id' => (int) $d['department_id'], 'label' => (string) $d['name'], 'detail' => 'Department'], $r['rows']);
            return $r;
        })(),
        default => ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => PICKER_PAGE, 'pages' => 1],
    };
}

/** A principal's label by kind and id (the picker's label, a grant row's name). */
function principal_label(PDO $pdo, string $kind, int $id): ?string
{
    $v = match ($kind) {
        'member', 'agent' => one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :id AND is_agent = :ag', ['id' => $id, 'ag' => $kind === 'agent' ? 't' : 'f']),
        'group' => one_value($pdo, 'SELECT name FROM mcp_groups WHERE group_id = :id AND archived_at IS NULL', ['id' => $id]),
        'department' => one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', ['id' => $id]),
        default => null,
    };
    return $v === null ? null : (string) $v;
}

/** Every dataset I may name — stubs included (a request names a dataset I may only know exists); for the admin every dataset (they discover all). */
function any_datasets_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "status <> 'retired' AND (:q = '' OR key ILIKE :like OR title ILIKE :like)";
    $args = ['q' => $q, 'like' => picker_like($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_datasets WHERE $where",
        "SELECT dataset_id, key, title, zone, is_stub, classification FROM mcp_datasets WHERE $where ORDER BY title, dataset_id", $args, $page);
    $r['rows'] = array_map(static fn (array $d): array => ['id' => (int) $d['dataset_id'], 'label' => (string) $d['title'], 'detail' => (string) $d['key'],
        'badge' => $d['is_stub'] ? 'Request access' : (string) $d['zone'], 'color' => $d['is_stub'] ? 'warning' : zone_color((string) $d['zone'])], $r['rows']);
    return $r;
}
