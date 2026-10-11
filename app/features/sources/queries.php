<?php
declare(strict_types=1);

/**
 * The sources' reads (slice 6, sources-pulls.md "Query functions"; replicates slice 1's catalog queries): EVERY row a person sees comes through a
 * walled view — mcp_sources (never the credential: only its kind's last four, through source_credentials' own columns once the view has
 * admitted the source), mcp_source_pulls, mcp_loads (read on the dataset), mcp_lineage_edges (discover), mcp_datasets (a stub is never a
 * name here). sources_due() is the WORKER's: it has no member, so it reads the base table (the mcp_* views answer a member only).
 * The same functions answer the screens' JSON and (Phase 4) the records tools find_sources and get_source.
 */

const SOURCE_PAGE = 24;
/** The source page's tabs, by anchor (?tab=): the connector's settings, the last pulls, the datasets it feeds. */
const SOURCE_TABS = ['settings' => 'Settings', 'pulls' => 'Pulls', 'feeds' => 'Feeds'];
/** A pull's status as a badge: [bootstrap colour, words]. */
const PULL_STATUS = ['running' => ['primary', 'Running'], 'ok' => ['success', 'Succeeded'], 'partial' => ['warning', 'Partly'], 'failed' => ['danger', 'Failed'], 'blocked' => ['dark', 'Blocked']];

require_once dirname(__DIR__, 2) . '/lake/fetch/Connector.php';        // the connectors' labels and icons (no pull is made by a screen)
require_once dirname(__DIR__) . '/catalog/queries.php';                // like_pattern

/** The list's filters from the query string — anything unknown dropped: connector, paused (yes|no), q. */
function source_filters_from_request(): array
{
    $f = [];
    $c = request_string('connector');
    if (in_array($c, DL_CONNECTORS_V1, true)) {
        $f['connector'] = $c;
    }
    $p = request_string('paused');
    if ($p === 'yes' || $p === 'no') {
        $f['paused'] = $p;
    }
    $q = mb_substr(trim(request_string('q')), 0, 120);
    if ($q !== '') {
        $f['q'] = $q;
    }
    return $f;
}

/** The columns every source query selects (the view's, the last pull's, the target dataset's — a stub is no target here). */
const SOURCE_SELECT = "s.source_id, s.name, s.connector, s.role, s.base_url, s.settings, s.has_credential, s.credential_last4, s.schedule_minutes, s.last_pull_id, s.last_ok_at,
       s.consecutive_failures, s.backoff_until, s.paused_at, s.paused_reason, s.active, s.created_by, s.created_at, s.updated_at,
       p.status AS last_status, p.started_at AS last_started_at, p.finished_at AS last_finished_at, p.items_seen AS last_items_seen, p.items_landed AS last_items_landed,
       p.error AS last_error, d.dataset_id AS target_id, d.key AS target_key, d.title AS target_title";
const SOURCE_FROM = "mcp_sources s LEFT JOIN mcp_source_pulls p ON p.pull_id = s.last_pull_id
       LEFT JOIN mcp_datasets d ON d.dataset_id = NULLIF(s.settings ->> 'target_dataset_id', '')::bigint AND NOT d.is_stub";

/**
 * Which sources — one page (SOURCE_PAGE) of the cards: a paused one last, then by name. Filters: connector, paused (yes|no), q (name).
 * $filters['limit'] (≤ 100) for the records tool. Returns ['rows', 'total'].
 */
function find_sources(PDO $pdo, array $f, int $page): array
{
    $w = ['true'];
    $a = [];
    if (($f['connector'] ?? null) !== null) {
        $w[] = 's.connector = :connector';
        $a['connector'] = (string) $f['connector'];
    }
    if (($f['paused'] ?? null) === 'yes') {
        $w[] = 's.paused_at IS NOT NULL';
    } elseif (($f['paused'] ?? null) === 'no') {
        $w[] = 's.paused_at IS NULL';
    }
    if (($f['q'] ?? '') !== '') {
        $w[] = 's.name ILIKE :q';
        $a['q'] = like_pattern((string) $f['q']);
    }
    $where = implode(' AND ', $w);
    $limit = max(1, min(100, (int) ($f['limit'] ?? SOURCE_PAGE)));
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_sources s WHERE $where", $a);
    $st = $pdo->prepare('SELECT ' . SOURCE_SELECT . ' FROM ' . SOURCE_FROM . " WHERE $where ORDER BY (s.paused_at IS NOT NULL), s.name, s.source_id LIMIT $limit OFFSET " . (max(0, $page - 1) * $limit));
    $st->execute($a);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['settings'] = json_decode((string) $r['settings'], true) ?: [];
    }
    unset($r);
    return ['rows' => $rows, 'total' => $total];
}

