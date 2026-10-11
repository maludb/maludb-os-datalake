<?php
/** The loads ledger (screen `load-list`): filters as a GET form, the table (load-row-{id}), paging. Data: f, q, page, result */
$tz = member_timezone();
$url = static function (array $change) use ($q): string {
    $p = array_filter(array_merge($q, $change), static fn ($v) => $v !== null && $v !== '');
    return '/loads' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $page * LOAD_PAGE < $result['total'];
?>
<?= view('shared/page-header.php', ['id' => 'load-list', 'title' => 'Loads', 'crumbs' => [['Home', '/'], ['Loads', null]],
    'action' => hx_link('/upload', '<i class="feather-upload-cloud me-1"></i>Upload', 'btn btn-primary btn-touch', 'id="load-list-upload-btn"')]) ?>
<div class="main-content" id="load-list-content">
    <form method="get" action="/loads" class="card mb-3" id="load-list-filters" hx-get="/loads" hx-target="#page-content" hx-push-url="true"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="load-list-dataset">Dataset</label>
            <input type="text" class="form-control btn-touch" id="load-list-dataset" name="dataset" value="<?= e($q['dataset']) ?>" placeholder="raw.crm_deals"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 mb-1" for="load-list-source">Source</label>
            <input type="text" class="form-control btn-touch" id="load-list-source" name="source" value="<?= e($q['source']) ?>" placeholder="upload or an id"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="load-list-status">Status</label>
            <select class="form-select btn-touch" id="load-list-status" name="status"><option value="">Any</option>
                <?php foreach (LOAD_STATUS as $k => [, $w]): ?><option value="<?= e($k) ?>"<?= $q['status'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 mb-1" for="load-list-since">Since</label>
            <input type="date" class="form-control btn-touch" id="load-list-since" name="since" value="<?= e($q['since']) ?>"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="load-list-filter-btn">Filter</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-2" id="load-list-total"><?= (int) $result['total'] ?> load<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="load-list-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-download"></i></span>
            <div><div class="fw-semibold">No loads here</div><div class="fs-12 text-muted">A load is made when a file is mapped (Upload) or a source is pulled.</div></div></div></div></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="load-list-table">
            <thead><tr><th>#</th><th>Dataset</th><th>From</th><th>Status</th><th class="text-end">Read</th><th class="text-end">Loaded</th><th class="text-end">Rejected</th><th class="text-end">Rescued</th><th>Checks</th><th>Queued</th><th>Finished</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $r): [$cls, $word] = load_badge((string) $r['status']); $chk = CHECKS_OUTCOME[$r['checks_outcome']] ?? null; ?>
                <tr id="load-row-<?= (int) $r['load_id'] ?>">
                    <td><?= hx_link('/loads/' . (int) $r['load_id'], (string) (int) $r['load_id'], 'fw-semibold') ?></td>
                    <td><?= $r['dataset_key'] === null ? '—' : hx_link('/datasets/' . $r['dataset_key'], e($r['dataset_key']), 'dataset-key') ?></td>
                    <td><?= $r['source_id'] === null ? 'upload' : hx_link('/sources/' . (int) $r['source_id'], 'source ' . (int) $r['source_id']) ?></td>
                    <td><span class="badge <?= e($cls) ?>"><?= e($word) ?></span></td>
                    <td class="text-end"><?= e(count_words((int) $r['rows_read'])) ?></td><td class="text-end"><?= e(count_words((int) $r['rows_loaded'])) ?></td>
                    <td class="text-end"><?= (int) $r['rows_rejected'] ?><?= (int) $r['cast_rejects'] > 0 ? ' <span class="text-muted">+' . (int) $r['cast_rejects'] . ' cells</span>' : '' ?></td>
                    <td class="text-end"><?= (int) $r['rows_rescued'] ?></td>
                    <td><?= $chk === null ? '<span class="text-muted">none</span>' : '<span class="badge bg-soft-' . e($chk[0]) . ' text-' . e($chk[0]) . '">' . e($chk[1]) . '</span>' ?></td>
                    <td class="text-nowrap"><?= e(format_ts($r['queued_at'], $tz, 'M j, g:i A')) ?></td><td class="text-nowrap"><?= $r['finished_at'] === null ? '—' : e(format_ts($r['finished_at'], $tz, 'M j, g:i A')) ?></td>
                    <td><?= e((string) ($r['requested_by_name'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
        <?php if ($page > 1 || $more): ?>
            <nav class="mt-3" id="load-list-pagination"><ul class="pagination mb-0">
                <?php if ($page > 1): ?><li class="page-item"><?= hx_link($url(['page' => $page - 1]), 'Previous', 'page-link', 'id="load-list-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $page + 1]), 'Next', 'page-link', 'id="load-list-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
