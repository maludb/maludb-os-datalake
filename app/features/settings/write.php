<?php
declare(strict_types=1);

/**
 * A person's own settings — the writes (actions prefs_save, notification_read, notifications_read_all, token_mint, token_revoke, key_mint,
 * key_rotate, key_revoke, key_sql_switch; sso-shell.md). A secret is returned ONCE to the handler that minted it — only its SHA-256 is
 * stored; it never reaches a log, a notification or a later JSON answer. The rules a key obeys are the database's (db/005: the rate
 * defaults, a rotation keeps the old key `key_rotation_overlap_hours`, a key resolves only while its person is admitted).
 */

/** Save the choices ($fields keys present are changed; absent are kept). Answers ['before' => …, 'after' => …] of the changed keys and the prefs. */
function save_prefs(PDO $pdo, int $memberId, array $fields): array
{
    $before = find_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($fields, $before) + $before;
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, digest, updated_at)
                   VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), :dg, now())
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                        text_kinds = EXCLUDED.text_kinds, digest = EXCLUDED.digest, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']),
                   'tk' => $lit($after['text_kinds']), 'dg' => $after['digest'] ? 't' : 'f']);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/** Mark one of the member's own notifications read, or every unread one (null). Returns how many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

/** A new secret: `mcp_` (a person's MCP token) or `dlk_` (an API key) + 48 hex — and its hash. */
function new_secret(string $scope): array
{
    $raw = ($scope === 'api' ? 'dlk_' : 'mcp_') . bin2hex(random_bytes(24));
    return [$raw, hash('sha256', $raw)];
}

/** Mint a person's own MCP token (read-only, the two MCP servers): shown once; only the hash is stored. ['id', 'secret']. */
function mint_token(PDO $pdo, int $memberId, string $label, ?int $days): array
{
    [$raw, $hash] = new_secret('mcp');
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, expires_at)
                         VALUES (:m, :l, :h, 'mcp', CASE WHEN CAST(:d AS integer) IS NULL THEN NULL ELSE now() + make_interval(days => CAST(:d AS integer)) END) RETURNING id");
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => $hash, 'd' => $days]);
    return ['id' => (int) $st->fetchColumn(), 'secret' => $raw];
}

/** Revoke a live MCP token: the caller's own, or anyone's ($any — keys.all). False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $id, bool $any): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND scope = 'mcp' AND revoked_at IS NULL" . ($any ? '' : ' AND member_id = :m') . ' RETURNING id');
    $st->execute(['id' => $id] + ($any ? [] : ['m' => $memberId]));
    return $st->fetchColumn() !== false;
}

/**
 * Mint an API key acting as the person: its limits (null = the business's defaults, filled by the trigger), the SQL switch (the handler
 * has checked sql.editor), an expiry. Shown once; only the hash is stored. ['id', 'secret'].
 */
function mint_key(PDO $pdo, int $memberId, string $label, ?int $perMinute, ?int $perDay, bool $allowSql, ?int $days): array
{
    [$raw, $hash] = new_secret('api');
    $st = $pdo->prepare("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, allow_sql, expires_at)
                         VALUES (:m, :l, :h, 'api', :pm, :pd, :sql, CASE WHEN CAST(:d AS integer) IS NULL THEN NULL ELSE now() + make_interval(days => CAST(:d AS integer)) END) RETURNING id");
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => $hash, 'pm' => $perMinute, 'pd' => $perDay, 'sql' => $allowSql ? 't' : 'f', 'd' => $days]);
    return ['id' => (int) $st->fetchColumn(), 'secret' => $raw];
}

/** Rotate one of the caller's own live keys (dl_key_rotate, db/005): the new key keeps the label, limits and switch; the old one expires after the overlap. ['id', 'secret']. */
function rotate_key(PDO $pdo, int $memberId, int $id): array
{
    if (find_token($pdo, $memberId, $id, 'api') === null) {
        throw new DomainException('Not found.');
    }
    [$raw, $hash] = new_secret('api');
    $st = $pdo->prepare('SELECT dl_key_rotate(:id, :h)');
    $st->execute(['id' => $id, 'h' => $hash]);
    return ['id' => (int) $st->fetchColumn(), 'secret' => $raw];
}

/** Revoke a live API key: the caller's own, or anyone's ($any — keys.all). False when there is none such. */
function revoke_key(PDO $pdo, int $memberId, int $id, bool $any): bool
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND scope = 'api' AND revoked_at IS NULL" . ($any ? '' : ' AND member_id = :m') . ' RETURNING id');
    $st->execute(['id' => $id] + ($any ? [] : ['m' => $memberId]));
    return $st->fetchColumn() !== false;
}

/** Turn a key's SQL switch on or off (the handler has checked sql.editor for on). */
function set_key_sql(PDO $pdo, int $memberId, int $id, bool $allow): void
{
    $st = $pdo->prepare("UPDATE mcp_access_tokens SET allow_sql = :a WHERE id = :id AND member_id = :m AND scope = 'api' AND revoked_at IS NULL RETURNING id");
    $st->execute(['a' => $allow ? 't' : 'f', 'id' => $id, 'm' => $memberId]);
    if ($st->fetchColumn() === false) {
        throw new DomainException('Not found.');
    }
}
