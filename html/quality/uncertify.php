<?php
declare(strict_types=1);
/**
 * Action `certify_remove` (log certify.remove — checks.manage or the dataset's owner; confirm): **dataset**, **reason** — the badge taken by hand,
 * the reason kept ("Certification lost: removed: …" on the page for 30 days). Logged with the reason's length. Undo: certify.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$id = (int) $d['id'];
if (!has_right('checks.manage') && (int) ($d['owner_member_id'] ?? 0) !== (int) current_member_id()) {
    refuse(403, 'Removing a certification is the owner\'s or a Data engineer\'s.');
}
$errors = [];
$reason = dl_text('reason', null, 500, 'The reason', $errors, true);
if ($reason === null && !isset($errors['reason'])) {
    $errors['reason'] = 'Say why the certification is removed.';
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$before = (string) one_value($pdo, 'SELECT certification FROM datasets WHERE id = :d', ['d' => $id]);
dl_guard($pdo, static function () use ($pdo, $id, $reason, $before): void {
    $pdo->beginTransaction();
    certify_remove($pdo, $id, (string) $reason, (int) current_member_id());
    log_activity($pdo, 'certify.remove', 'dataset', $id, ['dataset_id' => $id, 'before' => ['certification' => $before], 'after' => ['certification' => 'none', 'reason_length' => mb_strlen((string) $reason)]]);
    $pdo->commit();
});
dl_done('Certification removed', $id, dl_land(return_path('/datasets/' . $d['key'] . '?tab=quality'), 'uncertified'), 'datasetChanged', ['certification' => 'none']);
