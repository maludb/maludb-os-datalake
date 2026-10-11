<?php
/**
 * Transforms (screen `transform-list`; transforms-products-lineage.md; the sources' shape — slice 6): Enabled / Disabled as chips, then the transforms as CARDS — name, the dataset it
 * makes, the datasets it reads, mode and schedule, the last run's status and time — paged 24, and New transform. Data: filters, page, result, notice
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/transforms' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chip = static fn (string $id, string $label, bool $on, string $href): string => hx_link($href, $label, 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="transform-list-facet-' . e($id) . '"');
$more = $page * TRANSFORM_PAGE < $result['total'];
$here = $url(['page' => $page > 1 ? $page : null]);
?>
<?= view('shared/page-header.php', ['id' => 'transform-list', 'title' => 'Transforms', 'crumbs' => [['Home', '/'], ['Transforms', null]],
    'action' => hx_link('/transforms/new', '<i class="feather-plus me-1"></i>New transform', 'btn btn-primary btn-touch', 'id="transform-list-add-btn"')]) ?>
<div class="main-content" id="transform-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">State</div>
        <div class="facet-chips" id="transform-list-states">
            <?= $chip('enabled-any', 'All', !isset($filters['enabled']), $url(['enabled' => null, 'page' => null])) ?>
            <?= $chip('enabled-yes', 'Enabled', ($filters['enabled'] ?? null) === 'yes', $url(['enabled' => 'yes', 'page' => null])) ?>
            <?= $chip('enabled-no', 'Disabled', ($filters['enabled'] ?? null) === 'no', $url(['enabled' => 'no', 'page' => null])) ?>
        </div></div>
    <?php foreach (['q' => 'Name', 'dataset' => 'Dataset', 'owner' => 'Owner'] as $k => $label): if (!isset($filters[$k])) { continue; } ?>
        <?= hx_link($url([$k => null, 'page' => null]), e($label . ': ' . ($k === 'q' ? $filters[$k] : '#' . $filters[$k])) . ' <i class="feather-x"></i>', 'btn btn-touch btn-sm btn-primary mb-2 me-1', 'id="transform-list-filter-' . e($k) . '" title="Remove"') ?>
    <?php endforeach; ?>
    <div class="fs-12 text-muted mb-2" id="transform-list-total"><?= (int) $result['total'] ?> transform<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="transform-list-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-shuffle"></i></span>
            <div><div class="fw-semibold">No transforms here</div><div class="fs-12 text-muted">A transform is a SQL over datasets that makes another dataset — a product, a curated table or a cut in your sandbox — and keeps it up to date.</div></div>
            <?= hx_link('/transforms/new', 'Write the first transform', 'btn btn-primary btn-touch ms-md-3', 'id="transform-list-empty-add"') ?></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="transform-list-results">
            <?php foreach ($result['rows'] as $r): ?>
                <div class="col"><?= view('transforms/partials/card.php', ['r' => $r, 'back' => $here]) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="transform-list-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="transform-list-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="transform-list-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