/** One source (null when the view does not show it to this person): the row of find_sources + who made it and the credential's kind, label and last four. */
function find_source(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT ' . SOURCE_SELECT . ', m.display_name AS created_by_name FROM ' . SOURCE_FROM . ' LEFT JOIN members m ON m.id = s.created_by WHERE s.source_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['settings'] = json_decode((string) $r['settings'], true) ?: [];
    // the view admitted the source; the two columns it leaves out (not secret: how often we may ask, and who we say we are)
    $r += one_row($pdo, 'SELECT rate_per_second, user_agent FROM sources WHERE id = :id', ['id' => $id]) ?? ['rate_per_second' => 1, 'user_agent' => null];
    // the credential's own columns (never the ciphertext) say what is sealed
    $r['credential'] = $r['has_credential'] ? one_row($pdo, 'SELECT id AS credential_id, kind, label, last4, rotated_at, created_at FROM source_credentials WHERE source_id = :s ORDER BY id DESC LIMIT 1', ['s' => $id]) : null;
    return $r;
}

/** A source's last pulls (mcp_source_pulls), newest first, with the loads each made (the first six ids and the count — at the dataset's read level). */
function source_pulls(PDO $pdo, int $id, int $limit): array
{
    $st = $pdo->prepare('SELECT p.pull_id, p.kind, p.started_at, p.finished_at, p.status, p.items_seen, p.items_landed, p.http_requests, p.bytes, p.error,
                                (SELECT count(*) FROM mcp_loads l WHERE l.pull_id = p.pull_id) AS load_count,
                                (SELECT array_to_string(array_agg(x.load_id ORDER BY x.load_id), \',\') FROM (SELECT l2.load_id FROM mcp_loads l2 WHERE l2.pull_id = p.pull_id ORDER BY l2.load_id LIMIT 6) x) AS load_ids
                           FROM mcp_source_pulls p WHERE p.source_id = :s ORDER BY p.pull_id DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute(['s' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['load_ids'] = $r['load_ids'] === null || $r['load_ids'] === '' ? [] : array_map('intval', explode(',', (string) $r['load_ids']));
    }
    unset($r);
    return $rows;
}

/** The datasets a source feeds: the lineage edges from it (mcp_lineage_edges), then the target it declares if no pull has made its edge yet. */
function source_feeds(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT d.dataset_id, d.key, d.title, d.zone, d.row_count, d.data_updated_at, e.first_seen, e.last_seen, true AS has_edge
                           FROM mcp_lineage_edges e JOIN mcp_datasets d ON d.dataset_id = e.to_dataset_id AND NOT d.is_stub
                          WHERE e.from_source_id = :s ORDER BY d.key");
    $st->execute(['s' => $id]);
    $rows = $st->fetchAll();
    $target = one_value($pdo, "SELECT NULLIF(settings ->> 'target_dataset_id', '')::bigint FROM mcp_sources WHERE source_id = :s", ['s' => $id]);
    if ($target !== null && !in_array((int) $target, array_map(static fn (array $r): int => (int) $r['dataset_id'], $rows), true)) {
        $d = one_row($pdo, 'SELECT dataset_id, key, title, zone, row_count, data_updated_at, NULL::timestamptz AS first_seen, NULL::timestamptz AS last_seen, false AS has_edge FROM mcp_datasets WHERE dataset_id = :d AND NOT is_stub', ['d' => (int) $target]);
        if ($d !== null) {
            $rows[] = $d;
        }
    }
    return $rows;
}

/**
 * The sources the worker pulls now: active, not paused, past its back-off, with a schedule, no pull running, and `last_ok_at` older than the schedule
 * (or never) — oldest first. The WORKER's: base tables, no member. Rows: id, name, connector, schedule_minutes, last_ok_at.
 */
function sources_due(PDO $pdo, DateTimeImmutable $now): array
{
    $st = $pdo->prepare("SELECT s.id, s.name, s.connector, s.schedule_minutes, s.last_ok_at FROM sources s
                          WHERE s.active AND s.paused_at IS NULL AND s.connector <> 'upload' AND s.schedule_minutes > 0
                            AND (s.backoff_until IS NULL OR s.backoff_until <= CAST(:now AS timestamptz))
                            AND (s.last_ok_at IS NULL OR s.last_ok_at <= CAST(:now2 AS timestamptz) - make_interval(mins => s.schedule_minutes))
                            AND NOT EXISTS (SELECT 1 FROM source_pulls p WHERE p.source_id = s.id AND p.status = 'running' AND p.started_at > CAST(:now3 AS timestamptz) - interval '6 hours')
                          ORDER BY s.last_ok_at NULLS FIRST, s.id");
    $iso = $now->format(DATE_ATOM);
    $st->execute(['now' => $iso, 'now2' => $iso, 'now3' => $iso]);
    return $st->fetchAll();
}

/** The `source` picker: a source the person may see (mcp_sources' wall), by name. Rows: id, label, detail (the connector), badge (paused). */
function sources_for_pick(PDO $pdo, string $q, array $params, int $page = 1): array
{
    $where = $q === '' ? 'true' : 's.name ILIKE :q';
    $args = $q === '' ? [] : ['q' => picker_like($q)];
    return picker_page($pdo, "SELECT count(*) FROM mcp_sources s WHERE $where",
        "SELECT s.source_id AS id, s.name AS label, s.connector AS detail, CASE WHEN s.paused_at IS NOT NULL THEN 'Paused' END AS badge, CASE WHEN s.paused_at IS NOT NULL THEN 'warning' END AS color
           FROM mcp_sources s WHERE $where ORDER BY s.name, s.source_id", $args, $page);
}
