<?php
declare(strict_types=1);
/**
 * /search — the catalog search (screen `search`; sso-shell.md): dl_search() with the screen's filters (kind, zone, department, owner, tag,
 * classification, certification, personal), paged 25; full results as cards, stubs as locked cards (name, owner, Request access), the
 * rest not at all — the wall is the function's. Logs `search.catalog` with the query cut at 120 characters, the count and the stub count.
 * One character is too short (422 to JSON; the page says so). JSON: present_search_row() — whitelisted.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/search/queries.php';
require_once dirname(__DIR__) . '/app/features/search/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$q = mb_substr(request_string('q'), 0, 200);
$filters = search_filters_from_request();
$page = max(1, min(400, request_integer('page') ?? 1));
$tooShort = $q !== '' && mb_strlen($q) < 2;
$result = ['rows' => [], 'total' => 0, 'stubs' => 0];
if (!$tooShort && ($q !== '' || $filters !== [])) {
    $result = search($pdo, $q, $filters, $page);
    log_activity($pdo, 'search.catalog', null, null, ['screen' => 'search', 'after' => ['q' => mb_substr($q, 0, 120), 'filters' => array_keys($filters),
        'count' => $result['total'], 'stubs' => $result['stubs'], 'page' => $page]]);
}
log_screen_view($pdo, 'search');
if (wants_json()) {
    if ($tooShort) {
        json_error('invalid', 'Type at least two characters.', 422);
    }
    respond_screen(['q' => $q, 'filters' => (object) $filters, 'page' => $page, 'page_size' => SEARCH_PAGE, 'total' => $result['total'],
        'rows' => array_map('present_search_row', $result['rows'])]);
}
$owners = [];
$departments = [];
if (isset($filters['owner'])) {
    $owners = [(int) $filters['owner'] => (string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :i', ['i' => (int) $filters['owner']]) ?? '')];
}
if (isset($filters['department'])) {
    $departments = [(int) $filters['department'] => (string) (one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :i', ['i' => (int) $filters['department']]) ?? '')];
}
if ($tooShort) {
    http_response_code(200);
}
render_screen('Search', view('search/index.php', ['q' => $q, 'filters' => $filters, 'page' => $page, 'result' => $result, 'tooShort' => $tooShort,
    'mayAsk' => has_right('ask.use'), 'owners' => array_filter($owners), 'departments' => array_filter($departments)]), ['activeNav' => 'search', 'screen' => 'search']);
