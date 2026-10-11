<?php
declare(strict_types=1);

/**
 * The masks (access-policies.md screen `policies`; access-and-policies §3): fifteen rows — each personal kind, confidential, restricted,
 * rescued — and how each is masked below read_unmasked. The query service applies them (services/query/policy.py mask_expr); a change is
 * seen from every reader's next query.
 */

/** The masks the database admits, with a person's words and an example of the masked form (as the query service writes it). */
const MASKS = [
    'full'          => ['Hidden', '***'],
    'partial_email' => ['First letter and the domain', 'j***@example.com'],
    'partial'       => ['First and last character', 'J*****n'],
    'hash'          => ['A keyed hash (joins still work)', 'h:3f9a1c0be27d4a65'],
    'null'          => ['Empty', '(empty)'],
    'year_only'     => ['The year only', '1987-01-01'],
];

/** What each row applies to, in words, in the screen's order. */
const MASK_SUBJECTS = [
    'email' => 'Email addresses', 'phone' => 'Phone numbers', 'name' => 'Names', 'address' => 'Postal addresses', 'national_id' => 'National identifiers',
    'payment_card' => 'Payment card numbers', 'bank_account' => 'Bank account numbers', 'birth_date' => 'Birth dates', 'ip_address' => 'IP addresses',
    'member_id' => 'Member identifiers', 'free_text_personal' => 'Free text about a person', 'other' => 'Other personal data',
    'confidential' => 'A confidential column (no personal kind)', 'restricted' => 'A restricted column', 'rescued' => 'Rescued fields (_dl_rescued)',
];

function masking_policies(PDO $pdo): array
{
    $rows = $pdo->query('SELECT p.applies_to, p.mask, p.note, p.updated_at FROM mcp_masking_policies p')->fetchAll();
    $order = array_flip(array_keys(MASK_SUBJECTS));
    usort($rows, static fn (array $a, array $b): int => ($order[$a['applies_to']] ?? 99) <=> ($order[$b['applies_to']] ?? 99));
    return $rows;
}

function find_masking_policy(PDO $pdo, string $appliesTo): ?array
{
    return one_row($pdo, 'SELECT applies_to, mask, note, updated_at FROM mcp_masking_policies WHERE applies_to = :a', ['a' => $appliesTo]);
}
