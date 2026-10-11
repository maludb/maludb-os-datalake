<?php
declare(strict_types=1);
/**
 * POST /sql/run — the editor's Run (and Explain: dry_run=1) — A READ DOOR, never a registry action (query-service-editor.md divergence 1): POST
 * + CSRF, sql.editor; the session is released BEFORE the lake is asked, so Cancel (another request of the same session) is never queued
 * behind the run. Fields: sql, as_of (a snapshot or a time), params[name] (bound by the service as literals), request_id (the page's — Cancel
 * names it), dry_run. One run_sql(): the result partial (the table ≤ the display cap, the truncation notice, each masked column marked, quick
 * stats on the shown rows, the run's link) or the refusal partial (the service's sentence, the dataset, Request access). JSON: {result}.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/present.php';
require_post();
require_login();
verify_csrf();
require_right('sql.editor');
$pdo = db();
$me = (int) current_member_id();
$sql = (string) ($_POST['sql'] ?? '');
$asOf = trim((string) ($_POST['as_of'] ?? ''));
$asOf = $asOf === '' ? null : (ctype_digit($asOf) ? (int) $asOf : $asOf);
$params = [];
foreach ((array) ($_POST['params'] ?? []) as $k => $v) {
    if (is_string($k) && !is_array($v)) {
        $params[$k] = (string) $v;
    }
}
$rid = editor_request_id_from($_POST['request_id'] ?? null);
$dry = request_bool('dry_run');
session_write_close();                                       // Cancel must not wait for this request's session lock
$r = run_sql($pdo, $me, $sql, $asOf, $params, ['request_id' => $rid, 'dry_run' => $dry]);
$stats = $r['status'] === 'ok' && !$dry ? result_stats($r['columns'], $r['rows']) : null;
if (wants_json()) {
    respond_screen(['result' => present_result($r, $stats)]);
}
if (!is_htmx_request()) {                                    // without JavaScript the form posts here: the whole screen comes back with the result
    render_screen('SQL', view('sql/index.php', ['pre' => ['sql' => $sql, 'saved_query' => null, 'title' => null], 'catalog' => editor_catalog($pdo, $me),
        'runs' => my_runs($pdo, $me, 20), 'as_of' => $asOf === null ? '' : (string) $asOf, 'request_id' => editor_request_id(), 'result' => $r, 'stats' => $stats]),
        ['activeNav' => 'sql', 'screen' => 'sql']);
    exit;
}
echo view($r['status'] === 'ok' ? 'sql/partials/result.php' : 'sql/partials/refusal.php', ['r' => $r, 'stats' => $stats, 'sql' => $sql, 'as_of' => $asOf]);
