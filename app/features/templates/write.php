<?php
declare(strict_types=1);

/** Writes of saved mappings (uploads-loads.md): a template is a mapping a person names, applied to next month's file in one step. */

/**
 * Save a template: $f = name, kind (csv | xlsx | json | parquet), mapping (columns {source: {name, type, personal_kind, skip}}, and optionally
 * sheet, header_row, dedupe_key). A name is unique (the database's index). Returns its id.
 */
function template_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $mapping = json_encode($f['mapping'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO import_templates (name, kind, mapping, created_by) VALUES (:n, :k, CAST(:m AS jsonb), :by) RETURNING id');
        $st->execute(['n' => $f['name'], 'k' => $f['kind'], 'm' => $mapping, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE import_templates SET name = :n, kind = :k, mapping = CAST(:m AS jsonb) WHERE id = :id')
        ->execute(['n' => $f['name'], 'k' => $f['kind'], 'm' => $mapping, 'id' => $id]);
    return $id;
}

function template_delete(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM import_templates WHERE id = :id')->execute(['id' => $id]);
}
