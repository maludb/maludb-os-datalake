<?php
/**
 * Access requests (screen `requests`): tabs Mine · To decide (its badge) · History. To decide comes first on a phone: each card carries the
 * inline Approve (a level, an optional expiry, the impact line) and Deny (a reason). Data: box, result, to_decide, notice, dataset
 */
$url = static function (array $change) use ($box, $dataset): string {
    $p = array_filter(array_merge(['box' => $box, 'dataset' => $dataset], $change), static fn ($v) => $v !== null && $v !== '');
    return '/access/requests' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $result['page'] * ACCESS_PAGE < $result['total'];
$me = (int) current_member_id();
?>
<?= view('shared/page-header.php', ['id' => 'requests', 'title' => 'Access requests', 'crumbs' => [['Home', '/'], ['Access', '/access'], ['Requests', null]],
    'action' => has_right('access.request') ? hx_link('/access/requests/new', '<i class="feather-unlock me-1"></i>Request access', 'btn btn-primary btn-touch', 'id="requests-new-btn"') : '']) ?>
<div class="main-content" id="requests-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('access/partials/subnav.php', ['active' => 'requests', 'to_decide' => $to_decide]) ?>
    <ul class="nav nav-tabs mb-3" id="requests-boxes" role="tablist">
        <?php foreach (REQUEST_BOXES as $k => $label): ?>
            <li class="nav-item"><?= hx_link($url(['box' => $k, 'page' => null]), e($label) . ($k === 'to_decide' && $to_decide > 0 ? ' <span class="badge bg-danger ms-1" id="requests-to-decide-badge">' . (int) $to_decide . '</span>' : ''),
                'nav-link' . ($box === $k ? ' active' : ''), 'id="requests-box-' . e($k) . '"' . ($box === $k ? ' aria-current="page"' : '')) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="requests-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-inbox"></i></span>
            <div><div class="fw-semibold"><?= $box === 'to_decide' ? 'Nothing waits for you' : ($box === 'mine' ? 'You have asked for nothing yet' : 'No decided requests yet') ?></div>
                <div class="fs-12 text-muted"><?= $box === 'to_decide' ? 'A request for one of your datasets lands here, and on your phone.' : 'Ask from a dataset\'s page, or with Request access.' ?></div></div></div></div></div>
    <?php else: ?>
        <div class="row g-2" id="requests-list">
            <?php foreach ($result['rows'] as $r): ?>
                <div class="col-12 col-md-6 col-xl-4"><?= view('access/partials/request-card.php', ['r' => $r, 'box' => $box, 'may_decide' => $box === 'to_decide' && may_decide_request($r, $me)]) ?></div>
            <?php endforeach; ?>
        </div>
        <?php if ($result['page'] > 1 || $more): ?>
            <nav class="mt-3" id="requests-pagination"><ul class="pagination mb-0">
                <?php if ($result['page'] > 1): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] - 1]), 'Previous', 'page-link', 'id="requests-prev"') ?></li><?php endif; ?>
                <?php if ($more): ?><li class="page-item"><?= hx_link($url(['page' => $result['page'] + 1]), 'Next', 'page-link', 'id="requests-next"') ?></li><?php endif; ?>
            </ul></nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
