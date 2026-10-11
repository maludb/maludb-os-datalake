<?php
declare(strict_types=1);
/** Action `saved_query_delete` (log saved_query.delete — own; confirm): **saved_query** — my own, soft-deleted (deleted_at). Lands on /queries. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/write.php';
dl_handler_begin();
$pdo = db();
$id = (int) (req_val('saved_query') ?? 0);
$q = $id > 0 ? find_saved_query($pdo, $id) : null;
if ($q === null || (int) $q['owner_member_id'] !== (int) current_member_id()) {
    respond_not_found('No such saved query of yours.');
}
dl_guard($pdo, static function () use ($pdo, $id, $q): void {
    $pdo->beginTransaction();
    saved_query_delete($pdo, $id);
    log_activity($pdo, 'saved_query.delete', 'saved_query', $id, ['before' => ['title' => $q['title'], 'datasets' => count($q['datasets']), 'shared' => (bool) $q['shared']]]);
    $pdo->commit();
});
dl_done('Deleted ' . $q['title'], $id, dl_land('/queries', 'deleted'), 'savedQueryChanged');
