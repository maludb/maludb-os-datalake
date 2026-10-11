<?php
declare(strict_types=1);

/**
 * Reads of loads (uploads-loads.md; the tools find_loads, get_load, load_rejects, schema_history, profile_history). Walled views only:
 * mcp_loads (read on the dataset), mcp_load_rejects (the raw line at write), mcp_schema_versions and mcp_column_profiles (discover).
 */

require_once dirname(__DIR__) . '/catalog/queries.php';     // like_pattern, text_array_literal

const LOAD_PAGE = 50;
const LOAD_STATUS = ['queued' => ['secondary', 'Queued'], 'running' => ['info', 'Running'], 'loaded' => ['success', 'Loaded'],
    'no_op' => ['light', 'Already loaded'], 'failed' => ['danger', 'Failed'], 'rolled_back' => ['dark', 'Rolled back']];
const CHECKS_OUTCOME = ['none' => null, 'passed' => ['success', 'Checks passed'], 'warned' => ['warning', 'Checks warned'], 'failed' => ['danger', 'Checks failed']];

/** Loads by dataset, source, status and since (the load-list screen, the find_loads tool): [rows, total]. */
function find_loads(PDO $pdo, array $f, int $page): array
{
    $where = ['true'];
    $args = [];
    if (($f['dataset'] ?? null) !== null) {
        $where[] = 'l.dataset_id = :d';
        $args['d'] = (int) $f['dataset'];
    }
    if (($f['source'] ?? null) !== null) {
        if ($f['source'] === 'upload') {
            $where[] = 'l.source_id IS NULL';
        } else {
            $where[] = 'l.source_id = :s';
            $args['s'] = (int) $f['source'];
        }
    }
    if (($f['status'] ?? []) !== []) {
        $where[] = 'l.status = ANY (CAST(:st AS text[]))';
        $args['st'] = text_array_literal((array) $f['status']);
    }
    if (($f['since'] ?? null) !== null) {
        $where[] = 'l.queued_at >= CAST(:since AS date)';
        $args['since'] = (string) $f['since'];
    }
    $w = implode(' AND ', $where);
    $total = (int) one_value($pdo, "SELECT count(*) FROM mcp_loads l WHERE $w", $args);
    $st = $pdo->prepare("SELECT l.load_id, l.dataset_id, d.key AS dataset_key, l.source_id, l.status, l.rows_read, l.rows_loaded, l.rows_rejected,
                                l.rows_rescued, l.cast_rejects, l.checks_outcome, l.queued_at, l.finished_at, l.requested_by, m.display_name AS requested_by_name,
                                l.mode, l.snapshot_id
                           FROM mcp_loads l LEFT JOIN datasets d ON d.id = l.dataset_id LEFT JOIN members m ON m.id = l.requested_by
                          WHERE $w ORDER BY l.load_id DESC LIMIT " . LOAD_PAGE . ' OFFSET ' . (max(0, $page - 1) * LOAD_PAGE));
    $st->execute($args);
    return ['rows' => $st->fetchAll(), 'total' => $total];
}

/** One load as its dataset's reader may see it (mcp_loads), with the dataset's key and zone and who asked for it; null when not visible. */
function find_load(PDO $pdo, int $id): ?array
{
    $r = one_row($pdo, "SELECT l.*, d.key AS dataset_key, d.zone AS dataset_zone, d.title AS dataset_title, m.display_name AS requested_by_name,
                               rb.display_name AS rolled_back_by_name, t.name AS template_name
                          FROM mcp_loads l LEFT JOIN datasets d ON d.id = l.dataset_id LEFT JOIN members m ON m.id = l.requested_by
                          LEFT JOIN members rb ON rb.id = l.rolled_back_by LEFT JOIN import_templates t ON t.id = l.template_id
                         WHERE l.load_id = :id", ['id' => $id]);
    if ($r === null) {
        return null;
    }
    $r['schema_seen'] = $r['schema_seen'] === null ? null : json_decode((string) $r['schema_seen'], true);
    $r['job'] = one_row($pdo, "SELECT job_id, kind, status, attempts, error, started_at, finished_at FROM mcp_lake_jobs WHERE kind IN ('load', 'rollback') AND ref_id = :id
                               ORDER BY job_id DESC LIMIT 1", ['id' => $id]);
    return $r;
}

/** A load's rejected lines (line, column, error, message; the raw text only when $raw — the dataset's write level). */
function load_rejects(PDO $pdo, int $load, bool $raw, int $page): array
{
    $st = $pdo->prepare('SELECT reject_id, line, column_name, error_type, message' . ($raw ? ', raw_line' : '') . ' FROM mcp_load_rejects
                          WHERE load_id = :l ORDER BY line NULLS LAST, reject_id LIMIT ' . LOAD_PAGE . ' OFFSET ' . (max(0, $page - 1) * LOAD_PAGE));
    $st->execute(['l' => $load]);
    return $st->fetchAll();
}

function load_rejects_count(PDO $pdo, int $load): int
{
    return (int) one_value($pdo, 'SELECT count(*) FROM mcp_load_rejects WHERE load_id = :l', ['l' => $load]);
}

/** The status partial's state: [status, error, counts, finished] — polled every 2 s until final. */
function load_state(PDO $pdo, int $id): ?array
{
    return one_row($pdo, 'SELECT load_id, dataset_id, status, rows_read, rows_loaded, rows_rejected, rows_rescued, cast_rejects, error, finished_at, snapshot_id FROM mcp_loads WHERE load_id = :id', ['id' => $id]);
}

function load_is_final(string $status): bool
{
    return in_array($status, ['loaded', 'no_op', 'failed', 'rolled_back'], true);
}

/** The schema's history of a dataset (schema_history): versions, newest first. */
function schema_history(PDO $pdo, int $datasetId, bool $pendingOnly = false, int $limit = 20): array
{
    $st = $pdo->prepare('SELECT schema_version_id, version, snapshot_id, columns, changes, breaking, detected_by, decision, decided_at, created_at FROM mcp_schema_versions
                          WHERE dataset_id = :d' . ($pendingOnly ? " AND decision = 'pending'" : '') . ' ORDER BY version DESC LIMIT ' . max(1, min(200, $limit)));
    $st->execute(['d' => $datasetId]);
    return array_map(static function (array $r): array {
        $r['columns'] = json_decode((string) $r['columns'], true) ?? [];
        $r['changes'] = json_decode((string) $r['changes'], true) ?? [];
        return $r;
    }, $st->fetchAll());
}

/** A column's profile over time (profile_history), newest first — made safe by present_profile_row() before it is shown. */
function profile_history(PDO $pdo, int $datasetId, string $column, int $limit = 50): array
{
    $st = $pdo->prepare('SELECT snapshot_id, load_id, profile, profiled_at FROM mcp_column_profiles WHERE dataset_id = :d AND column_name = :c
                          ORDER BY profiled_at DESC LIMIT ' . max(1, min(400, $limit)));
    $st->execute(['d' => $datasetId, 'c' => $column]);
    return $st->fetchAll();
}

/** The last snapshots of a dataset a load made or rolled back (the Preview tab's as-of picker, for loads.manage): [{snapshot_id, at, what}]. */
function dataset_snapshots(PDO $pdo, int $datasetId, int $limit = 10): array
{
    $st = $pdo->prepare("SELECT s.snapshot_id, s.at, s.what FROM (
                             SELECT snapshot_id, finished_at AS at, 'load ' || load_id AS what FROM mcp_loads WHERE dataset_id = :d AND snapshot_id IS NOT NULL AND status IN ('loaded', 'rolled_back')
                             UNION ALL
                             SELECT rollback_snapshot_id, rolled_back_at, 'rollback of load ' || load_id FROM mcp_loads WHERE dataset_id = :d AND rollback_snapshot_id IS NOT NULL) s
                          ORDER BY s.snapshot_id DESC LIMIT " . max(1, min(50, $limit)));
    $st->execute(['d' => $datasetId]);
    return $st->fetchAll();
}

/** The `load` picker / resolver: loads I may read, newest first. */
function loads_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "(:q = '' OR d.key ILIKE :like OR CAST(l.load_id AS text) = :q)";
    $args = ['q' => $q, 'like' => like_pattern($q)];
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_loads l JOIN datasets d ON d.id = l.dataset_id WHERE $where",
        "SELECT l.load_id, d.key, l.status, l.queued_at FROM mcp_loads l JOIN datasets d ON d.id = l.dataset_id WHERE $where ORDER BY l.load_id DESC", $args, $page);
    $r['rows'] = array_map(static fn (array $l): array => ['id' => (int) $l['load_id'], 'label' => 'Load ' . (int) $l['load_id'] . ' — ' . $l['key'],
        'detail' => (string) $l['queued_at'], 'badge' => LOAD_STATUS[$l['status']][1] ?? $l['status'], 'color' => LOAD_STATUS[$l['status']][0] ?? 'secondary'], $r['rows']);
    return $r;
}
