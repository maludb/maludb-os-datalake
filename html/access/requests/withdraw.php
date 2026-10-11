<?php
declare(strict_types=1);
/** Action `access_request_withdraw` (log access.withdraw — own: its requester, or the agent that drafted it): **access_request**. A waiting request only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/access/write.php';
dl_handler_begin();
$pdo = db();
$id = (int) (req_val('access_request') ?? 0);
$r = $id > 0 ? find_access_request($pdo, $id) : null;
$me = (int) current_member_id();
if ($r === null || ((int) $r['requester_id'] !== $me && (int) ($r['drafted_by_agent'] ?? 0) !== $me)) {
    respond_not_found('No such request.');
}
if ($r['status'] !== 'pending') {
    refuse(422, 'That request was already ' . $r['status'] . '.');
}
dl_guard($pdo, static function () use ($pdo, $id, $me, $r): void {
    $pdo->beginTransaction();
    request_withdraw($pdo, $id, $me);
    log_activity($pdo, 'access.withdraw', 'access_request', $id, ['dataset_id' => (int) $r['dataset_id'], 'before' => ['status' => 'pending'], 'after' => ['status' => 'withdrawn']]);
    $pdo->commit();
});
dl_done('Withdrawn', $id, dl_land(return_path('/access/requests?box=mine'), 'withdrawn'), 'accessChanged');
