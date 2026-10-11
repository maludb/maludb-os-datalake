<?php
declare(strict_types=1);

/**
 * The Preview tab (uploads-loads.md; query-service §7 `preview`; the dataset_preview tool): ONE lake_query() of kind preview — the query
 * service compiles the caller's policy, masks and filters the rows, runs the read and audits it (query_runs, source preview). PHP never opens
 * DuckDB. Logged `query.run` with the run's id, the dataset, the rows and the masked columns' count — never the SQL, never a value.
 *
 *   preview($pdo, $member, $datasetId, $asOf) → [status, columns, rows, masked_columns, as_of, query_run_id, message, refusal_rule, truncated, ms]
 */
function preview(PDO $pdo, int $member, int $datasetId, int|string|null $asOf): array
{
    $key = (string) one_value($pdo, 'SELECT key FROM datasets WHERE id = :id', ['id' => $datasetId]);
    $a = lake_preview($key, $asOf);
    $masked = array_values(array_map(static fn (string $m): string => substr($m, strlen($key) + 1),
        array_filter((array) ($a['masked_columns'] ?? []), static fn ($m): bool => is_string($m) && str_starts_with($m, $key . '.'))));
    $out = ['status' => (string) ($a['status'] ?? 'error'), 'refusal_rule' => $a['refusal_rule'] ?? null,
            'message' => ($a['status'] ?? '') === 'ok' ? ($a['message'] ?? null) : lake_refusal_message($a),
            'columns' => (array) ($a['columns'] ?? []), 'rows' => (array) ($a['rows'] ?? []), 'masked_columns' => $masked,
            'as_of' => isset($a['as_of_snapshot']) ? (int) $a['as_of_snapshot'] : null, 'query_run_id' => isset($a['query_run_id']) ? (int) $a['query_run_id'] : null,
            'truncated' => (bool) ($a['truncated'] ?? false), 'ms' => (int) ($a['ms'] ?? 0), 'dataset' => $key];
    if ($out['query_run_id'] !== null) {
        log_activity($pdo, 'query.run', 'query_run', $out['query_run_id'], ['dataset_id' => $datasetId, 'query_run_id' => $out['query_run_id'],
            'after' => ['source' => 'preview', 'dataset' => $key, 'status' => $out['status'], 'rows' => count($out['rows']), 'masked_columns' => count($masked),
                        'as_of' => $asOf === null ? null : (string) $asOf]]);
    }
    return $out;
}

/** The masks the caller's level implies, by column (the "masked for you" hover: the mask's kind) — db/008 dl_masks_for at the caller's level. */
function preview_mask_kinds(PDO $pdo, int $datasetId, string $level): array
{
    $v = one_value($pdo, 'SELECT dl_masks_for(:d, :l)::text', ['d' => $datasetId, 'l' => $level]);
    return json_decode((string) ($v ?? '{}'), true) ?: [];
}
