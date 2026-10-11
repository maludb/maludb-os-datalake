<?php
declare(strict_types=1);
/**
 * Action `check_run` (log check.run — checks.manage or the dataset's steward): **dataset** (every enabled check now), check (one) — a
 * `checks` job for the writer; each result lands through dl_check_result_record (an incident opened or resolved by the database).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/checks/write.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
if (!may_run_checks($d)) {
    refuse(403, 'Running checks is an engineer\'s or the dataset\'s steward\'s.');
}
$id = (int) $d['id'];
$check = req_has('check') && req_val('check') !== '' ? (int) req_val('check') : null;
if ($check !== null && one_value($pdo, 'SELECT 1 FROM checks WHERE id = :c AND dataset_id = :d AND enabled', ['c' => $check, 'd' => $id]) === null) {
    dl_refuse_fields(['check' => 'That check is not one of ' . $d['key'] . '\'s, or it is turned off.']);
}
if ($check === null && one_value($pdo, 'SELECT 1 FROM checks WHERE dataset_id = :d AND enabled LIMIT 1', ['d' => $id]) === null) {
    dl_refuse_fields(['dataset' => $d['key'] . ' has no check turned on.']);
}
$job = dl_guard($pdo, static function () use ($pdo, $id, $check, $d): int {
    $pdo->beginTransaction();
    $job = check_run($pdo, $id, $check, (int) current_member_id());
    log_activity($pdo, 'check.run', 'dataset', $id, ['dataset_id' => $id, 'after' => ['dataset' => $d['key'], 'check' => $check, 'job_id' => $job, 'phase' => 'queued']]);
    $pdo->commit();
    return $job;
});
dl_done('Running ' . ($check === null ? 'every check' : 'the check'), $id, dl_land(return_path('/datasets/' . $d['key'] . '/checks'), 'queued'), 'checksQueued', ['job_id' => $job]);
