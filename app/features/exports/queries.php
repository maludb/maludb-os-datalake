<?php
declare(strict_types=1);

/**
 * Exports (slice 3 — query-service-editor.md; query-service §7 `export`): a result as a CSV, XLSX or Parquet file within the download limit
 * (query_rows_download; XLSX also within Excel's sheet), made by the WRITER through the person's views with the policy frozen at the request
 * (★6), kept 7 days, streamed to its maker alone by /exports/{id}/download — 404 once expired or deleted, the file unlinked by the writer.
 *   my_exports($pdo, $member, $limit)      my exports, newest first (mcp_exports — my own)
 *   export_file($pdo, $member, $id)        [path, filename, mime] of my ready, unexpired export — or null (the door answers 404)
 */
const EXPORT_FORMATS = ['csv' => ['text/csv; charset=utf-8', 'CSV'], 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Excel'],
                        'parquet' => ['application/vnd.apache.parquet', 'Parquet']];

function my_exports(PDO $pdo, int $member, int $limit): array
{
    $st = $pdo->prepare("SELECT x.*, d.key AS dataset_key, x.expires_at < now() AS expired FROM mcp_exports x LEFT JOIN mcp_datasets d ON d.dataset_id = x.dataset_id
                          WHERE x.created_by = :m AND x.kind IN ('result', 'dataset') ORDER BY x.export_id DESC LIMIT :n");
    $st->bindValue('m', $member, PDO::PARAM_INT);
    $st->bindValue('n', max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function export_file(PDO $pdo, int $member, int $id): ?array
{
    $x = one_row($pdo, "SELECT id, format, storage_path, dataset_id FROM exports
                         WHERE id = :id AND created_by = :m AND status = 'done' AND storage_path IS NOT NULL AND expires_at > now()", ['id' => $id, 'm' => $member]);
    if ($x === null || !isset(EXPORT_FORMATS[$x['format']]) || !preg_match('#^exports/\d+\.(csv|xlsx|parquet)$#', (string) $x['storage_path'])) {
        return null;
    }
    $path = rtrim((string) env('DL_STORAGE_DIR', ''), '/') . '/' . $x['storage_path'];
    if (!is_file($path)) {
        return null;
    }
    $key = $x['dataset_id'] === null ? null : one_value($pdo, 'SELECT key FROM datasets WHERE id = :id', ['id' => (int) $x['dataset_id']]);
    $stem = $key === null ? 'result' : str_replace('.', '_', (string) $key);
    return ['path' => $path, 'filename' => $stem . '-export-' . (int) $x['id'] . '.' . $x['format'], 'mime' => EXPORT_FORMATS[$x['format']][0],
            'dataset_id' => $x['dataset_id'] === null ? null : (int) $x['dataset_id'], 'format' => $x['format']];
}

/** An export's badge: [class, words]. */
function export_badge(array $x): array
{
    if (!empty($x['expired'])) {
        return ['bg-dark text-white', 'Expired'];
    }
    return match ((string) $x['status']) {
        'queued', 'running' => ['bg-soft-info text-info', $x['status'] === 'queued' ? 'Queued' : 'Making'],
        'done' => ['bg-soft-success text-success', 'Ready'],
        default => ['bg-soft-danger text-danger', 'Failed'],
    };
}
