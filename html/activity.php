<?php
declare(strict_types=1);
/** /activity — my own trail here, in sentences (screen `activity`); ?action= a kind, ?period= days, ?page_no= the page. HTMX swaps the rows. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/activity/queries.php';
require_login();
$pdo = db();
$me = (int) current_member_id();
$action = preg_replace('/[^a-z_.]/', '', request_string('action')) ?? '';
$since = request_integer('period');
$since = in_array($since, [1, 7, 30, 90], true) ? $since : null;
$page = max(1, min(1000, request_integer('page_no') ?? 1));
$filters = ['action' => $action, 'since' => $since];
$r = find_my_activity($pdo, $me, $page, $filters);
log_screen_view($pdo, 'activity');
if (wants_json()) {
    respond_screen(['page' => $page, 'more' => $r['more'], 'rows' => array_map('present_activity_row', $r['rows'])]);
}
$query = array_filter(['action' => $action, 'period' => $since]);
$tz = member_timezone();
$rowsHtml = view('activity/partials/rows.php', ['rows' => $r['rows'], 'page' => $page, 'more' => $r['more'], 'query' => $query, 'tz' => $tz]);
if (is_htmx_request() && (($_SERVER['HTTP_HX_TARGET'] ?? '') === 'activity-results')) {
    echo $rowsHtml;
    exit;
}
render_screen('My activity', view('activity/page.php', ['filters' => $filters, 'resultsHtml' => $rowsHtml]), ['activeNav' => 'activity', 'screen' => 'activity']);
