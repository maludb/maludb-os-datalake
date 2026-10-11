<?php
declare(strict_types=1);

/** Saved queries' JSON (the find_saved_queries / run_saved_query tools' shapes): never another's unshared query, never a policy. */
function present_saved_query_row(array $q): array
{
    $datasets = is_array($q['datasets'] ?? null) ? $q['datasets'] : (json_decode((string) ($q['datasets_json'] ?? '[]'), true) ?: []);
    return ['saved_query_id' => (int) $q['saved_query_id'], 'title' => $q['title'], 'question' => $q['question'], 'owner_member_id' => (int) $q['owner_member_id'],
            'owner_name' => $q['owner_name'] ?? null, 'shared' => (bool) $q['shared'], 'mine' => (int) $q['owner_member_id'] === (int) current_member_id(),
            'verified' => $q['verified_at'] !== null, 'verified_by' => $q['verified_by'] === null ? null : (int) $q['verified_by'],
            'verified_by_name' => $q['verified_by_name'] ?? null, 'verified_at' => json_ts($q['verified_at']), 'unverified_reason' => $q['unverified_reason'],
            'datasets' => array_map(static fn (array $d): array => ['dataset_id' => (int) $d['dataset_id'], 'key' => $d['key']], $datasets),
            'updated_at' => json_ts($q['updated_at']), 'url' => '/queries/' . (int) $q['saved_query_id']];
}

function present_saved_query(array $q): array
{
    return present_saved_query_row($q) + ['description' => $q['description'], 'sql' => $q['sql'],
            'parameters' => array_map(static fn (array $p): array => ['name' => $p['name'], 'type' => $p['type'], 'default' => $p['default'] ?? '',
                'required' => !empty($p['required'])], $q['parameters']), 'created_at' => json_ts($q['created_at'])];
}

/** The badge: [class, words] — Verified by {name} / Unverified — the SQL changed / none. */
function verified_badge(array $q): ?array
{
    if ($q['verified_at'] !== null) {
        return ['bg-soft-success text-success', 'Verified by ' . ($q['verified_by_name'] ?? 'an engineer')];
    }
    if (($q['unverified_reason'] ?? '') === 'the SQL changed after it was verified') {
        return ['badge-outline-warning', 'Unverified — the SQL changed'];
    }
    return null;
}
