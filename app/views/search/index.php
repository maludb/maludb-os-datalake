<?php
/**
 * Search (screen `search`) — the catalog search's results as cards: certified first, then usage; stubs for what the person may not
 * discover (a name, an owner, Request access); facets as chips; paged 25. Data: q, filters, page, result {rows, total, stubs}, tooShort, mayAsk, owners, departments
 */
$url = static function (array $change) use ($q, $filters): string {
    $p = array_filter(array_merge(['q' => $q], $filters, $change), static fn ($v) => $v !== null && $v !== '');
    foreach ($p as $k => $v) { if (is_bool($v)) { $p[$k] = $v ? 'yes' : 'no'; } }
    return '/search' . ($p === [] ? '' : '?' . http_build_query($p));
};
$chip = static function (string $id, string $label, bool $on, string $href): string {
    return hx_link($href, e($label), 'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="search-facet-' . e($id) . '"');
};
$more = $page * SEARCH_PAGE < $result['total'];
$here = $url([]);
?>
<?= view('shared/page-header.php', ['id' => 'search', 'title' => 'Search', 'crumbs' => [['Home', '/'], ['Search', null]]]) ?>
<div class="main-content" id="search-content">
    <form method="get" action="/search" class="mb-3" id="search-form" role="search">
        <?php foreach ($filters as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e(is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) ?>"><?php endforeach; ?>
        <div class="input-group">
            <input type="search" name="q" id="search-field-q" class="form-control btn-touch" value="<?= e($q) ?>" placeholder="A dataset, a column, a term, a metric…" maxlength="200" autocomplete="off" aria-label="Search the lake">
            <button type="submit" class="btn btn-primary btn-touch" id="search-submit-btn" aria-label="Search"><i class="feather-search"></i></button>
            <?php if ($mayAsk): ?><button type="submit" formaction="/ask" class="btn btn-light-brand btn-touch" id="search-ask-btn" aria-label="Ask"><i class="feather-message-square"></i></button><?php endif; ?>
        </div>
        <div class="fs-11 text-muted mt-1">"exact words", -leave-out, or a name close enough. Certified data first; a locked card is data you may ask for.</div>
    </form>
    <div class="facet-chips mb-2" id="search-kinds">
        <?= $chip('kind-all', 'Everything', !isset($filters['kind']), $url(['kind' => null, 'page' => null])) ?>
        <?php foreach (SEARCH_KINDS as $k => $label): ?><?= $chip('kind-' . $k, $label, ($filters['kind'] ?? null) === $k, $url(['kind' => $k, 'page' => null])) ?><?php endforeach; ?>
    </div>
    <div class="facet-chips mb-3" id="search-facets">
        <?php foreach (SEARCH_ZONES as $z): $on = ($filters['zone'] ?? null) === $z; ?><?= $chip('zone-' . $z, $z, $on, $url(['zone' => $on ? null : $z, 'page' => null])) ?><?php endforeach; ?>
        <?php $on = ($filters['certification'] ?? null) === 'certified'; ?><?= $chip('certified', 'Certified', $on, $url(['certification' => $on ? null : 'certified', 'page' => null])) ?>
        <?php $on = ($filters['personal'] ?? null) === true; ?><?= $chip('personal', 'Personal data', $on, $url(['personal' => $on ? null : true, 'page' => null])) ?>
        <?php foreach (['department' => 'Department', 'owner' => 'Owner', 'tag' => 'Tag', 'classification' => 'Classification'] as $k => $label): if (!isset($filters[$k])) { continue; }
            $value = $k === 'owner' ? ($owners[(int) $filters[$k]] ?? '#' . $filters[$k]) : ($k === 'department' ? ($departments[(int) $filters[$k]] ?? '#' . $filters[$k]) : (string) $filters[$k]); ?>
            <?= hx_link($url([$k => null, 'page' => null]), e($label . ': ' . $value) . ' <i class="feather-x"></i>', 'btn btn-touch btn-sm btn-primary', 'id="search-facet-' . e($k) . '" title="Remove"') ?>
        <?php endforeach; ?>
    </div>
    <?php if ($tooShort): ?>
        <div class="alert alert-warning" id="search-too-short">Type at least two characters.</div>
    <?php elseif ($q === '' && $filters === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="search-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
            <div><div class="fw-semibold">Find the data you need</div><div class="fs-12 text-muted">Datasets by their name, description, columns and tags; glossary terms; saved queries; metrics.</div></div></div></div></div>
    <?php elseif ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="search-none"><span class="avatar-text avatar-lg rounded"><i class="feather-search"></i></span>
            <div><div class="fw-semibold">Nothing matches<?= $q !== '' ? ' “' . e($q) . '”' : '' ?></div><div class="fs-12 text-muted">Try fewer words, part of a name, or fewer filters.</div></div></div></div></div>
    <?php else: ?>
        <div class="fs-12 text-muted mb-2" id="search-total"><?= (int) $result['total'] ?> result<?= $result['total'] === 1 ? '' : 's' ?></div>
        <div id="search-results">
            <?php foreach ($result['rows'] as $r): ?><?= view('search/partials/result.php', ['r' => $r, 'here' => $here]) ?><?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav id="search-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="search-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="search-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
