<?php
declare(strict_types=1);

/**
 * Access — the writes (access-policies.md; access-and-policies.md §2, §4). The handler has gated the caller (the right, the level, people
 * only where the manifest says) and validated the fields; these functions write and tell. The database is the referee: one pending request
 * per person and dataset (its unique index), a request decided by its owner or grants.manage and never its requester (dl_access_decide),
 * a grant revoked never deleted (its trigger), the trail written by trigger (dl_grants_trail).
 */

/** A request; its decider(s) told (dl_access_deciders: the owner, else every holder of grants.manage). Returns the request's id. */
function request_create(PDO $pdo, int $requester, int $dataset, string $level, string $reason, ?string $until, ?int $draftedBy): int
{
    try {
        $st = $pdo->prepare('INSERT INTO access_requests (requester_id, dataset_id, level, reason, wanted_until, drafted_by_agent)
                             VALUES (:r, :d, :l, :why, CAST(:u AS date), :ag) RETURNING id');
        $st->execute(['r' => $requester, 'd' => $dataset, 'l' => $level, 'why' => $reason, 'u' => $until, 'ag' => $draftedBy]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') {
            throw new DomainException('A request already waits for this dataset — withdraw it to ask again.');
        }
        throw $e;
    }
    $id = (int) $st->fetchColumn();
    $who = (string) (one_value($pdo, 'SELECT display_name FROM mcp_members WHERE member_id = :m', ['m' => $requester]) ?? 'Someone');
    $key = (string) one_value($pdo, 'SELECT key FROM mcp_datasets WHERE dataset_id = :d', ['d' => $dataset]);
    $title = $who . ' asks to ' . (DL_LEVEL_VERBS[$level] ?? $level) . ' ' . $key;
    $body = mb_substr($reason, 0, 300) . ($draftedBy !== null ? ' (drafted by an agent for them)' : '');
    foreach ($pdo->query('SELECT dl_access_deciders(' . $dataset . ')')->fetchAll(PDO::FETCH_COLUMN) as $decider) {
        if ((int) $decider === $requester) {
            continue;
        }
        $pdo->prepare("SELECT dl_notify(:m, 'access_requested', :t, :b, 'access_request', :id, :d, :actor, :dd)")
            ->execute(['m' => (int) $decider, 't' => $title, 'b' => $body, 'id' => $id, 'd' => $dataset, 'actor' => $requester, 'dd' => 'access_requested:' . $id . ':' . (int) $decider]);
    }
    return $id;
}

/** Withdraw a pending request (its requester; or the agent that drafted it, for the same person). */
function request_withdraw(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare("UPDATE access_requests SET status = 'withdrawn'
                          WHERE id = :id AND status = 'pending' AND (requester_id = :by OR drafted_by_agent = :by2)");
    $st->execute(['id' => $id, 'by' => $by, 'by2' => $by]);
    if ($st->rowCount() === 0) {
        throw new DomainException('Only a waiting request of yours can be withdrawn.');
    }
}

/**
 * Decide a request (dl_access_decide — the owner or grants.manage, never the requester; an approval makes a dataset grant at the asked or
 * another level, with the expiry): the requester told (access_decided — with no dataset gate: they asked, so they know it exists). The grant's id.
 */
function request_decide(PDO $pdo, int $id, bool $approve, ?string $level, ?string $until, ?string $note): ?int
{
    $st = $pdo->prepare('SELECT dl_access_decide(:id, :a, :l, CAST(:u AS timestamptz), :n)');
    $st->execute(['id' => $id, 'a' => $approve ? 't' : 'f', 'l' => $level, 'u' => $until, 'n' => $note]);
    $gid = $st->fetchColumn();
    $r = one_row($pdo, 'SELECT r.requester_id, r.dataset_id, r.level, d.key FROM access_requests r JOIN datasets d ON d.id = r.dataset_id WHERE r.id = :id', ['id' => $id]);
    if ($r !== null) {
        $granted = $approve ? (string) (one_value($pdo, 'SELECT level FROM grants WHERE id = :g', ['g' => (int) $gid]) ?? $r['level']) : null;
        $title = $approve ? 'Approved: you may ' . (DL_LEVEL_VERBS[$granted] ?? $granted) . ' ' . $r['key'] : 'Denied: ' . $r['key'];
        $body = $approve ? ($until !== null ? 'Until ' . format_date(substr($until, 0, 10)) . '. ' : '') . ($note ?? '') : (string) $note;
        $pdo->prepare("SELECT dl_notify(:m, 'access_decided', :t, :b, 'access_request', :id, NULL, :actor, :dd)")
            ->execute(['m' => (int) $r['requester_id'], 't' => $title, 'b' => mb_substr($body, 0, 400), 'id' => $id, 'actor' => current_member_id(), 'dd' => 'access_decided:' . $id]);
    }
    return $gid === false || $gid === null ? null : (int) $gid;
}

/**
 * A grant (★13: its row filter was checked by the handler first — row_filter_check — and is stored as the TEMPLATE, bound per reader at read).
 * $f: target_kind, dataset_id|zone|tag_id, principal_kind, principal_id, level, row_filter, expires_at (UTC), reason. Returns its id.
 */
function grant_set(PDO $pdo, array $f, int $by): int
{
    $st = $pdo->prepare('INSERT INTO grants (target_kind, dataset_id, zone, tag_id, principal_kind, principal_id, level, row_filter, expires_at, reason, granted_by)
                         VALUES (:tk, :ds, :zone, :tag, :pk, :pid, :lvl, :rf, CAST(:exp AS timestamptz), :why, :by) RETURNING id');
    $st->execute(['tk' => $f['target_kind'], 'ds' => $f['dataset_id'] ?? null, 'zone' => $f['zone'] ?? null, 'tag' => $f['tag_id'] ?? null,
                  'pk' => $f['principal_kind'], 'pid' => $f['principal_id'] ?? null, 'lvl' => $f['level'], 'rf' => $f['row_filter'] ?? null,
                  'exp' => $f['expires_at'] ?? null, 'why' => $f['reason'] ?? null, 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** The same live grant already exists (same target, principal, level and filter) — its id, or null. */
function grant_duplicate(PDO $pdo, array $f): ?int
{
    $v = one_value($pdo, 'SELECT id FROM grants WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > now()) AND target_kind = :tk
                             AND dataset_id IS NOT DISTINCT FROM CAST(:ds AS bigint) AND zone IS NOT DISTINCT FROM CAST(:zone AS text)
                             AND tag_id IS NOT DISTINCT FROM CAST(:tag AS bigint) AND principal_kind = :pk AND principal_id IS NOT DISTINCT FROM CAST(:pid AS bigint)
                             AND level = :lvl AND row_filter IS NOT DISTINCT FROM CAST(:rf AS text) LIMIT 1',
        ['tk' => $f['target_kind'], 'ds' => $f['dataset_id'] ?? null, 'zone' => $f['zone'] ?? null, 'tag' => $f['tag_id'] ?? null, 'pk' => $f['principal_kind'],
         'pid' => $f['principal_id'] ?? null, 'lvl' => $f['level'], 'rf' => $f['row_filter'] ?? null]);
    return $v === null ? null : (int) $v;
}

/** Revoke a live grant (revoked_at, revoked_by — never DELETE; the trail by trigger). */
function grant_revoke(PDO $pdo, int $id, int $by): void
{
    $st = $pdo->prepare('UPDATE grants SET revoked_at = now(), revoked_by = :by WHERE id = :id AND revoked_at IS NULL');
    $st->execute(['id' => $id, 'by' => $by]);
    if ($st->rowCount() === 0) {
        throw new DomainException('That grant is already revoked.');
    }
}
