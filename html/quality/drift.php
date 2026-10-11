<?php
declare(strict_types=1);
/**
 * Action `drift_decide` (log drift.decide — contracts.manage; PEOPLE; confirm): **dataset** (the curated one), **schema_version** (a pending change
 * of the raw dataset it promotes from), **accept** (yes or no). Accepting DRAFTS the contract's next version (an added column a minor, a removed
 * one a major) for an engineer to publish — never published here; rejecting records the decision (the rescued values stay in _dl_rescued).
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/quality/queries.php';
dl_handler_begin();
require_human();
require_right('contracts.manage');
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$id = (int) $d['id'];
$errors = [];
$sv = dl_int('schema_version', null, 1, PHP_INT_MAX, 'The schema change', $errors);
if (!req_has('accept')) {
    $errors['accept'] = 'Accept or reject.';
}
$accept = dl_yes('accept', false);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$v = one_row($pdo, 'SELECT v.dataset_id, v.version, v.changes FROM schema_versions v WHERE v.id = :v', ['v' => $sv]) ?? respond_not_found('No such schema change.');
require_dataset($pdo, (string) $v['dataset_id'], 'discover');
$draft = dl_guard($pdo, static function () use ($pdo, $id, $sv, $accept, $v): ?int {
    $pdo->beginTransaction();
    $draft = drift_decide($pdo, $id, (int) $sv, $accept, (int) current_member_id());
    $kinds = array_values(array_unique(array_map(static fn (array $c): string => (string) ($c['kind'] ?? ''), json_decode((string) $v['changes'], true) ?: [])));
    log_activity($pdo, 'drift.decide', 'dataset', $id, ['dataset_id' => $id, 'after' => ['schema_version' => (int) $sv, 'of' => (int) $v['dataset_id'], 'version' => (int) $v['version'],
        'decision' => $accept ? 'accepted' : 'rejected', 'changes' => $kinds, 'draft_contract_id' => $draft]]);
    $pdo->commit();
    return $draft;
});
dl_done($accept ? 'Accepted — the contract\'s next version is drafted' : 'Rejected', $id,
    dl_land(return_path($accept ? '/datasets/' . $d['key'] . '/contract' : '/datasets/' . $d['key'] . '?tab=quality'), $accept ? 'drafted' : 'drift_rejected'), 'contractChanged',
    ['draft_contract_id' => $draft]);
