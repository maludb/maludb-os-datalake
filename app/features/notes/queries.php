<?php
declare(strict_types=1);

/**
 * Notes on a record (slice 1; GL's `notes` verbatim): a dataset's notes panel; an incident's (slice 5) and a saved query's (slice 3) render
 * through the same functions and the same two handlers. The wall is mcp_notes' (a dataset's notes only when the caller may discover it).
 * A note's body is never logged — its length is.
 */

const NOTE_KINDS = ['dataset', 'incident', 'saved_query'];
const NOTE_MAX = 8000;

/** The notes on a record, newest first, with their authors' names. */
function find_notes(PDO $pdo, string $kind, int $id, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT n.note_id, n.record_type, n.record_id, n.member_id, m.display_name AS author_name, n.body, n.created_at
                           FROM mcp_notes n LEFT JOIN mcp_members m ON m.member_id = n.member_id
                          WHERE n.record_type = :k AND n.record_id = :id ORDER BY n.created_at DESC, n.note_id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute(['k' => $kind, 'id' => $id]);
    return $st->fetchAll();
}

/** One note the caller may see. */
function find_note(PDO $pdo, int $noteId): ?array
{
    $st = $pdo->prepare('SELECT note_id, record_type, record_id, member_id, body, created_at FROM mcp_notes WHERE note_id = :id');
    $st->execute(['id' => $noteId]);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/**
 * The dataset a note's record belongs to, after the record's own wall: a dataset → itself (the handler gates it); an incident → its dataset
 * (mcp_incidents); a saved query → null (mcp_saved_queries is its wall: the owner's or shared). Throws 'Not found.' for a record the
 * caller may not see.
 */
function note_record_dataset(PDO $pdo, string $kind, int $id): ?int
{
    if ($kind === 'dataset') {
        return $id;
    }
    if ($kind === 'incident') {
        $d = one_value($pdo, 'SELECT dataset_id FROM mcp_incidents WHERE incident_id = :id', ['id' => $id]);
        return $d === null ? throw new DomainException('Not found.') : (int) $d;
    }
    if (one_value($pdo, 'SELECT 1 FROM mcp_saved_queries WHERE saved_query_id = :id', ['id' => $id]) === null) {
        throw new DomainException('Not found.');
    }
    return null;
}

/** Where a note's record lives (the landing after add or delete). */
function note_record_url(string $kind, int $id, ?string $key = null): string
{
    return match ($kind) {
        'dataset'  => '/datasets/' . ($key ?? $id) . '#notes-panel',
        'incident' => '/incidents/' . $id . '#notes-panel',
        default    => '/queries/' . $id . '#notes-panel',
    };
}

/** A note's JSON (the body is the reader's — they may see the record). */
function present_note(array $n): array
{
    return ['note_id' => (int) $n['note_id'], 'record_type' => $n['record_type'], 'record_id' => (int) $n['record_id'],
            'author' => ['member_id' => $n['member_id'] === null ? null : (int) $n['member_id'], 'name' => $n['author_name'] ?? null],
            'body' => $n['body'], 'created_at' => json_ts($n['created_at'])];
}
