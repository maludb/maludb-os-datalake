<?php
declare(strict_types=1);

/**
 * The glossary's writes (term_create, term_update, term_approve, term_deprecate — catalog.edit; approve is a person's). A term starts as a
 * draft and an engineer approves it (OpenMetadata's); a live name is unique case-insensitively (the database's index); a deprecated term
 * leaves the pickers and the search index but keeps its columns' links.
 */

/** Make or change a term. $f: name, definition, synonyms (list), parent_id, steward_member_id. Changing an approved term keeps it approved. */
function term_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    $syn = static fn (array $s): string => text_array_literal($s);
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO glossary_terms (name, definition, synonyms, parent_id, steward_member_id, created_by)
                             VALUES (:n, :d, CAST(:s AS text[]), :p, :st, :by) RETURNING id');
        $st->execute(['n' => $f['name'], 'd' => $f['definition'], 's' => $syn($f['synonyms'] ?? []), 'p' => $f['parent_id'] ?? null,
                      'st' => $f['steward_member_id'] ?? null, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $sets = [];
    $args = ['id' => $id];
    foreach (['name' => 'name', 'definition' => 'definition', 'parent_id' => 'parent_id', 'steward_member_id' => 'steward_member_id'] as $k => $col) {
        if (array_key_exists($k, $f)) {
            $sets[] = "$col = :$k";
            $args[$k] = $f[$k];
        }
    }
    if (array_key_exists('synonyms', $f)) {
        $sets[] = 'synonyms = CAST(:synonyms AS text[])';
        $args['synonyms'] = $syn($f['synonyms']);
    }
    if ($sets !== []) {
        $pdo->prepare('UPDATE glossary_terms SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($args);
    }
    return $id;
}

/** Approve a draft (a person; the handler has checked). */
function term_approve(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare("UPDATE glossary_terms SET status = 'approved', approved_by = :by, approved_at = now() WHERE id = :id AND status = 'draft'");
    $st->execute(['by' => $by, 'id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Only a draft term is approved.');
    }
}

/** Deprecate a term (its columns keep the link; it leaves the pickers and the search index). */
function term_deprecate(PDO $pdo, int $id): void
{
    $st = $pdo->prepare("UPDATE glossary_terms SET status = 'deprecated' WHERE id = :id AND status <> 'deprecated'");
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('That term is already deprecated.');
    }
}
