<?php
declare(strict_types=1);
/**
 * Action `dataset_retire` (log dataset.retire — a sandbox dataset: its owner; any other: an engineer; confirm; PAUSES FOR AN AGENT —
 * deletion): **dataset**. The entry stays and its key is never reused; it leaves the catalog for everyone (no level reaches a retired
 * dataset, db/008) and the search index. Nothing is deleted from the lake here (retention and erasure are slice 8's). No undo.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
if ($d['zone'] === 'sandbox' ? $d['my_level'] !== 'own' : !has_right('catalog.edit')) {
    refuse(403, $d['zone'] === 'sandbox' ? 'Only its owner retires a sandbox dataset.' : 'Only a data engineer retires ' . $d['key'] . '.');
}
$id = (int) $d['id'];
dl_guard($pdo, static function () use ($pdo, $id, $d): void {
    $pdo->beginTransaction();
    dataset_retire($pdo, $id);
    log_activity($pdo, 'dataset.retire', 'dataset', $id, ['before' => ['status' => $d['status']], 'after' => ['status' => 'retired', 'key' => $d['key']]]);
    $pdo->commit();
});
dl_done('Retired ' . $d['key'] . ' — its key is never used again', $id, dl_land(return_path($d['zone'] === 'sandbox' ? '/browse?owner=me' : '/browse'), 'retired'), 'datasetChanged');
