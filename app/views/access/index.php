<?php
/**
 * What I may read (screen `my-access`; access-policies.md): a card per dataset — key, title, my level, through what ("a grant to Sales",
 * "you own it", "you are its steward"), "Rows filtered", the count of columns masked for me, the expiry; my open requests below. Data:
 * level, result, requests, notice
 */
$url = static function (array $change) use ($level): string {
    $p = array_filter(array_merge(['level' => $level === 'read' ? null : $level], $change), static fn ($v) => $v !== null && $v !== '');
    return '/access' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $result['page'] * ACCESS_PAGE < $result['total'];
?>
<?= view('shared/page-header.php', ['id' => 'my-access', 'title' => 'My access', 'crumbs' => [['Home', '/'], ['Access', null]],
    'action' => has_right('access.request') ? hx_link('/access/requests/new', '<i class="feather-unlock me-1"></i>Request access', 'btn btn-primary btn-touch', 'id="my-access-request-btn"') : '']) ?>
<div class="main-content" id="my-access-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'my-access']) ?>
    <div class="facet-chips mb-3" id="my-access-levels" aria-label="The least level shown">
        <?php foreach (['discover' => 'Everything I may see', 'read' => 'What I may read', 'read_unmasked' => 'Unmasked', 'write' => 'What I may change', 'own' => 'What I own'] as $k => $label): ?>
            <?= hx_link($url(['level' => $k === 'read' ? null : $k]), e($label), 'btn btn-touch btn-sm ' . ($level === $k ? 'btn-primary' : 'btn-light'), 'id="my-access-level-' . e($k) . '"') ?>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 text-muted mb-2" id="my-access-total"><?= (int) $result['total'] ?> dataset<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="my-access-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-lock"></i></span>
            <div><div class="fw-semibold">Nothing here yet</div><div class="fs-12 text-muted">Find a dataset in the catalog and request access to it — its owner decides.</div></div></div></div></div>
    <?php else: ?>
        <div class="row g-2">
        <?php foreach ($result['rows'] as $r): $b = level_badge((string) $r['level']); ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 dataset-card" id="access-card-<?= (int) $r['dataset_id'] ?>"><div class="card-body py-3">
                <div class="d-flex justify-content-between gap-2">
                    <div class="fw-semibold text-break"><?= hx_link('/datasets/' . (int) $r['dataset_id'] . '?tab=access', e((string) $r['title']), 'text-dark') ?></div>
                    <span class="badge <?= e($b[0]) ?> align-self-start"><?= e($b[1]) ?></span>
                </div>
                <div class="fs-12 font-monospace text-muted text-break"><?= e((string) $r['key']) ?></div>
                <div class="fs-12 mt-1">Through <?= e(access_source_words($r)) ?></div>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    <?php if ($r['row_filtered']): ?><span class="badge border border-info text-info bg-transparent">Rows filtered</span><?php endif; ?>
                    <?php if ((int) $r['masked_columns'] > 0): ?><span class="badge border border-secondary text-secondary bg-transparent">Masked for you · <?= (int) $r['masked_columns'] ?></span><?php endif; ?>
                    <?php if ($r['expires_at'] !== null): ?><span class="badge border border-warning text-warning bg-transparent">Until <?= e(format_date(substr((string) $r['expires_at'], 0, 10))) ?></span><?php endif; ?>
                </div>
            </div></div></div>
        <?php endforeach; ?>
        </div>
        <?php if ($result['page'] > 1 || $more): ?>
            <nav class="mt-3" id="my-access-pagination"><ul class="pagination mb-0">
                <?php if ($result['page'] > 1): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] - 1]), 'Previous', 'page-link', 'id="my-access-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] + 1]), 'Next', 'page-link', 'id="my-access-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
    <h6 class="fs-12 text-uppercase text-muted mt-4 mb-2">My open requests</h6>
    <?php if ($requests === []): ?>
        <div class="fs-12 text-muted" id="my-access-no-requests">None waiting.</div>
    <?php else: ?>
        <div class="row g-2" id="my-access-requests">
            <?php foreach ($requests as $r): ?><div class="col-12 col-md-6 col-xl-4"><?= view('access/partials/request-card.php', ['r' => $r, 'box' => 'mine', 'may_decide' => false]) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
