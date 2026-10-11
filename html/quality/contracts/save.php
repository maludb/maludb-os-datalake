<?php
declare(strict_types=1);
/**
 * Action `contract_draft_save` (log contract.draft — contracts.manage): **dataset** (a curated one), **version** (major.minor.patch), **schema**
 * (a list of name, logical_type, physical_type, required, unique, primary_key, source = {raw key}.{column}, description — or the form's rows
 * with `raw`), key[], ordering_column, sla (freshness, retention, time_to_detect, time_to_notify, time_to_repair as ISO 8601 durations),
 * contract (the draft to update). One draft at a time per dataset: saving again changes it. Every source names one raw dataset — "A curated
 * dataset promotes from one raw dataset — two inputs are a transform". The breaking changes come back (the database's rule).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/contracts/write.php';
dl_handler_begin();
require_right('contracts.manage');
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$id = (int) $d['id'];
if ($d['zone'] !== 'curated') {
    dl_refuse_fields(['dataset' => 'A contract is a curated dataset\'s — it types the promotion from raw.']);
}
$f = contract_fields_from_request($_POST);
[$clean, $errors] = contract_validate($pdo, $id, $f);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$before = contract_draft($pdo, $id);
$cid = dl_guard($pdo, static function () use ($pdo, $id, $f, $before, $d, $clean): int {
    $pdo->beginTransaction();
    $cid = contract_draft_save($pdo, $id, $f, (int) current_member_id());
    $breaking = json_decode((string) one_value($pdo, 'SELECT dl_contract_breaking_changes(:c)', ['c' => $cid]), true) ?: [];
    log_activity($pdo, 'contract.draft', 'contract', $cid, ['dataset_id' => $id,
        'before' => $before === null ? null : ['version' => $before['version'], 'columns' => count((array) $before['schema'])],
        'after' => ['version' => $clean['version'], 'columns' => count($clean['schema']), 'key' => $clean['key'], 'raw' => $clean['raw'], 'breaking' => count($breaking)]]);
    $pdo->commit();
    return $cid;
});
$breaking = json_decode((string) one_value($pdo, 'SELECT dl_contract_breaking_changes(:c)', ['c' => $cid]), true) ?: [];
dl_done('Draft ' . $clean['version'] . ' saved', $cid, dl_land(return_path('/datasets/' . $d['key'] . '/contract'), 'drafted', 'contract-draft'), 'contractChanged',
    ['version' => $clean['version'], 'breaking' => $breaking]);
