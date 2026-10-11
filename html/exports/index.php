<?php
declare(strict_types=1);
/**
 * /exports (screen `exports` — query-service-editor.md): my exports as a table — the format, the dataset or "a result", rows, truncated,
 * masked, the status (Queued / Making / Ready / Failed / Expired), made, expires; Download (the door, 404 once expired) and Delete. export.own.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/catalog/present.php';
require_login();
require_right('export.own');
$pdo = db();
$rows = my_exports($pdo, (int) current_member_id(), 100);
log_screen_view($pdo, 'exports');
if (wants_json()) {
    respond_screen(['rows' => array_map(static fn (array $x): array => ['export_id' => (int) $x['export_id'], 'kind' => $x['kind'], 'format' => $x['format'],
        'status' => !empty($x['expired']) ? 'expired' : $x['status'], 'dataset' => $x['dataset_key'], 'rows' => $x['row_count'] === null ? null : (int) $x['row_count'],
        'truncated' => (bool) $x['truncated'], 'masked' => (bool) $x['masked'], 'bytes' => $x['byte_size'] === null ? null : (int) $x['byte_size'],
        'query_run_id' => $x['query_run_id'] === null ? null : (int) $x['query_run_id'], 'created_at' => json_ts($x['created_at']), 'finished_at' => json_ts($x['finished_at']),
        'expires_at' => json_ts($x['expires_at']), 'download' => $x['status'] === 'done' && empty($x['expired']) ? '/exports/' . (int) $x['export_id'] . '/download' : null], $rows)]);
}
render_screen('My exports', view('exports/index.php', ['rows' => $rows, 'notice' => dl_notice(request_string('notice') ?: null,
    ['queued' => ['info', 'The file is being made — it appears here when ready, and you are told.'], 'deleted' => ['secondary', 'Deleted.']])]),
    ['activeNav' => has_right('sql.editor') ? 'sql' : 'queries', 'screen' => 'exports']);
