<?php
/**
 * The metric page (screen `metric-view`): the header (title, the Certified badge, the product it is over, the owner), the definition (the expression, the fixed filter, the time column, the grains and
 * dimensions), the pickers — Grain and Cut by — that re-render #metric-result (the chart and the table below it) in place, and Certify · Retire · Edit for metrics.manage. Data: m, grain, by, points,
 * chart_answer, table, win, may, notice
 */
$id = (int) $m['metric_id'];
[$stateKey, $stateClass, $stateWord] = metric_state($m);
$tz = member_timezone();
$act = static function (string $name, string $label, string $icon, string $btnClass, string $confirm = '', array $hidden = []) use ($id): string {
    return '<form method="post" action="/metrics/' . $name . '.php" hx-post="/metrics/' . $name . '.php" hx-target="#flash" class="d-inline"' . ($confirm === '' ? '' : ' hx-confirm="' . e($confirm) . '"') . '>'
        . csrf_field() . '<input type="hidden" name="metric" value="' . $id . '">'
        . implode('', array_map(static fn (string $k, string $v): string => '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">', array_keys($hidden), array_values($hidden)))
        . '<button type="submit" class="btn ' . $btnClass . ' btn-touch" id="metric-' . $id . '-' . $name . '"><i class="' . $icon . ' me-1"></i>' . e($label) . '</button></form>';
};
?>
<?= view('shared/page-header.php', ['id' => 'metric-view', 'title' => $m['title'], 'crumbs' => [['Metrics', '/metrics'], [$m['title'], null]], 'back' => back_link() ?? ['/metrics', 'Metrics']]) ?>
<div class="main-content pt-0 pb-2" id="metric-view-header" data-metric-id="<?= $id ?>">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <span class="badge bg-soft-<?= e($stateClass) ?> text-<?= e($stateClass === 'light' ? 'dark' : $stateClass) ?>" id="metric-view-state"><?= $stateKey === 'certified' ? '<i class="feather-check-circle me-1"></i>' : '' ?><?= e($stateWord) ?></span>
        <span class="fs-12 text-muted" id="metric-view-name"><code><?= e($m['name']) ?></code></span>
    </div>
    <?php if ($m['description'] !== null && $m['description'] !== ''): ?><div class="fs-13 text-break mb-2" id="metric-view-description"><?= e((string) $m['description']) ?></div><?php endif; ?>
    <div class="row g-2 fs-12 text-muted mb-2">
        <div class="col-12 col-md-6">
            <div id="metric-view-product"><i class="feather-package me-1"></i>Over <?= hx_link('/datasets/' . $m['dataset_key'], e((string) $m['dataset_key']), 'dataset-key fw-semibold', 'id="metric-view-product-link"') ?></div>
            <div id="metric-view-owner"><i class="feather-user me-1"></i><?= e((string) ($m['owner_name'] ?? 'No owner')) ?><?= $m['certified_at'] !== null ? ' · certified ' . e(ago_words($m['certified_at']) ?? '') : '' ?></div>
        </div>
        <div class="col-12 col-md-6">
            <div id="metric-view-grains"><i class="feather-calendar me-1"></i><?= $m['time_column'] === null ? 'No time column — one number' : 'By ' . e(implode(', ', $m['grains'])) . ' on <code>' . e((string) $m['time_column']) . '</code>' ?></div>
            <div id="metric-view-dimensions"><i class="feather-layers me-1"></i><?= $m['dimensions'] === [] ? 'No dimensions to cut by' : 'Cut by ' . e(implode(', ', $m['dimensions'])) ?></div>
        </div>
    </div>
    <details class="mb-2" id="metric-definition"><summary class="fs-12 fw-semibold" style="min-height: 44px; display: flex; align-items: center; cursor: pointer;">Definition</summary>
        <pre class="sql-readonly font-monospace fs-12 mb-1" id="metric-view-expression"><?= e((string) $m['expression']) ?></pre>
        <?php if ($m['filters'] !== null && $m['filters'] !== ''): ?><div class="fs-12">where <code id="metric-view-filters"><?= e((string) $m['filters']) ?></code></div><?php endif; ?>
    </details>
    <div class="d-flex flex-wrap gap-2" id="metric-view-actions">
        <?php if ($may['certify']): ?><?= $m['certified'] ? $act('certify', 'Remove the badge', 'feather-x-circle', 'btn-light', '', ['certify' => 'no']) : $act('certify', 'Certify', 'feather-check-circle', 'btn-primary', '', ['certify' => 'yes']) ?><?php endif; ?>
        <?php if ($may['edit']): ?><?= hx_link('/metrics/' . $id . '/edit', '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="metric-edit-link"') ?><?php endif; ?>
        <?php if ($may['retire']): ?><?= $act('retire', 'Retire', 'feather-archive', 'btn-light text-danger', 'Retire ' . $m['title'] . '? It leaves every list; its history stays.') ?><?php endif; ?>
    </div>
</div>
<div class="main-content pt-2" id="metric-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/metrics/<?= $id ?>" class="card mb-3" id="metric-pickers" hx-get="/metrics/<?= $id ?>?partial=result" hx-trigger="change" hx-target="#metric-result" hx-swap="outerHTML" hx-push-url="false"><div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <?php if ($m['time_column'] !== null): ?>
            <div class="col-12 col-md-3"><label class="form-label fs-12 mb-1" for="metric-grain">Grain</label>
                <select class="form-select btn-touch" id="metric-grain" name="grain"><?php foreach ($m['grains'] as $g): ?><option value="<?= e($g) ?>"<?= $g === $grain ? ' selected' : '' ?>><?= e(METRIC_GRAINS[$g] ?? $g) ?></option><?php endforeach; ?></select></div>
            <?php endif; ?>
            <div class="col-12 col-md-7"><fieldset id="metric-by"><legend class="form-label fs-12 mb-1">Cut by</legend>
                <?php if ($m['dimensions'] === []): ?><span class="fs-12 text-muted">This metric has no dimensions.</span><?php endif; ?>
                <div class="d-flex flex-wrap gap-3">
                <?php foreach ($m['dimensions'] as $dim): ?>
                    <div class="form-check mb-0" style="min-height: 44px; display: flex; align-items: center; gap: .4rem;"><input class="form-check-input mt-0" type="checkbox" name="by[]" value="<?= e($dim) ?>" id="metric-by-<?= e($dim) ?>"<?= in_array($dim, $by, true) ? ' checked' : '' ?>>
                        <label class="form-check-label" for="metric-by-<?= e($dim) ?>"><?= e($dim) ?></label></div>
                <?php endforeach; ?>
                </div></fieldset></div>
            <div class="col-12 col-md-2"><button type="submit" class="btn btn-light-brand btn-touch w-100" id="metric-pickers-show">Show</button></div>
        </div>
    </div></form>
    <?= view('metrics/partials/result.php', ['m' => $m, 'grain' => $grain, 'by' => $by, 'points' => $points, 'chart_answer' => $chart_answer, 'table' => $table, 'win' => $win]) ?>
</div>
