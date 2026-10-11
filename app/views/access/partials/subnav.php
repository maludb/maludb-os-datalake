<?php
/** The Access screens' own tabs (a file beyond the spec's list — access-policies.md "Built and proven"): My access · Requests (the To-decide badge) · Grants · Groups · Masks · Personal data · Break-glass — each shown to who may open it. Data: active, to_decide? */
$pdo = db();
$me = (int) current_member_id();
$ext = (bool) (current_member()['is_external'] ?? false);
$toDecide = $to_decide ?? requests_to_decide_count($pdo, $me);
$tabs = [
    ['my-access', '/access', 'My access', has_right('access.request')],
    ['requests', '/access/requests', 'Requests', true],
    ['grants', '/access/grants', 'Grants', !$ext],
    ['groups', '/groups', 'Groups', !$ext],
    ['policies', '/access/policies', 'Masks', has_right('policies.manage') || has_right('catalog.edit')],
    ['personal-data', '/access/personal-data', 'Personal data', !$ext],
    ['admin-read', '/access/break-glass', 'Break-glass', has_right('lake.admin_read')],
];
?>
<nav class="facet-chips mb-3 access-subnav" id="access-subnav" aria-label="Access">
    <?php foreach ($tabs as [$id, $url, $label, $show]): if (!$show) { continue; } $on = $id === $active; ?>
        <?= hx_link($url, e($label) . ($id === 'requests' && $toDecide > 0 ? ' <span class="badge bg-danger ms-1" id="access-subnav-to-decide">' . (int) $toDecide . '</span>' : ''),
            'btn btn-touch btn-sm ' . ($on ? 'btn-primary' : 'btn-light'), 'id="access-subnav-' . e($id) . '"' . ($on ? ' aria-current="page"' : '')) ?>
    <?php endforeach; ?>
</nav>
