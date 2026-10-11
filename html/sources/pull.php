<?php
declare(strict_types=1);
/**
 * Action `source_pull_now` (log source.pull — sources.manage): **source** — a pull QUEUED for the worker (a source_pulls row with policy.queued; the worker
 * takes it within the minute and lands each file once by its hash, a load for each). Refused: a source that pulls nothing (an upload shape — manual_only),
 * a paused one (resume it first), a pull already queued or running. The pulls are the PHP worker's, never the writer's.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
[$pdo, $s, $me, $target] = source_action_begin();
$pull = dl_guard($pdo, static function () use ($pdo, $s, $me, $target): int {
    $pdo->beginTransaction();
    $p = source_pull_queue($pdo, (int) $s['source_id'], $me);
    log_activity($pdo, 'source.pull', 'source', (int) $s['source_id'], ['dataset_id' => $target, 'after' => ['pull_id' => $p, 'connector' => $s['connector']]]);
    $pdo->commit();
    return $p;
});
dl_done('Queued a pull of ' . $s['name'] . ' — the worker takes it within the minute', (int) $s['source_id'], dl_land(return_path('/sources/' . (int) $s['source_id'] . '?tab=pulls'), 'queued'), 'sourceChanged', ['pull_id' => $pull]);
