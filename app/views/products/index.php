<?php
/**
 * The product gallery (screen `products`): Certified / All as chips, then the products as CARDS (the browse screen's look) — title, key, the certification badge, the health dot, the owner, "data
 * updated", rows, how many metrics are defined over it, and Query this (sql.editor). Paged 24. Data: filters, page, result, may_query
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/products' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chip = static fn (string $id, string $label, bool $on, string $href): string => hx_link($href, $label, 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="products-facet-' . e($id) . '"');
$more = $page * PRODUCT_PAGE < $result['total'];
$here = $url(['page' => $page > 1 ? $page : null]);
$tz = member_timezone();
?>
<?= view('shared/page-header.php', ['id' => 'products', 'title' => 'Products', 'crumbs' => [['Home', '/'], ['Products', null]]]) ?>
<div class="main-content" id="products-content">
    <div class="mb-3"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Show</div>
        <div class="facet-chips" id="products-states">
            <?= $chip('all', 'All products', !isset($filters['certified']), $url(['certified' => null, 'page' => null])) ?>
            <?= $chip('certified', 'Certified', ($filters['certified'] ?? null) === 'yes', $url(['certified' => 'yes', 'page' => null])) ?>
        </div></div>
    <?php if (isset($filters['q'])): ?><?= hx_link($url(['q' => null, 'page' => null]), e('Name: ' . $filters['q']) . ' <i class="feather-x"></i>', 'btn btn-touch btn-sm btn-primary mb-2 me-1', 'id="products-filter-q" title="Remove"') ?><?php endif; ?>
    <div class="fs-12 text-muted mb-2" id="products-total"><?= (int) $result['total'] ?> product<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="products-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-package"></i></span>
            <div><div class="fw-semibold">No product you may see</div><div class="fs-12 text-muted">A product is a dataset made for people to use — built by a transform, described, checked and certified. They appear here as they are made.</div></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="products-list">
            <?php foreach ($result['rows'] as $r): [$dot, $word] = health_dot((string) $r['health']);
                $extra = '<div class="d-flex flex-wrap align-items-center gap-1 mt-1 fs-11"><span class="text-nowrap"><span class="health-dot ' . e($dot) . '" aria-hidden="true"></span> ' . e($word) . '</span>'
                    . '<span class="text-muted ms-1">' . ($r['row_count'] === null ? 'no rows yet' : e(count_words((int) $r['row_count'])) . ' rows') . '</span>'
                    . '<span class="text-muted ms-1" title="' . e($r['data_updated_at'] === null ? '' : format_ts($r['data_updated_at'], $tz)) . '">· data ' . e(ago_words($r['data_updated_at']) ?? 'never loaded') . '</span></div>'
                    . '<div class="d-flex flex-wrap align-items-center gap-2 mt-2 fs-12" id="product-' . (int) $r['dataset_id'] . '-actions">'
                    . ((int) $r['metric_count'] > 0 ? hx_link('/metrics?dataset=' . $r['key'], '<i class="feather-trending-up me-1"></i>' . (int) $r['metric_count'] . ' metric' . ((int) $r['metric_count'] === 1 ? '' : 's'), 'btn btn-light btn-sm btn-touch', 'id="product-' . (int) $r['dataset_id'] . '-metrics"') : '<span class="text-muted">No metrics</span>')
                    . ($may_query ? hx_link('/sql?dataset=' . $r['key'], '<i class="feather-terminal me-1"></i>Query this', 'btn btn-light btn-sm btn-touch', 'id="product-' . (int) $r['dataset_id'] . '-query"') : '') . '</div>'; ?>
                <div class="col"><?= view('shared/record-card.php', ['r' => ['id' => 'product-card-' . (int) $r['dataset_id'], 'title' => $r['title'], 'key' => $r['key'], 'zone' => 'product', 'certification' => $r['certification'],
                    'owner_name' => $r['owner_name'], 'url' => '/datasets/' . $r['key'], 'back' => $here, 'extra' => $extra, 'usage_30d' => (int) $r['usage_30d']]]) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="products-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="products-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="products-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
