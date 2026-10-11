<?php
/** One grant as a ledger row (grant-row-{id}): target, principal with its kind's icon, level, the row filter's template, expiry, granted by, when, reason; Revoke where I may; a revoked row greyed. Data: g, may_revoke */
$id = (int) $g['grant_id'];
$lb = level_badge((string) $g['level']);
$state = grant_state_badge($g);
$revoked = $g['revoked_at'] !== null;
$target = grant_target_words($g);
?>
<tr id="grant-row-<?= $id ?>" class="<?= $revoked ? 'text-muted opacity-50' : '' ?>">
    <td class="text-break"><?php if ($g['target_kind'] === 'dataset' && $g['dataset_id'] !== null): ?><?= hx_link('/datasets/' . (int) $g['dataset_id'] . '?tab=access', '<span class="font-monospace">' . e($target) . '</span>') ?><?php else: ?><span class="font-monospace"><?= e($target) ?></span><?php endif; ?></td>
    <td><i class="<?= e(principal_icon((string) $g['principal_kind'])) ?> me-1" aria-hidden="true"></i><?php if ($g['principal_kind'] === 'group' && $g['principal_id'] !== null): ?><?= hx_link('/groups/' . (int) $g['principal_id'], e((string) $g['principal_name'])) ?><?php else: ?><?= e((string) ($g['principal_name'] ?? $g['principal_kind'])) ?><?php endif; ?>
        <span class="fs-11 text-muted d-block"><?= e((string) $g['principal_kind']) ?></span></td>
    <td><span class="badge <?= e($lb[0]) ?>"><?= e($lb[1]) ?></span><?php if ($state !== null): ?> <span class="badge <?= e($state[0]) ?>"><?= e($state[1]) ?></span><?php endif; ?></td>
    <td class="font-monospace fs-12 text-break"><?= $g['row_filter'] === null ? '<span class="text-muted">—</span>' : e((string) $g['row_filter']) ?></td>
    <td class="fs-12"><?= $g['expires_at'] === null ? '—' : e(format_date(substr((string) $g['expires_at'], 0, 10))) ?></td>
    <td class="fs-12"><?= e((string) ($g['granted_by_name'] ?? '—')) ?><span class="d-block text-muted"><?= e(format_ts((string) $g['created_at'], member_timezone(), 'M j, Y')) ?></span></td>
    <td class="fs-12 text-break"><?= e(mb_substr((string) ($g['reason'] ?? ''), 0, 200)) ?><?php if ($g['access_request_id'] !== null): ?> <span class="text-muted">(a request)</span><?php endif; ?></td>
    <td class="text-end"><?php if (!$revoked && !empty($may_revoke)): ?>
        <form method="post" action="/access/grants/revoke.php" hx-post="/access/grants/revoke.php" hx-target="#flash" hx-confirm="Revoke this grant? It stops at once." id="grant-<?= $id ?>-revoke-form">
            <?= csrf_field() ?><input type="hidden" name="grant" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e(here_url()) ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="grant-<?= $id ?>-revoke">Revoke</button>
        </form>
    <?php elseif ($revoked): ?><span class="fs-11">Revoked <?= e(format_ts((string) $g['revoked_at'], member_timezone(), 'M j, Y')) ?><?= $g['revoked_by_name'] !== null ? ' by ' . e((string) $g['revoked_by_name']) : '' ?></span><?php endif; ?></td>
</tr>
