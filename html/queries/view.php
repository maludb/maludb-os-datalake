<?php
declare(strict_types=1);
/**
 * /queries/{id} (screen `query-view`): one saved query — its question, description, the SQL (read-only), the parameters as a form (name, type,
 * default), the datasets it reads; Run (?run=1 with p[name]=…) shows the result only, as the editor's partial; Verify / Unverify
 * (queries.verify), Edit and Delete (its owner). Mine, or shared and readable (mcp_saved_queries) — else 404. queries.run.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/present.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/present.php';
require_login();
require_right('queries.run');
$pdo = db();
$me = (int) current_member_id();
$id = (int) (request_integer('id') ?? 0);
$q = $id > 0 ? find_saved_query($pdo, $id) : null;
if ($q === null) {
    respond_not_found('No such saved query.');
}
$result = null;
$stats = null;
$errors = [];
$given = is_array($_GET['p'] ?? null) ? array_map(static fn ($v): string => is_array($v) ? '' : (string) $v, $_GET['p']) : [];
if (request_bool('run')) {
    $bound = saved_query_bind($q['parameters'], $given, $errors);
    if ($errors === []) {
        session_write_close();
        $result = run_sql($pdo, $me, (string) $q['sql'], null, $bound, ['saved_query' => $q, 'request_id' => editor_request_id()]);
        $stats = $result['status'] === 'ok' ? result_stats($result['columns'], $result['rows']) : null;
    }
} else {
    log_screen_view($pdo, 'query-view', ['entity_type' => 'saved_query', 'entity_id' => $id]);
}
if (wants_json()) {
    if ($errors !== []) {
        respond_invalid(array_values($errors), $errors);
    }
    respond_screen(['saved_query' => present_saved_query($q), 'result' => $result === null ? null : present_result($result, $stats),
        'may' => ['edit' => (int) $q['owner_member_id'] === $me && has_right('queries.save'), 'verify' => has_right('queries.verify')]]);
}
if ($result !== null && is_htmx_request() && request_string('partial') === '1') {
    echo view($result['status'] === 'ok' ? 'sql/partials/result.php' : 'sql/partials/refusal.php', ['r' => $result, 'stats' => $stats, 'sql' => (string) $q['sql'], 'as_of' => null]);
    exit;
}
render_screen((string) $q['title'], view('queries/view.php', ['q' => $q, 'result' => $result, 'stats' => $stats, 'errors' => $errors, 'given' => $given,
    'notice' => dl_notice(request_string('notice') ?: null, ['saved' => ['success', 'Saved.'], 'created' => ['success', 'Saved.'], 'verified' => ['success', 'Verified.'],
        'unverified' => ['secondary', 'The badge is removed.'], 'refused' => ['warning', 'Saved — but the lake refuses it to you today: Run says why.']])]),
    ['activeNav' => 'queries', 'screen' => 'query-view', 'entity' => 'saved_query', 'recordId' => $id]);
