<?php
declare(strict_types=1);
/**
 * /datasets/{id}/lineage?depth= (screen `lineage-graph`; transforms-products-lineage.md divergence 4) — the lineage graph of one dataset as a server-rendered SVG: five layered columns
 * by default (two hops up to the sources, the dataset in the middle, two hops down — `depth` 1 to 4), a node per dataset the viewer may discover, a source as a rounded grey node, the rest
 * counted as "n hidden"; under it the same facts as lists (#lineage-graph-up, #lineage-graph-down — what a phone reads) and the IMPACT of a change (datasets, saved queries, metrics, transforms,
 * K7 consumers downstream). Needs `discover` on the dataset (a stub is the same 404). JSON: the two walks and the impact.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_once dirname(__DIR__, 2) . '/app/features/lineage/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lineage/present.php';
require_once dirname(__DIR__, 2) . '/app/features/lineage/svg.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$ref = request_string('ref') !== '' ? request_string('ref') : request_string('id');
$d = require_dataset($pdo, $ref, 'discover');
$depth = max(1, min(LINEAGE_MAX_DEPTH, request_integer('depth') ?? 2));
$up = lineage_hops($pdo, (int) $d['id'], 'up', $depth);
$down = lineage_hops($pdo, (int) $d['id'], 'down', $depth);
$impact = dataset_impact($pdo, (int) $d['id']);
log_screen_view($pdo, 'lineage-graph', ['entity_type' => 'dataset', 'entity_id' => (int) $d['id']]);
if (wants_json()) {
    respond_screen(['dataset' => ['dataset_id' => (int) $d['id'], 'key' => $d['key'], 'title' => $d['title'], 'zone' => $d['zone']], 'depth' => $depth,
        'upstream' => present_lineage($up), 'downstream' => present_lineage($down), 'impact' => present_dataset_impact($impact), 'svg' => ['columns' => 2 * $depth + 1]]);
}
$centre = ['dataset_id' => (int) $d['id'], 'key' => $d['key'], 'title' => $d['title'], 'zone' => $d['zone'], 'certification' => $d['certification']];
render_screen('Lineage of ' . $d['title'], view('lineage/graph.php', ['d' => $d, 'depth' => $depth, 'up' => $up, 'down' => $down, 'impact' => $impact, 'centre' => $centre]),
    ['activeNav' => 'browse', 'screen' => 'lineage-graph', 'entity' => 'dataset', 'recordId' => (string) $d['id'], 'datasetKey' => $d['key']]);
