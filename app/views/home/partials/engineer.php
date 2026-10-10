<?php /** For a Data engineer (catalog.edit): failed loads in 24 h, late datasets, the Steward's pending proposals — slice 10 fills each. Data: s */
$e = $s['engineer'];
$labels = ['failed_loads' => ['Failed loads (24 h)', '/loads?status=failed'], 'late' => ['Late datasets', '/quality'], 'proposals' => ['The Steward\'s proposals', '/steward']];
?>
<div class="card mb-3 home-tile" id="home-engineer"><div class="card-header"><h5 class="card-title mb-0">For engineers</h5></div><div class="card-body">
<?php if (in_array(null, $e, true)): ?>
    <div class="tile-pending" id="home-engineer-pending">Failed loads, late datasets and the Steward's proposals appear here once <?= e(HOME_TILE_SLICES['engineer']) ?> is built.</div>
<?php else: ?>
    <div class="d-flex flex-wrap gap-3">
    <?php foreach ($labels as $k => [$label, $url]): ?>
        <div id="home-engineer-<?= e(str_replace('_', '-', $k)) ?>"><div class="home-tile-figure"><?= (int) $e[$k] ?></div><div class="fs-12"><a href="<?= e($url) ?>"><?= e($label) ?></a></div></div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div></div>
