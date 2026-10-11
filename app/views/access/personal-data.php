<?php
/**
 * The personal-data map (screen `personal-data`): a table — dataset key, column, the personal kind (confirmed) or the proposed kind with its
 * confidence, classified by, confirmed when; Confirm / Reject inline for a person who may (never an agent — ★15); each column links to its
 * page. Data: f, result, kinds, notice
 */
$url = static function (array $change) use ($f): string {
    $p = array_filter(array_merge($f, $change), static fn ($v) => $v !== null && $v !== '');
    return '/access/personal-data' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $result['page'] * PERSONAL_PAGE < $result['total'];
$me = (int) current_member_id();
?>
<?= view('shared/page-header.php', ['id' => 'personal-data', 'title' => 'Personal data', 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Personal data', null]]]) ?>
<div class="main-content" id="personal-data-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'personal-data']) ?>
    <form method="get" action="/access/personal-data" class="card mb-3" id="personal-data-filters" hx-get="/access/personal-data" hx-target="#page-content" hx-push-url="true"><div class="card-body row g-2 align-items-end">
        <?php if (!empty($f['dataset'])): ?><input type="hidden" name="dataset" value="<?= (int) $f['dataset'] ?>"><?php endif; ?>
        <div class="col-6 col-md-4"><label class="form-label fs-12 mb-1" for="personal-data-kind">Kind</label>
            <select class="form-select btn-touch" id="personal-data-kind" name="kind"><option value="">Any kind</option>
                <?php foreach ($kinds as $k): ?><option value="<?= e($k) ?>"<?= ($f['kind'] ?? '') === $k ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $k)) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4"><label class="form-label fs-12 mb-1" for="personal-data-confirmed">Confirmed</label>
            <select class="form-select btn-touch" id="personal-data-confirmed" name="confirmed"><option value="">Either</option>
                <option value="no"<?= ($f['confirmed'] ?? '') === 'no' ? ' selected' : '' ?>>Waiting for a person</option><option value="yes"<?= ($f['confirmed'] ?? '') === 'yes' ? ' selected' : '' ?>>Confirmed</option></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="personal-data-filter-btn">Filter</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-2" id="personal-data-total"><?= (int) $result['total'] ?> column<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="personal-data-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-shield"></i></span>
            <div><div class="fw-semibold">No personal data found</div><div class="fs-12 text-muted">The classifier proposes personal columns as data lands; they appear here for a person to confirm.</div></div></div></div></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table mb-0 align-middle" id="personal-data-table">
            <thead><tr><th>Dataset</th><th>Column</th><th>Kind</th><th>By</th><th>Confirmed</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $c): $cid = (int) $c['column_id']; $proposed = $c['proposed_personal_kind'] !== null; $confirmed = $c['classification_confirmed_at'] !== null; ?>
                <tr id="personal-row-<?= $cid ?>">
                    <td class="font-monospace fs-12 text-break"><?= hx_link('/datasets/' . (int) $c['dataset_id'] . '?tab=columns', e((string) $c['key'])) ?></td>
                    <td class="font-monospace fs-12"><?= hx_link('/datasets/' . (int) $c['dataset_id'] . '/columns/' . rawurlencode((string) $c['name']), e((string) $c['name'])) ?></td>
                    <td><?php if ($c['personal_kind'] !== null): ?><span class="badge bg-soft-danger text-danger"><?= e(str_replace('_', ' ', (string) $c['personal_kind'])) ?></span><?php endif; ?>
                        <?php if ($proposed): ?><span class="badge border border-warning text-warning bg-transparent" id="personal-row-<?= $cid ?>-proposed">proposed: <?= e(str_replace('_', ' ', (string) $c['proposed_personal_kind'])) ?> · <?= (int) round((float) $c['proposed_confidence'] * 100) ?>%</span><?php endif; ?></td>
                    <td class="fs-12"><?= e((string) $c['classified_by']) ?></td>
                    <td class="fs-12"><?= $confirmed ? e(format_ts((string) $c['classification_confirmed_at'], member_timezone(), 'M j, Y')) : '<span class="text-muted">waiting</span>' ?></td>
                    <td class="text-end"><?php if (!$confirmed && may_confirm_personal($c, $me)): $kind = (string) ($c['proposed_personal_kind'] ?? $c['personal_kind']); ?>
                        <div class="d-flex gap-1 justify-content-end">
                        <form method="post" action="/access/columns/confirm.php" hx-post="/access/columns/confirm.php" hx-target="#flash" id="personal-row-<?= $cid ?>-confirm-form">
                            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= (int) $c['dataset_id'] ?>"><input type="hidden" name="column" value="<?= e((string) $c['name']) ?>">
                            <input type="hidden" name="accept" value="yes"><input type="hidden" name="personal_kind" value="<?= e($kind) ?>"><input type="hidden" name="return_to" value="<?= e($url([])) ?>">
                            <button type="submit" class="btn btn-success btn-sm btn-touch" id="personal-row-<?= $cid ?>-confirm">Confirm</button></form>
                        <form method="post" action="/access/columns/confirm.php" hx-post="/access/columns/confirm.php" hx-target="#flash" hx-confirm="Not personal data — the column is shown unmasked by its classification?" id="personal-row-<?= $cid ?>-reject-form">
                            <?= csrf_field() ?><input type="hidden" name="dataset" value="<?= (int) $c['dataset_id'] ?>"><input type="hidden" name="column" value="<?= e((string) $c['name']) ?>">
                            <input type="hidden" name="accept" value="no"><input type="hidden" name="return_to" value="<?= e($url([])) ?>">
                            <button type="submit" class="btn btn-light btn-sm btn-touch" id="personal-row-<?= $cid ?>-reject">Reject</button></form>
                        </div>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?php if ($result['page'] > 1 || $more): ?>
            <nav class="mt-3" id="personal-data-pagination"><ul class="pagination mb-0">
                <?php if ($result['page'] > 1): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] - 1]), 'Previous', 'page-link', 'id="personal-data-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] + 1]), 'Next', 'page-link', 'id="personal-data-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
