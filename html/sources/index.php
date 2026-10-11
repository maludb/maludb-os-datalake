<?php
declare(strict_types=1);
/**
 * /sources (screen `source-list`; sources-pulls.md — slice 6 replaces the Phase 2 placeholder): the sources as CARDS (the browse screen's shape): name, connector,
 * the raw dataset it feeds, schedule, the last pull's status and time, the failures in a row, a paused badge with its reason; chips for the connector and
 * for paused; paged 24 (params connector, paused = yes|no, q). sources.manage. JSON: present_source_row() — whitelisted — with the connectors.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('sources.manage');
$pdo = db();
$filters = source_filters_from_request();
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_sources($pdo, $filters, $page);
log_screen_view($pdo, 'source-list');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => SOURCE_PAGE, 'total' => $result['total'], 'rows' => array_map('present_source_row', $result['rows']),
        'connectors' => array_values(array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'pulls' => $c['capabilities']['pulls']], dl_fetch_connectors())),
        'may' => ['add' => true]]);
}
$notices = ['deleted' => ['success', 'Deleted. The dataset it fed is still there.']];
render_screen('Sources', view('sources/index.php', ['filters' => $filters, 'page' => $page, 'result' => $result, 'may_add' => true,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'sources', 'screen' => 'source-list']);
