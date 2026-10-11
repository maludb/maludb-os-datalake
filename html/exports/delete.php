<?php
declare(strict_types=1);
/** Action `export_delete` (log export.delete — own; confirm): **export** — the row goes now (the door answers 404 at once); the writer's sweep unlinks the file. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/exports/write.php';
dl_handler_begin();
$pdo = db();
$id = (int) (req_val('export') ?? 0);
$x = $id > 0 ? one_row($pdo, 'SELECT export_id, format, row_count, dataset_id, created_by FROM mcp_exports WHERE export_id = :id AND created_by = :m',
    ['id' => $id, 'm' => (int) current_member_id()]) : null;
if ($x === null) {
    respond_not_found('No such export of yours.');
}
dl_guard($pdo, static function () use ($pdo, $id, $x): void {
    $pdo->beginTransaction();
    export_delete($pdo, $id);
    log_activity($pdo, 'export.delete', 'export', $id, ['dataset_id' => $x['dataset_id'] === null ? null : (int) $x['dataset_id'],
        'before' => ['format' => $x['format'], 'rows' => $x['row_count'] === null ? null : (int) $x['row_count']]]);
    $pdo->commit();
});
dl_done('Deleted the export', $id, dl_land('/exports', 'deleted'), 'exportQueued');
