<?php
/**
 * Grants (screen `grants`): a ledger — target, principal, level, row filter (the template), expiry, granted by, when, reason; Revoke per row
 * where I may; Add grant; "Show revoked" greys the revoked rows in. mcp_grants' wall: grants.manage every grant, a dataset's owner the grants
 * on it, a grantee their own. Data: f, result, may_add, notice, scope_label
 */
$url = static function (array $change) use ($f): string {
    $p = array_filter(array_merge($f, $change), static fn ($v) => $v !== null && $v !== '' && $v !== false);
    return '/access/grants' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $result['page'] * ACCESS_PAGE < $result['total'];
$pdo = db();
$addUrl = '/access/grants/new' . (($q = http_build_query(array_filter(['dataset' => $f['dataset'] ?? null, 'zone' => $f['zone'] ?? null, 'tag' => $f['tag'] ?? null]))) !== '' ? '?' . $q : '');
?>
<?= view('shared/page-header.php', ['id' => 'grants', 'title' => 'Grants' . ($scope_label !== null ? ' — ' . $scope_label : ''), 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Grants', null]],
    'action' => $may_add ? hx_link($addUrl, '<i class="feather-plus me-1"></i>Add grant', 'btn btn-primary btn-touch', 'id="grants-add-btn"') : '']) ?>
<div class="main-content" id="grants-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'grants']) ?>
    <form method="get" action="/access/grants" class="card mb-3" id="grants-filters" hx-get="/access/grants" hx-target="#page-content" hx-push-url="true"><div class="card-body row g-2 align-items-end">
        <?php if (!empty($f['dataset'])): ?><input type="hidden" name="dataset" value="<?= (int) $f['dataset'] ?>"><?php endif; ?>
        <?php if (!empty($f['tag'])): ?><input type="hidden" name="tag" value="<?= (int) $f['tag'] ?>"><?php endif; ?>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="grants-zone">Zone</label>
            <select class="form-select btn-touch" id="grants-zone" name="zone"><option value="">Any target</option>
                <?php foreach (GRANT_ZONES as $z): ?><option value="<?= e($z) ?>"<?= ($f['zone'] ?? '') === $z ? ' selected' : '' ?>>zone <?= e($z) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="grants-principal">Who</label>
            <input type="text" class="form-control btn-touch font-monospace" id="grants-principal" name="principal" value="<?= e((string) ($f['principal'] ?? '')) ?>" placeholder="everyone, group:3, member:26"></div>
        <div class="col-6 col-md-3 form-check ms-2 mb-2"><input class="form-check-input" type="checkbox" id="grants-include-revoked" name="include_revoked" value="1"<?= !empty($f['include_revoked']) ? ' checked' : '' ?>>
            <label class="form-check-label fs-12" for="grants-include-revoked">Show revoked</label></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="grants-filter-btn">Filter</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-2" id="grants-total"><?= (int) $result['total'] ?> grant<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="grants-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-key"></i></span>
            <div><div class="fw-semibold">No grants here</div><div class="fs-12 text-muted">A grant gives a person, a group, a department, an agent or everyone a level on a dataset, a zone or a tag.</div></div></div></div></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" id="grants-table">
            <thead><tr><th>Target</th><th>Who</th><th>Level</th><th>Row filter</th><th>Expires</th><th>Granted</th><th>Reason</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $g): ?>
                <?= view('access/partials/grant-row.php', ['g' => $g, 'may_revoke' => may_grant_on($pdo, (string) $g['target_kind'], $g['dataset_id'] === null ? null : (int) $g['dataset_id'])]) ?>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <?php if ($result['page'] > 1 || $more): ?>
            <nav class="mt-3" id="grants-pagination"><ul class="pagination mb-0">
                <?php if ($result['page'] > 1): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] - 1]), 'Previous', 'page-link', 'id="grants-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] + 1]), 'Next', 'page-link', 'id="grants-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
