<?php
/**
 * A run's result (#sql-result's content): the count, "Showing the first N rows" when truncated, the masked columns marked "masked for you"
 * (sql-masked-{column}), quick stats under each header (on the shown rows — sql-stats-{column}), the time and the run's link; above it
 * Save, Download, Save as dataset (each by its right); below 768 the rows as records. A dry run (Explain) shows the plan and the datasets
 * it reads. Data: r (run_sql()), stats, sql, as_of
 */
$masked = [];
foreach ($r['masked_columns'] as $m) {
    $masked[substr($m, (int) strrpos($m, '.') + 1)] = true;
}
$n = count($r['rows']);
$run = $r['query_run_id'];
?>
<div class="card" id="sql-result-card"><div class="card-body">
<?php if ($r['dry_run']): ?>
    <div class="d-flex flex-wrap align-items-center gap-2 fs-12 mb-2" id="sql-dry-run">
        <span class="badge bg-soft-success text-success">Checked — it may run</span>
        <span>Reads <?= $r['datasets'] === [] ? 'no dataset' : implode(', ', array_map(static fn (string $k): string => hx_link('/datasets/' . $k, e($k), 'font-monospace'), $r['datasets'])) ?></span>
        <?php if ($run !== null): ?><span class="ms-auto"><?= hx_link('/runs/' . $run, 'run #' . $run, 'text-muted', 'id="sql-run-link"') ?></span><?php endif; ?>
    </div>
    <?php if (($r['plan'] ?? null) !== null): ?><pre class="sql-plan fs-11 bg-light p-2 mb-0" id="sql-plan"><?= e((string) $r['plan']) ?></pre><?php endif; ?>
<?php else: ?>
    <div class="d-flex flex-wrap align-items-center gap-2 fs-12 mb-2" id="sql-result-head">
        <span class="fw-semibold" id="sql-row-count"><?= number_format($n) ?> row<?= $n === 1 ? '' : 's' ?></span>
        <?php if ($r['truncated']): ?><span class="badge bg-soft-warning text-warning" id="sql-truncated">Showing the first <?= number_format($n) ?> rows</span><?php endif; ?>
        <?php if ($masked !== []): ?><span class="badge bg-soft-warning text-warning" id="sql-masked-count"><?= count($masked) ?> column<?= count($masked) === 1 ? '' : 's' ?> masked for you</span><?php endif; ?>
        <span class="text-muted"><?= (int) $r['ms'] ?> ms<?= $r['as_of'] !== null ? ' · snapshot ' . (int) $r['as_of'] : '' ?></span>
        <?php if ($run !== null): ?><?= hx_link('/runs/' . $run, 'run #' . $run, 'text-muted', 'id="sql-run-link"') ?><?php endif; ?>
    </div>
    <?php if (($r['message'] ?? null) !== null && $n === 0): ?><div class="fs-12 text-muted mb-2" id="sql-message"><?= e((string) $r['message']) ?></div><?php endif; ?>
    <?= view('sql/partials/save-inline.php', ['sql' => $sql, 'as_of' => $as_of, 'r' => $r]) ?>
    <?php if ($n > 0): ?>
        <div class="table-responsive d-none d-md-block sql-result-table-wrap"><table class="table table-sm table-striped fs-12 mb-0" id="sql-result-table">
            <thead><tr><?php foreach ($r['columns'] as $c): $name = (string) $c['name']; $m = isset($masked[$name]); ?>
                <th class="align-top text-nowrap<?= $m ? ' is-masked' : '' ?>"><?= e($name) ?><?php if ($m): ?> <span class="badge bg-warning text-dark fw-normal" id="sql-masked-<?= e($name) ?>">masked for you</span><?php endif; ?>
                    <?= $stats === null ? '' : view('sql/partials/stats.php', ['name' => $name, 's' => $stats[$name] ?? null]) ?></th>
            <?php endforeach; ?></tr></thead>
            <tbody><?php foreach ($r['rows'] as $row): ?><tr><?php foreach ($r['columns'] as $i => $c): $v = $row[$i] ?? null; ?><td class="text-nowrap<?= isset($masked[(string) $c['name']]) ? ' is-masked' : '' ?>"><?= $v === null ? '<span class="text-muted">∅</span>' : e(mb_substr(is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : json_encode($v), 0, 200)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
        </table></div>
        <div class="d-md-none sql-records" id="sql-records">
            <?php foreach (array_slice($r['rows'], 0, 200) as $k => $row): ?>
                <div class="card mb-2 sql-record" id="sql-record-<?= $k + 1 ?>"><div class="card-body p-2 fs-12"><div class="text-muted mb-1">Record <?= $k + 1 ?></div>
                    <?php foreach ($r['columns'] as $i => $c): $v = $row[$i] ?? null; $name = (string) $c['name']; ?>
                        <div class="d-flex gap-2"><span class="text-muted text-nowrap"><?= e($name) ?><?= isset($masked[$name]) ? ' <span class="badge bg-warning text-dark fw-normal">masked</span>' : '' ?></span>
                            <span class="ms-auto text-end text-break"><?= $v === null ? '∅' : e(mb_substr(is_scalar($v) ? (string) $v : json_encode($v), 0, 200)) ?></span></div>
                    <?php endforeach; ?></div></div>
            <?php endforeach; ?>
            <?php if ($n > 200): ?><div class="fs-12 text-muted" id="sql-records-more">The first 200 of <?= number_format($n) ?> rows — a wider screen shows the table.</div><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div></div>
