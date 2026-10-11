<?php
declare(strict_types=1);
/**
 * /access/requests/new?dataset=&level= (screen `request-add`) — to ask for access: a dataset I may know exists (its stub is enough), the level,
 * the reason, wanted until; "a request already waits" when one does. access.request. JSON: the values and the pending request.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/present.php';
require_login();
require_right('access.request');
$pdo = db();
$me = (int) current_member_id();
$d = null;
$ref = request_string('dataset');
if ($ref !== '') {
    $d = require_level($pdo, $ref, 'discover');               // a stub passes: one asks for what one may only know exists
}
$level = request_string('level');
$v = ['dataset' => $d === null ? null : (int) $d['id'], 'level' => in_array($level, REQUEST_LEVELS, true) ? $level : 'read', 'reason' => '', 'wanted_until' => ''];
$pending = $d === null ? null : my_pending_request($pdo, $me, (int) $d['id']);
log_screen_view($pdo, 'request-add', $d === null ? [] : ['entity_type' => 'dataset', 'entity_id' => (int) $d['id'], 'dataset_id' => (int) $d['id']]);
if (wants_json()) {
    respond_screen(['values' => $v, 'dataset' => $d === null ? null : ['id' => (int) $d['id'], 'key' => $d['key'], 'title' => $d['title'], 'my_level' => $d['my_level']],
                    'pending' => $pending === null ? null : ['request_id' => (int) $pending['request_id'], 'level' => $pending['level']]]);
}
render_screen('Request access', view('access/request-form.php', ['v' => $v, 'd' => $d, 'pending' => $pending]), ['activeNav' => 'access', 'screen' => 'request-add',
    'entity' => $d === null ? '' : 'dataset', 'recordId' => $d === null ? '' : (string) $d['id'], 'datasetKey' => $d['key'] ?? '']);
