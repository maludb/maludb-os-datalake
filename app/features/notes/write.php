<?php
declare(strict_types=1);

/** Notes' writes (note_add — discover on the record's dataset; note_delete — the author or catalog.edit). Returns the new note's id. */
function note_add(PDO $pdo, string $kind, int $id, string $body, int $by): int
{
    $st = $pdo->prepare('INSERT INTO notes (record_type, record_id, member_id, body) VALUES (:k, :id, :by, :b) RETURNING id');
    $st->execute(['k' => $kind, 'id' => $id, 'by' => $by, 'b' => $body]);
    return (int) $st->fetchColumn();
}

function note_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM notes WHERE id = :id')->execute(['id' => $id]);
}
