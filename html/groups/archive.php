<?php
declare(strict_types=1);
/** Action `group_archive` (log group.archive — grants.manage; confirm): **group** (its grants stay until revoked — they reach nobody). Undo: group_update. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/write.php';
dl_handler_begin();
require_right('grants.manage');
$pdo = db();
$id = (int) (req_val('group') ?? 0);
$g = $id > 0 ? find_group($pdo, $id) : null;
if ($g === null) {
    respond_not_found('No such group.');
}
dl_guard($pdo, static function () use ($pdo, $id): void {
    $pdo->beginTransaction();
    group_archive($pdo, $id);
    log_activity($pdo, 'group.archive', 'group', $id, ['before' => ['archived' => false], 'after' => ['archived' => true]]);
    $pdo->commit();
});
dl_done('Archived ' . $g['name'], $id, dl_land('/groups', 'archived'), 'groupChanged');
