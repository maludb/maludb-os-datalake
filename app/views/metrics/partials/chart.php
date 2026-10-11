<?php
/**
 * The metric's chart (#metric-chart): the last twelve periods at the grain as a server-rendered SVG line — one series, so no legend; the value axis starts at ZERO; a thin line (2 px), a marker per
 * period (9 px, ringed in the surface colour), the last value labelled directly, a compact drawing on a phone, a recessive grid, the axes in muted ink; a period with no rows is a gap (no marker), never a zero; each marker has a
 * larger invisible hit target with its value as the native tooltip. The numbers are also in the table below (the chart's table view). Data: m, points [{period, label, value|null}], grain
 */
$vals = array_values(array_filter(array_column($points, 'value'), static fn ($v) => $v !== null));
$max = $vals === [] ? 1.0 : max($vals);
$min = $vals === [] ? 0.0 : min($vals);
$lo = min(0.0, $min);                                           // from zero (a negative value pulls the floor down)
$step = pow(10, floor(log10(max(abs($max), abs($lo), 1e-9))));
$hi = ceil($max / $step) * $step;
if ($hi <= $lo) { $hi = $lo + 1; }
$n = count($points);
$compact = static function (float $v): string {
    $a = abs($v);
    return $a >= 1e9 ? round($v / 1e9, 1) . 'B' : ($a >= 1e6 ? round($v / 1e6, 1) . 'M' : ($a >= 1e3 ? round($v / 1e3, 1) . 'k' : (string) round($v, 2)));
};
$lastIdx = null;
foreach ($points as $i => $p) { if ($p['value'] !== null) { $lastIdx = $i; } }
// two drawings of the same chart: a wide one from 768 px, a compact one on a phone (the same numbers; a wide drawing scaled to 340 px would make its labels unreadable)
$layouts = [
    ['id' => 'metric-chart-svg', 'class' => 'metric-chart-wide d-none d-md-block', 'W' => 640, 'H' => 248, 'L' => 58, 'R' => 64, 'T' => 18, 'B' => 34, 'every' => 1, 'r' => 4.5],
    ['id' => 'metric-chart-svg-compact', 'class' => 'metric-chart-compact d-md-none', 'W' => 340, 'H' => 236, 'L' => 42, 'R' => 50, 'T' => 20, 'B' => 32, 'every' => 3, 'r' => 4.5],
];
?>
<figure class="mb-0 metric-chart" id="metric-chart" data-points="<?= count($vals) ?>" data-slots="<?= $n ?>">
<?php foreach ($layouts as $Ly): ['W' => $W, 'H' => $H, 'L' => $L, 'R' => $R, 'T' => $T, 'B' => $B] = $Ly;
    $x = static fn (int $i): float => $n === 1 ? $L + ($W - $L - $R) / 2 : $L + $i * (($W - $L - $R) / ($n - 1));
    $y = static fn (float $v): float => $T + ($H - $T - $B) * (1 - ($v - $lo) / ($hi - $lo));
    $runs = [];
    $cur = [];
    foreach ($points as $i => $p) {
        if ($p['value'] === null) { if ($cur) { $runs[] = $cur; } $cur = []; continue; }
        $cur[] = [$x($i), $y((float) $p['value'])];
    }
    if ($cur) { $runs[] = $cur; } ?>
<svg class="metric-chart-svg <?= $Ly['class'] ?>" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e($m['title'] . ' by ' . $grain . ': ' . count($vals) . ' of ' . $n . ' periods have a value') ?>" id="<?= $Ly['id'] ?>">
    <title><?= e($m['title']) ?> by <?= e($grain) ?></title>
    <?php for ($g = 0; $g <= 4; $g++): $gv = $lo + ($hi - $lo) * $g / 4; ?>
        <line class="metric-grid<?= $g === 0 ? ' metric-grid-base' : '' ?>" x1="<?= $L ?>" x2="<?= $W - $R ?>" y1="<?= round($y($gv), 1) ?>" y2="<?= round($y($gv), 1) ?>"/>
        <text class="metric-axis" x="<?= $L - 6 ?>" y="<?= round($y($gv) + 4, 1) ?>" text-anchor="end"><?= e($compact($gv)) ?></text>
    <?php endfor; ?>
    <?php foreach ($runs as $run): if (count($run) > 1): ?>
        <polyline class="metric-line" points="<?= e(implode(' ', array_map(static fn (array $p): string => round($p[0], 1) . ',' . round($p[1], 1), $run))) ?>"/>
    <?php endif; endforeach; ?>
    <?php foreach ($points as $i => $p): $px = round($x($i), 1); ?>
        <g class="metric-slot" data-period="<?= e($p['period']) ?>">
            <?php if (($n - 1 - $i) % $Ly['every'] === 0): ?><text class="metric-axis" x="<?= $px ?>" y="<?= $H - 10 ?>" text-anchor="middle"><?= e($p['label']) ?></text><?php endif; ?>
            <?php if ($p['value'] !== null): $py = round($y((float) $p['value']), 1); $words = $p['label'] . ': ' . format_metric_value($p['value'], (string) $m['format'], $m['unit']); ?>
                <circle class="metric-point" cx="<?= $px ?>" cy="<?= $py ?>" r="<?= $Ly['r'] ?>"><title><?= e($words) ?></title></circle>
                <circle class="metric-hit" cx="<?= $px ?>" cy="<?= $py ?>" r="14"><title><?= e($words) ?></title></circle>
                <?php if ($i === $lastIdx): ?><text class="metric-last" x="<?= $px ?>" y="<?= max(12, $py - 11) ?>" text-anchor="<?= $px > $W - $R - 30 ? 'end' : 'middle' ?>"><?= e(format_metric_value($p['value'], (string) $m['format'], $m['unit'])) ?></text><?php endif; ?>
            <?php endif; ?>
        </g>
    <?php endforeach; ?>
</svg>
<?php endforeach; ?>
<figcaption class="fs-11 text-muted mt-1">Last <?= $n ?> <?= e($grain) ?>s, from zero<?= $vals === [] ? ' — no period has a value yet' : '' ?>. The numbers are in the table below.</figcaption>
</figure>
