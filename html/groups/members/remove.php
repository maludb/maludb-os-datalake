<?php
declare(strict_types=1);
/** Action `group_member_remove` (log group.member_remove — grants.manage or an owner of the group; confirm): **group**, **member** (left_at — never DELETE; its grants stop reaching them). Undo: group_member_add. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/groups/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/groups/write.php';
dl_handler_begin();
$pdo = db();
$gid = (int) (req_val('group') ?? 0);
$g = $gid > 0 ? find_group($pdo, $gid) : null;
if ($g === null) {
    respond_not_found('No such group.');
}
$me = (int) current_member_id();
if (!may_manage_group($pdo, $g, $me)) {
    refuse(403, 'Only a Lake admin or an owner of the group changes its members.');
}
if ($g['kind'] === 'department') {
    refuse(422, 'A department\'s group follows the department — change who is in ' . ($g['department_name'] ?? 'it') . ' in the directory.');
}
$member = (int) (req_val('member') ?? 0);
dl_guard($pdo, static function () use ($pdo, $gid, $member): void {
    $pdo->beginTransaction();
    group_member_remove($pdo, $gid, $member);
    log_activity($pdo, 'group.member_remove', 'group', $gid, ['before' => ['member_id' => $member], 'after' => ['left' => true]]);
    $pdo->commit();
});
dl_done('Removed from ' . $g['name'], $gid, dl_land('/groups/' . $gid, 'member_removed'), 'groupChanged', ['member_id' => $member]);
