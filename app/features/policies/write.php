<?php
declare(strict_types=1);

/** The masks — the write (policies.manage; pauses for an agent: external_send). */
function policy_set(PDO $pdo, string $appliesTo, string $mask, int $by): void
{
    $st = $pdo->prepare('UPDATE masking_policies SET mask = :m, updated_by = :by WHERE applies_to = :a');
    $st->execute(['m' => $mask, 'by' => $by, 'a' => $appliesTo]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Not found.');
    }
}
