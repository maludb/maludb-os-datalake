<?php
declare(strict_types=1);
/**
 * /access?level= (screen `my-access`; access-policies.md) — what I may read, as cards (dl_my_access: the candidates are only what reaches me —
 * owned, stewarded, granted by dataset, zone or tag — judged one by one), through what, "rows filtered", masked columns' count, expiry; my
 * open requests below. access.request. JSON: the rows and the requests.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/access/present.php';
require_login();
require_right('access.request');
$pdo = db();
$me = (int) current_member_id();
$level = request_string('level');
$level = in_array($level, ACCESS_LEVELS, true) ? $level : 'read';
$result = my_access($pdo, $me, $level, max(1, min(1000, request_integer('page') ?? 1)));
$requests = find_access_requests($pdo, $me, 'mine', ['status' => 'pending'], 1)['rows'];
log_screen_view($pdo, 'my-access');
if (wants_json()) {
    respond_screen(['level' => $level, 'page' => $result['page'], 'total' => $result['total'], 'datasets' => array_map('present_my_access_row', $result['rows']),
                    'open_requests' => array_map('present_request', $requests)]);
}
$notices = ['requested' => ['success', 'Sent — the dataset\'s owner decides, and you will hear.'], 'withdrawn' => ['success', 'Withdrawn.']];
render_screen('My access', view('access/index.php', ['level' => $level, 'result' => $result, 'requests' => $requests, 'notice' => dl_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'access', 'screen' => 'my-access']);
