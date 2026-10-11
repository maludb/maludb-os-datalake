<?php
declare(strict_types=1);
/**
 * /products (screen `products`; transforms-products-lineage.md — slice 7 replaces the Phase 2 placeholder): the product gallery — the datasets of the product zone the person may discover, as CARDS
 * (the browse screen's look): certified first, the owner, how fresh, the health dot, how many metrics are defined over it, and Query this. Filters q, certified (yes); paged 24. catalog.browse.
 * JSON: present_product_row().
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/metrics/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$filters = [];
if (($q = mb_substr(trim(request_string('q')), 0, 120)) !== '') {
    $filters['q'] = $q;
}
if (request_string('certified') === 'yes') {
    $filters['certified'] = 'yes';
}
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = products($pdo, $filters, $page);
log_screen_view($pdo, 'products');
if (wants_json()) {
    respond_screen(['filters' => (object) $filters, 'page' => $page, 'page_size' => PRODUCT_PAGE, 'total' => $result['total'], 'rows' => array_map('present_product_row', $result['rows']),
        'may' => ['query' => has_right('sql.editor')]]);
}
render_screen('Products', view('products/index.php', ['filters' => $filters, 'page' => $page, 'result' => $result, 'may_query' => has_right('sql.editor')]), ['activeNav' => 'products', 'screen' => 'products']);
