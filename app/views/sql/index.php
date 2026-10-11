<?php
/**
 * The SQL editor (screen `sql`): desk-first — my readable datasets on the left (a click inserts the key or the column), the editor, the result,
 * my last runs; at 375 the editor full width and the catalog below it. The completion list rides in #sql-completion (JSON) for
 * assets/js/sql-editor.js. Data: pre, catalog, runs, as_of, request_id, result?, stats?
 */
$completion = ['keys' => [], 'columns' => []];
foreach ($catalog as $d) {
    $completion['keys'][] = $d['key'];
    $completion['columns'][$d['key']] = array_map(static fn (array $c): string => (string) $c['name'], $d['columns']);
}
$result = $result ?? null;
?>
<?= view('shared/page-header.php', ['id' => 'sql', 'title' => 'SQL', 'crumbs' => [['Home', '/'], ['SQL', null]],
    'action' => hx_link('/queries', '<i class="feather-bookmark me-1"></i>Saved queries', 'btn btn-light btn-touch', 'id="sql-queries-btn"')]) ?>
<div class="main-content" id="sql-content">
    <div class="row g-3">
        <div class="col-12 col-lg-9 order-1 order-lg-2">
            <?= view('sql/partials/editor.php', ['pre' => $pre, 'as_of' => $as_of, 'request_id' => $request_id]) ?>
            <div id="sql-result" class="mt-3" aria-live="polite"><?php if ($result !== null): ?><?= view($result['status'] === 'ok' ? 'sql/partials/result.php' : 'sql/partials/refusal.php',
                ['r' => $result, 'stats' => $stats ?? null, 'sql' => $pre['sql'], 'as_of' => $as_of]) ?><?php endif; ?></div>
            <?= view('sql/partials/runs.php', ['runs' => $runs]) ?>
        </div>
        <div class="col-12 col-lg-3 order-2 order-lg-1"><?= view('sql/partials/catalog.php', ['catalog' => $catalog]) ?></div>
    </div>
</div>
<script type="application/json" id="sql-completion"><?= json_encode($completion, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>
