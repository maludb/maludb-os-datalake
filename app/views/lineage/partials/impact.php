<?php
/**
 * What a change to this dataset would reach (#lineage-impact): the datasets, the metrics, the transforms and the saved queries downstream (each a link), the applications that read it
 * (named only to its owner or a grants manager), and a count of what the viewer cannot see. Data: impact
 */
$any = $impact['datasets'] !== [] || $impact['metrics'] !== [] || $impact['transforms'] !== [] || $impact['saved_queries'] !== [] || (int) $impact['consumers_count'] > 0 || (int) $impact['hidden'] > 0;
?>
<div class="card" id="lineage-impact"><div class="card-header"><h5 class="card-title mb-0">If this changes</h5></div><div class="card-body">
<?php if (!$any): ?>
    <div class="fs-12 text-muted" id="lineage-impact-empty">Nothing is built on it: no dataset, metric, transform, saved query or application reads it downstream.</div>
<?php else: ?>
    <div class="row g-3">
        <?php if ($impact['datasets'] !== []): ?><div class="col-12 col-md-6" id="lineage-impact-datasets"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Datasets</div>
            <?php foreach ($impact['datasets'] as $x): ?><div class="mb-1"><?= hx_link('/datasets/' . $x['key'], e((string) $x['key']), 'dataset-key') ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($impact['metrics'] !== []): ?><div class="col-12 col-md-6" id="lineage-impact-metrics"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Metrics</div>
            <?php foreach ($impact['metrics'] as $x): ?><div class="mb-1"><?= hx_link('/metrics/' . (int) $x['id'], e((string) $x['title']), '') ?><?= $x['certified'] ? ' <span class="badge bg-soft-success text-success">Certified</span>' : '' ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($impact['transforms'] !== []): ?><div class="col-12 col-md-6" id="lineage-impact-transforms"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Transforms</div>
            <?php foreach ($impact['transforms'] as $x): ?><div class="mb-1"><?= hx_link('/transforms/' . (int) $x['id'], e((string) $x['name']), 'font-monospace') ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($impact['saved_queries'] !== []): ?><div class="col-12 col-md-6" id="lineage-impact-queries"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Saved queries</div>
            <?php foreach ($impact['saved_queries'] as $x): ?><div class="mb-1"><?= hx_link('/queries/' . (int) $x['id'], e((string) $x['title']), '') ?><?= $x['verified'] ? ' <span class="badge bg-soft-success text-success">Verified</span>' : '' ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ((int) $impact['consumers_count'] > 0): ?><div class="col-12 col-md-6" id="lineage-impact-consumers"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Applications that read it</div>
            <?php if ($impact['consumers'] !== null): foreach ($impact['consumers'] as $x): ?><div class="mb-1"><code><?= e((string) $x) ?></code></div><?php endforeach; else: ?>
                <div class="fs-12 text-muted"><?= (int) $impact['consumers_count'] ?> application<?= (int) $impact['consumers_count'] === 1 ? '' : 's' ?></div><?php endif; ?></div><?php endif; ?>
    </div>
    <?php if ((int) $impact['hidden'] > 0): ?><div class="mt-3" id="lineage-impact-hidden"><span class="badge bg-soft-secondary text-secondary"><?= (int) $impact['hidden'] ?> hidden</span>
        <span class="fs-12 text-muted">more downstream that you may not see.</span></div><?php endif; ?>
<?php endif; ?>
</div></div>
