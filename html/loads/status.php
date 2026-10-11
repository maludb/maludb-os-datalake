<?php
declare(strict_types=1);
/**
 * /loads/{id}/status — the load page's polling door (uploads-loads.md; not a screen): the counts partial again while the load is queued or
 * running (it polls every 2 s), the final counts once the writer finished (no more polling). Read on its dataset. JSON: the state.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
$pdo = db();
$id = request_integer('id') ?? 0;
$probe = one_value($pdo, 'SELECT dataset_id FROM loads WHERE id = :id', ['id' => $id]);
if ($probe === null) {
    respond_not_found('No such load.');
}
require_dataset($pdo, (string) $probe, 'read');
$l = find_load($pdo, $id) ?? respond_not_found('No such load.');
if (wants_json()) {
    respond_screen(['load_id' => $id, 'status' => $l['status'], 'final' => load_is_final((string) $l['status']), 'rows_loaded' => (int) $l['rows_loaded'],
        'rows_rejected' => (int) $l['rows_rejected'], 'error' => $l['error']]);
}
if (load_is_final((string) $l['status']) && is_htmx_request() && request_bool('was_running')) {
    hx_trigger('loadFinished');                         // the page reloads its other panels (the rejects, the drift) once
}
echo view('loads/partials/counts.php', ['l' => $l]);
