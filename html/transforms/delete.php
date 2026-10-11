<?php
declare(strict_types=1);
/**
 * Action `transform_delete` (log transform.delete — transforms.manage or the owner; confirm; pauses for an agent: deletion): **transform** — the transform and its runs go; the
 * output dataset STAYS with its data and its lineage as history. Refused while a run is queued or running. Lands on /transforms.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/write.php';
require_once dirname(__DIR__, 2) . '/app/features/transforms/present.php';
dl_handler_begin();
require_any_right('transforms.manage|sandbox.transform');
$pdo = db();
$id = (int) (req_val('transform') ?? 0);
$t = $id > 0 ? find_transform($pdo, $id) : null;
if ($t === null) {
    respond_not_found('No such transform.');
}
require_manage_transform($t);
dl_guard($pdo, static function () use ($pdo, $id, $t): void {
    $pdo->beginTransaction();
    $runs = (int) one_value($pdo, 'SELECT count(*) FROM transform_runs WHERE transform_id = :id', ['id' => $id]);
    transform_delete($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'transform.delete', 'transform', $id, ['dataset_id' => (int) $t['output_dataset_id'], 'before' => ['name' => $t['name'], 'output' => $t['output_key'], 'version' => (int) $t['version'],
        'mode' => $t['mode'], 'schedule' => $t['schedule']], 'after' => ['runs_deleted' => $runs]]);
    $pdo->commit();
});
dl_done('Deleted ' . $t['name'] . ' — ' . $t['output_key'] . ' stays', $id, dl_land('/transforms', 'deleted'), 'transformChanged');
