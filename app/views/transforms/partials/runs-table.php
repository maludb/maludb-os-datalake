<?php
/**
 * The Runs tab (#transform-runs, table #transform-runs-table): the last 50 runs — when, the version, the status, the rows written, the input snapshots, the output snapshot, and what
 * happened (a failure in words). While one is queued or running the block polls itself every 3 s (GET /transforms/{id}?tab=runs&partial=runs), then stops. Data: t, runs
 */
$id = (int) $t['transform_id'];
$tz = member_timezone();
$busy = false;
foreach ($runs as $r) {
    $busy = $busy || in_array($r['status'], ['queued', 'running'], true);
}
?>
<div id="transform-runs"<?= $busy ? ' hx-get="/transforms/' . $id . '?tab=runs&partial=runs" hx-trigger="every 3s" hx-target="this" hx-swap="outerHTML"' : '' ?>>
<?php if ($runs === []): ?>
    <div class="card"><div class="card-body"><div class="empty-state" id="transform-runs-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-play"></i></span>
        <div><div class="fw-semibold">Not run yet</div><div class="fs-12 text-muted">Run queues one for the writer; a transform with a schedule runs when it is due.</div></div></div></div></div>
<?php else: ?>
    <?php if ($busy): ?><div class="fs-12 text-muted mb-2" id="transform-runs-busy"><span class="spinner-border spinner-border-sm text-info me-1" aria-hidden="true"></span>A run is queued or running…</div><?php endif; ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="transform-runs-table">
        <thead><tr><th>Started</th><th>Version</th><th>Status</th><th class="text-end">Rows written</th><th>Read at</th><th>Made</th><th>What happened</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): [$cls, $word] = transform_run_badge((string) $r['status']); [$code, $words] = transform_error_parts($r['error']); $snaps = $r['input_snapshots']; ?>
            <tr id="transform-run-<?= (int) $r['transform_run_id'] ?>">
                <td class="text-nowrap"><?= e($r['started_at'] === null ? 'queued' : format_ts($r['started_at'], $tz, 'M j, g:i A')) ?></td>
                <td>v<?= (int) $r['version'] ?></td>
                <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
                <td class="text-end"><?= $r['status'] === 'ok' || $r['status'] === 'no_op' ? e(count_words((int) $r['rows_written'])) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-muted text-nowrap"><?= $snaps === [] ? '—' : 'snapshot ' . (int) max(array_map('intval', $snaps)) ?></td>
                <td class="text-muted text-nowrap"><?= $r['output_snapshot'] === null ? '—' : 'snapshot ' . (int) $r['output_snapshot'] ?></td>
                <td class="text-break"><?php if ($code !== null): ?><code class="me-1"><?= e($code) ?></code><?php endif; ?><?= $words === '' ? '<span class="text-muted">—</span>' : e(mb_substr($words, 0, 240)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div></div>
<?php endif; ?>
</div>
