<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's or key's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'digest' => (bool) $p['digest'], 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_key(array $k): array
{
    return ['api_key' => (int) $k['id'], 'label' => $k['label'], 'rate_per_minute' => (int) $k['rate_per_minute'], 'rate_per_day' => (int) $k['rate_per_day'],
            'allow_sql' => (bool) $k['allow_sql'], 'rotated_from' => $k['rotated_from'] !== null ? (int) $k['rotated_from'] : null,
            'calls_today' => (int) ($k['calls_today'] ?? 0), 'created_at' => json_ts($k['created_at']), 'last_used_at' => json_ts($k['last_used_at'] ?? null),
            'expires_at' => json_ts($k['expires_at'] ?? null), 'revoked' => ($k['revoked_at'] ?? null) !== null, 'live' => token_live($k)];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'record_type' => $n['record_type'],
            'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'dataset_id' => ($n['dataset_id'] ?? null) !== null ? (int) $n['dataset_id'] : null,
            'incident_id' => ($n['incident_id'] ?? null) !== null ? (int) $n['incident_id'] : null,
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at']), 'url' => notification_record_url($n)];
}

/** The screen a notification points at, by the record it names (null when none) — the one route map of the lake's record kinds. */
function notification_record_url(array $n): ?string
{
    $id = ($n['record_id'] ?? null) !== null ? (int) $n['record_id'] : null;
    $byType = $id === null ? null : match ($n['record_type'] ?? null) {
        'dataset' => '/datasets/' . $id, 'incident' => '/incidents/' . $id, 'load' => '/loads/' . $id, 'source' => '/sources/' . $id,
        'access_request' => '/access/requests', 'export' => '/exports', 'proposal' => '/steward', 'erasure' => '/admin/erasures/' . $id,
        'saved_query' => '/queries/' . $id, 'transform' => '/transforms/' . $id, 'metric' => '/metrics/' . $id, 'check' => '/quality',
        default => null,
    };
    if ($byType !== null) {
        return $byType;
    }
    if (($n['incident_id'] ?? null) !== null) {
        return '/incidents/' . (int) $n['incident_id'];
    }
    if (($n['dataset_id'] ?? null) !== null) {
        return '/datasets/' . (int) $n['dataset_id'];
    }
    return null;
}

/** A notice kind's icon (db/014's sixteen kinds). */
function notification_icon(string $kind): string
{
    return ['check_failed' => 'feather-x-circle', 'incident_assigned' => 'feather-alert-triangle', 'freshness_missed' => 'feather-clock', 'schema_changed' => 'feather-columns',
            'access_requested' => 'feather-unlock', 'access_decided' => 'feather-lock', 'certification_lost' => 'feather-award', 'deprecated' => 'feather-archive',
            'export_ready' => 'feather-download', 'load_failed' => 'feather-alert-octagon', 'proposal' => 'feather-feather', 'steward_note' => 'feather-file-text',
            'admin_read' => 'feather-eye', 'erasure_done' => 'feather-trash-2', 'mention' => 'feather-at-sign', 'digest' => 'feather-inbox'][$kind] ?? 'feather-bell';
}
