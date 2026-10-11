<?php
declare(strict_types=1);
/**
 * /transforms (screen `transform-list`; transforms-products-lineage.md — slice 7 replaces the Phase 2 placeholder): transforms as CARDS (the sources' shape): name, the dataset it
 * makes and the datasets it reads, mode, schedule, the last run's status and time. Chips: enabled | disabled; filters dataset (a key or id: its output OR an input), q, owner; paged 24.
 * transforms.manage or sandbox.transform (the menu's rights). A transform is shown to whoever discovers its output. JSON: present_transform_row().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_any_right('transforms.manage|sandbox.transform');
$pdo = db();
$filters = transform_filters_from_request($pdo);
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_transforms($pdo, $filters, $page);
log_screen_view($pdo, 'transform-list');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => TRANSFORM_PAGE, 'total' => $result['total'], 'rows' => array_map('present_transform_row', $result['rows']),
        'may' => ['add' => true]]);
}
$notices = ['deleted' => ['success', 'Deleted. The dataset it made is still there.']];
render_screen('Transforms', view('transforms/index.php', ['filters' => $filters, 'page' => $page, 'result' => $result, 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'transforms', 'screen' => 'transform-list']);
