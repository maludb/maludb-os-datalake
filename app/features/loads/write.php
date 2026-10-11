<?php
declare(strict_types=1);

/**
 * Writes of loads (uploads-loads.md): a replay and a rollback are JOBS for the writer — PHP never touches the lake. A rollback makes a NEW
 * snapshot without the load's rows (history is never rewritten: the previous snapshot stays readable as of until it expires).
 */

/** Replay a load from its landing file: refused when the landing was purged; queued again (a rolled-back load comes back). Returns the job's id. */
function load_queue(PDO $pdo, int $load, int $by): int
{
    $l = one_row($pdo, 'SELECT id, dataset_id, status, landing_purged_at, blob_id FROM loads WHERE id = :id FOR UPDATE', ['id' => $load])
        ?? throw new DomainException('Not found.');
    $purged = $l['landing_purged_at'] !== null || $l['blob_id'] === null
        || one_value($pdo, 'SELECT deleted_at FROM blobs WHERE id = :b', ['b' => (int) $l['blob_id']]) !== null;
    if ($purged) {
        throw new DomainException('Its landing file was purged — the load cannot be replayed');
    }
    if (in_array($l['status'], ['queued', 'running'], true)) {
        throw new DomainException('The load is ' . $l['status'] . ' already');
    }
    if (one_value($pdo, "SELECT 1 FROM lake_jobs WHERE kind = 'rollback' AND ref_id = :id AND status IN ('queued', 'running')", ['id' => $load]) !== null) {
        throw new DomainException('A rollback of this load is under way — replay it once it is rolled back');
    }
    $pdo->prepare("UPDATE loads SET status = 'queued', queued_at = now(), started_at = NULL, finished_at = NULL, error = NULL,
                          rolled_back_at = NULL, rolled_back_by = NULL WHERE id = :id")->execute(['id' => $load]);
    return lake_enqueue($pdo, 'load', (int) $l['dataset_id'], $load, ['replay' => true], 3);
}

/** Roll a load back: a rollback job for the writer (a new snapshot without the load's rows). Returns the job's id. */
function load_rollback(PDO $pdo, int $load, int $by): int
{
    $l = one_row($pdo, 'SELECT id, dataset_id, status FROM loads WHERE id = :id', ['id' => $load]) ?? throw new DomainException('Not found.');
    if ($l['status'] === 'rolled_back') {
        throw new DomainException('This load was rolled back already');
    }
    if (!in_array($l['status'], ['loaded', 'no_op'], true)) {
        throw new DomainException('A ' . strtolower(LOAD_STATUS[$l['status']][1] ?? $l['status']) . ' load has no rows to roll back');
    }
    if (one_value($pdo, "SELECT 1 FROM lake_jobs WHERE kind = 'load' AND ref_id = :id AND status IN ('queued', 'running')", ['id' => $load]) !== null) {
        throw new DomainException('A replay of this load is under way — roll it back once it has run');
    }
    return lake_enqueue($pdo, 'rollback', (int) $l['dataset_id'], $load, [], 3);
}
