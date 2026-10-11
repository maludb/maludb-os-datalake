<?php
declare(strict_types=1);
/**
 * Action `policy_set` (log policy.set — policies.manage; confirm; pauses for an agent: external_send): **applies_to** (a personal kind,
 * confidential, restricted or rescued), **mask** (full, partial_email, partial, hash, null or year_only). Undo: policy_set with the old mask.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/policies/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/policies/write.php';
dl_handler_begin();
require_right('policies.manage');
$pdo = db();
$errors = [];
$applies = dl_choice('applies_to', null, array_keys(MASK_SUBJECTS), 'What it applies to', $errors, false);
$mask = dl_choice('mask', null, array_keys(MASKS), 'The mask', $errors, false);
if ($errors !== [] || $applies === null || $mask === null) {
    dl_refuse_fields($errors + ['mask' => 'Choose the mask.']);
}
$before = find_masking_policy($pdo, $applies) ?? respond_not_found('No such mask.');
$me = (int) current_member_id();
dl_guard($pdo, static function () use ($pdo, $applies, $mask, $me, $before): void {
    $pdo->beginTransaction();
    policy_set($pdo, $applies, $mask, $me);
    log_activity($pdo, 'policy.set', 'masking_policy', null, ['before' => ['applies_to' => $applies, 'mask' => $before['mask']], 'after' => ['applies_to' => $applies, 'mask' => $mask]]);
    $pdo->commit();
});
dl_done('The mask for ' . (MASK_SUBJECTS[$applies] ?? $applies) . ' is now ' . strtolower(MASKS[$mask][0]), $applies, dl_land('/access/policies', 'saved', 'policy-row-' . $applies), 'accessChanged',
    ['applies_to' => $applies, 'mask' => $mask]);
