<?php /** Open incidents on datasets I use (slice 5 fills `incidents`; until then the tile says so). Data: s */ ?>
<div class="card mb-3 home-tile" id="home-incidents"><div class="card-header"><h5 class="card-title mb-0">Open incidents</h5></div><div class="card-body">
<?php if ($s['incidents'] === null): ?>
    <div class="tile-pending" id="home-incidents-pending">Open incidents on the datasets you use appear here once <?= e(HOME_TILE_SLICES['incidents']) ?> is built.</div>
<?php elseif ($s['incidents'] === []): ?>
    <div class="fs-12 text-muted" id="home-incidents-empty">No open incident on what you use.</div>
<?php else: ?>
    <div class="list-group list-group-flush">
    <?php foreach ($s['incidents'] as $i): ?>
        <?= hx_link(with_back('/incidents/' . (int) $i['incident_id'], '/'), '<span class="fw-semibold">' . e($i['title']) . '</span><span class="fs-11 text-muted d-block">' . e($i['severity'] . ' · ' . $i['status']) . '</span>', 'list-group-item list-group-item-action', 'id="home-incident-' . (int) $i['incident_id'] . '"') ?>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div></div>
