<?php
declare(strict_types=1);
/**
 * Action `promote_rebuild` (log promote.rebuild — promote.run and write; confirm): **dataset** — curated rebuilt from ALL of raw under the active
 * contract (the table and its quarantine emptied and filled again in one commit). A `promote` job with `full`.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
dl_handler_begin();
require_right('promote.run');
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'write');
if ($d['zone'] !== 'curated') {
    dl_refuse_fields(['dataset' => 'Promotion is raw → curated: name a curated dataset.']);
}
$id = (int) $d['id'];
$job = dl_guard($pdo, static function () use ($pdo, $id, $d): int {
    $pdo->beginTransaction();
    $job = promote($pdo, $id, true, (int) current_member_id());
    log_activity($pdo, 'promote.rebuild', 'dataset', $id, ['dataset_id' => $id, 'after' => ['dataset' => $d['key'], 'job_id' => $job, 'phase' => 'queued']]);
    $pdo->commit();
    return $job;
});
dl_done('Rebuild queued', $id, dl_land(return_path('/datasets/' . $d['key'] . '?tab=quality'), 'rebuilding'), 'promotionQueued', ['job_id' => $job]);
