<?php
declare(strict_types=1);
/** Action `check_delete` (log check.delete — checks.manage; confirm; pauses for an agent: deletion): **check** — its results go with it; its incidents keep their history. */
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
$c = find_check($pdo, $id) ?? respond_not_found('No such check.');
dl_guard($pdo, static function () use ($pdo, $id, $d, $c): void {
    $pdo->beginTransaction();
    $results = (int) one_value($pdo, 'SELECT count(*) FROM check_results WHERE check_id = :id', ['id' => $id]);
    check_delete($pdo, $id);
    log_activity($pdo, 'check.delete', 'check', $id, ['dataset_id' => (int) $d['id'], 'before' => ['kind' => $c['kind'], 'column' => $c['column_name']], 'after' => ['results_deleted' => $results]]);
    $pdo->commit();
});
dl_done('Deleted', $id, dl_land(return_path('/datasets/' . $d['key'] . '/checks'), 'deleted'), 'checksChanged');
