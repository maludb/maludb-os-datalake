<?php
/** One load (screen `load-view`): the counts (polled while it runs), the file, the drift, the checks, the error, the rejected lines, Replay · Roll back. Data: l, d, may_write, rejects, rejects_total, page, pending, notice */
$id = (int) $l['load_id'];
$tz = member_timezone();
$manage = $may_write && has_right('loads.manage');
$replayable = $l['landing_purged_at'] === null && $l['blob_id'] !== null;
$actions = '';
if ($manage) {
    $actions .= '<form method="post" action="/loads/queue.php" hx-post="/loads/queue.php" hx-target="#flash" hx-confirm="Replay this load from its landing file?" class="d-inline">'
        . csrf_field() . '<input type="hidden" name="load" value="' . $id . '"><button type="submit" class="btn btn-light btn-touch" id="load-replay-btn"' . ($replayable && !in_array($l['status'], ['queued', 'running'], true) ? '' : ' disabled') . '><i class="feather-rotate-cw me-1"></i>Replay</button></form> ';
    $actions .= '<form method="post" action="/loads/rollback.php" hx-post="/loads/rollback.php" hx-target="#flash" hx-confirm="Roll this load back? Its rows leave the dataset in a new snapshot; the earlier snapshot stays readable as of." class="d-inline">'
        . csrf_field() . '<input type="hidden" name="load" value="' . $id . '"><button type="submit" class="btn btn-outline-danger btn-touch" id="load-rollback-btn"' . (in_array($l['status'], ['loaded', 'no_op'], true) ? '' : ' disabled') . '><i class="feather-corner-up-left me-1"></i>Roll back</button></form>';
}
?>
<?= view('shared/page-header.php', ['id' => 'load-view', 'title' => 'Load ' . $id, 'crumbs' => [['Home', '/'], ['Loads', has_right('loads.manage') ? '/loads' : null], ['Load ' . $id, null]],
    'back' => ['/datasets/' . $l['dataset_key'], $l['dataset_key']], 'action' => $actions]) ?>
<div class="main-content" id="load-view-content" hx-get="/loads/<?= $id ?>" hx-trigger="loadFinished from:body" hx-target="#page-content" hx-swap="innerHTML">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <?= view('loads/partials/counts.php', ['l' => $l]) ?>
            <div class="card mb-3"><div class="card-body">
                <h6 class="fs-12 text-uppercase text-muted">Into</h6>
                <div><?= hx_link('/datasets/' . $l['dataset_key'], e($l['dataset_key']), 'dataset-key fw-semibold', 'id="load-view-dataset"') ?> <span class="text-muted fs-12"><?= e((string) $l['dataset_title']) ?></span></div>
                <div class="fs-12 text-muted mt-1">Mode: <?= $l['mode'] === 'replace' ? 'replace its rows' : 'add rows' ?><?= $l['template_name'] !== null ? ' · mapping "' . e($l['template_name']) . '"' : '' ?>
                    · asked by <?= e((string) ($l['requested_by_name'] ?? 'the lake')) ?> · queued <?= e(format_ts($l['queued_at'], $tz)) ?></div>
                <?php if ($l['rolled_back_at'] !== null): ?>
                    <div class="alert alert-dark fs-12 mt-2 mb-0" id="load-view-rolled-back">Rolled back <?= e(format_ts($l['rolled_back_at'], $tz)) ?><?= $l['rolled_back_by_name'] ? ' by ' . e($l['rolled_back_by_name']) : '' ?>
                        — snapshot <?= (int) $l['rollback_snapshot_id'] ?>. The rows are still readable as of snapshot <?= (int) $l['snapshot_id'] ?> until it expires.</div>
                <?php endif; ?>
            </div></div>
            <?= view('loads/partials/drift.php', ['l' => $l, 'pending' => $pending]) ?>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3" id="load-file"><div class="card-body">
                <h6 class="fs-12 text-uppercase text-muted">The file</h6>
                <?php if ($l['source_id'] === null && $l['file_name'] === null): ?><div class="fs-12 text-muted">—</div><?php else: ?>
                    <div class="fw-semibold text-break" id="load-file-name"><?= e((string) ($l['file_name'] ?? 'from source ' . (int) $l['source_id'])) ?></div>
                    <div class="fs-12 text-muted"><?= $l['file_bytes'] === null ? '' : e(bytes_words((int) $l['file_bytes'])) . ' · ' ?>sha256 <span class="font-monospace" id="load-file-sha"><?= e(substr((string) $l['landing_sha256'], 0, 12)) ?></span></div>
                <?php endif; ?>
                <?php if (!$replayable): ?><div class="badge bg-soft-dark text-dark mt-2" id="load-file-purged">Landing purged — not replayable</div><?php endif; ?>
                <div class="mt-2 fs-12">Checks: <?php $chk = CHECKS_OUTCOME[$l['checks_outcome']] ?? null; ?><?= $chk === null ? '<span class="text-muted" id="load-checks">none — no contract checks this dataset yet</span>' : '<span class="badge bg-soft-' . e($chk[0]) . ' text-' . e($chk[0]) . '" id="load-checks">' . e($chk[1]) . '</span>' ?></div>
            </div></div>
            <?php if ($l['error'] !== null): ?>
                <div class="alert alert-danger" id="load-error"><div class="fw-semibold">What went wrong</div><div class="fs-12"><?= e((string) $l['error']) ?></div></div>
            <?php endif; ?>
        </div>
    </div>
    <?= view('loads/partials/rejects.php', ['l' => $l, 'rejects' => $rejects, 'total' => $rejects_total, 'page' => $page, 'raw' => $may_write]) ?>
</div>
