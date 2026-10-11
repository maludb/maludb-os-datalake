<?php
declare(strict_types=1);

/**
 * Incidents — the reads (contracts-quality.md; the records tools `open_incidents` (the incident resolver), `get_incident`). An incident is the
 * database's: dl_check_result_record() opened it from a failing result and resolves it on a pass (db/010); people acknowledge, assign, note,
 * resolve or dismiss it. Every read through mcp_incidents / mcp_incident_notes (what the caller may discover) and mcp_check_results (failing
 * keys only at `read`).
 */
require_once dirname(__DIR__) . '/checks/queries.php';

const INCIDENT_OPEN = ['new', 'acknowledged', 'investigating'];
const INCIDENT_STATUSES = ['new', 'acknowledged', 'investigating', 'resolved', 'dismissed'];
const INCIDENT_PAGE = 50;

/** Incidents by filters — status (open | one of INCIDENT_STATUSES), severity, assignee (a member id or 'me'), dataset (id) — 50 a page. */
function find_incidents(PDO $pdo, array $f, int $page): array
{
    $where = ['NOT d.is_stub'];
    $args = [];
    $status = (string) ($f['status'] ?? '');
    if ($status === 'open') {
        $where[] = "i.status IN ('new', 'acknowledged', 'investigating')";
    } elseif (in_array($status, INCIDENT_STATUSES, true)) {
        $where[] = 'i.status = :st';
        $args['st'] = $status;
    }
    if (in_array($f['severity'] ?? null, ['warn', 'error'], true)) {
        $where[] = 'i.severity = :sev';
        $args['sev'] = $f['severity'];
    }
    if (($f['assignee'] ?? null) !== null && $f['assignee'] !== '') {
        $where[] = 'i.assignee_member_id = :as';
        $args['as'] = $f['assignee'] === 'me' ? (int) current_member_id() : (int) $f['assignee'];
    }
    if (!empty($f['dataset'])) {
        $where[] = 'i.dataset_id = :ds';
        $args['ds'] = (int) $f['dataset'];
    }
    if (!empty($f['department'])) {
        $where[] = 'd.department_id = :dep';
        $args['dep'] = (int) $f['department'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_incidents i JOIN mcp_datasets d ON d.dataset_id = i.dataset_id WHERE $w", $args);
    $page = max(1, $page);
    $st = $pdo->prepare("SELECT i.*, d.key, d.title AS dataset_title, (SELECT display_name FROM mcp_members m WHERE m.member_id = i.assignee_member_id) AS assignee_name,
                                jsonb_array_length(i.downstream) AS downstream_count
                           FROM mcp_incidents i JOIN mcp_datasets d ON d.dataset_id = i.dataset_id WHERE $w
                          ORDER BY (i.status IN ('new', 'acknowledged', 'investigating')) DESC, (i.severity = 'error') DESC, i.opened_at DESC, i.incident_id DESC
                          LIMIT " . INCIDENT_PAGE . ' OFFSET ' . (($page - 1) * INCIDENT_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page];
}

/**
 * One incident the caller may discover: the row, its dataset (key, title, owner, steward), its check, the result that opened it (and resolved
 * it), the notes in order with the Steward's triage first, the downstream keys the caller may know (the rest counted).
 */
function find_incident(PDO $pdo, int $id): ?array
{
    $i = one_row($pdo, 'SELECT i.*, d.key, d.title AS dataset_title, d.owner_member_id, d.steward_member_id, d.zone,
                               (SELECT display_name FROM mcp_members m WHERE m.member_id = i.assignee_member_id) AS assignee_name
                          FROM mcp_incidents i JOIN mcp_datasets d ON d.dataset_id = i.dataset_id AND NOT d.is_stub WHERE i.incident_id = :id', ['id' => $id]);
    if ($i === null) {
        return null;
    }
    $i['check'] = $i['check_id'] === null ? null : find_check($pdo, (int) $i['check_id']);
    $result = static fn (?int $rid): ?array => $rid === null ? null
        : one_row($pdo, 'SELECT result_id, value, threshold, passed, rows_failed, failing_keys, error, ran_at, load_id FROM mcp_check_results WHERE result_id = :r', ['r' => $rid]);
    $i['opened_by'] = $result($i['opened_by_result_id'] === null ? null : (int) $i['opened_by_result_id']);
    $i['resolved_by'] = $result($i['resolved_by_result_id'] === null ? null : (int) $i['resolved_by_result_id']);
    $st = $pdo->prepare("SELECT n.note_id, n.member_id, n.body, n.kind, n.created_at, (SELECT display_name FROM mcp_members m WHERE m.member_id = n.member_id) AS author,
                                (SELECT is_agent FROM mcp_members m WHERE m.member_id = n.member_id) AS by_agent
                           FROM mcp_incident_notes n WHERE n.incident_id = :id ORDER BY (n.kind = 'triage') DESC, n.created_at, n.note_id");
    $st->execute(['id' => $id]);
    $i['notes'] = $st->fetchAll();
    $down = json_decode((string) $i['downstream'], true) ?: [];
    $known = [];
    foreach ($down as $k) {
        if (db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_datasets WHERE key = :k AND NOT is_stub)', ['k' => (string) $k])) {
            $known[] = (string) $k;
        }
    }
    $i['downstream_known'] = $known;
    $i['downstream_hidden'] = count($down) - count($known);
    return $i;
}

/** Open incidents for the picker (the incident resolver). */
function incidents_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "NOT d.is_stub AND i.status IN ('new', 'acknowledged', 'investigating') AND (:q = '' OR i.title ILIKE :like OR d.key ILIKE :like)";
    $args = ['q' => $q, 'like' => picker_like($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_incidents i JOIN mcp_datasets d ON d.dataset_id = i.dataset_id WHERE $where",
        "SELECT i.incident_id, i.title, i.severity, i.status, d.key FROM mcp_incidents i JOIN mcp_datasets d ON d.dataset_id = i.dataset_id WHERE $where ORDER BY i.opened_at DESC", $args, $page);
    $r['rows'] = array_map(static fn (array $i): array => ['id' => (int) $i['incident_id'], 'label' => (string) $i['title'], 'detail' => $i['key'] . ' · ' . $i['status'],
        'badge' => $i['severity'] === 'error' ? 'Error' : 'Warn', 'color' => $i['severity'] === 'error' ? 'danger' : 'warning'], $r['rows']);
    return $r;
}

/** Who may do what to an incident (contracts-quality.md "Who may do what"): the dataset's owner and steward, incidents.manage. */
function incident_may(array $i): array
{
    $me = (int) current_member_id();
    $owner = (int) ($i['owner_member_id'] ?? 0) === $me;
    $steward = (int) ($i['steward_member_id'] ?? 0) === $me;
    $manage = has_right('incidents.manage');
    $open = in_array($i['status'], INCIDENT_OPEN, true);
    $agent = is_action_authed();
    return ['ack' => $open && $i['status'] === 'new' && ($manage || $owner || $steward || $agent), 'assign' => $open && ($manage || $owner),
            'note' => true, 'resolve' => $open && ($manage || $owner) && !$agent, 'dismiss' => $open && $manage && !$agent];
}
