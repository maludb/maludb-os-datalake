<?php
/** Saved queries as cards (screen `queries`): filters as a GET form, a card per query (query-card-{id}), paging. Data: f, q, page, result */
$url = static function (array $change) use ($q): string {
    $p = array_filter(array_merge($q, $change), static fn ($v) => $v !== null && $v !== '');
    return '/queries' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $page * SAVED_QUERY_PAGE < $result['total'];
$me = (int) current_member_id();
?>
<?= view('shared/page-header.php', ['id' => 'queries', 'title' => 'Saved queries', 'crumbs' => [['Home', '/'], ['Saved queries', null]],
    'action' => has_right('sql.editor') ? hx_link('/sql', '<i class="feather-terminal me-1"></i>New in the editor', 'btn btn-primary btn-touch', 'id="queries-new-btn"') : '']) ?>
<div class="main-content" id="queries-content">
    <form method="get" action="/queries" class="card mb-3" id="queries-filters" hx-get="/queries" hx-target="#page-content" hx-push-url="true"><div class="card-body row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 mb-1" for="queries-q">Find</label>
            <input type="search" class="form-control btn-touch" id="queries-q" name="q" value="<?= e($q['q']) ?>" placeholder="a title or a question"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="queries-verified">Verified</label>
            <select class="form-select btn-touch" id="queries-verified" name="verified"><option value="">Any</option>
                <option value="yes"<?= $q['verified'] === 'yes' ? ' selected' : '' ?>>Verified</option><option value="no"<?= $q['verified'] === 'no' ? ' selected' : '' ?>>Not verified</option></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="queries-dataset">Reads</label>
            <input type="text" class="form-control btn-touch font-monospace" id="queries-dataset" name="dataset" value="<?= e($q['dataset']) ?>" placeholder="curated.sales_orders"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="queries-filter-btn">Filter</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-2" id="queries-total"><?= (int) $result['total'] ?> saved quer<?= $result['total'] === 1 ? 'y' : 'ies' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="queries-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-bookmark"></i></span>
            <div><div class="fw-semibold">No saved queries here</div><div class="fs-12 text-muted">Save one from the editor's result, or ask a colleague to share theirs.</div></div></div></div></div>
    <?php else: ?>
        <div class="row g-2">
        <?php foreach ($result['rows'] as $r): $p = present_saved_query_row($r); $b = verified_badge($r); ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 query-card" id="query-card-<?= (int) $p['saved_query_id'] ?>"><div class="card-body py-3">
                <div class="fw-semibold text-break"><?= hx_link($p['url'], e((string) $p['title']), 'text-dark') ?></div>
                <?php if (($p['question'] ?? '') !== ''): ?><div class="fs-12 text-muted mt-1"><?= e((string) $p['question']) ?></div><?php endif; ?>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    <?php if ($b !== null): ?><span class="badge <?= e($b[0]) ?>"><?= e($b[1]) ?></span><?php endif; ?>
                    <span class="badge bg-soft-secondary text-secondary"><?= $p['mine'] ? ($p['shared'] ? 'Mine · shared' : 'Mine') : 'Shared' ?></span>
                    <?php foreach ($p['datasets'] as $d): ?><span class="badge text-bg-light font-monospace"><?= e($d['key']) ?></span><?php endforeach; ?>
                </div>
                <div class="fs-11 text-muted mt-2"><i class="feather-user me-1"></i><?= e((string) ($p['owner_name'] ?? '—')) ?></div>
            </div></div></div>
        <?php endforeach; ?>
        </div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="queries-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="queries-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="queries-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
