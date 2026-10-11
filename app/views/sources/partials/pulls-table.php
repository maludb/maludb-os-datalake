<?php
/**
 * The Pulls tab (#source-pulls, table #source-pulls-table): the last 50 pulls — when it started, the status, items seen, items landed, bytes, requests, the
 * error (≤ 200 characters) and the loads it made, each a link. While a pull is queued or running the block polls itself every 3 s
 * (GET /sources/{id}?tab=pulls&partial=pulls), then stops. Data: s, pulls
 */
$id = (int) $s['source_id'];
$tz = member_timezone();
$busy = false;
foreach ($pulls as $p) {
    $busy = $busy || $p['status'] === 'running';
}
?>
<div id="source-pulls"<?= $busy ? ' hx-get="/sources/' . $id . '?tab=pulls&partial=pulls" hx-trigger="every 3s" hx-target="this" hx-swap="outerHTML"' : '' ?>>
<?php if ($pulls === []): ?>
    <div class="card"><div class="card-body"><div class="empty-state" id="source-pulls-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-download-cloud"></i></span>
        <div><div class="fw-semibold">Not pulled yet</div><div class="fs-12 text-muted">Pull now queues one for the worker; a source with a schedule is pulled when it is due.</div></div></div></div></div>
<?php else: ?>
    <?php if ($busy): ?><div class="fs-12 text-muted mb-2" id="source-pulls-busy"><span class="spinner-border spinner-border-sm text-info me-1" aria-hidden="true"></span>A pull is queued or running…</div><?php endif; ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="source-pulls-table">
        <thead><tr><th>Started</th><th>Status</th><th class="text-end">Seen</th><th class="text-end">Landed</th><th class="text-end">Bytes</th><th class="text-end">Requests</th><th>Loads</th><th>What happened</th></tr></thead>
        <tbody>
        <?php foreach ($pulls as $p): [$cls, $word] = pull_badge((string) $p['status']); $n = (int) $p['load_count']; ?>
            <tr id="pull-<?= (int) $p['pull_id'] ?>">
                <td class="text-nowrap" title="<?= e($p['kind']) ?>"><?= e(format_ts($p['started_at'], $tz, 'M j, g:i A')) ?></td>
                <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
                <td class="text-end"><?= e(count_words((int) $p['items_seen'])) ?></td><td class="text-end"><?= e(count_words((int) $p['items_landed'])) ?></td>
                <td class="text-end"><?= e(bytes_words((int) $p['bytes']) ?? '0 B') ?></td><td class="text-end"><?= (int) $p['http_requests'] ?></td>
                <td class="text-nowrap"><?php if ($n === 0): ?><span class="text-muted">—</span><?php else: ?>
                    <?php foreach ($p['load_ids'] as $lid): ?><?= hx_link('/loads/' . (int) $lid, '#' . (int) $lid, 'me-1') ?><?php endforeach; ?>
                    <?= $n > count($p['load_ids']) ? hx_link('/loads?source=' . $id, '+' . ($n - count($p['load_ids'])) . ' more') : '' ?><?php endif; ?></td>
                <td class="text-break"><?= $p['error'] === null ? '<span class="text-muted">—</span>' : e(mb_substr((string) $p['error'], 0, 200)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
<?php endif; ?>
</div>
