<?php
declare(strict_types=1);

/**
 * Groups (access-policies.md; DocCloud's `groups` / `group_members` verbatim, db/006): a custom group, or one mirroring a department —
 * kept by dl_groups_sync_departments() after each directory sync, its members read-only here. A group grants nothing by itself: a grant
 * naming it does (the grants it holds). Reads go through mcp_groups (insiders; an external sees none).
 */

const GROUP_KINDS = ['custom' => 'Custom', 'department' => 'Mirrors a department'];

const GROUP_SELECT = "SELECT g.group_id, g.key, g.name, g.description, g.kind, g.department_id, g.archived_at, g.member_count, dp.name AS department_name,
                             (SELECT string_agg(m.display_name, ', ' ORDER BY m.display_name) FROM group_members gm JOIN mcp_members m ON m.member_id = gm.member_id
                               WHERE gm.group_id = g.group_id AND gm.left_at IS NULL AND gm.role = 'owner') AS owners
                        FROM mcp_groups g LEFT JOIN mcp_departments dp ON dp.department_id = g.department_id";

/** Groups as cards (screen `groups`): live by default; $kind custom|department; $q a name. */
function find_groups(PDO $pdo, ?string $q, ?string $kind, bool $archived = false): array
{
    $where = [$archived ? 'g.archived_at IS NOT NULL' : 'g.archived_at IS NULL'];
    $args = [];
    if ($q !== null && $q !== '') {
        $where[] = '(g.name ILIKE :q OR g.description ILIKE :q)';
        $args['q'] = picker_like($q);
    }
    if ($kind !== null && isset(GROUP_KINDS[$kind])) {
        $where[] = 'g.kind = :k';
        $args['k'] = $kind;
    }
    $st = $pdo->prepare(GROUP_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY g.kind, g.name, g.group_id LIMIT 500');
    $st->execute($args);
    return $st->fetchAll();
}

function find_group(PDO $pdo, int $id): ?array
{
    return one_row($pdo, GROUP_SELECT . ' WHERE g.group_id = :id', ['id' => $id]);
}

/** A group's live members: [member_id, display_name, is_agent, role, joined_at]. */
function group_members(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT gm.member_id, m.display_name, m.is_agent, m.job_title, gm.role, gm.joined_at
                           FROM group_members gm JOIN mcp_members m ON m.member_id = gm.member_id
                          WHERE gm.group_id = :id AND gm.left_at IS NULL ORDER BY (gm.role = \'owner\') DESC, m.display_name');
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/** May I change this group's members or words? grants.manage, or a live owner of a custom group. */
function may_manage_group(PDO $pdo, array $g, int $me): bool
{
    if (has_right('grants.manage')) {
        return true;
    }
    return (string) $g['kind'] === 'custom' && db_bool($pdo, "SELECT EXISTS (SELECT 1 FROM group_members WHERE group_id = :g AND member_id = :m AND left_at IS NULL AND role = 'owner')",
        ['g' => (int) $g['group_id'], 'm' => $me]);
}

/** A key from a name ("Finance analysts" → finance-analysts), unique among groups. */
function group_key_from(PDO $pdo, string $name): string
{
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)), '-');
    $base = $base === '' || !preg_match('/^[a-z0-9]/', $base) ? 'group' : substr($base, 0, 50);
    $key = $base;
    for ($i = 2; db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM groups WHERE key = :k)', ['k' => $key]); $i++) {
        $key = $base . '-' . $i;
    }
    return $key;
}

/** The group picker (live groups, the caller an insider). */
function groups_for_pick(PDO $pdo, string $q, array $params, int $page): array
{
    $where = "archived_at IS NULL AND (:q = '' OR name ILIKE :like OR description ILIKE :like)";
    $r = picker_page($pdo, "SELECT count(*) FROM mcp_groups WHERE $where",
        "SELECT group_id, name, kind, member_count FROM mcp_groups WHERE $where ORDER BY name, group_id", ['q' => $q, 'like' => picker_like($q)], $page);
    $r['rows'] = array_map(static fn (array $g): array => ['id' => (int) $g['group_id'], 'label' => (string) $g['name'],
        'detail' => (int) $g['member_count'] . ' member' . ((int) $g['member_count'] === 1 ? '' : 's'),
        'badge' => $g['kind'] === 'department' ? 'Department' : null, 'color' => 'secondary'], $r['rows']);
    unset($params);
    return $r;
}
