<?php
declare(strict_types=1);
/**
 * /metrics/{id}?grain=&by[]= (screen `metric-view`; transforms-products-lineage.md divergence 5): one metric — its definition (the expression, the product, the fixed filter, the time column), the CHART
 * (the last twelve periods at the chosen grain, a server-rendered SVG line from zero; the value from the query service as the viewer, so a masked cut arrives masked), a grain picker and a
 * dimension picker that re-render the table below the chart (HTMX `?partial=result`; plain GET without JavaScript), and Certify · Retire · Edit for metrics.manage. A metric is shown to whoever
 * discovers its product; its numbers to whoever may read it. JSON: the metric, the chart's points, the table.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('metrics.use');
$pdo = db();
$id = request_integer('id') ?? 0;
$m = $id > 0 ? find_metric($pdo, $id) : null;
if ($m === null) {
    respond_not_found('No such metric.');
}
$grain = metric_pick_grain($m, request_string('grain'));
$by = [];
$rawBy = $_GET['by'] ?? [];
foreach ((is_array($rawBy) ? $rawBy : [$rawBy]) as $b) {
    if (is_string($b) && in_array($b, $m['dimensions'], true) && !in_array($b, $by, true) && count($by) < 4) {
        $by[] = $b;
    }
}
[$points, $chartAnswer, $win] = metric_series($pdo, $m, $grain);
$table = $by === [] ? $chartAnswer : metric_table($pdo, $m, $grain, $by, [], $win);
$may = ['edit' => has_right('metrics.manage'), 'certify' => has_right('metrics.manage'), 'retire' => has_right('metrics.manage')];
if (request_string('partial') === 'result' && is_htmx_request()) {
    header('Vary: HX-Request');
    echo view('metrics/partials/result.php', ['m' => $m, 'grain' => $grain, 'by' => $by, 'points' => $points, 'chart_answer' => $chartAnswer, 'table' => $table, 'win' => $win]);
    exit;
}
log_screen_view($pdo, 'metric-view', ['entity_type' => 'metric', 'entity_id' => $id, 'dataset_id' => (int) $m['dataset_id']]);
if (wants_json()) {
    respond_screen(['metric' => present_metric($m, $may), 'grain' => $grain, 'by' => $by, 'window' => $win,
        'chart' => ['ok' => $chartAnswer['ok'], 'message' => $chartAnswer['message'], 'points' => $points, 'query_run_id' => $chartAnswer['query_run_id']],
        'value' => $table['ok'] ? present_metric_value($m, $grain, $table) : null, 'refusal' => $table['ok'] ? null : ['rule' => $table['rule'], 'message' => $table['message']]]);
}
$notices = ['created' => ['success', 'Defined. Certify it when you have checked the number.'], 'saved' => ['success', 'Saved.'], 'dropped' => ['warning', 'Saved — the definition changed, so it is no longer certified.'],
    'certified' => ['success', 'Certified.'], 'uncertified' => ['secondary', 'The badge is removed.']];
render_screen($m['title'], view('metrics/view.php', ['m' => $m, 'grain' => $grain, 'by' => $by, 'points' => $points, 'chart_answer' => $chartAnswer, 'table' => $table, 'win' => $win, 'may' => $may,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'metrics', 'screen' => 'metric-view', 'entity' => 'metric', 'recordId' => (string) $id]);
