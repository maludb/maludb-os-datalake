<?php
declare(strict_types=1);
/**
 * /runs/{id} (screen `query-run-view` — query-service-editor.md): one query run — who (and on whose behalf, which agent run), the source, the
 * datasets (links), the masks and row filters applied (NAMES only), rows, truncated, bytes, time, status, the refusal's rule. The SQL to its
 * caller, the person it acted for and audit.read; "hidden" to a dataset's owner (mcp_query_runs decides both). 404 for anyone else.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/runs/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/runs/present.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/editor/present.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = (int) (request_integer('id') ?? 0);
$r = $id > 0 ? find_run($pdo, $id) : null;
if ($r === null) {
    respond_not_found('No such query run.');
}
log_screen_view($pdo, 'query-run-view', ['entity_type' => 'query_run', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['run' => present_run($r)]);
}
render_screen('Run #' . $id, view('runs/view.php', ['r' => present_run($r)]), ['activeNav' => has_right('sql.editor') ? 'sql' : 'queries', 'screen' => 'query-run-view',
    'entity' => 'query_run', 'recordId' => $id]);
