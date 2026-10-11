<?php
declare(strict_types=1);
/**
 * Action `personal_confirm` (log column.confirm — the owner, the steward or catalog.edit; PEOPLE only, ★15 — an agent proposes): **dataset**,
 * **column**, **accept** (yes or no), personal_kind (another kind than proposed). dl_column_confirm_personal: accepted → the kind, at least
 * confidential, masked below read_unmasked; rejected → not personal. Undo: column_classify.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/personal/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/personal/write.php';
dl_handler_begin();
require_human();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$c = find_personal_column($pdo, (int) $d['id'], (string) (req_val('column') ?? '')) ?? respond_not_found('No such column.');
$me = (int) current_member_id();
if (!may_confirm_personal($c, $me)) {
    refuse(403, 'Confirming personal data is the dataset\'s owner\'s, its steward\'s or a data engineer\'s.');
}
$errors = [];
if (!req_has('accept')) {
    $errors['accept'] = 'Confirm or reject.';
}
$accept = dl_yes('accept');
$kind = null;
if ($accept) {
    $k = (string) (req_val('personal_kind') ?? '');
    if ($k !== '' && !in_array($k, personal_kinds($pdo), true)) {
        $errors['personal_kind'] = 'The personal kind is one of: ' . implode(', ', personal_kinds($pdo)) . '.';
    }
    $kind = $k === '' ? null : $k;
    if ($kind === null && $c['proposed_personal_kind'] === null && $c['personal_kind'] === null) {
        $errors['personal_kind'] = 'Name the personal kind — nothing was proposed.';
    }
    $kind ??= $c['proposed_personal_kind'] ?? $c['personal_kind'];
}
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$cid = (int) $c['column_id'];
$after = dl_guard($pdo, static function () use ($pdo, $cid, $accept, $kind, $c, $d): array {
    $pdo->beginTransaction();
    personal_confirm($pdo, $cid, $accept, $kind);
    $after = column_class_state($pdo, $cid);
    log_activity($pdo, 'column.confirm', 'dataset_column', $cid, ['dataset_id' => (int) $d['id'],
        'before' => ['personal_kind' => $c['personal_kind'], 'proposed' => $c['proposed_personal_kind']], 'after' => ['accepted' => $accept, 'personal_kind' => $after['personal_kind'], 'classification' => $after['classification']]]);
    $pdo->commit();
    return $after;
});
dl_done(($accept ? 'Confirmed: ' . $c['name'] . ' is ' . str_replace('_', ' ', (string) $after['personal_kind']) : 'Rejected: ' . $c['name'] . ' is not personal data'), $cid,
    dl_land(return_path('/access/personal-data'), $accept ? 'confirmed' : 'rejected'), 'datasetChanged', ['accepted' => $accept, 'personal_kind' => $after['personal_kind']]);
