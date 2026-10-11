<?php
/**
 * Metrics (screen `metric-list`): Certified / Draft as chips, then the metrics as CARDS — title, the product it is over, the grains and dimensions, the Certified badge — certified first, paged 24,
 * and New metric for metrics.manage. Data: filters, page, result, may_add, notice
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/metrics' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chip = static fn (string $id, string $label, bool $on, string $href): string => hx_link($href, $label, 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="metric-list-facet-' . e($id) . '"');
$more = $page * METRIC_PAGE < $result['total'];
$here = $url(['page' => $page > 1 ? $page : null]);
?>
<?= view('shared/page-header.php', ['id' => 'metric-list', 'title' => 'Metrics', 'crumbs' => [['Home', '/'], ['Metrics', null]],
    'action' => $may_add ? hx_link('/metrics/new', '<i class="feather-plus me-1"></i>New metric', 'btn btn-primary btn-touch', 'id="metric-list-add-btn"') : '']) ?>
<div class="main-content" id="metric-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Badge</div>
        <div class="facet-chips" id="metric-list-states">
            <?= $chip('certified-any', 'All', !isset($filters['certified']), $url(['certified' => null, 'page' => null])) ?>
            <?= $chip('certified-yes', 'Certified', ($filters['certified'] ?? null) === 'yes', $url(['certified' => 'yes', 'page' => null])) ?>
            <?= $chip('certified-no', 'Draft', ($filters['certified'] ?? null) === 'no', $url(['certified' => 'no', 'page' => null])) ?>
        </div></div>
    <?php foreach (['q' => 'Name', 'dataset' => 'Product', 'owner' => 'Owner'] as $k => $label): if (!isset($filters[$k])) { continue; } ?>
        <?= hx_link($url([$k => null, 'page' => null]), e($label . ': ' . ($k === 'q' ? $filters[$k] : '#' . $filters[$k])) . ' <i class="feather-x"></i>', 'btn btn-touch btn-sm btn-primary mb-2 me-1', 'id="metric-list-filter-' . e($k) . '" title="Remove"') ?>
    <?php endforeach; ?>
    <div class="fs-12 text-muted mb-2" id="metric-list-total"><?= (int) $result['total'] ?> metric<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="metric-list-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-trending-up"></i></span>
            <div><div class="fw-semibold">No metrics here</div><div class="fs-12 text-muted">A metric is a number defined once — revenue, active customers — over a product, cut by the grains and dimensions it allows, and certified so everyone means the same thing by it.</div></div>
            <?php if ($may_add): ?><?= hx_link('/metrics/new', 'Define the first metric', 'btn btn-primary btn-touch ms-md-3', 'id="metric-list-empty-add"') ?><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="metric-list-results">
            <?php foreach ($result['rows'] as $r): ?><div class="col"><?= view('metrics/partials/card.php', ['r' => $r, 'back' => $here]) ?></div><?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="metric-list-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="metric-list-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="metric-list-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
