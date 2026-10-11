<?php
declare(strict_types=1);

/**
 * Exports' writes (slice 3): make (export.own + read on every dataset the SQL reads — the dry run and the frozen policy say so), delete (my own:
 * the row goes now, so the door answers 404 at once; the writer's sweep unlinks the file — PHP never deletes under exports/).
 *   export_make($pdo, $member, $format, $what, $asOf) → [export_id, job_id]   $what: ['sql' => …] | ['saved_query' => row, 'params' => …] | ['dataset' => row]
 *   export_delete($pdo, $id)
 *   export_sweep_queue($pdo)                                                 the writer's sweep (one queued at a time)
 */
require_once dirname(__DIR__) . '/editor/write.php';     // dry_run_for_job, frozen_policy, FieldRefused

function export_make(PDO $pdo, int $member, string $format, array $what, int|string|null $asOf): array
{
    if (!isset(EXPORT_FORMATS[$format])) {
        throw new FieldRefused(['format' => 'The format is csv, xlsx or parquet.']);
    }
    $params = [];
    $saved = null;
    $datasetId = null;
    if (isset($what['dataset'])) {
        $sql = 'SELECT * FROM ' . $what['dataset']['key'];
        $kind = 'dataset';
        $datasetId = (int) $what['dataset']['id'];
    } elseif (isset($what['saved_query'])) {
        $saved = $what['saved_query'];
        $sql = (string) $saved['sql'];
        $params = $what['params'] ?? [];
        $kind = 'result';
    } else {
        $sql = (string) ($what['sql'] ?? '');
        $kind = 'result';
    }
    $keys = dry_run_for_job($pdo, $member, $sql, $asOf, $params, $saved);
    $policy = frozen_policy($pdo, $member, $keys, 'export');
    foreach ($keys as $k) {
        if (!isset($policy['datasets'][$k])) {
            throw new FieldRefused(['sql' => ucfirst((string) ($policy['refused'][$k]['message'] ?? 'you may not read ' . $k))]);
        }
    }
    $datasetId ??= (int) one_value($pdo, 'SELECT id FROM datasets WHERE key = :k', ['k' => $keys[0]]);
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO exports (kind, format, status, created_by, dataset_id, sql_sha256, on_behalf_of, params)
                         VALUES (:k, :f, 'queued', :m, :d, :sha, :obo, CAST(:p AS jsonb)) RETURNING id");
    $st->execute(['k' => $kind, 'f' => $format, 'm' => $member, 'd' => $datasetId, 'sha' => hash('sha256', $sql), 'obo' => lake_on_behalf_of(),
                  'p' => json_encode(['keys' => $keys, 'saved_query_id' => $saved === null ? null : (int) $saved['saved_query_id'], 'as_of' => $asOf])]);
    $id = (int) $st->fetchColumn();
    $job = lake_enqueue($pdo, 'export', null, $id, ['op' => 'make', 'sql' => $sql, 'params' => (object) $params, 'as_of' => $asOf, 'keys' => $keys,
        'policy' => $policy, 'format' => $format, 'saved_query_id' => $saved === null ? null : (int) $saved['saved_query_id'], 'agent_run_id' => current_agent_run_id()]);
    log_activity($pdo, 'export.make', 'export', $id, ['dataset_id' => $datasetId,
        'after' => ['format' => $format, 'kind' => $kind, 'datasets' => count($keys), 'job_id' => $job, 'saved_query_id' => $saved === null ? null : (int) $saved['saved_query_id']]]);
    $pdo->commit();
    return ['export_id' => $id, 'job_id' => $job];
}

function export_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM exports WHERE id = :id')->execute(['id' => $id]);
    export_sweep_queue($pdo);
}

function export_sweep_queue(PDO $pdo): int
{
    return lake_enqueue($pdo, 'export', null, null, ['op' => 'sweep'], 7);
}
