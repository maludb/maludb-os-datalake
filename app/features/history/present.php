<?php
declare(strict_types=1);

/** The History tab's JSON and record_history's row (the field NAMES a change touched — never a value beyond what the log already holds). */
function present_history_row(array $r): array
{
    $i = static fn ($v): ?int => $v === null ? null : (int) $v;
    if (($r['kind'] ?? 'activity') === 'schema') {
        return ['kind' => 'schema', 'at' => json_ts($r['at'] ?? $r['created_at']), 'schema_version_id' => (int) $r['schema_version_id'], 'version' => (int) $r['version'],
                'snapshot_id' => $i($r['snapshot_id']), 'changes' => $r['changes'], 'breaking' => (bool) $r['breaking'], 'detected_by' => $r['detected_by'],
                'decision' => $r['decision'], 'decided_by' => $r['decided_by_name'] ?? null, 'decided_at' => json_ts($r['decided_at'])];
    }
    $after = is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
    return ['kind' => 'activity', 'at' => json_ts($r['occurred_at']), 'log_id' => (int) $r['log_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor_member_id' => $i($r['actor_member_id']), 'actor_name' => $r['actor_name'], 'source' => $r['source'], 'action' => $r['action'],
            'sentence' => $r['sentence'], 'fields' => array_values(array_diff(array_keys($after), ['title', 'key', 'name', 'label'])), 'after' => $after,
            'agent_run_id' => $i($r['agent_run_id'])];
}

/** A schema version in words: "Version 3 — added region, retyped amount (breaking)". */
function schema_version_sentence(array $v): string
{
    $parts = [];
    foreach ((array) $v['changes'] as $c) {
        $verb = ['added' => 'added', 'removed' => 'removed', 'retyped' => 'retyped', 'renamed' => 'renamed'][$c['kind'] ?? ''] ?? 'changed';
        $parts[] = $verb . ' ' . ($c['column'] ?? '?');
    }
    return 'Schema version ' . (int) $v['version'] . ($parts === [] ? '' : ' — ' . implode(', ', array_slice($parts, 0, 6)) . (count($parts) > 6 ? '…' : ''))
        . (!empty($v['breaking']) ? ' (breaking)' : '') . ' · seen by ' . $v['detected_by'] . ($v['decision'] !== 'none' ? ' · ' . $v['decision'] : '');
}
