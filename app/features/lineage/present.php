<?php
declare(strict_types=1);

/** Lineage's JSON (the lineage_upstream / lineage_downstream / impact tools' shapes): never a policy, a path or a hidden node's name — only how many are hidden. */

function present_lineage_node(array $n): array
{
    $base = ['kind' => $n['kind'], 'depth' => (int) $n['depth'], 'via' => $n['via'], 'last_seen' => json_ts($n['last_seen'] ?? null)];
    if ($n['kind'] === 'source') {
        return $base + ['source_id' => (int) $n['source_id'], 'source_name' => $n['name'], 'connector' => $n['connector']];
    }
    return $base + ['dataset_id' => (int) $n['dataset_id'], 'key' => $n['key'], 'title' => $n['title'], 'zone' => $n['zone'], 'certification' => $n['certification'] ?? null,
        'transform' => $n['transform_id'] === null ? null : ['transform_id' => (int) $n['transform_id'], 'name' => $n['transform_name']], 'columns_traced' => (int) ($n['columns_traced'] ?? 0),
        'url' => '/datasets/' . $n['key']];
}

/** lineage_upstream / lineage_downstream: {nodes[], hidden}. */
function present_lineage(array $hops): array
{
    return ['direction' => $hops['direction'], 'depth' => $hops['depth'], 'nodes' => array_map('present_lineage_node', $hops['nodes']), 'hidden' => (int) $hops['hidden'],
            'hidden_by_depth' => (object) $hops['hidden_by_depth']];
}

/** One edge a transform makes. */
function present_lineage_edge(array $e): array
{
    return ['edge_id' => (int) $e['edge_id'], 'from' => $e['from_key'], 'to' => $e['to_key'], 'via' => 'transform', 'columns_traced' => count($e['column_map']),
            'column_map' => (object) $e['column_map'], 'first_seen' => json_ts($e['first_seen']), 'last_seen' => json_ts($e['last_seen'])];
}

/** impact: {datasets[], saved_queries[], metrics[], transforms[], consumers[]|null, consumers_count, hidden}. */
function present_dataset_impact(array $i): array
{
    return $i;
}

/** An edge's `via` badge: [class, words]. */
function lineage_via_badge(string $via): array
{
    [$c, $w] = LINEAGE_VIA[$via] ?? ['secondary', $via];
    return ['bg-soft-' . $c . ' text-' . $c, $w];
}
