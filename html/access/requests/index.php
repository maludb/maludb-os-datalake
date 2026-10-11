<?php
declare(strict_types=1);
/**
 * /access/requests?box=mine|to_decide|history (screen `requests`) — the requests I asked, those waiting for me to decide (the dataset's owner,
 * or grants.manage — never the requester), and the decided ones I may see (mcp_access_requests' wall). ?impact={id}&level= answers the
 * approval's impact line alone (decide-inline.php's level select). JSON: the box's requests, the To-decide count.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$me = (int) current_member_id();
if (($impactId = request_integer('impact')) !== null) {
    $r = find_access_request($pdo, $impactId) ?? respond_not_found('No such request.');
    $impact = request_impact($pdo, $impactId, request_string('level'));
    if (wants_json()) {
        respond_screen(['impact' => $impact]);
    }
    echo view('access/partials/impact.php', ['r' => $r, 'impact' => $impact]);
    exit;
}
$box = request_string('box');
$toDecide = requests_to_decide_count($pdo, $me);
if (!isset(REQUEST_BOXES[$box])) {
    $box = $toDecide > 0 ? 'to_decide' : 'mine';
}
$dataset = request_integer('dataset');
$result = find_access_requests($pdo, $me, $box, ['dataset' => $dataset], max(1, min(1000, request_integer('page') ?? 1)));
log_screen_view($pdo, 'requests');
if (wants_json()) {
    respond_screen(['box' => $box, 'to_decide' => $toDecide, 'page' => $result['page'], 'total' => $result['total'],
                    'requests' => array_map(static fn (array $r): array => present_request($r) + ['may_decide' => may_decide_request($r, $me)], $result['rows'])]);
}
$notices = ['requested' => ['success', 'Sent — the dataset\'s owner decides, and you will hear.'], 'withdrawn' => ['success', 'Withdrawn.'],
            'approved' => ['success', 'Approved — they have been told.'], 'denied' => ['warning', 'Denied — they have been told why.']];
render_screen('Access requests', view('access/requests.php', ['box' => $box, 'result' => $result, 'to_decide' => $toDecide, 'dataset' => $dataset,
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'access', 'screen' => 'requests']);
