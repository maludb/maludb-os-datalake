<?php
declare(strict_types=1);
/** Action `dataset_unfollow` (log dataset.unfollow — one's own follow; discover): **dataset**. Not following is a no-op. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
dl_handler_begin();
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$id = (int) $d['id'];
$me = (int) current_member_id();
$was = my_follow($pdo, $id);
dl_guard($pdo, static function () use ($pdo, $id, $me, $was): void {
    $pdo->beginTransaction();
    dataset_unfollow($pdo, $id, $me);
    if ($was !== null) {
        log_activity($pdo, 'dataset.unfollow', 'dataset', $id, ['before' => ['what' => $was['what']]]);
    }
    $pdo->commit();
});
dl_done($was === null ? 'You were not following ' . $d['key'] : 'You no longer follow ' . $d['key'], $id, dl_land(return_path('/datasets/' . $id), 'unfollowed'), 'datasetChanged');
