<?php
declare(strict_types=1);
/**
 * /loads/{id} (screen `load-view`; uploads-loads.md): one load — the counts (loads/partials/counts.php, polled while queued or running), the
 * file (its name, size, the hash's first 12; "landing purged — not replayable"), the schema seen and the drift (added / rescued / missing; a
 * pending schema version links the dataset's History), the checks' outcome (slice 5), the error; the rejected lines (line, column, error, the
 * message — the raw text only at the dataset's write level); Replay and Roll back (loads.manage and write). Read on its dataset: a load of a
 * dataset I may not read is the same 404 as none.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = request_integer('id') ?? 0;
$probe = one_value($pdo, 'SELECT dataset_id FROM loads WHERE id = :id', ['id' => $id]);
if ($probe === null) {
    respond_not_found('No such load.');
}
$d = require_dataset($pdo, (string) $probe, 'read');
$l = find_load($pdo, $id) ?? respond_not_found('No such load.');
$mayWrite = dl_level_rank((string) $d['my_level']) >= DL_LEVELS['write'];
$page = max(1, min(100, request_integer('rejects_page') ?? 1));
$rejects = load_rejects($pdo, $id, $mayWrite, $page);
$rejectsTotal = load_rejects_count($pdo, $id);
$pending = schema_history($pdo, (int) $l['dataset_id'], true, 5);
log_screen_view($pdo, 'load-view', ['entity_type' => 'load', 'entity_id' => $id, 'dataset_id' => (int) $l['dataset_id']]);
if (wants_json()) {
    respond_screen(['load' => present_load($l, $mayWrite), 'rejects' => ['page' => $page, 'total' => $rejectsTotal, 'rows' => array_map('present_reject', $rejects)],
        'pending_schema_versions' => count($pending)]);
}
$notices = ['queued' => ['success', 'Queued again — the writer replays it from its landing file.'], 'rollback' => ['warning', 'The rollback is queued: a new snapshot without these rows.']];
render_screen('Load ' . $id, view('loads/view.php', ['l' => $l, 'd' => $d, 'may_write' => $mayWrite, 'rejects' => $rejects, 'rejects_total' => $rejectsTotal, 'page' => $page,
    'pending' => $pending, 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'loads', 'screen' => 'load-view', 'entity' => 'load', 'recordId' => (string) $id, 'datasetKey' => $l['dataset_key']]);
