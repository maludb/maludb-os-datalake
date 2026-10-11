<?php
declare(strict_types=1);

/**
 * Saved queries' writes (slice 3): save (create, or update MY OWN — another's is never changed: copy it), delete (my own; soft — deleted_at),
 * verify / unverify (queries.verify — db/011's dl_saved_query_verify; the badge drops by itself when the SQL changes).
 *   saved_query_save($pdo, $id, $f, $by) → [id, refusal?, datasets]: the SQL dry-run FIRST through the one door (query-service §7) — a SQL
 *     the allow-list refuses can never run and is not saved (the sentence on `sql`); one the POLICY refuses (a dataset I may not read today) is
 *     saved with its refusal shown, never silently; the datasets it reads come from the parse (saved_queries.dataset_ids), never from a person.
 */
require_once dirname(__DIR__) . '/uploads/write.php';     // FieldRefused

function saved_query_save(PDO $pdo, ?int $id, array $f, int $by): array
{
    $r = run_sql($pdo, $by, (string) $f['sql'], null, saved_query_probe_values($f['parameters']), ['dry_run' => true, 'request_id' => editor_request_id()]);
    $refusal = null;
    if ($r['status'] !== 'ok') {
        if (in_array($r['refusal']['rule'], SHAPE_RULES, true) || in_array($r['refusal']['rule'], ['engine_unavailable', 'busy', 'rate', 'concurrency'], true)) {
            throw new FieldRefused(['sql' => ucfirst((string) $r['refusal']['message'])]);
        }
        $refusal = $r['refusal'];
    }
    $keys = $r['datasets'] !== [] ? $r['datasets'] : array_values(array_filter([$r['refusal']['dataset'] ?? null]));
    $ids = [];
    if ($keys !== []) {
        $st = $pdo->prepare('SELECT id FROM datasets WHERE key = ANY (CAST(:k AS text[])) ORDER BY id');
        $st->execute(['k' => text_array_literal($keys)]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
    $vals = ['t' => $f['title'], 'd' => $f['description'], 'q' => $f['question'], 's' => $f['sql'], 'p' => json_encode($f['parameters'], JSON_UNESCAPED_UNICODE),
             'ds' => pg_array_literal($ids), 'sh' => $f['shared'] ? 'true' : 'false'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO saved_queries (owner_member_id, title, description, question, sql, parameters, dataset_ids, shared)
                             VALUES (:o, :t, :d, :q, :s, CAST(:p AS jsonb), CAST(:ds AS bigint[]), CAST(:sh AS boolean)) RETURNING id');
        $st->execute($vals + ['o' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $st = $pdo->prepare('UPDATE saved_queries SET title = :t, description = :d, question = :q, sql = :s, parameters = CAST(:p AS jsonb),
                                    dataset_ids = CAST(:ds AS bigint[]), shared = CAST(:sh AS boolean)
                              WHERE id = :id AND owner_member_id = :o AND deleted_at IS NULL');
        $st->execute($vals + ['id' => $id, 'o' => $by]);
        if ($st->rowCount() === 0) {
            throw new DomainException('Not found.');
        }
    }
    return ['id' => $id, 'refusal' => $refusal, 'datasets' => $keys];
}

function saved_query_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE saved_queries SET deleted_at = now() WHERE id = :id AND deleted_at IS NULL')->execute(['id' => $id]);
}

function query_verify(PDO $pdo, int $id, bool $on): void
{
    $pdo->prepare('SELECT dl_saved_query_verify(:id, CAST(:on AS boolean))')->execute(['id' => $id, 'on' => $on ? 'true' : 'false']);
}
