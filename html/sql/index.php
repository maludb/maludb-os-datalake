<?php
declare(strict_types=1);
/**
 * /sql (screen `sql` — query-service-editor.md, THE SECOND EXEMPLAR): desk-first, the editor full width at 375 with the result as records.
 * Left, MY READABLE DATASETS with their columns (a click inserts the key or the column); the editor — a <textarea>, completion of those keys
 * and columns by assets/js/sql-editor.js (no third-party editor), the as-of field, Run (POST /sql/run, HTMX into #sql-result), Explain (the
 * dry run's plan), Cancel while running (POST /sql/cancel); the result below; my last 20 runs. ?dataset={key} starts with
 * SELECT * FROM {key} LIMIT 100 (a dataset I may read); ?saved_query={id} with its SQL. sql.editor.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('sql.editor');
$pdo = db();
$me = (int) current_member_id();
$pre = editor_prefill($pdo, request_string('dataset'), request_string('saved_query'));
$catalog = editor_catalog($pdo, $me);
$runs = my_runs($pdo, $me, 20);
$asOf = request_string('as_of');
log_screen_view($pdo, 'sql');
if (wants_json()) {
    respond_screen(['sql' => $pre['sql'], 'saved_query' => $pre['saved_query'], 'as_of' => $asOf === '' ? null : $asOf,
        'catalog' => array_map('present_catalog_entry', $catalog), 'runs' => array_map('present_run_row', $runs),
        'limits' => ['rows_shown' => (int) one_value($pdo, "SELECT dl_setting_int('query_rows_shown')"), 'timeout_s' => lake_timeout('screen')],
        'may' => ['save' => has_right('queries.save'), 'export' => has_right('export.own'), 'sandbox' => has_right('sandbox.save')]]);
}
render_screen('SQL', view('sql/index.php', ['pre' => $pre, 'catalog' => $catalog, 'runs' => $runs, 'as_of' => $asOf, 'request_id' => editor_request_id()]),
    ['activeNav' => 'sql', 'screen' => 'sql']);
