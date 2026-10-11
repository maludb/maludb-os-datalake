<?php
declare(strict_types=1);
/**
 * Action `query_verify` (log query.verify — queries.verify; an agent's pauses, `other`): **saved_query**, verify (yes — the badge with my
 * name, for THIS SQL; no — the badge removed). A query I may see (mcp_saved_queries). The database's dl_saved_query_verify() checks the right
 * again and keeps the SQL's hash, so an edit drops the badge by itself.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/saved_queries/write.php';
dl_handler_begin();
require_right('queries.verify');
$pdo = db();
$id = (int) (req_val('saved_query') ?? 0);
$q = $id > 0 ? find_saved_query($pdo, $id) : null;
if ($q === null) {
    respond_not_found('No such saved query.');
}
$on = dl_yes('verify', true);
dl_guard($pdo, static function () use ($pdo, $id, $on, $q): void {
    $pdo->beginTransaction();
    query_verify($pdo, $id, $on);
    log_activity($pdo, 'query.verify', 'saved_query', $id, ['before' => ['verified' => $q['verified_at'] !== null], 'after' => ['verified' => $on, 'title' => $q['title']]]);
    $pdo->commit();
});
dl_done($on ? 'Verified ' . $q['title'] : 'Removed the badge from ' . $q['title'], $id, dl_land(return_path('/queries/' . $id), $on ? 'verified' : 'unverified'), 'savedQueryChanged',
    ['verified' => $on]);
