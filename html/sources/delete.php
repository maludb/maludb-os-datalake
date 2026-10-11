<?php
declare(strict_types=1);
/**
 * Action `source_delete` (log source.delete — sources.manage; confirm; pauses for an agent: deletion): **source** — its pulls and its sealed credential
 * go with it; the datasets it fed STAY (their loads lose the source, the dataset's source link is cleared). Refused while a pull runs.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/sources/write.php';
[$pdo, $s, $me, $target] = source_action_begin();
dl_guard($pdo, static function () use ($pdo, $s, $me, $target): void {
    $pdo->beginTransaction();
    $pulls = (int) one_value($pdo, 'SELECT count(*) FROM source_pulls WHERE source_id = :s', ['s' => (int) $s['source_id']]);
    $loads = (int) one_value($pdo, 'SELECT count(*) FROM loads WHERE source_id = :s', ['s' => (int) $s['source_id']]);
    source_delete($pdo, (int) $s['source_id'], $me);
    log_activity($pdo, 'source.delete', 'source', (int) $s['source_id'], ['dataset_id' => $target, 'before' => ['name' => $s['name'], 'connector' => $s['connector'],
        'settings' => source_log_settings((string) $s['connector'], (array) $s['settings'])], 'after' => ['pulls_deleted' => $pulls, 'loads_kept' => $loads]]);
    $pdo->commit();
});
dl_done('Deleted ' . $s['name'] . ' — its dataset stays', (int) $s['source_id'], dl_land(return_path('/sources'), 'deleted'), 'sourceChanged');
