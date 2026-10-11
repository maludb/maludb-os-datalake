<?php
/** A metric as a card (id metric-card-{id}): the title, the Certified or Draft badge, the product it is over, the grains and dimensions. Data: r (a find_metrics row), back? */
[$stateKey, $stateClass, $stateWord] = metric_state($r);
$id = (int) $r['metric_id'];
$url = isset($back) ? with_back('/metrics/' . $id, $back) : '/metrics/' . $id;
?>
<div class="card metric-card mb-2" id="metric-card-<?= $id ?>"><div class="card-body py-3">
    <div class="d-flex align-items-start gap-2">
        <span class="avatar-text avatar-md rounded flex-shrink-0" aria-hidden="true"><i class="feather-trending-up"></i></span>
        <div class="min-w-0">
            <div class="fw-semibold text-break"><?= hx_link($url, e((string) $r['title']), 'text-dark', 'id="metric-card-' . $id . '-link"') ?></div>
            <div class="fs-11 text-muted"><code><?= e((string) $r['name']) ?></code></div>
        </div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
        <span class="badge bg-soft-<?= e($stateClass) ?> text-<?= e($stateClass === 'light' ? 'dark' : $stateClass) ?>" id="metric-card-<?= $id ?>-state"><?= $stateKey === 'certified' ? '<i class="feather-check-circle me-1"></i>' : '' ?><?= e($stateWord) ?></span>
    </div>
    <div class="fs-12 mt-2" id="metric-card-<?= $id ?>-product"><i class="feather-package me-1 text-muted"></i><?= hx_link('/datasets/' . $r['dataset_key'], e((string) $r['dataset_key']), 'dataset-key') ?></div>
    <div class="fs-11 text-muted mt-1" id="metric-card-<?= $id ?>-cuts"><i class="feather-calendar me-1"></i><?= $r['time_column'] === null ? 'one number' : e(implode(', ', $r['grains'])) ?>
        <?php if ($r['dimensions'] !== []): ?> · <i class="feather-layers me-1"></i><?= e(implode(', ', $r['dimensions'])) ?><?php endif; ?></div>
    <?php if ($r['owner_name'] !== null): ?><div class="fs-11 text-muted mt-1"><i class="feather-user me-1"></i><?= e((string) $r['owner_name']) ?></div><?php endif; ?>
</div></div>
