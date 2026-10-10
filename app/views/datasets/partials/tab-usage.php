<?php
/**
 * Usage (slice 1 frames it; slice 3's query runs fill it): queries a day for 30 days as a 30-cell strip, the people and agents who read it
 * most, and the datasets read with it — dataset_usage() (the wall the view's and the functions'). Zeros until slice 3 records runs. Data: d, usage, here
 */
$total = array_sum(array_column($usage['daily'], 'queries'));
?>
<div class="card mb-3" id="dataset-usage"><div class="card-body">
    <div class="d-flex justify-content-between align-items-baseline mb-2"><h6 class="fs-12 text-uppercase text-muted mb-0">Queries a day, 30 days</h6>
        <span class="fs-12 text-muted" id="dataset-usage-total"><?= e(count_words($total)) ?> in all</span></div>
    <?= view('datasets/partials/usage-strip.php', ['daily' => $usage['daily']]) ?>
    <?php if ($total === 0): ?><div class="fs-12 text-muted mt-2" id="dataset-usage-none">Nobody has queried it in 30 days<?= nav_built('/sql/index.php') ? '' : ' — queries are counted once the SQL editor (slice 3) runs them' ?>.</div><?php endif; ?>
</div></div>
<div class="row g-3">
    <div class="col-md-6"><div class="card h-100" id="dataset-usage-users"><div class="card-body">
        <h6 class="fs-12 text-uppercase text-muted mb-2">Who reads it most</h6>
        <?php if ($usage['frequent_users'] === []): ?><div class="fs-12 text-muted">Nobody yet.</div><?php endif; ?>
        <?php foreach ($usage['frequent_users'] as $u): ?>
            <div class="d-flex justify-content-between fs-13 py-1"><span><?= e($u['name']) ?><?= $u['member_kind'] === 'agent' ? ' <span class="badge text-bg-info">agent</span>' : '' ?></span><span class="text-muted"><?= (int) $u['queries'] ?></span></div>
        <?php endforeach; ?>
    </div></div></div>
    <div class="col-md-6"><div class="card h-100" id="dataset-usage-joined"><div class="card-body">
        <h6 class="fs-12 text-uppercase text-muted mb-2">Read together with</h6>
        <?php if ($usage['joined_with'] === []): ?><div class="fs-12 text-muted">Nothing yet.</div><?php endif; ?>
        <?php foreach ($usage['joined_with'] as $j): ?>
            <div class="d-flex justify-content-between fs-13 py-1"><?= hx_link(with_back('/datasets/' . $j['key'], $here), e($j['key']), 'dataset-key text-reset') ?><span class="text-muted"><?= (int) $j['times'] ?>×</span></div>
        <?php endforeach; ?>
    </div></div></div>
</div>
