<?php
declare(strict_types=1);

/**
 * Lineage's reads (slice 7, transforms-products-lineage.md "Query functions"): where a dataset's data comes from and what is made from it, one hop or several, and what would be
 * touched by a change to it. The walk is the database's (dl_lineage — OpenLineage's graph kept as lineage_edges: a load, a promotion, a transform, an export); the WALL is applied
 * here: a node the caller may not discover is COUNTED and never named (mcp_datasets answers a stub for a dataset the caller may only know exists — a stub is hidden here too:
 * lineage names what a person can open), a source is named only to someone who may see sources (mcp_sources). The same functions answer the lineage tab, the graph and (Phase 4)
 * the records tools lineage_upstream, lineage_downstream and impact.
 *
 *   lineage_hops($pdo, $dataset, $direction, $depth)   ['nodes' => […], 'hidden' => n, 'hidden_by_depth' => [depth => n], 'hidden_parents' => [depth => [parent ids]]]
 *   transform_edges($pdo, $transform)                  the edges one transform makes (both ends discoverable): from_key, to_key, column_map, last_seen
 *   dataset_impact($pdo, $dataset)                     what is downstream — datasets, saved queries, metrics, transforms, K7 consumers — with the ones the caller cannot see counted
 */

const LINEAGE_MAX_DEPTH = 4;
/** An edge's `via` as a badge: [bootstrap colour, words]. */
const LINEAGE_VIA = ['load' => ['info', 'load'], 'promote' => ['primary', 'promote'], 'transform' => ['success', 'transform'], 'export' => ['secondary', 'export']];

