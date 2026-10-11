<?php
/**
 * Sources (screen `source-list`; sources-pulls.md; the browse screen's shape — slice 1): the connectors and the paused ones as chips, then the sources as
 * CARDS — name, connector icon, the raw dataset it feeds, its schedule, the last pull's status and time, the failures in a row, a paused badge with
 * the reason — paged 24, and Add source. Data: filters, page, result, may_add, notice
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/sources' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chip = static fn (string $id, string $label, bool $on, string $href): string => hx_link($href, $label, 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="source-list-facet-' . e($id) . '"');
$more = $page * SOURCE_PAGE < $result['total'];
$here = $url(['page' => $page > 1 ? $page : null]);
?>
<?= view('shared/page-header.php', ['id' => 'source-list', 'title' => 'Sources', 'crumbs' => [['Home', '/'], ['Sources', null]],
    'action' => $may_add ? hx_link('/sources/new', '<i class="feather-plus me-1"></i>Add source', 'btn btn-primary btn-touch', 'id="source-list-add-btn"') : '']) ?>
<div class="main-content" id="source-list">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-2"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Connectors</div>
        <div class="facet-chips" id="source-list-connectors">
            <?= $chip('connector-all', 'All', !isset($filters['connector']), $url(['connector' => null, 'page' => null])) ?>
            <?php foreach (DL_CONNECTORS_V1 as $c): $on = ($filters['connector'] ?? null) === $c; ?>
                <?= $chip('connector-' . $c, '<i class="' . e(connector_icon($c)) . ' me-1"></i>' . e(connector_label($c)), $on, $url(['connector' => $on ? null : $c, 'page' => null])) ?>
            <?php endforeach; ?>
        </div></div>
    <div class="mb-3"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">State</div>
        <div class="facet-chips" id="source-list-states">
            <?= $chip('paused-any', 'Any', !isset($filters['paused']), $url(['paused' => null, 'page' => null])) ?>
            <?= $chip('paused-yes', 'Paused', ($filters['paused'] ?? null) === 'yes', $url(['paused' => 'yes', 'page' => null])) ?>
            <?= $chip('paused-no', 'Running on schedule', ($filters['paused'] ?? null) === 'no', $url(['paused' => 'no', 'page' => null])) ?>
        </div></div>
    <?php if (isset($filters['q'])): ?>
        <?= hx_link($url(['q' => null, 'page' => null]), e('Name: ' . $filters['q']) . ' <i class="feather-x"></i>', 'btn btn-touch btn-sm btn-primary mb-2 me-1', 'id="source-list-filter-q" title="Remove"') ?>
    <?php endif; ?>
    <div class="fs-12 text-muted mb-2" id="source-list-total"><?= (int) $result['total'] ?> source<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="source-list-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-link"></i></span>
            <div><div class="fw-semibold">No sources here</div><div class="fs-12 text-muted">A source brings data in on its own: a file at an address, an SFTP directory, a sibling application's share, an outside PostgreSQL table.</div></div>
            <?php if ($may_add): ?><?= hx_link('/sources/new', 'Add the first source', 'btn btn-primary btn-touch ms-md-3', 'id="source-list-empty-add"') ?><?php endif; ?></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="source-list-results">
            <?php foreach ($result['rows'] as $r): ?>
                <div class="col"><?= view('sources/partials/card.php', ['r' => $r, 'back' => $here]) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="source-list-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="source-list-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="source-list-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
