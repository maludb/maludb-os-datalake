<?php /** For the Lake admin: the engine, storage, the last backup and drill — slice 10 fills each. Data: s */ $a = $s['admin']; ?>
<div class="card mb-3 home-tile" id="home-admin"><div class="card-header"><h5 class="card-title mb-0">The lake</h5></div><div class="card-body">
<?php if (in_array(null, $a, true)): ?>
    <div class="tile-pending" id="home-admin-pending">The engine's state, storage and the last backup and restore drill appear here once <?= e(HOME_TILE_SLICES['admin']) ?> is built.</div>
<?php else: ?>
    <div class="fs-12" id="home-admin-engine">Queue: <?= (int) ($a['engine']['queue']['queued'] ?? 0) ?> queued, <?= (int) ($a['engine']['queue']['running'] ?? 0) ?> running, <?= (int) ($a['engine']['queue']['failed_24h'] ?? 0) ?> failed in 24 h.</div>
<?php endif; ?>
</div></div>
