<?php
/**
 * The quality home (screen `quality`): at 1280 three lists side by side as cards (failing checks, late datasets, open incidents); at 375 one
 * status list — the same rows, the worst first. Every row links to its dataset or incident. Data: f, h, departments
 */
$tz = member_timezone();
$sevBadge = static fn (string $s): string => '<span class="badge ' . e(severity_badge($s)[0]) . '">' . e(severity_badge($s)[1]) . '</span>';
?>
<?= view('shared/page-header.php', ['id' => 'quality', 'title' => 'Quality', 'crumbs' => [['Home', '/'], ['Quality', null]],
    'action' => hx_link('/incidents', '<i class="feather-alert-triangle me-1"></i>All incidents', 'btn btn-light btn-touch', 'id="quality-incidents-btn"')]) ?>
<div class="main-content" id="quality-content">
    <form method="get" action="/quality" class="card mb-3" id="quality-filters" hx-get="/quality" hx-target="#page-content" hx-push-url="true"><div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="quality-severity">Severity</label>
            <select class="form-select btn-touch" id="quality-severity" name="severity"><option value="">Any</option>
                <option value="error"<?= $f['severity'] === 'error' ? ' selected' : '' ?>>Error</option><option value="warn"<?= $f['severity'] === 'warn' ? ' selected' : '' ?>>Warn</option></select></div>
        <div class="col-6 col-md-4"><label class="form-label fs-12 mb-1" for="quality-department">Department</label>
            <select class="form-select btn-touch" id="quality-department" name="department"><option value="">Every department</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>"<?= (int) ($f['department'] ?? 0) === (int) $d['department_id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="quality-filter-btn">Filter</button></div>
    </div></form>
    <div class="row g-3">
        <div class="col-12 col-xl-4">
            <div class="card h-100" id="quality-failing"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0 me-auto">Failing checks</h5>
                <span class="badge bg-light text-dark" id="quality-failing-count"><?= count($h['failing']) ?></span></div>
            <?php if ($h['failing'] === []): ?>
                <div class="card-body"><div class="fs-12 text-muted" id="quality-failing-empty">No check is failing on what you may see.</div></div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                <?php foreach ($h['failing'] as $c): $last = $c['last_result'] ?? []; ?>
                    <?= hx_link(with_back('/datasets/' . $c['key'] . '/checks', '/quality'), '<div class="d-flex gap-2 align-items-start"><span class="health-dot ' . ($c['severity'] === 'error' ? 'bg-danger' : 'bg-warning') . ' mt-1" aria-hidden="true"></span><div class="min-w-0">'
                        . '<div class="fw-semibold text-truncate">' . e($c['key']) . '</div><div class="fs-12">' . e(check_words($c)) . '</div>'
                        . '<div class="fs-11 text-muted">value ' . e(check_num($last['value'] ?? null)) . ' · threshold ' . e((string) ($c['threshold'] ?? '—')) . ' · since ' . e(format_ts($c['since'], $tz, 'M j, g:i A')) . '</div></div></div>',
                        'list-group-item list-group-item-action', 'id="quality-failing-' . (int) $c['check_id'] . '"') ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?></div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="card h-100" id="quality-late"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0 me-auto">Late datasets</h5>
                <span class="badge bg-light text-dark" id="quality-late-count"><?= count($h['late']) ?></span></div>
            <?php if ($h['late'] === []): ?>
                <div class="card-body"><div class="fs-12 text-muted" id="quality-late-empty">Everything you may see is within its freshness expectation.</div></div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                <?php foreach ($h['late'] as $l): ?>
                    <?= hx_link(with_back('/datasets/' . $l['key'] . '?tab=quality', '/quality'), '<div class="d-flex gap-2 align-items-start"><span class="health-dot ' . ($l['state'] === 'error' ? 'bg-danger' : 'bg-warning') . ' mt-1" aria-hidden="true"></span><div class="min-w-0">'
                        . '<div class="fw-semibold text-truncate">' . e($l['key']) . '</div><div class="fs-12">' . ($l['state'] === 'error' ? 'Past its error limit' : 'Past its warning') . '</div>'
                        . '<div class="fs-11 text-muted">data updated ' . e($l['data_updated_at'] === null ? 'never' : format_ts($l['data_updated_at'], $tz, 'M j, g:i A')) . ' · overdue ' . e($l['overdue_s'] === null ? '—' : overdue_words((int) $l['overdue_s'])) . '</div></div></div>',
                        'list-group-item list-group-item-action', 'id="quality-late-' . (int) $l['dataset_id'] . '"') ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?></div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="card h-100" id="quality-incidents"><div class="card-header d-flex align-items-center"><h5 class="card-title mb-0 me-auto">Open incidents</h5>
                <span class="badge bg-light text-dark" id="quality-incidents-count"><?= (int) $h['incidents_total'] ?></span></div>
            <?php if ($h['incidents'] === []): ?>
                <div class="card-body"><div class="fs-12 text-muted" id="quality-incidents-empty">No open incident on what you may see.</div></div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                <?php foreach ($h['incidents'] as $i): $b = incident_badge((string) $i['status']); ?>
                    <?= hx_link(with_back('/incidents/' . (int) $i['incident_id'], '/quality'), '<div class="d-flex gap-2 align-items-start"><span class="health-dot ' . ($i['severity'] === 'error' ? 'bg-danger' : 'bg-warning') . ' mt-1" aria-hidden="true"></span><div class="min-w-0">'
                        . '<div class="fw-semibold">' . e($i['title']) . '</div><div class="fs-11 text-muted">' . $sevBadge((string) $i['severity']) . ' <span class="badge ' . e($b[0]) . '">' . e($b[1]) . '</span> '
                        . e($i['assignee_name'] ?? 'unassigned') . ' · opened ' . e(format_ts($i['opened_at'], $tz, 'M j, g:i A'))
                        . ((int) $i['downstream_count'] > 0 ? ' · reaches ' . (int) $i['downstream_count'] . ' downstream' : '') . '</div></div></div>',
                        'list-group-item list-group-item-action', 'id="quality-incident-' . (int) $i['incident_id'] . '"') ?>
                <?php endforeach; ?>
                </div>
            <?php endif; ?></div>
        </div>
    </div>
</div>
