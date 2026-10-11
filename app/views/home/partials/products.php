<?php /** Certified products as cards (slice 7: certified_products). Data: s */ ?>
<div class="card mb-3 home-tile" id="home-products"><div class="card-header"><h5 class="card-title mb-0">Certified products</h5></div><div class="card-body">
<?php if ($s['products'] === []): ?>
    <div class="fs-12 text-muted" id="home-products-empty">No certified product yet.</div>
<?php else: foreach ($s['products'] as $r): ?>
    <?= view('shared/record-card.php', ['r' => ['id' => 'home-product-' . (int) $r['dataset_id'], 'title' => $r['title'], 'key' => $r['key'], 'zone' => 'product',
        'certification' => $r['certification'], 'owner_name' => $r['owner_name'] ?? null, 'url' => '/datasets/' . $r['key'], 'back' => '/']]) ?>
<?php endforeach; endif; ?>
<?php if ($s['products'] !== []): ?><div class="mt-2"><?= hx_link('/products?certified=yes', 'All certified products', 'fs-12', 'id="home-products-all"') ?></div><?php endif; ?>
</div></div>
