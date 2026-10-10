<?php /** Popular this month (dl_popular_datasets(30, NULL, 6), db/018 — the wall the function's; Phase 2). Data: s */ ?>
<div class="card mb-3 home-tile" id="home-popular"><div class="card-header"><h5 class="card-title mb-0">Popular this month</h5></div><div class="card-body">
<?php if ($s['popular'] === []): ?>
    <div class="fs-12 text-muted" id="home-popular-empty">Nothing has been queried this month yet.</div>
<?php else: foreach ($s['popular'] as $r): ?>
    <?= view('shared/record-card.php', ['r' => ['id' => 'home-popular-' . (int) $r['dataset_id'], 'title' => $r['title'], 'key' => $r['key'], 'zone' => explode('.', (string) $r['key'])[0],
        'certification' => $r['certification'], 'url' => '/datasets/' . $r['key'], 'back' => '/',
        'extra' => '<div class="fs-11 text-muted mt-1">' . e(count_words($r['queries'])) . ' queries by ' . e(count_words($r['people'])) . ' ' . ((int) $r['people'] === 1 ? 'person' : 'people') . '</div>']]) ?>
<?php endforeach; endif; ?>
</div></div>
