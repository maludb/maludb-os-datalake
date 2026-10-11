<?php
declare(strict_types=1);
/**
 * Action `transform_run` (log transform.run — transforms.manage or the transform's owner): **transform** — a run queued for the writer: the transform_runs row (queued) and
 * the `transform` job. One at a time: a run already queued or running is that run ("already running"). The writer reads the inputs as the OWNER could when the SQL was
 * saved (the frozen level), so the product never widens. Answers the run's id (`run`) and the job's; the transform's page follows it (Runs tab, polled).
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
$q = dl_guard($pdo, static function () use ($pdo, $id, $t): array {
    $pdo->beginTransaction();
    $q = transform_run_queue($pdo, $id, (int) current_member_id());
    log_activity($pdo, 'transform.run', 'transform', $id, ['dataset_id' => (int) $t['output_dataset_id'], 'after' => ['transform_run_id' => $q['run'], 'job_id' => $q['job'], 'new' => $q['new'],
        'version' => (int) $t['version'], 'output' => $t['output_key']]]);
    $pdo->commit();
    return $q;
});
dl_done($q['new'] ? 'Queued a run of ' . $t['name'] : $t['name'] . ' already has a run queued or running', $id, dl_land(return_path('/transforms/' . $id . '?tab=runs'), $q['new'] ? 'queued' : 'running'),
    'transformChanged', ['run' => $q['run'], 'job_id' => $q['job'], 'new' => $q['new']]);
