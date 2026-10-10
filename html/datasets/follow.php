<?php
declare(strict_types=1);
/**
 * Action `dataset_follow` (log dataset.follow — follow.use and discover): **dataset**, what[] (schema_changed, check_failed, deprecated,
 * access_decided, certification_lost, load_failed — all by default). Following again changes what one hears about.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/write.php';
dl_handler_begin();
require_right('follow.use');
$pdo = db();
$d = require_dataset($pdo, (string) (req_val('dataset') ?? ''), 'discover');
$what = request_list('what') ?? array_keys(FOLLOW_KINDS);
$bad = array_diff($what, array_keys(FOLLOW_KINDS));
if ($bad !== [] || $what === []) {
    dl_refuse_fields(['what' => 'Follow one or more of: ' . implode(', ', array_keys(FOLLOW_KINDS)) . '.']);
}
$id = (int) $d['id'];
$me = (int) current_member_id();
dl_guard($pdo, static function () use ($pdo, $id, $me, $what): void {
    $pdo->beginTransaction();
    dataset_follow($pdo, $id, $me, $what);
    log_activity($pdo, 'dataset.follow', 'dataset', $id, ['after' => ['what' => array_values($what)]]);
    $pdo->commit();
});
dl_done('You follow ' . $d['key'], $id, dl_land(return_path('/datasets/' . $id), 'followed'), 'datasetChanged', ['what' => array_values($what)]);
