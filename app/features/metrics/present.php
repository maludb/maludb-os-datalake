<?php
declare(strict_types=1);

/** Metrics' and products' JSON (the find_metrics / metric_value tools' shapes): whitelisted — never a raw row, a policy or another's data. */

/** A metric's state: [key, bootstrap colour, words] — certified, or draft (the retired are not shown at all). */
function metric_state(array $m): array
{
    return $m['certified'] ? ['certified', 'success', 'Certified'] : ['draft', 'light', 'Draft'];
}

function present_metric_row(array $m): array
{
    return ['metric_id' => (int) $m['metric_id'], 'name' => $m['name'], 'title' => $m['title'], 'description' => $m['description'],
            'dataset' => ['dataset_id' => (int) $m['dataset_id'], 'key' => $m['dataset_key'], 'title' => $m['dataset_title']],
            'time_column' => $m['time_column'], 'grains' => array_values($m['grains']), 'dimensions' => array_values($m['dimensions']), 'format' => $m['format'], 'unit' => $m['unit'],
            'certified' => (bool) $m['certified'], 'certified_at' => json_ts($m['certified_at']), 'owner' => ['member_id' => $m['owner_member_id'] === null ? null : (int) $m['owner_member_id'], 'name' => $m['owner_name']],
            'url' => '/metrics/' . (int) $m['metric_id']];
}

/** get_metric: the card plus the definition (the expression and the fixed filter are an engineer's text, shown to whoever may discover the product). */
function present_metric(array $m, array $may = []): array
{
    return present_metric_row($m) + ['expression' => $m['expression'], 'filters' => $m['filters'], 'columns' => $m['columns'] ?? [], 'updated_at' => json_ts($m['updated_at']), 'may' => $may];
}

/** A product card / a gallery row. */
function present_product_row(array $p): array
{
    return ['dataset_id' => (int) $p['dataset_id'], 'key' => $p['key'], 'title' => $p['title'], 'description' => $p['description'] ?? null, 'certification' => $p['certification'],
            'status' => $p['status'], 'owner' => ['member_id' => $p['owner_member_id'] === null ? null : (int) $p['owner_member_id'], 'name' => $p['owner_name'] ?? null],
            'row_count' => $p['row_count'] === null ? null : (int) $p['row_count'], 'data_updated_at' => json_ts($p['data_updated_at'] ?? null), 'health' => $p['health'] ?? null,
            'metrics' => (int) $p['metric_count'], 'certified_metrics' => (int) $p['certified_metric_count'], 'url' => '/datasets/' . $p['key'], 'query_url' => '/sql?dataset=' . $p['key']];
}

/** The value a metric asked for, as the metric_value tool answers: {metric, grain, rows[{period?, …dimensions, value}], definition, masked_columns, query_run_id}. */
function present_metric_value(array $m, ?string $grain, array $answer): array
{
    $rows = [];
    foreach ($answer['rows'] as $r) {
        $o = [];
        foreach ($answer['columns'] as $i => $c) {
            $o[$c] = $i === count($answer['columns']) - 1 && $r[$i] !== null ? (float) $r[$i] : $r[$i];
        }
        $rows[] = $o;
    }
    return ['metric' => $m['name'], 'grain' => $grain, 'rows' => $rows, 'truncated' => $answer['truncated'], 'masked_columns' => $answer['masked_columns'],
            'definition' => ['expression' => $m['expression'], 'dataset' => $m['dataset_key'], 'filters' => $m['filters'], 'time_column' => $m['time_column'], 'dimensions' => array_values($m['dimensions'])],
            'query_run_id' => $answer['query_run_id']];
}

/** The form's values, from a metric or the defaults. */
function metric_form_values(?array $m, array $given = []): array
{
    if ($m === null) {
        return ['name' => '', 'title' => '', 'description' => '', 'dataset' => ($given['dataset'] ?? '') !== '' && ctype_digit((string) $given['dataset']) ? (int) $given['dataset'] : null, 'expression' => '',
                'time_column' => '', 'grains' => ['month'], 'dimensions' => [], 'filters' => '', 'format' => 'number', 'unit' => ''];
    }
    return ['name' => (string) $m['name'], 'title' => (string) $m['title'], 'description' => (string) ($m['description'] ?? ''), 'dataset' => (int) $m['dataset_id'], 'expression' => (string) $m['expression'],
            'time_column' => (string) ($m['time_column'] ?? ''), 'grains' => array_values($m['grains']), 'dimensions' => array_values($m['dimensions']), 'filters' => (string) ($m['filters'] ?? ''),
            'format' => (string) $m['format'], 'unit' => (string) ($m['unit'] ?? '')];
}
