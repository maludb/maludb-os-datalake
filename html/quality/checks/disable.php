<?php
declare(strict_types=1);
/** Action `check_disable` (log check.disable — checks.manage): **check**, enable (yes — turns it back on). A check turned off runs no more and certification stops counting it. Undo: check_disable. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/write.php';
dl_handler_begin();
require_right('checks.manage');
$pdo = db();
$id = (int) (req_val('check') ?? 0);
$probe = $id > 0 ? one_value($pdo, 'SELECT dataset_id FROM checks WHERE id = :id', ['id' => $id]) : null;
if ($probe === null) {
    respond_not_found('No such check.');
}
$d = require_dataset($pdo, (string) $probe, 'discover');
$enable = dl_yes('enable', false);
dl_guard($pdo, static function () use ($pdo, $id, $enable, $d): void {
    $pdo->beginTransaction();
    check_disable($pdo, $id, $enable);
    log_activity($pdo, 'check.disable', 'check', $id, ['dataset_id' => (int) $d['id'], 'after' => ['enabled' => $enable]]);
    $pdo->commit();
});
dl_done($enable ? 'Turned back on' : 'Turned off', $id, dl_land(return_path('/datasets/' . $d['key'] . '/checks'), $enable ? 'enabled' : 'disabled', 'check-row-' . $id), 'checksChanged');
