<?php
declare(strict_types=1);

/** The break-glass (access-and-policies §5): my open windows — a dataset's rows for an hour, read unmasked, every query audited. */

function my_admin_reads(PDO $pdo, int $member): array
{
    $st = $pdo->prepare('SELECT a.id AS admin_read_id, a.dataset_id, d.key, d.title, a.started_at, a.expires_at, length(a.reason) AS reason_length
                           FROM admin_reads a JOIN datasets d ON d.id = a.dataset_id
                          WHERE a.member_id = :m AND a.expires_at > now() ORDER BY a.expires_at');
    $st->execute(['m' => $member]);
    return $st->fetchAll();
}

/** My open window on one dataset, or null. */
function my_open_admin_read(PDO $pdo, int $member, int $dataset): ?array
{
    return one_row($pdo, 'SELECT id AS admin_read_id, expires_at FROM admin_reads WHERE member_id = :m AND dataset_id = :d AND expires_at > now() ORDER BY expires_at DESC LIMIT 1',
        ['m' => $member, 'd' => $dataset]);
}
