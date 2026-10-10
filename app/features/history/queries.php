<?php
declare(strict_types=1);

/**
 * Who did what (slice 1; the activity tools record_history, recent_activity, activity_search and actor_timeline, and a dataset's History
 * tab): every row through mcp_activity_log — the wall per row (a row about a dataset only when the caller may discover it; a query run's
 * row only the caller's own or anyone's for audit.read). A dataset's History merges its activity with its schema versions
 * (mcp_schema_versions), newest first. Screen views and searches are not history: they are excluded from a record's trail.
 */

require_once dirname(__DIR__) . '/activity/queries.php';

const HISTORY_PAGE = 50;
/** The record kinds record_history answers (the tool surface): the entity_type each is logged under. */
const HISTORY_KINDS = ['dataset' => 'dataset', 'load' => 'load', 'check' => 'check', 'incident' => 'incident', 'grant' => 'grant', 'source' => 'source',
    'transform' => 'transform', 'saved_query' => 'saved_query', 'metric' => 'metric', 'erasure' => 'erasure', 'term' => 'glossary_term', 'tag' => 'tag'];
/** The actions a record's trail leaves out (opening a screen, searching). */
const HISTORY_NOISE = ['screen.view', 'search.catalog'];

/** The SELECT list every history row shares — the actor named from the mirror as My activity does, so a person who has left still has a name (record_history's row: {log_id, occurred_at, actor_member_id, actor_name, source, action, after}). */
function history_select(): string
{
    return "SELECT l.log_id, l.occurred_at, l.actor_member_id, m.display_name AS actor_name, (m.member_kind = 'agent') AS actor_is_agent, l.source, l.action,
                   l.screen, l.entity_type, l.entity_id, l.after, l.dataset_id, l.query_run_id, l.agent_run_id
              FROM mcp_activity_log l LEFT JOIN members m ON m.id = l.actor_member_id";
}

/** One row of a trail with its sentence (the words of app/features/activity's activity_sentence()). */
function history_row(array $r): array
{
    $r['activity_id'] = $r['log_id'];
    $r['sentence'] = activity_sentence($r);
    return $r;
}

/**
 * A dataset's History tab: its activity rows (by the audit key dataset_id) and its schema versions, merged newest first, one page of 50.
 * Rows: {kind: activity|schema, at, …} — an activity row's sentence and changed field names; a schema version's number, changes and decision.
 */
function dataset_history(PDO $pdo, int $datasetId, int $page): array
{
    $page = max(1, $page);
    $noise = text_array_literal(HISTORY_NOISE);
    $sql = "WITH h AS (
                SELECT 'activity' AS kind, l.log_id AS id, l.occurred_at AS at FROM mcp_activity_log l
                 WHERE l.dataset_id = :d AND NOT (l.action = ANY (CAST(:noise AS text[])))
                UNION ALL
                SELECT 'schema', v.schema_version_id, v.created_at FROM mcp_schema_versions v WHERE v.dataset_id = :d)
            SELECT kind, id, at, count(*) OVER () AS total FROM h ORDER BY at DESC, id DESC LIMIT " . HISTORY_PAGE . ' OFFSET ' . (($page - 1) * HISTORY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute(['d' => $datasetId, 'noise' => $noise]);
    $index = $st->fetchAll();
    $total = $index === [] ? 0 : (int) $index[0]['total'];
    $logIds = array_map(static fn (array $r): int => (int) $r['id'], array_filter($index, static fn (array $r): bool => $r['kind'] === 'activity'));
    $verIds = array_map(static fn (array $r): int => (int) $r['id'], array_filter($index, static fn (array $r): bool => $r['kind'] === 'schema'));
    $logs = [];
    if ($logIds !== []) {
        $st = $pdo->prepare(history_select() . ' WHERE l.log_id = ANY (CAST(:ids AS bigint[]))');
        $st->execute(['ids' => '{' . implode(',', $logIds) . '}']);
        foreach ($st->fetchAll() as $r) {
            $logs[(int) $r['log_id']] = history_row($r);
        }
    }
    $vers = [];
    if ($verIds !== []) {
        $st = $pdo->prepare('SELECT v.schema_version_id, v.version, v.snapshot_id, v.changes, v.breaking, v.detected_by, v.decision, v.decided_at, v.created_at,
                                    m.display_name AS decided_by_name
                               FROM mcp_schema_versions v LEFT JOIN mcp_members m ON m.member_id = v.decided_by WHERE v.schema_version_id = ANY (CAST(:ids AS bigint[]))');
        $st->execute(['ids' => '{' . implode(',', $verIds) . '}']);
        foreach ($st->fetchAll() as $r) {
            $r['changes'] = json_decode((string) $r['changes'], true) ?: [];
            $vers[(int) $r['schema_version_id']] = $r;
        }
    }
    $rows = [];
    foreach ($index as $i) {
        $row = $i['kind'] === 'activity' ? ($logs[(int) $i['id']] ?? null) : ($vers[(int) $i['id']] ?? null);
        if ($row !== null) {
            $rows[] = ['kind' => $i['kind'], 'at' => $i['at']] + $row;
        }
    }
    return ['rows' => $rows, 'total' => $total];
}

