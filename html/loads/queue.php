<?php
declare(strict_types=1);
/**
 * Action `load_queue` (log load.queue — loads.manage and write; confirm): **load** — replays a load from its landing file (the same mapping):
 * refused when the landing was purged ("not replayable"). A load still in the lake answers "already loaded" (no_op) when the writer runs it;
 * a rolled-back one comes back. The job is the writer's.
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
    $job = load_queue($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'load.queue', 'load', $id, ['dataset_id' => (int) $d['id'], 'after' => ['dataset' => $d['key'], 'job_id' => $job, 'replay' => true]]);
    $pdo->commit();
    return $job;
});
dl_done('Queued load ' . $id . ' again', $id, dl_land('/loads/' . $id, 'queued'), 'jobQueued', ['job_id' => $job]);
