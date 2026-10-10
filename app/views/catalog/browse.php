<?php
/**
 * Browse (screen `browse`; catalog.md): three ways in as chips — Zones · Departments · Tags (counts of what the person may discover) — then
 * the datasets as cards (RecordCard's look): title, key, zone chip, certification badge, the health dot, owner, "data updated", rows; a
 * deprecated card greyed with "use {successor}"; paged 24. Stubs are never listed here (search shows them). Data: filters, page, result, facets, owners, may_add, notice
 */
$url = static function (array $change) use ($filters): string {
    $p = array_filter(array_merge($filters, $change), static fn ($v) => $v !== null && $v !== '');
    return '/browse' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chip = static fn (string $id, string $label, bool $on, string $href): string => hx_link($href, $label, 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="browse-facet-' . e($id) . '"');
$more = $page * DATASET_PAGE < $result['total'];
$here = $url(['page' => $page > 1 ? $page : null]);
$tz = member_timezone();
$deptName = static function (int $id) use ($facets): string { foreach ($facets['departments'] as $d) { if ($d['department_id'] === $id) { return $d['name']; } } return 'Department ' . $id; };
?>
<?= view('shared/page-header.php', ['id' => 'browse', 'title' => 'Browse', 'crumbs' => [['Home', '/'], ['Browse', null]],
    'action' => $may_add ? hx_link('/datasets/new', '<i class="feather-plus me-1"></i>New dataset', 'btn btn-primary btn-touch', 'id="browse-add-btn"') : '']) ?>
<div class="main-content" id="browse-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-2"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Zones</div>
        <div class="facet-chips" id="browse-zones">
            <?= $chip('zone-all', 'All', !isset($filters['zone']), $url(['zone' => null, 'page' => null])) ?>
            <?php foreach ($facets['zones'] as $z => $n): $on = ($filters['zone'] ?? null) === $z; ?>
                <?= $chip('zone-' . $z, e($z) . ' <span class="opacity-75 ms-1">' . (int) $n . '</span>', $on, $url(['zone' => $on ? null : $z, 'page' => null])) ?>
            <?php endforeach; ?>
        </div></div>
    <?php if ($facets['departments'] !== []): ?>
    <div class="mb-2"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Departments</div>
        <div class="facet-chips" id="browse-departments">
            <?php foreach ($facets['departments'] as $dp): $on = ($filters['department'] ?? null) === $dp['department_id']; ?>
                <?= $chip('department-' . $dp['department_id'], e($dp['name']) . ' <span class="opacity-75 ms-1">' . (int) $dp['count'] . '</span>', $on, $url(['department' => $on ? null : $dp['department_id'], 'page' => null])) ?>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <?php if ($facets['tags'] !== []): ?>
    <div class="mb-3"><div class="fs-11 text-uppercase text-muted fw-semibold mb-1">Tags <?= hx_link('/tags', 'all tags', 'ms-1 text-lowercase fw-normal', 'id="browse-tags-link"') ?></div>
        <div class="facet-chips" id="browse-tags">
            <?php foreach ($facets['tags'] as $t): $on = ($filters['tag'] ?? null) === $t['slug']; ?>
                <?= $chip('tag-' . $t['slug'], e($t['label']) . ' <span class="opacity-75 ms-1">' . (int) $t['count'] . '</span>', $on, $url(['tag' => $on ? null : $t['slug'], 'page' => null])) ?>
            <?php endforeach; ?>
        </div></div>
    <?php endif; ?>
    <?php foreach (['owner' => 'Owner', 'status' => 'Status', 'q' => 'Name'] as $k => $label): if (!isset($filters[$k])) { continue; }
        $value = $k === 'owner' ? ($filters[$k] === 'me' ? 'me' : ($filters[$k] === 'none' ? 'nobody' : ($owners[(int) $filters[$k]] ?? '#' . $filters[$k]))) : (string) $filters[$k]; ?>
        <?= hx_link($url([$k => null, 'page' => null]), e($label . ': ' . $value) . ' <i class="feather-x"></i>', 'btn btn-touch btn-sm btn-primary mb-2 me-1', 'id="browse-filter-' . e($k) . '" title="Remove"') ?>
    <?php endforeach; ?>
    <div class="fs-12 text-muted mb-2" id="browse-total"><?= (int) $result['total'] ?> dataset<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="browse-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-database"></i></span>
            <div><div class="fw-semibold">Nothing here you may see</div><div class="fs-12 text-muted">Try another zone, department or tag — or search: a dataset you may ask for shows there as a locked card.</div></div></div></div></div>
    <?php else: ?>
        <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-2" id="browse-results">
            <?php foreach ($result['rows'] as $r): [$dot, $word] = health_dot((string) $r['health']); $deprecated = $r['status'] === 'deprecated'; $st = dataset_status_badge((string) $r['status']);
                $extra = '<div class="d-flex flex-wrap align-items-center gap-1 mt-1 fs-11">'
                    . ($st !== null ? '<span class="badge ' . e($st[0]) . '">' . e($st[1]) . '</span>' : '')
                    . '<span class="text-nowrap"><span class="health-dot ' . e($dot) . '" aria-hidden="true"></span> ' . e($word) . '</span>'
                    . '<span class="text-muted ms-1">' . ($r['row_count'] === null ? 'no rows yet' : e(count_words((int) $r['row_count'])) . ' rows') . '</span>'
                    . '<span class="text-muted ms-1" title="' . e($r['data_updated_at'] === null ? '' : format_ts($r['data_updated_at'], $tz)) . '">· data ' . e(ago_words($r['data_updated_at']) ?? 'never loaded') . '</span>'
                    . '</div>' . ($deprecated && $r['successor_key'] !== null ? '<div class="fs-12 mt-1" id="browse-card-' . (int) $r['dataset_id'] . '-successor">Use ' . hx_link('/datasets/' . $r['successor_key'], e($r['successor_key']), 'dataset-key') . '</div>' : ''); ?>
                <div class="col<?= $deprecated ? ' is-deprecated' : '' ?>">
                    <?= view('shared/record-card.php', ['r' => ['id' => 'browse-card-' . (int) $r['dataset_id'], 'title' => $r['title'], 'key' => $r['key'], 'zone' => $r['zone'],
                        'certification' => $r['certification'], 'owner_name' => $r['owner_name'], 'url' => '/datasets/' . $r['key'], 'back' => $here, 'extra' => $extra,
                        'usage_30d' => (int) $r['usage_30d']]]) ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="browse-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="browse-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="browse-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
