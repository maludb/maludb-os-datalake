<?php
declare(strict_types=1);
/**
 * Action `load_rollback` (log load.rollback — loads.manage and write; confirm; an agent's pauses for approval, `deletion`): **load** — the
 * writer makes a NEW snapshot without the load's rows (in its dataset and in what a promotion made from them); the previous snapshot stays
 * readable as of. Logged here as queued; the writer logs the same event as done with the rows removed and the snapshot.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/loads/write.php';
dl_handler_begin();
require_right('loads.manage');
$pdo = db();
$id = (int) (req_val('load') ?? 0);
$probe = $id > 0 ? one_value($pdo, 'SELECT dataset_id FROM loads WHERE id = :id', ['id' => $id]) : null;
if ($probe === null) {
    respond_not_found('No such load.');
}
$d = require_dataset($pdo, (string) $probe, 'write');
$job = dl_guard($pdo, static function () use ($pdo, $id, $d): int {
    $pdo->beginTransaction();
    $job = load_rollback($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'load.rollback', 'load', $id, ['dataset_id' => (int) $d['id'], 'after' => ['phase' => 'queued', 'dataset' => $d['key'], 'job_id' => $job]]);
    $pdo->commit();
    return $job;
});
dl_done('Rolling back load ' . $id, $id, dl_land('/loads/' . $id, 'rollback'), 'jobQueued', ['job_id' => $job]);
