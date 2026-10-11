<?php
declare(strict_types=1);

/** Groups — the JSON whitelists. */

function present_group(array $g): array
{
    return ['group_id' => (int) $g['group_id'], 'key' => (string) $g['key'], 'name' => (string) $g['name'], 'description' => $g['description'],
            'kind' => (string) $g['kind'], 'department' => $g['department_id'] === null ? null : ['id' => (int) $g['department_id'], 'name' => $g['department_name']],
            'member_count' => (int) $g['member_count'], 'owners' => $g['owners'], 'archived' => $g['archived_at'] !== null, 'url' => '/groups/' . (int) $g['group_id']];
}

function present_group_member(array $m): array
{
    return ['member_id' => (int) $m['member_id'], 'name' => (string) $m['display_name'], 'is_agent' => (bool) $m['is_agent'], 'role' => (string) $m['role'],
            'joined_at' => json_ts($m['joined_at'] ?? null)];
}
