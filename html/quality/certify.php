<?php
declare(strict_types=1);
/**
 * Action `certify` (log certify.set — checks.manage; promoted: the owner; confirm; pauses for an agent: other): **dataset**, level (certified or
 * promoted — the owner's lighter badge). The database decides (dl_certify): refused in its words with what the dataset lacks when it is not
 * eligible. Undo: certify_remove.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$id = (int) $d['id'];
$errors = [];
$level = dl_choice('level', 'certified', ['certified', 'promoted'], 'The level', $errors, false) ?? 'certified';
if ($errors !== []) {
    dl_refuse_fields($errors);
}
if ($level === 'certified' && !has_right('checks.manage')) {
    refuse(403, 'Certifying is a Data engineer\'s.');
}
if ($level === 'promoted' && !has_right('checks.manage') && (int) ($d['owner_member_id'] ?? 0) !== (int) current_member_id()) {
    refuse(403, 'Promoting is the owner\'s or a Data engineer\'s.');
}
$before = (string) one_value($pdo, 'SELECT certification FROM datasets WHERE id = :d', ['d' => $id]);
dl_guard($pdo, static function () use ($pdo, $id, $level, $before): void {
    $pdo->beginTransaction();
    certify($pdo, $id, $level);
    log_activity($pdo, 'certify.set', 'dataset', $id, ['dataset_id' => $id, 'before' => ['certification' => $before], 'after' => ['certification' => $level]]);
    $pdo->commit();
});
dl_done($level === 'certified' ? 'Certified' : 'Promoted', $id, dl_land(return_path('/datasets/' . $d['key'] . '?tab=quality'), 'certified'), 'datasetChanged', ['certification' => $level]);
