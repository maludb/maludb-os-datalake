<?php
declare(strict_types=1);

/**
 * Groups — the writes (access-policies.md). A department mirror's members are the directory's (dl_group_sync_department on insert and after
 * each sync) — never added or removed here; a member leaves with `left_at`, never DELETE; an archived group's grants stay (they reach nobody).
 */

/** Make or change a group: $f name, key (made from the name when empty), description, kind, department_id. The group's id. */
function group_save(PDO $pdo, ?int $id, array $f, int $by): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO groups (key, name, description, kind, department_id, created_by) VALUES (:k, :n, :d, :kind, :dep, :by) RETURNING id');
        $st->execute(['k' => $f['key'], 'n' => $f['name'], 'd' => $f['description'] ?? null, 'kind' => $f['kind'], 'dep' => $f['department_id'] ?? null, 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE groups SET name = :n, description = :d WHERE id = :id AND archived_at IS NULL')
        ->execute(['n' => $f['name'], 'd' => $f['description'] ?? null, 'id' => $id]);
    return $id;
}

function group_archive(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('UPDATE groups SET archived_at = now() WHERE id = :id AND archived_at IS NULL');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        throw new DomainException('That group is already archived.');
    }
}

/** Add a member (or bring back one who left, or change their role). */
function group_member_add(PDO $pdo, int $group, int $member, string $role, int $by): void
{
    $pdo->prepare("INSERT INTO group_members (group_id, member_id, role, added_by) VALUES (:g, :m, :r, :by)
                   ON CONFLICT (group_id, member_id) DO UPDATE SET role = EXCLUDED.role, added_by = EXCLUDED.added_by,
                          joined_at = CASE WHEN group_members.left_at IS NULL THEN group_members.joined_at ELSE now() END, left_at = NULL")
        ->execute(['g' => $group, 'm' => $member, 'r' => $role, 'by' => $by]);
}

function group_member_remove(PDO $pdo, int $group, int $member): void
{
    $st = $pdo->prepare('UPDATE group_members SET left_at = now() WHERE group_id = :g AND member_id = :m AND left_at IS NULL');
    $st->execute(['g' => $group, 'm' => $member]);
    if ($st->rowCount() === 0) {
        throw new DomainException('They are not a member of this group.');
    }
}
