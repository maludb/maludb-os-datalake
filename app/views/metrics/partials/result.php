<?php
/**
 * What the pickers re-render (#metric-result): the chart (always the uncut series at the grain) and the table below it — the series itself, or the cut by the chosen dimensions. A refusal is the
 * service's sentence (with Request access); a masked cut says so; a cut longer than the screen's cap says it was cut. Data: m, grain, by, points, chart_answer, table, win
 */
$cut = $by !== [];
$cols = $table['columns'];
$hasPeriod = $m['time_column'] !== null;
?>
<div id="metric-result" data-grain="<?= e($grain) ?>" data-by="<?= e(implode(',', $by)) ?>">
<?php if (!$chart_answer['ok']): ?>
    <div class="card mb-3"><div class="card-body"><div class="empty-state" id="metric-refused"><span class="avatar-text avatar-lg rounded"><i class="feather-lock"></i></span>
        <div><div class="fw-semibold">The lake did not answer</div><div class="fs-12 text-muted"><?= e((string) $chart_answer['message']) ?></div></div>
        <?php if ($chart_answer['request_access'] !== null): ?><?= hx_link($chart_answer['request_access'], 'Request access', 'btn btn-primary btn-touch ms-md-3', 'id="metric-request-access"') ?><?php endif; ?></div></div></div>
<?php else: ?>
    <div class="card mb-3"><div class="card-body">
    <?php if ($hasPeriod): ?><?= view('metrics/partials/chart.php', ['m' => $m, 'points' => $points, 'grain' => $grain]) ?>
    <?php else: ?><div id="metric-value" class="text-center py-3"><div class="fs-1 fw-bold"><?= e(format_metric_value($points[0]['value'] ?? null, (string) $m['format'], $m['unit'])) ?></div><div class="fs-12 text-muted"><?= e($m['title']) ?> — it has no time column, so there is one number.</div></div><?php endif; ?></div></div>
    <div class="card" id="metric-table-card"><div class="card-header d-flex flex-wrap align-items-center gap-2"><h5 class="card-title mb-0"><?= $cut ? 'By ' . e(implode(', ', $by)) : 'By ' . e($grain) ?></h5>
        <?php foreach ($table['masked_columns'] as $mc): ?><span class="badge bg-soft-warning text-warning" title="<?= e($mc) ?>">masked for you: <?= e(substr($mc, (int) strrpos($mc, '.') + 1)) ?></span><?php endforeach; ?></div>
    <?php if (!$table['ok']): ?>
        <div class="card-body"><div class="fs-12 text-danger" id="metric-table-refused"><?= e((string) $table['message']) ?></div></div>
    <?php elseif ($table['rows'] === []): ?>
        <div class="card-body"><div class="fs-12 text-muted" id="metric-table-empty">No rows in this window.</div></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="metric-table">
            <thead><tr><?php foreach ($cols as $i => $c): $isVal = $i === count($cols) - 1; ?><th class="<?= $isVal ? 'text-end' : '' ?>"><?= e($isVal ? ($m['title']) : ($hasPeriod && $i === 0 ? ucfirst($grain) : $c)) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($table['rows'] as $row): ?><tr>
                <?php foreach ($cols as $i => $c): $isVal = $i === count($cols) - 1; ?>
                    <td class="<?= $isVal ? 'text-end' : '' ?>"><?php if ($isVal): ?><?= e(format_metric_value($row[$i], (string) $m['format'], $m['unit'])) ?>
                        <?php elseif ($hasPeriod && $i === 0): ?><?= e(metric_period_label(substr((string) $row[$i], 0, 10), $grain)) ?>
                        <?php else: ?><?= $row[$i] === null ? '<span class="text-muted">—</span>' : e((string) $row[$i]) ?><?php endif; ?></td>
                <?php endforeach; ?></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php if ($table['truncated']): ?><div class="card-footer fs-12 text-muted" id="metric-table-truncated">Showing the first <?= count($table['rows']) ?> rows — narrow the window or cut by fewer dimensions.</div><?php endif; ?>
    <?php endif; ?>
    </div>
<?php endif; ?>
</div>
