<?php /** My activity (screen `activity`): my own trail here, in sentences. Data: rows, page, more, filters, query, tz, resultsHtml */
$actions = ['' => 'Anything', 'dataset.' => 'Datasets', 'column.' => 'Columns', 'search.' => 'Searches', 'query.' => 'Queries', 'upload.' => 'Uploads', 'load.' => 'Loads',
    'access.' => 'Access', 'grant.' => 'Grants', 'check.' => 'Checks', 'incident.' => 'Incidents', 'export.' => 'Exports', 'settings.' => 'Settings', 'key.' => 'API keys',
    'token.' => 'Tokens', 'member.' => 'Sign-ons', 'screen.' => 'Screens opened'];
?>
<?= view('shared/page-header.php', ['id' => 'activity', 'title' => 'My activity', 'crumbs' => [['Home', '/'], ['My activity', null]], 'back' => back_link()]) ?>
<div class="main-content" id="activity-content">
    <div class="card stretch stretch-full" id="activity-card">
        <div class="card-header">
            <h5 class="card-title">What you did here</h5>
            <form id="activity-filters" class="d-flex flex-wrap gap-2" method="get" action="/activity" hx-get="/activity" hx-target="#activity-results" hx-swap="outerHTML" hx-trigger="change" hx-push-url="true">
                <select name="action" id="activity-filter-action" class="form-select form-select-sm w-auto btn-touch" aria-label="What"><?php foreach ($actions as $v => $l): ?><option value="<?= e($v) ?>" <?= $filters['action'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <select name="period" id="activity-filter-period" class="form-select form-select-sm w-auto btn-touch" aria-label="Period"><option value="">All time</option><?php foreach ([1 => 'Today', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $v => $l): ?><option value="<?= $v ?>" <?= $filters['since'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <noscript><button type="submit" class="btn btn-light btn-sm">Show</button></noscript>
            </form>
        </div>
        <?= $resultsHtml ?>
    </div>
</div>
