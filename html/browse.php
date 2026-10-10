<?php
declare(strict_types=1);
/**
 * /browse — the lake by zone, by department and by tag (screen `browse`; catalog.md): the three ways in as chips, then the datasets the
 * person may discover as cards, paged 24 (params zone, department, tag, owner — an id, `me` or `none` —, status, q). Stubs never appear
 * here (search shows them). JSON: present_dataset_row() — whitelisted — with the facets.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$filters = browse_filters_from_request();
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_datasets($pdo, $filters, $page);
$facets = browse_facets($pdo);
log_screen_view($pdo, 'browse');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => DATASET_PAGE, 'total' => $result['total'],
        'rows' => array_map('present_dataset_row', $result['rows']), 'facets' => $facets, 'may' => ['add' => has_right('catalog.edit')]]);
}
$owners = [];
if (isset($filters['owner']) && is_int($filters['owner'])) {
    $owners[$filters['owner']] = (string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :i', ['i' => $filters['owner']]) ?? '');
}
$notices = ['retired' => ['success', 'Retired. Its key is never used again.']];
render_screen('Browse', view('catalog/browse.php', ['filters' => $filters, 'page' => $page, 'result' => $result, 'facets' => $facets, 'owners' => array_filter($owners),
    'may_add' => has_right('catalog.edit'), 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'browse', 'screen' => 'browse']);
