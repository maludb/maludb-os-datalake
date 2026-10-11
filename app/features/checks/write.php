<?php
declare(strict_types=1);

/**
 * Checks — the writes (contracts-quality.md; actions check_create / check_update / check_disable / check_delete / check_run). A check is a row;
 * its results are the writer's (the `checks` job, the promotion) through dl_check_result_record() — PHP never records a result nor opens an
 * incident. An agent's check is never blocking (dl_checks_guard — db/010 — refuses it; the form hides the switch).
 */
require_once __DIR__ . '/queries.php';

/** Insert ($id null) or update a check: $f = dataset_id, kind, column_name, params (array), dimension, severity, blocking, run_when, description, source. */
function check_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $params = json_encode((object) ($f['params'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO checks (dataset_id, column_name, kind, params, dimension, severity, blocking, run_when, description, source, created_by)
                             VALUES (:d, :c, :k, CAST(:p AS jsonb), :dim, :sev, :b, :w, :desc, :src, :by) RETURNING id');
        $st->execute(['d' => $f['dataset_id'], 'c' => $f['column_name'], 'k' => $f['kind'], 'p' => $params, 'dim' => $f['dimension'], 'sev' => $f['severity'],
            'b' => $f['blocking'] ? 't' : 'f', 'w' => $f['run_when'], 'desc' => $f['description'], 'src' => $f['source'], 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $st = $pdo->prepare('UPDATE checks SET column_name = :c, params = CAST(:p AS jsonb), dimension = :dim, severity = :sev, blocking = :b, run_when = :w,
                                description = :desc WHERE id = :id');
    $st->execute(['c' => $f['column_name'], 'p' => $params, 'dim' => $f['dimension'], 'sev' => $f['severity'], 'b' => $f['blocking'] ? 't' : 'f',
        'w' => $f['run_when'], 'desc' => $f['description'], 'id' => $id]);
    return $id;
}

/** Turn a check off ($enable false) or back on. */
function check_disable(PDO $pdo, int $id, bool $enable): void
{
    $pdo->prepare('UPDATE checks SET enabled = :e WHERE id = :id')->execute(['e' => $enable ? 't' : 'f', 'id' => $id]);
}

/** Delete a check — its results go with it (the foreign key); its incidents keep their history (check_id set NULL). */
function check_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM checks WHERE id = :id')->execute(['id' => $id]);
}

/** Run a dataset's enabled checks now (or one): a `checks` job for the writer — its id. */
function check_run(PDO $pdo, int $dataset, ?int $check, int $by): int
{
    return lake_enqueue($pdo, 'checks', $dataset, $check, $check === null ? ['when' => 'all'] : ['check' => $check], 3);
}
