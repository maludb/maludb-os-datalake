<?php
declare(strict_types=1);

/**
 * A person's own settings — the reads (screens `settings`, `notifications`, `settings-tokens`): how they are told (notification_prefs,
 * db/014: email, text, the kinds, the kinds by text, the digest), their in-app notifications, their MCP tokens (scope mcp — the two MCP
 * servers read as them) and their API keys (scope api — the lake's API acts as them, rate-limited, the SQL switch). The writes are in
 * write.php. The application's settings (settings_save) are slice 10's.
 */

/** Every notice a person can be told about, with the sentence the settings screen shows (db/014 notifications.kind — all sixteen). */
const NOTICE_KINDS = [
    'check_failed'       => 'A check fails on a dataset I own, steward or follow',
    'incident_assigned'  => 'An incident is assigned to me',
    'freshness_missed'   => 'A dataset of mine is late',
    'schema_changed'     => 'A dataset I follow changes its columns',
    'access_requested'   => 'Someone asks for access to a dataset I decide',
    'access_decided'     => 'My access request is decided',
    'certification_lost' => 'A dataset I own or follow loses its certification',
    'deprecated'         => 'A dataset I use is deprecated',
    'export_ready'       => 'An export of mine is ready',
    'load_failed'        => 'A load of mine fails',
    'proposal'           => 'The Steward proposes a change to a dataset of mine',
    'steward_note'       => 'The Steward\'s weekly note',
    'admin_read'         => 'An admin opens a dataset of mine with the break-glass',
    'erasure_done'       => 'An erasure I asked for is done',
    'mention'            => 'Someone mentions me in a note',
    'digest'             => 'The daily digest',
];

/** The person's choices, or the table's defaults when they never saved any. */
function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds, digest FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\\{[^']*\\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'digest' => false, 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'digest' => (bool) $r['digest'], 'saved' => true];
}

/** The same, by the spec's name. */
function my_prefs(PDO $pdo, int $memberId): array
{
    return find_prefs($pdo, $memberId);
}

/**
 * What the kernel last said about this person's texts, from our own outbox (this application never sees a phone number):
 * 'no_verified_phone', 'opted_out', 'no_sender' or null when the latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains((string) $r['detail'], $code)) {
            return $code;
        }
    }
    return null;
}

/** The last ten things sent to the person (the outbox: kind, channel, subject, status — never the body). */
function recent_sent(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT id, kind, channel, subject, status, detail, created_at FROM notification_outbox WHERE member_id = :m ORDER BY id DESC LIMIT 10');
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

// ---- notifications (the bell) ----------------------------------------------------------------------------------------
function find_my_notifications(PDO $pdo, int $memberId, bool $unreadOnly = false, int $limit = 50, int $page = 1): array
{
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, read_at, created_at, dataset_id, incident_id FROM mcp_notifications'
        . ($unreadOnly ? ' WHERE read_at IS NULL' : '') . ' ORDER BY (read_at IS NULL) DESC, created_at DESC, notification_id DESC LIMIT ' . $limit . ' OFFSET ' . (max(1, $page) - 1) * $limit);
    $st->execute();
    return $st->fetchAll();
}

// ---- tokens and keys --------------------------------------------------------------------------------------------------
/** My MCP tokens (scope mcp), newest first. */
function find_my_tokens(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT key_id AS id, label, scope, last_used_at, expires_at, revoked_at, created_at FROM mcp_tokens WHERE member_id = :m AND scope = 'mcp' ORDER BY created_at DESC, key_id DESC");
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

/** My API keys (scope api) with their limits, the SQL switch, the key each replaced and today's calls (dl_key_calls_today), newest first. */
function find_my_keys(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare("SELECT key_id AS id, label, scope, rate_per_minute, rate_per_day, allow_sql, rotated_from, calls_today, last_used_at, expires_at, revoked_at, created_at
                           FROM mcp_tokens WHERE member_id = :m AND scope = 'api' ORDER BY created_at DESC, key_id DESC");
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

/** One token or key the caller may act on: their own, or anyone's for a holder of keys.all ($any) — null when there is none such. */
function find_token(PDO $pdo, int $memberId, int $id, string $scope, bool $any = false): ?array
{
    $st = $pdo->prepare('SELECT key_id AS id, member_id, label, scope, rate_per_minute, rate_per_day, allow_sql, rotated_from, last_used_at, expires_at, revoked_at, created_at
                           FROM mcp_tokens WHERE key_id = :id AND scope = :s' . ($any ? '' : ' AND member_id = :m'));
    $st->execute(['id' => $id, 's' => $scope] + ($any ? [] : ['m' => $memberId]));
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Is a key live (neither revoked nor expired)? */
function token_live(array $t): bool
{
    return ($t['revoked_at'] ?? null) === null && (($t['expires_at'] ?? null) === null || strtotime((string) $t['expires_at']) > time());
}

/** The business's default limits for a new key (dl_settings via mcp_settings). */
function key_defaults(PDO $pdo): array
{
    $r = $pdo->query('SELECT key_rate_per_minute, key_rate_per_day FROM mcp_settings')->fetch() ?: [];
    return ['rate_per_minute' => (int) ($r['key_rate_per_minute'] ?? 60), 'rate_per_day' => (int) ($r['key_rate_per_day'] ?? 2000)];
}
