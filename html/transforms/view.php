<?php
declare(strict_types=1);
/**
 * /transforms/{id}?tab= (screen `transform-view`; transforms-products-lineage.md): the header (name, the dataset it makes, state, owner, version, mode, schedule) with Run · Enable /
 * Disable · ⋯ (Edit · Delete), and four tabs by anchor — SQL (read-only, monospace; "you may not read every input" when the viewer may not), Inputs and output (the datasets it reads,
 * how many are hidden from the viewer, the output with its key, the level it was frozen at as words), Runs (the last 50, polled while one is queued or running), Lineage (the edges it
 * makes). `?partial=runs` (HTMX) answers the runs table alone. A transform is shown to whoever discovers its output (catalog.browse). JSON: present_transform(), the runs, the edges.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/present.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/write.php';
require_once dirname(__DIR__, 2) . '/app/features/lineage/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/lineage/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = request_integer('id') ?? 0;
$t = $id > 0 ? find_transform($pdo, $id) : null;
if ($t === null) {
    respond_not_found('No such transform.');
}
$tab = request_string('tab');
$tab = isset(TRANSFORM_TABS[$tab]) ? $tab : 'sql';
$runs = transform_runs($pdo, $id, 50);
if (request_string('partial') === 'runs' && is_htmx_request()) {
    header('Vary: HX-Request');
    echo view('transforms/partials/runs-table.php', ['t' => $t, 'runs' => $runs]);
    exit;
}
$edges = transform_edges($pdo, $id);
$manage = may_manage_transform($t);
$busy = false;
foreach ($runs as $r) {
    $busy = $busy || in_array($r['status'], ['queued', 'running'], true);
}
$may = ['run' => $manage && $t['frozen_at'] !== null, 'enable' => $manage, 'edit' => $manage, 'delete' => $manage && !$busy];
log_screen_view($pdo, 'transform-view', ['entity_type' => 'transform', 'entity_id' => $id, 'dataset_id' => (int) $t['output_dataset_id']]);
if (wants_json()) {
    respond_screen(['transform' => present_transform($t, $may), 'tab' => $tab, 'tabs' => array_map(static fn (string $k, string $l): array => ['name' => $k, 'label' => $l], array_keys(TRANSFORM_TABS), array_values(TRANSFORM_TABS)),
        'runs' => array_map('present_transform_run', $runs), 'edges' => array_map('present_lineage_edge', $edges)]);
}
$notices = ['created' => ['success', 'Saved and frozen at your level. Run it to make the dataset.'], 'saved' => ['success', 'Saved.'],
    'refrozen' => ['success', 'A new version — refrozen as you. Run it to make the dataset again.'], 'queued' => ['info', 'Queued — the writer takes the run within a moment.'],
    'running' => ['info', 'A run is already queued or running.'], 'enabled' => ['success', 'Enabled — its schedule runs it.'], 'disabled' => ['warning', 'Disabled — its schedule does not run it; you may still run it.']];
render_screen($t['name'], view('transforms/view.php', ['t' => $t, 'tab' => $tab, 'runs' => $runs, 'edges' => $edges, 'may' => $may, 'busy' => $busy, 'here' => here_url(),
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'transforms', 'screen' => 'transform-view', 'entity' => 'transform', 'recordId' => (string) $id]);
