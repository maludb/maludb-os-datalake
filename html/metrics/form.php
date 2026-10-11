<?php
declare(strict_types=1);
/**
 * /metrics/new?dataset= (screen `metric-add`) and /metrics/{id}/edit (screen `metric-edit`): name, title, the product (the record picker over the product zone), the expression (one aggregate over the
 * product's columns), the time column, the grains, the dimensions (the product's columns as chips), a fixed filter, the format and unit, a description. Saving validates through the query service as me.
 * Changing the expression, filter, time column, dimensions or product removes the Certified badge. metrics.manage. JSON: the values and the choices.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/present.php';
require_login();
require_right('metrics.manage');
$pdo = db();
$id = request_integer('id') ?? 0;
$m = null;
if ($id > 0) {
    $m = find_metric($pdo, $id) ?? respond_not_found('No such metric.');
}
$v = metric_form_values($m, ['dataset' => request_string('dataset')]);
$columns = [];
if ($v['dataset'] !== null) {
    $d = dataset_by_ref($pdo, (string) $v['dataset']);
    $columns = $d !== null && dl_level_rank(dataset_level($pdo, (int) $d['id'])) >= DL_LEVELS['discover'] ? metric_columns($pdo, (int) $d['id']) : [];
}
if (request_string('fragment') === 'columns' && is_htmx_request()) {
    echo view('metrics/partials/columns.php', ['v' => $v, 'columns' => $columns]);
    exit;
}
log_screen_view($pdo, $m === null ? 'metric-add' : 'metric-edit', $m === null ? [] : ['entity_type' => 'metric', 'entity_id' => $id, 'dataset_id' => (int) $m['dataset_id']]);
if (wants_json()) {
    respond_screen(['metric_id' => $m === null ? null : $id, 'values' => $v, 'grains' => array_keys(METRIC_GRAINS), 'formats' => array_keys(METRIC_FORMATS), 'columns' => $columns]);
}
render_screen($m === null ? 'New metric' : 'Edit ' . $m['title'], view('metrics/form.php', ['m' => $m, 'v' => $v, 'columns' => $columns]),
    ['activeNav' => 'metrics', 'screen' => $m === null ? 'metric-add' : 'metric-edit', 'entity' => 'metric', 'recordId' => $m === null ? null : (string) $id]);
