<?php
declare(strict_types=1);
/** Action `group_member_add` (log group.member_add — grants.manage or an owner of the group): **group**, **member**, role (owner or member). A department's group follows the directory — never here. Undo: group_member_remove. */
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
if ($g['archived_at'] !== null) {
    refuse(422, 'An archived group does not change.');
}
$errors = [];
$member = dl_ref($pdo, 'member', null, 'SELECT 1 FROM mcp_members WHERE member_id = :id', 'member', $errors, false);
$role = dl_choice('role', 'member', ['member', 'owner'], 'The role', $errors, false) ?? 'member';
if ($errors !== []) {
    dl_refuse_fields($errors);
}
dl_guard($pdo, static function () use ($pdo, $gid, $member, $role, $me): void {
    $pdo->beginTransaction();
    group_member_add($pdo, $gid, (int) $member, $role, $me);
    log_activity($pdo, 'group.member_add', 'group', $gid, ['after' => ['member_id' => $member, 'role' => $role]]);
    $pdo->commit();
});
dl_done('Added to ' . $g['name'], $gid, dl_land('/groups/' . $gid, 'member_added', 'group-member-' . $member), 'groupChanged', ['member_id' => $member]);
