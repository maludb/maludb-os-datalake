<?php
/**
 * One way of the lineage as a list (#lineage-up / #lineage-down): each node a dataset (a link) or a source, the edge's via and when it was last seen, what the transform is (a link),
 * and "n hidden" when some of the walk is not the viewer's to discover. Data: hops (lineage_hops), id, title, empty, compact? (the dataset tab: no depth column)
 */
$compact = $compact ?? false;
$nodes = $hops['nodes'];
?>
<div class="card h-100" id="<?= e($id) ?>"><div class="card-header"><h5 class="card-title mb-0"><?= e($title) ?></h5></div>
<?php if ($nodes === [] && (int) $hops['hidden'] === 0): ?>
    <div class="card-body"><div class="fs-12 text-muted" id="<?= e($id) ?>-empty"><?= e($empty) ?></div></div>
<?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($nodes as $n): [$vc, $vw] = lineage_via_badge((string) $n['via']); ?>
        <div class="list-group-item" id="<?= e($id) ?>-<?= $n['kind'] === 'source' ? 'source-' . (int) $n['source_id'] : (int) $n['dataset_id'] ?>">
            <?php if ($n['kind'] === 'source'): ?>
                <i class="feather-link me-1 text-muted"></i><span class="fw-semibold"><?= e((string) ($n['name'] ?? 'A source')) ?></span> <span class="badge bg-soft-secondary text-secondary">source</span>
            <?php else: ?>
                <?= hx_link('/datasets/' . $n['key'], e((string) $n['key']), 'dataset-key fw-semibold') ?> <span class="badge text-bg-<?= e(zone_color((string) $n['zone'])) ?> zone-chip"><?= e((string) $n['zone']) ?></span>
                <?php if (($n['certification'] ?? 'none') === 'certified'): ?><span class="badge bg-soft-success text-success">Certified</span><?php endif; ?>
                <div class="fs-11 text-muted"><?= e((string) $n['title']) ?></div>
            <?php endif; ?>
            <div class="fs-11 text-muted mt-1">
                <span class="badge <?= e($vc) ?>"><?= e($vw) ?></span>
                <?php if (!empty($n['transform_id'])): ?><?= hx_link('/transforms/' . (int) $n['transform_id'], e((string) $n['transform_name']), 'ms-1') ?><?php endif; ?>
                <?php if (!$compact && (int) $n['depth'] > 1): ?><span class="ms-1"><?= (int) $n['depth'] ?> hops</span><?php endif; ?>
                <?php if ($n['last_seen'] !== null): ?><span class="ms-1" title="<?= e(format_ts($n['last_seen'], member_timezone())) ?>">last seen <?= e(ago_words($n['last_seen']) ?? '') ?></span><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ((int) $hops['hidden'] > 0): ?>
        <div class="list-group-item" id="<?= e($id) ?>-hidden"><span class="badge bg-soft-secondary text-secondary"><?= (int) $hops['hidden'] ?> hidden</span>
            <span class="fs-12 text-muted">dataset<?= (int) $hops['hidden'] === 1 ? '' : 's' ?> you may not discover.</span></div>
    <?php endif; ?>
    </div>
<?php endif; ?>
</div>
