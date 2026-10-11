<?php
declare(strict_types=1);

/**
 * Access — the badges and the JSON whitelists (access-policies.md "Status vocabulary"). A row filter's TEXT is shown to the grant's
 * granter-side readers only (the owner, grants.manage — the grants screen and the Access tab's list); a reader is told "rows filtered".
 */

/** A level as a badge: discover light, read info, read_unmasked primary, write warning, own success. [class, label] */
function level_badge(string $level): array
{
    return match ($level) {
        'discover' => ['bg-light text-dark border', 'Discover'],
        'read' => ['bg-info', 'Read'],
        'read_unmasked' => ['bg-primary', 'Read unmasked'],
        'write' => ['bg-warning text-dark', 'Write'],
        'own' => ['bg-success', 'Own'],
        default => ['bg-secondary', 'No access'],
    };
}

/** A request's status: pending warning "Waiting", approved success, denied danger, withdrawn secondary, expired dark. */
function request_status_badge(string $status): array
{
    return match ($status) {
        'pending' => ['bg-warning text-dark', 'Waiting'],
        'approved' => ['bg-success', 'Approved'],
        'denied' => ['bg-danger', 'Denied'],
        'withdrawn' => ['bg-secondary', 'Withdrawn'],
        default => ['bg-dark', 'Expired'],
    };
}

/** A grant's state: live (none), expiring within seven days (outline-warning "Expires {date}"), revoked (dark). [class, label] | null */
function grant_state_badge(array $g): ?array
{
    if ($g['revoked_at'] !== null) {
        return ['bg-dark', 'Revoked'];
    }
    if ($g['expires_at'] !== null && strtotime((string) $g['expires_at']) <= time() + 7 * 86400) {
        return ['border border-warning text-warning bg-transparent', 'Expires ' . format_date(substr((string) $g['expires_at'], 0, 10))];
    }
    return null;
}

/** An icon for a principal kind. */
function principal_icon(string $kind): string
{
    return ['member' => 'feather-user', 'agent' => 'feather-cpu', 'group' => 'feather-users', 'department' => 'feather-briefcase', 'everyone' => 'feather-globe',
            'application' => 'feather-grid'][$kind] ?? 'feather-user';
}

function present_my_access_row(array $r): array
{
    return ['dataset_id' => (int) $r['dataset_id'], 'key' => (string) $r['key'], 'title' => (string) $r['title'], 'level' => (string) $r['level'],
            'through' => access_source_words($r), 'row_filtered' => (bool) $r['row_filtered'], 'masked_columns' => (int) $r['masked_columns'],
            'expires_at' => json_ts($r['expires_at'] ?? null), 'url' => '/datasets/' . (int) $r['dataset_id']];
}

function present_request(array $r): array
{
    $stub = (bool) ($r['is_stub'] ?? false);
    return ['request_id' => (int) $r['request_id'], 'status' => (string) $r['status'], 'level' => (string) $r['level'],
            'dataset' => ['id' => (int) $r['dataset_id'], 'key' => (string) $r['key'], 'title' => (string) $r['title'], 'stub' => $stub],
            'requester' => ['id' => (int) $r['requester_id'], 'name' => $r['requester_name']], 'requester_level' => (string) ($r['requester_level'] ?? 'none'),
            'reason' => (string) $r['reason'], 'wanted_until' => $r['wanted_until'],
            'drafted_by' => $r['drafted_by_agent'] === null ? null : ['id' => (int) $r['drafted_by_agent'], 'name' => $r['drafted_by_name']],
            'decided_by' => $r['decided_by'] === null ? null : ['id' => (int) $r['decided_by'], 'name' => $r['decided_by_name']],
            'decided_at' => json_ts($r['decided_at'] ?? null), 'decision_note' => $r['decision_note'], 'grant_id' => $r['grant_id'] === null ? null : (int) $r['grant_id'],
            'created_at' => json_ts($r['created_at'] ?? null)];
}

/** A grant for JSON: the filter's text only to who may see the ledger (the caller read the row through mcp_grants — the owner, grants.manage, or the grantee). */
function present_grant(array $g): array
{
    return ['grant_id' => (int) $g['grant_id'], 'target' => ['kind' => (string) $g['target_kind'], 'dataset_id' => $g['dataset_id'] === null ? null : (int) $g['dataset_id'],
            'dataset_key' => $g['dataset_key'], 'zone' => $g['zone'], 'tag' => $g['tag_slug'], 'words' => grant_target_words($g)],
            'principal' => ['kind' => (string) $g['principal_kind'], 'id' => $g['principal_id'] === null ? null : (int) $g['principal_id'], 'ref' => $g['principal_ref'],
            'name' => $g['principal_name']],
            'level' => (string) $g['level'], 'row_filter' => $g['row_filter'], 'expires_at' => json_ts($g['expires_at'] ?? null), 'reason' => $g['reason'],
            'granted_by' => $g['granted_by_name'], 'created_at' => json_ts($g['created_at'] ?? null), 'revoked_at' => json_ts($g['revoked_at'] ?? null),
            'revoked_by' => $g['revoked_by_name'], 'access_request_id' => $g['access_request_id'] === null ? null : (int) $g['access_request_id']];
}

/** The Access tab for JSON (list mode: who reads it; explain mode: my reasons as sentences). */
function present_access_tab(array $t): array
{
    $out = ['mode' => $t['mode'], 'level' => $t['level'], 'explain' => access_explain_sentences($t['explain'] ?? [])
            + ['masked_columns' => array_values($t['explain']['masked_columns'] ?? []), 'row_filtered' => ($t['explain']['row_filter'] ?? null) !== null]];
    if ($t['mode'] === 'list') {
        $out['readers'] = array_map(static fn (array $r): array => ['principal_kind' => (string) $r['principal_kind'],
            'principal_id' => $r['principal_id'] === null ? null : (int) $r['principal_id'], 'name' => $r['principal_name'], 'level' => (string) $r['level'],
            'row_filter' => $r['row_filter'], 'masked_columns' => (int) $r['masked_columns'], 'via' => (string) $r['via'],
            'grant_id' => $r['grant_id'] === null ? null : (int) $r['grant_id'], 'expires_at' => json_ts($r['expires_at'] ?? null)], $t['rows']);
    }
    return $out;
}
