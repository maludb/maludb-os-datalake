<?php
declare(strict_types=1);
/**
 * /loads (screen `load-list`; uploads-loads.md): loads as a ledger table — id, dataset, source or "upload", the status, read / loaded / rejected
 * / rescued, the checks' outcome (slice 5 fills it), queued and finished, who asked — filtered by dataset, source (an id or `upload`), status
 * and since; paged 50. loads.manage (the menu's right); each row is a load of a dataset the person may read (mcp_loads).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('loads.manage');
$pdo = db();
$f = [];
if (($d = request_string('dataset')) !== '') {
    $ds = dataset_by_ref($pdo, $d);
    $f['dataset'] = $ds === null ? -1 : (int) $ds['id'];
}
if (($s = request_string('source')) !== '') {
    $f['source'] = $s === 'upload' ? 'upload' : (ctype_digit($s) ? (int) $s : -1);
}
if (($st = request_string('status')) !== '' && isset(LOAD_STATUS[$st])) {
    $f['status'] = [$st];
}
if (($since = request_date('since')) !== null && $since !== false) {
    $f['since'] = $since;
}
$page = max(1, min(1000, request_integer('page') ?? 1));
$result = find_loads($pdo, $f, $page);
log_screen_view($pdo, 'load-list');
if (wants_json()) {
    respond_screen(['filters' => (object) $f, 'page' => $page, 'page_size' => LOAD_PAGE, 'total' => $result['total'], 'rows' => array_map('present_load_row', $result['rows'])]);
}
render_screen('Loads', view('loads/index.php', ['f' => $f, 'q' => ['dataset' => request_string('dataset'), 'source' => request_string('source'), 'status' => request_string('status'),
    'since' => request_string('since')], 'page' => $page, 'result' => $result]), ['activeNav' => 'loads', 'screen' => 'load-list']);
