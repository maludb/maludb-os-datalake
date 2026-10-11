<?php
declare(strict_types=1);
/**
 * /queries (screen `queries` — query-service-editor.md): saved queries as cards — the title, the question, the owner, the Verified badge (by
 * whom) or "Unverified — the SQL changed", the datasets as chips, shared or mine. Filters q, verified (yes | no), dataset (a key or id), owner
 * (an id); paged 25. Through mcp_saved_queries: my own, or shared and every dataset it reads readable by me. queries.run (the menu's right).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/present.php';
require_login();
require_right('queries.run');
$pdo = db();
$f = ['q' => mb_substr(request_string('q'), 0, 120), 'verified' => in_array(request_string('verified'), ['yes', 'no'], true) ? request_string('verified') : ''];
if (($d = request_string('dataset')) !== '') {
    $ds = dataset_by_ref($pdo, $d);
    $f['dataset'] = $ds === null ? -1 : (int) $ds['id'];
}
if (ctype_digit(request_string('owner'))) {
    $f['owner'] = (int) request_string('owner');
}
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_saved_queries($pdo, $f, $page);
log_screen_view($pdo, 'queries');
if (wants_json()) {
    respond_screen(['filters' => (object) array_filter($f, static fn ($v) => $v !== '' && $v !== null), 'page' => $page, 'page_size' => SAVED_QUERY_PAGE,
        'total' => $result['total'], 'rows' => array_map('present_saved_query_row', $result['rows'])]);
}
render_screen('Saved queries', view('queries/index.php', ['f' => $f, 'q' => ['q' => request_string('q'), 'verified' => request_string('verified'),
    'dataset' => request_string('dataset'), 'owner' => request_string('owner')], 'page' => $page, 'result' => $result]), ['activeNav' => 'queries', 'screen' => 'queries']);
