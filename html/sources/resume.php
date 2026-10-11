<?php
declare(strict_types=1);
/** Action `source_resume` (log source.resume — sources.manage; undone by source_pause): **source** — the ladder cleared; a source paused for `no_connection` tries again at its next pull (or pull it now). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
[$pdo, $s, $me, $target] = source_action_begin();
dl_guard($pdo, static function () use ($pdo, $s, $me, $target): void {
    $pdo->beginTransaction();
    source_resume($pdo, (int) $s['source_id'], $me);
    log_activity($pdo, 'source.resume', 'source', (int) $s['source_id'], ['dataset_id' => $target, 'before' => ['paused_reason' => $s['paused_reason'] === null ? null : mb_substr((string) $s['paused_reason'], 0, 200)]]);
    $pdo->commit();
});
dl_done('Resumed ' . $s['name'], (int) $s['source_id'], dl_land(return_path('/sources/' . (int) $s['source_id']), 'resumed'), 'sourceChanged');
