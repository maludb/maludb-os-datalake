<?php
/** A load's counts (#load-counts): polled every 2 s from /loads/{id}/status while the writer has it (queued, running), still once final. Data: l */
$final = load_is_final((string) $l['status']);
[$cls, $word] = load_badge((string) $l['status']);
$n = static fn ($v): string => e(count_words((int) $v));
?>
<div class="card mb-3" id="load-counts"<?= $final ? '' : ' hx-get="/loads/' . (int) $l['load_id'] . '/status?was_running=1" hx-trigger="every 2s" hx-target="this" hx-swap="outerHTML"' ?>><div class="card-body">
    <div class="d-flex align-items-center gap-2 mb-2"><span class="badge <?= e($cls) ?>" id="load-status"><?= e($word) ?></span>
        <?php if (!$final): ?><span class="fs-12 text-muted"><?= $l['status'] === 'queued' ? 'Queued — the writer will start it shortly.' : 'The writer is loading it…' ?></span><span class="spinner-border spinner-border-sm text-info" aria-hidden="true"></span><?php endif; ?>
        <?php if ($l['status'] === 'no_op'): ?><span class="fs-12 text-muted">This very file is in the dataset already — nothing was added.</span><?php endif; ?></div>
    <div class="row row-cols-2 row-cols-md-5 g-2 text-center">
        <div><div class="fs-5 fw-semibold" id="load-rows-read"><?= $n($l['rows_read']) ?></div><div class="fs-11 text-muted">read</div></div>
        <div><div class="fs-5 fw-semibold text-success" id="load-rows-loaded"><?= $n($l['rows_loaded']) ?></div><div class="fs-11 text-muted">loaded</div></div>
        <div><div class="fs-5 fw-semibold<?= (int) $l['rows_rejected'] > 0 ? ' text-danger' : '' ?>" id="load-rows-rejected"><?= $n($l['rows_rejected']) ?></div><div class="fs-11 text-muted">lines refused</div></div>
        <div><div class="fs-5 fw-semibold<?= (int) $l['cast_rejects'] > 0 ? ' text-warning' : '' ?>" id="load-cast-rejects"><?= $n($l['cast_rejects']) ?></div><div class="fs-11 text-muted">cells left empty</div></div>
        <div><div class="fs-5 fw-semibold" id="load-rows-rescued"><?= $n($l['rows_rescued']) ?></div><div class="fs-11 text-muted">rows with rescued fields</div></div>
    </div>
    <?php if ($l['snapshot_id'] !== null && $final): ?><div class="fs-11 text-muted mt-2" id="load-snapshot">Snapshot <?= (int) $l['snapshot_id'] ?><?= $l['finished_at'] !== null ? ' · finished ' . e(format_ts($l['finished_at'], member_timezone())) : '' ?></div><?php endif; ?>
</div></div>
