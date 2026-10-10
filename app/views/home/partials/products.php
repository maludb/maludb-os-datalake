<?php /** Certified products as cards (slice 7 fills `products`; until then the tile says so). Data: s */ ?>
<div class="card mb-3 home-tile" id="home-products"><div class="card-header"><h5 class="card-title mb-0">Certified products</h5></div><div class="card-body">
<?php if ($s['products'] === null): ?>
    <div class="tile-pending" id="home-products-pending">The certified data products — the lake's trusted surface — appear here once <?= e(HOME_TILE_SLICES['products']) ?> is built.</div>
<?php elseif ($s['products'] === []): ?>
    <div class="fs-12 text-muted" id="home-products-empty">No certified product yet.</div>
<?php else: foreach ($s['products'] as $r): ?>
    <?= view('shared/record-card.php', ['r' => ['id' => 'home-product-' . (int) $r['dataset_id'], 'title' => $r['title'], 'key' => $r['key'], 'zone' => 'product',
        'certification' => $r['certification'], 'owner_name' => $r['owner_name'] ?? null, 'url' => '/datasets/' . $r['key'], 'back' => '/']]) ?>
<?php endforeach; endif; ?>
</div></div>
