<?php
declare(strict_types=1);
/**
 * Action `contract_publish` (log contract.publish — contracts.manage; confirm; pauses for an agent: other): **contract** (a draft). The
 * database decides (dl_contract_publish: a breaking change must be a new major — refused in its words otherwise; the previous active
 * deprecated; the dataset's key and ordering recorded; certification rechecked), then the lineage edge raw → curated (`promote`).
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/contracts/write.php';
dl_handler_begin();
require_right('contracts.manage');
$pdo = db();
$cid = (int) (req_val('contract') ?? 0);
$probe = $cid > 0 ? one_row($pdo, 'SELECT dataset_id, status, version FROM contracts WHERE id = :id', ['id' => $cid]) : null;
if ($probe === null) {
    respond_not_found('No such contract.');
}
$d = require_dataset($pdo, (string) $probe['dataset_id'], 'discover');
if ($probe['status'] !== 'draft') {
    refuse(422, 'Only a draft is published — ' . $probe['version'] . ' is ' . $probe['status'] . '.');
}
$r = dl_guard($pdo, static function () use ($pdo, $cid, $d): array {
    $pdo->beginTransaction();
    $r = contract_publish($pdo, $cid, (int) current_member_id());
    log_activity($pdo, 'contract.publish', 'contract', $cid, ['dataset_id' => (int) $d['id'],
        'after' => ['version' => $r['version'], 'breaking' => count($r['breaking']), 'raw' => $r['raw']]]);
    $pdo->commit();
    return $r;
});
dl_done('Published ' . $r['version'], $cid, dl_land(return_path('/datasets/' . $d['key'] . '/contract'), 'published'), 'contractChanged', ['version' => $r['version'], 'breaking' => $r['breaking']]);
