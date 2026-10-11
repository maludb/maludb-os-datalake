<?php
declare(strict_types=1);
/**
 * /metrics (screen `metric-list`; transforms-products-lineage.md — slice 7 replaces the Phase 2 placeholder): metrics as CARDS — the title, the product it is over, the grains and dimensions it may
 * be cut by, the Certified badge — certified first; chips certified | draft; filters dataset (a key or id), q, owner; paged 24. metrics.use (the menu's right). A metric is shown to whoever
 * discovers its product. JSON: present_metric_row().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('metrics.use');
$pdo = db();
$filters = metric_filters_from_request($pdo);
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_metrics($pdo, $filters, $page);
log_screen_view($pdo, 'metric-list');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => METRIC_PAGE, 'total' => $result['total'], 'rows' => array_map('present_metric_row', $result['rows']),
        'may' => ['add' => has_right('metrics.manage')]]);
}
$notices = ['retired' => ['success', 'Retired. It no longer shows in any list.']];
render_screen('Metrics', view('metrics/index.php', ['filters' => $filters, 'page' => $page, 'result' => $result, 'may_add' => has_right('metrics.manage'), 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'metrics', 'screen' => 'metric-list']);
