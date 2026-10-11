<?php
declare(strict_types=1);
/** Action `source_pause` (log source.pause — sources.manage; undone by source_resume): **source**, reason — the worker stops pulling it until it is resumed. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
[$pdo, $s, $me, $target] = source_action_begin();
$errors = [];
$reason = dl_text('reason', null, 300, 'The reason', $errors);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
dl_guard($pdo, static function () use ($pdo, $s, $me, $target, $reason): void {
    $pdo->beginTransaction();
    source_pause($pdo, (int) $s['source_id'], $reason, $me);
    log_activity($pdo, 'source.pause', 'source', (int) $s['source_id'], ['dataset_id' => $target, 'after' => ['reason' => $reason === null ? null : mb_substr($reason, 0, 200)]]);
    $pdo->commit();
});
dl_done('Paused ' . $s['name'], (int) $s['source_id'], dl_land(return_path('/sources/' . (int) $s['source_id']), 'paused'), 'sourceChanged');
