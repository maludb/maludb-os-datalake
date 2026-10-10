<?php /** My recent and followed datasets (slice 3 fills `recent`, slice 1 `followed` — built; a half not built yet says which slice builds it). Data: s */ ?>
<div class="card mb-3 home-tile" id="home-recent"><div class="card-header"><h5 class="card-title mb-0">Recent and followed</h5></div><div class="card-body">
<?php if ($s['recent'] === null && $s['followed'] === null): ?>
    <div class="tile-pending" id="home-recent-pending">The datasets you query appear here once <?= e(HOME_TILE_SLICES['recent']) ?> is built, the ones you follow once <?= e(HOME_TILE_SLICES['followed']) ?> is.</div>
<?php else: $rows = array_merge($s['followed'] ?? [], $s['recent'] ?? []); ?>
    <?php if ($rows === []): ?><div class="fs-12 text-muted" id="home-recent-empty">Nothing yet — follow a dataset to keep it here.</div><?php endif; ?>
    <?php foreach (['recent', 'followed'] as $k): if ($s[$k] !== null) { continue; } ?>
        <div class="tile-pending" id="home-<?= e($k) ?>-pending"><?= $k === 'recent' ? 'The datasets you query' : 'The datasets you follow' ?> appear here once <?= e(HOME_TILE_SLICES[$k]) ?> is built.</div>
    <?php endforeach; ?>
    <?php foreach ($rows as $r): ?>
        <?= view('shared/record-card.php', ['r' => ['id' => 'home-recent-' . (int) $r['dataset_id'], 'title' => $r['title'], 'key' => $r['key'], 'zone' => $r['zone'] ?? null,
            'certification' => $r['certification'] ?? null, 'url' => '/datasets/' . $r['key'], 'back' => '/']]) ?>
    <?php endforeach; ?>
<?php endif; ?>
</div></div>
