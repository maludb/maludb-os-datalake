<?php
/** Incidents (screen `incident-list`): filters as a GET form, the table (incident-row-{id}), paging. Data: f, result, scope */
$tz = member_timezone();
$q = array_filter(['status' => $f['status'], 'severity' => $f['severity'], 'assignee' => $f['assignee'], 'dataset' => $scope], static fn ($v) => $v !== null && $v !== '');
$url = static function (array $change) use ($q): string {
    $p = array_filter(array_merge($q, $change), static fn ($v) => $v !== null && $v !== '');
    return '/incidents' . ($p === [] ? '' : '?' . http_build_query($p));
};
$more = $result['page'] * INCIDENT_PAGE < $result['total'];
?>
<?= view('shared/page-header.php', ['id' => 'incident-list', 'title' => 'Incidents' . ($scope !== null ? ' — ' . $scope : ''), 'crumbs' => [['Home', '/'], ['Quality', '/quality'], ['Incidents', null]]]) ?>
<div class="main-content" id="incident-list-content">
    <form method="get" action="/incidents" class="card mb-3" id="incident-list-filters" hx-get="/incidents" hx-target="#page-content" hx-push-url="true"><div class="card-body row g-2 align-items-end">
        <?php if ($scope !== null): ?><input type="hidden" name="dataset" value="<?= e($scope) ?>"><?php endif; ?>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="incident-list-status">Status</label>
            <select class="form-select btn-touch" id="incident-list-status" name="status">
                <?php foreach (['open' => 'Open', 'all' => 'Any'] + array_combine(INCIDENT_STATUSES, array_map('ucfirst', INCIDENT_STATUSES)) as $k => $w): ?>
                    <option value="<?= e($k) ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= e($w) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 mb-1" for="incident-list-severity">Severity</label>
            <select class="form-select btn-touch" id="incident-list-severity" name="severity"><option value="">Any</option>
                <option value="error"<?= $f['severity'] === 'error' ? ' selected' : '' ?>>Error</option><option value="warn"<?= $f['severity'] === 'warn' ? ' selected' : '' ?>>Warn</option></select></div>
        <div class="col-6 col-md-3 form-check ms-2 mb-2"><input class="form-check-input" type="checkbox" id="incident-list-mine" name="assignee" value="me"<?= $f['assignee'] === 'me' ? ' checked' : '' ?>>
            <label class="form-check-label fs-12" for="incident-list-mine">Assigned to me</label></div>
        <div class="col-6 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="incident-list-filter-btn">Filter</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-2" id="incident-list-total"><?= (int) $result['total'] ?> incident<?= $result['total'] === 1 ? '' : 's' ?></div>
    <?php if ($result['rows'] === []): ?>
        <div class="card"><div class="card-body"><div class="empty-state" id="incident-list-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-check-circle"></i></span>
            <div><div class="fw-semibold">No incidents here</div><div class="fs-12 text-muted">A failing check opens one; a passing run resolves it.</div></div></div></div></div>
    <?php else: ?>
        <div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle fs-12" id="incident-list-table">
            <thead><tr><th>Incident</th><th class="d-none d-md-table-cell">Dataset</th><th class="d-none d-lg-table-cell">Kind</th><th>Severity</th><th>Status</th>
                <th class="d-none d-md-table-cell">Assignee</th><th class="d-none d-lg-table-cell">Opened</th><th class="d-none d-lg-table-cell">Resolved</th></tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $i): $b = incident_badge((string) $i['status']); $s = severity_badge((string) $i['severity']); ?>
                <tr id="incident-row-<?= (int) $i['incident_id'] ?>">
                    <td><?= hx_link(with_back('/incidents/' . (int) $i['incident_id'], here_url()), e($i['title']), 'fw-semibold') ?></td>
                    <td class="d-none d-md-table-cell font-monospace"><?= hx_link('/datasets/' . $i['key'], e($i['key'])) ?></td>
                    <td class="d-none d-lg-table-cell"><?= e($i['kind']) ?></td>
                    <td><span class="badge <?= e($s[0]) ?>"><?= e($s[1]) ?></span></td>
                    <td><span class="badge <?= e($b[0]) ?>"><?= e($b[1]) ?></span></td>
                    <td class="d-none d-md-table-cell"><?= e($i['assignee_name'] ?? '—') ?></td>
                    <td class="d-none d-lg-table-cell text-nowrap"><?= e(format_ts($i['opened_at'], $tz, 'M j, g:i A')) ?></td>
                    <td class="d-none d-lg-table-cell text-nowrap"><?= e($i['resolved_at'] === null ? '—' : format_ts($i['resolved_at'], $tz, 'M j, g:i A')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
        <div class="d-flex gap-2 mt-2">
            <?php if ($result['page'] > 1): ?><?= hx_link($url(['page' => $result['page'] - 1]), 'Newer', 'btn btn-light btn-sm btn-touch', 'id="incident-list-prev"') ?><?php endif; ?>
            <?php if ($more): ?><?= hx_link($url(['page' => $result['page'] + 1]), 'Older', 'btn btn-light btn-sm btn-touch', 'id="incident-list-next"') ?><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
