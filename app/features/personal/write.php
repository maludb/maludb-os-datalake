<?php
declare(strict_types=1);

/** Personal data — the writes: a column's classification and kind set by a person (column_classify), the classifier's proposal confirmed or rejected (personal_confirm). */

/**
 * Set a column's classification and/or personal kind directly (catalog.edit; any change pauses for an agent — a downgrade widens who sees
 * values). The database keeps a personal column at least confidential (its trigger) and re-derives the dataset (dl_dataset_derive, by trigger).
 * $classification null = unchanged; $personalKind '' = none (cleared), null = unchanged.
 */
function column_classify(PDO $pdo, int $column, ?string $classification, ?string $personalKind, int $by): void
{
    $sets = ["classified_by = 'person'", 'classification_confirmed_by = :by', 'classification_confirmed_at = now()', 'proposed_personal_kind = NULL', 'proposed_confidence = NULL'];
    $args = ['by' => $by, 'id' => $column];
    if ($classification !== null) {
        $sets[] = 'classification = :cl';
        $args['cl'] = $classification;
    }
    if ($personalKind !== null) {
        $sets[] = 'personal_kind = :pk';
        $args['pk'] = $personalKind === '' ? null : $personalKind;
    }
    $st = $pdo->prepare('UPDATE dataset_columns SET ' . implode(', ', $sets) . ' WHERE id = :id AND removed_snapshot_id IS NULL');
    $st->execute($args);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}

/** Accept (with the proposed or another kind) or reject the classifier's proposal — dl_column_confirm_personal (the owner, the steward, catalog.edit). */
function personal_confirm(PDO $pdo, int $column, bool $accept, ?string $kind): void
{
    $pdo->prepare('SELECT dl_column_confirm_personal(:c, :a, :k)')->execute(['c' => $column, 'a' => $accept ? 't' : 'f', 'k' => $kind]);
}

/** A column's classification fields as stored (before / after a change). */
function column_class_state(PDO $pdo, int $column): array
{
    return one_row($pdo, 'SELECT classification, personal_kind, proposed_personal_kind, classified_by, (classification_confirmed_at IS NOT NULL) AS confirmed
                            FROM dataset_columns WHERE id = :id', ['id' => $column]) ?? [];
}
