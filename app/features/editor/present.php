<?php
declare(strict_types=1);

/**
 * The editor's JSON (the query_run tool's shape — query-service-editor.md): whitelisted, never a policy, a salt, a path or a DSN.
 *   present_result($r, $stats)   a read: status, the columns each marked masked or not, the rows (≤ the display cap), truncated, the datasets,
 *                                the time, the run (id and URL), the refusal (its rule, the service's sentence, the dataset, Request access),
 *                                the plan of a dry run
 *   present_catalog_entry($d)    one readable dataset with its columns (the completion list)
 *   present_run_row($r)          one of my runs (the editor's "my last runs")
 */
function present_result(array $r, ?array $stats = null): array
{
    $masked = [];
    foreach ($r['masked_columns'] as $m) {
        $masked[substr($m, (int) strrpos($m, '.') + 1)] = true;
    }
    return ['status' => $r['status'], 'dry_run' => $r['dry_run'],
            'columns' => array_map(static fn (array $c): array => ['name' => (string) $c['name'], 'type' => $c['type'] ?? null,
                'masked' => isset($masked[(string) $c['name']])], $r['columns']),
            'rows' => $r['rows'], 'row_count' => count($r['rows']), 'truncated' => $r['truncated'], 'masked_columns' => $r['masked_columns'],
            'datasets' => $r['datasets'], 'ms' => $r['ms'], 'as_of' => $r['as_of'], 'message' => $r['status'] === 'ok' ? $r['message'] : null,
            'query_run_id' => $r['query_run_id'], 'run_url' => $r['query_run_id'] === null ? null : '/runs/' . $r['query_run_id'],
            'refusal' => $r['refusal'], 'plan' => $r['plan'], 'stats' => $stats, 'request_id' => $r['request_id']];
}

function present_catalog_entry(array $d): array
{
    return ['dataset_id' => $d['dataset_id'], 'key' => $d['key'], 'title' => $d['title'], 'level' => $d['level'],
            'columns' => array_map(static fn (array $c): array => ['name' => $c['name'], 'type' => $c['logical_type'], 'masked' => $c['masked']], $d['columns'])];
}

function present_run_row(array $r): array
{
    return ['query_run_id' => (int) $r['query_run_id'], 'source' => $r['source'], 'status' => $r['status'], 'refusal_rule' => $r['refusal_rule'],
            'datasets' => pg_text_array((string) $r['datasets']), 'rows' => (int) $r['rows_returned'], 'truncated' => (bool) $r['truncated'],
            'ms' => (int) $r['ms'], 'created_at' => json_ts($r['created_at']), 'saved_query_id' => $r['saved_query_id'] === null ? null : (int) $r['saved_query_id'],
            'first_line' => mb_substr((string) $r['first_line'], 0, 160), 'url' => '/runs/' . (int) $r['query_run_id']];
}

/** A run's status badge: [class, words]. */
function run_badge(string $status, ?string $rule = null, ?int $timeoutS = null): array
{
    [$cls, $words] = RUN_STATUS[$status] ?? ['bg-soft-danger text-danger', 'Failed'];
    if ($status === 'refused' && $rule !== null) {
        $words = 'Refused — ' . $rule;
    } elseif ($status === 'timeout') {
        $words = 'Stopped at ' . ($timeoutS ?? lake_timeout('screen')) . ' s';
    }
    return [$cls === '' ? 'bg-soft-success text-success' : $cls, $words];
}
