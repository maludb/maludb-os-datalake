<?php
declare(strict_types=1);

/** The Preview's JSON (the dataset_preview tool's shape): columns, rows (≤ 100), masked_columns, as_of, query_run_id. */
function present_preview(array $p, bool $showProvenance = false): array
{
    $keep = [];
    foreach ($p['columns'] as $i => $c) {
        if ($showProvenance || !str_starts_with((string) $c['name'], '_dl_')) {
            $keep[] = $i;
        }
    }
    return ['status' => $p['status'], 'refusal_rule' => $p['refusal_rule'], 'message' => $p['message'], 'dataset' => $p['dataset'],
            'columns' => array_map(static fn (int $i): array => ['name' => $p['columns'][$i]['name'], 'type' => $p['columns'][$i]['type'] ?? null,
                'masked' => in_array($p['columns'][$i]['name'], $p['masked_columns'], true)], $keep),
            'rows' => array_map(static fn (array $r): array => array_map(static fn (int $i) => $r[$i] ?? null, $keep), $p['rows']),
            'masked_columns' => $p['masked_columns'], 'as_of' => $p['as_of'], 'query_run_id' => $p['query_run_id'], 'truncated' => $p['truncated']];
}