/** The graph one way from a dataset: direction 'up' (what it is made from, to the sources) or 'down' (what is made from it). */
function lineage_hops(PDO $pdo, int $dataset, string $direction, int $depth): array
{
    $direction = $direction === 'down' ? 'down' : 'up';
    $depth = max(1, min(LINEAGE_MAX_DEPTH, $depth));
    $st = $pdo->prepare('SELECT dataset_id, source_id, depth, via, parent_dataset_id FROM dl_lineage(:d, :dir, :n) ORDER BY depth, dataset_id NULLS LAST, source_id');
    $st->execute(['d' => $dataset, 'dir' => $direction, 'n' => $depth]);
    $walk = $st->fetchAll();
    $ids = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['dataset_id'] === null ? null : (int) $r['dataset_id'], $walk))));
    $seen = [];
    if ($ids !== []) {
        $st = $pdo->prepare('SELECT dataset_id, key, title, zone, certification, status, row_count, data_updated_at FROM mcp_datasets
                              WHERE dataset_id = ANY (CAST(:ids AS bigint[])) AND NOT is_stub');
        $st->execute(['ids' => pg_array_literal($ids)]);
        foreach ($st->fetchAll() as $d) {
            $seen[(int) $d['dataset_id']] = $d;
        }
    }
    $edges = [];
    $touch = array_merge($ids, [$dataset]);
    $st = $pdo->prepare('SELECT e.from_dataset_id, e.from_source_id, e.to_dataset_id, e.via, e.transform_id, e.column_map, e.last_seen, t.name AS transform_name
                           FROM mcp_lineage_edges e LEFT JOIN mcp_transforms t ON t.transform_id = e.transform_id
                          WHERE e.to_dataset_id = ANY (CAST(:ids AS bigint[])) OR e.from_dataset_id = ANY (CAST(:ids2 AS bigint[]))');
    $st->execute(['ids' => pg_array_literal($touch), 'ids2' => pg_array_literal($touch)]);
    foreach ($st->fetchAll() as $e) {
        $edges[($e['from_dataset_id'] ?? 's' . $e['from_source_id']) . '>' . $e['to_dataset_id'] . '>' . $e['via']] = $e;
    }
    $srcIds = array_values(array_unique(array_filter(array_map(static fn (array $r): ?int => $r['source_id'] === null ? null : (int) $r['source_id'], $walk))));
    $sources = [];
    foreach ($srcIds as $sid) {
        $sources[$sid] = one_row($pdo, 'SELECT source_id, name, connector FROM mcp_sources WHERE source_id = :s', ['s' => $sid]);
    }
    $nodes = [];
    $hiddenBy = [];
    $hiddenParents = [];
    foreach ($walk as $w) {
        $d = (int) $w['depth'];
        $parent = (int) $w['parent_dataset_id'];
        if ($w['dataset_id'] === null) {
            $sid = (int) $w['source_id'];
            $edge = $edges['s' . $sid . '>' . $parent . '>' . $w['via']] ?? null;
            $nodes[] = ['kind' => 'source', 'source_id' => $sid, 'name' => $sources[$sid]['name'] ?? null, 'connector' => $sources[$sid]['connector'] ?? null, 'dataset_id' => null,
                'key' => null, 'title' => null, 'zone' => 'source', 'certification' => null, 'depth' => $d, 'via' => $w['via'], 'parent_dataset_id' => $parent,
                'last_seen' => $edge['last_seen'] ?? null, 'transform_id' => null, 'transform_name' => null, 'columns_traced' => 0];
            continue;
        }
        $did = (int) $w['dataset_id'];
        if (!isset($seen[$did])) {
            $hiddenBy[$d] = ($hiddenBy[$d] ?? 0) + 1;
            $hiddenParents[$d][$parent] = true;
            continue;
        }
        $x = $seen[$did];
        $key = $direction === 'up' ? $did . '>' . $parent . '>' . $w['via'] : $parent . '>' . $did . '>' . $w['via'];
        $edge = $edges[$key] ?? null;
        $nodes[] = ['kind' => 'dataset', 'dataset_id' => $did, 'source_id' => null, 'name' => null, 'connector' => null, 'key' => $x['key'], 'title' => $x['title'], 'zone' => $x['zone'],
            'certification' => $x['certification'], 'status' => $x['status'], 'depth' => $d, 'via' => $w['via'], 'parent_dataset_id' => $parent, 'last_seen' => $edge['last_seen'] ?? null,
            'transform_id' => isset($edge['transform_id']) && $edge['transform_id'] !== null ? (int) $edge['transform_id'] : null, 'transform_name' => $edge['transform_name'] ?? null,
            'columns_traced' => isset($edge['column_map']) ? count(json_decode((string) $edge['column_map'], true) ?: []) : 0];
    }
    return ['nodes' => $nodes, 'hidden' => array_sum($hiddenBy), 'hidden_by_depth' => $hiddenBy, 'hidden_parents' => array_map('array_keys', $hiddenParents), 'direction' => $direction, 'depth' => $depth];
}

/** The edges one transform makes (mcp_lineage_edges: both ends discoverable), by the input's key. */
function transform_edges(PDO $pdo, int $transform): array
{
    $st = $pdo->prepare("SELECT e.edge_id, f.key AS from_key, t.key AS to_key, e.column_map, e.first_seen, e.last_seen FROM mcp_lineage_edges e
                           JOIN mcp_datasets f ON f.dataset_id = e.from_dataset_id AND NOT f.is_stub JOIN mcp_datasets t ON t.dataset_id = e.to_dataset_id AND NOT t.is_stub
                          WHERE e.transform_id = :t AND e.via = 'transform' ORDER BY f.key");
    $st->execute(['t' => $transform]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['column_map'] = json_decode((string) $r['column_map'], true) ?: [];
    }
    unset($r);
    return $rows;
}

/**
 * IMPACT (dl_impact, db/012): everything downstream of a dataset, walled for the caller — datasets they can discover (the rest counted in `hidden`), the saved queries they may see
 * (mine, or shared and readable), the metrics and transforms they can discover, and the K7 consumers (named only to the dataset's owner or a grants.manager; counted otherwise).
 * Shape: {datasets[{id,key,title,zone}], saved_queries[{id,title,verified}], metrics[{id,name,title,certified}], transforms[{id,name}], consumers[names]|null, consumers_count, hidden}.
 */
function dataset_impact(PDO $pdo, int $dataset): array
{
    $raw = json_decode((string) one_value($pdo, 'SELECT dl_impact(:d)::text', ['d' => $dataset]), true) ?: [];
    $hidden = 0;
    $out = ['datasets' => [], 'saved_queries' => [], 'metrics' => [], 'transforms' => [], 'consumers' => null, 'consumers_count' => 0, 'hidden' => 0];
    $ids = array_map(static fn (array $x): int => (int) $x['id'], (array) ($raw['datasets'] ?? []));
    if ($ids !== []) {
        $st = $pdo->prepare('SELECT dataset_id, key, title, zone, certification FROM mcp_datasets WHERE dataset_id = ANY (CAST(:ids AS bigint[])) AND NOT is_stub ORDER BY key');
        $st->execute(['ids' => pg_array_literal($ids)]);
        $out['datasets'] = array_map(static fn (array $d): array => ['id' => (int) $d['dataset_id'], 'key' => $d['key'], 'title' => $d['title'], 'zone' => $d['zone'], 'certification' => $d['certification']], $st->fetchAll());
        $hidden += count($ids) - count($out['datasets']);
    }
    $pick = static function (string $sql, array $given, string $idKey) use ($pdo, &$hidden): array {
        $ids = array_map(static fn (array $x): int => (int) $x[$idKey], $given);
        if ($ids === []) {
            return [];
        }
        $st = $pdo->prepare($sql);
        $st->execute(['ids' => pg_array_literal($ids)]);
        $rows = $st->fetchAll();
        $hidden += count($ids) - count($rows);
        return $rows;
    };
    $out['saved_queries'] = array_map(static fn (array $q): array => ['id' => (int) $q['saved_query_id'], 'title' => $q['title'], 'verified' => $q['verified_at'] !== null],
        $pick('SELECT saved_query_id, title, verified_at FROM mcp_saved_queries WHERE saved_query_id = ANY (CAST(:ids AS bigint[])) ORDER BY title', (array) ($raw['saved_queries'] ?? []), 'id'));
    $out['metrics'] = array_map(static fn (array $m): array => ['id' => (int) $m['metric_id'], 'name' => $m['name'], 'title' => $m['title'], 'certified' => (bool) $m['certified']],
        $pick('SELECT metric_id, name, title, certified FROM mcp_metrics WHERE metric_id = ANY (CAST(:ids AS bigint[])) ORDER BY name', (array) ($raw['metrics'] ?? []), 'id'));
    $out['transforms'] = array_map(static fn (array $t): array => ['id' => (int) $t['transform_id'], 'name' => $t['name']],
        $pick('SELECT transform_id, name FROM mcp_transforms WHERE transform_id = ANY (CAST(:ids AS bigint[])) ORDER BY name', (array) ($raw['transforms'] ?? []), 'id'));
    $consumers = array_values(array_map('strval', (array) ($raw['consumers'] ?? [])));
    $out['consumers_count'] = count($consumers);
    $owner = (int) (one_value($pdo, 'SELECT owner_member_id FROM datasets WHERE id = :d', ['d' => $dataset]) ?? 0);
    $out['consumers'] = ($owner === (int) current_member_id() || has_right('grants.manage')) ? $consumers : null;
    $out['hidden'] = $hidden;
    return $out;
}