/** record_history — who did what to a record, newest first. $kind ∈ HISTORY_KINDS; a dataset by its audit key, anything else by entity. */
function record_history(PDO $pdo, string $kind, int $id, int $limit = 50, int $offset = 0): array
{
    if (!isset(HISTORY_KINDS[$kind])) {
        throw new DomainException('A record is one of: ' . implode(', ', array_keys(HISTORY_KINDS)) . '.');
    }
    $where = $kind === 'dataset' ? 'l.dataset_id = :id' : 'l.entity_type = :et AND l.entity_id = :id';
    $args = ['id' => $id] + ($kind === 'dataset' ? [] : ['et' => HISTORY_KINDS[$kind]]);
    $st = $pdo->prepare(history_select() . " WHERE $where AND NOT (l.action = ANY (CAST(:noise AS text[]))) ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT "
        . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset));
    $st->execute($args + ['noise' => text_array_literal(HISTORY_NOISE)]);
    return array_map('history_row', $st->fetchAll());
}

/** recent_activity — what changed since a time (default a day), in a zone or a department's datasets, optionally some actions only; + dataset_key. */
function recent_activity(PDO $pdo, ?string $since, ?string $zone, ?int $department, array $actions, int $limit = 50): array
{
    $w = ['l.occurred_at > COALESCE(CAST(:since AS timestamptz), now() - interval \'1 day\')', 'NOT (l.action = ANY (CAST(:noise AS text[])))'];
    $a = ['since' => $since, 'noise' => text_array_literal(HISTORY_NOISE)];
    if ($zone !== null) {
        $w[] = 'd.zone = :zone';
        $a['zone'] = $zone;
    }
    if ($department !== null) {
        $w[] = 'd.department_id = :dept';
        $a['dept'] = $department;
    }
    if ($actions !== []) {
        $w[] = 'l.action = ANY (CAST(:actions AS text[]))';
        $a['actions'] = text_array_literal($actions);
    }
    $st = $pdo->prepare(str_replace('FROM mcp_activity_log l', 'FROM mcp_activity_log l LEFT JOIN mcp_datasets d ON d.dataset_id = l.dataset_id AND NOT d.is_stub',
            str_replace('SELECT l.log_id', 'SELECT d.key AS dataset_key, l.log_id', history_select()))
        . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute($a);
    return array_map('history_row', $st->fetchAll());
}

/** activity_search — the activity's one guarded search: the action, the record's kind and the `after` text, ILIKE, ≤ 120 characters. */
function activity_search(PDO $pdo, string $q, ?string $from, ?string $to, int $limit = 50): array
{
    $q = mb_substr(trim($q), 0, 120);
    if (mb_strlen($q) < 2) {
        throw new DomainException('Search for at least two characters.');
    }
    $st = $pdo->prepare(history_select() . " WHERE (l.action ILIKE :q OR l.entity_type ILIKE :q OR CAST(l.after AS text) ILIKE :q)
            AND l.occurred_at >= COALESCE(CAST(:from AS timestamptz), '-infinity') AND l.occurred_at <= COALESCE(CAST(:to AS timestamptz), 'infinity')
          ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute(['q' => like_pattern($q), 'from' => $from, 'to' => $to]);
    return array_map('history_row', $st->fetchAll());
}

/** actor_timeline — what a person, a key or an agent did here: one's own, or anyone's for catalog.edit (the handler or the tool checks). */
function actor_timeline(PDO $pdo, ?int $member, ?int $key, ?string $from, ?string $to, int $limit = 50): array
{
    $w = [];
    $a = ['from' => $from, 'to' => $to];
    if ($member !== null) {
        $w[] = 'l.actor_member_id = :m';
        $a['m'] = $member;
    }
    if ($key !== null) {
        $w[] = 'l.token_id = :k';
        $a['k'] = $key;
    }
    if ($w === []) {
        throw new DomainException('Name a person, an agent or a key.');
    }
    $st = $pdo->prepare(history_select() . ' WHERE ' . implode(' AND ', $w) . " AND l.occurred_at >= COALESCE(CAST(:from AS timestamptz), '-infinity')
            AND l.occurred_at <= COALESCE(CAST(:to AS timestamptz), 'infinity') ORDER BY l.occurred_at DESC, l.log_id DESC LIMIT " . max(1, min(200, $limit)));
    $st->execute($a);
    return array_map('history_row', $st->fetchAll());
}
