<?php /** Queries a day for 30 days as a strip of 30 cells, each as tall as its day's share of the busiest (zeros greyed). Data: daily [{day, queries}] */
$max = max(1, ...array_map(static fn (array $r): int => (int) $r['queries'], $daily ?: [['queries' => 0]]));
?>
<div class="usage-strip" id="usage-strip" role="img" aria-label="<?= e(array_sum(array_column($daily, 'queries')) . ' queries in ' . count($daily) . ' days') ?>">
    <?php foreach ($daily as $r): $n = (int) $r['queries']; ?>
        <span class="usage-cell<?= $n === 0 ? ' zero' : '' ?>" style="height: <?= $n === 0 ? 8 : max(8, (int) round(100 * $n / $max)) ?>%" title="<?= e($r['day'] . ': ' . $n . ' quer' . ($n === 1 ? 'y' : 'ies')) ?>"></span>
    <?php endforeach; ?>
</div>
