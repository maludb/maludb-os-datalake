<?php
declare(strict_types=1);
/** /groups/{id} (screen `group-view`) — its members (Add / Remove for grants.manage or an owner of a custom group), a mirror's read-only; the grants it holds. JSON: the group, members, grants. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/access/present.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/present.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = request_integer('id') ?? respond_not_found('No such group.');
$g = find_group($pdo, $id) ?? respond_not_found('No such group.');
$me = (int) current_member_id();
$members = group_members($pdo, $id);
$grants = find_grants($pdo, ['principal' => 'group:' . $id, 'include_revoked' => false], 1)['rows'];
$mayManage = may_manage_group($pdo, $g, $me);
log_screen_view($pdo, 'group-view', ['entity_type' => 'group', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['group' => present_group($g), 'members' => array_map('present_group_member', $members), 'grants' => array_map('present_group_grant_row', $grants),
                    'may_manage' => $mayManage && $g['kind'] === 'custom']);
}
$notices = ['saved' => ['success', 'Saved.'], 'created' => ['success', 'Made. Add its members, then grant it a level.'], 'member_added' => ['success', 'Added.'], 'member_removed' => ['success', 'Removed — its grants no longer reach them.']];
render_screen((string) $g['name'], view('groups/view.php', ['g' => $g, 'members' => $members, 'grants' => $grants, 'may_manage' => $mayManage, 'may_archive' => has_right('grants.manage'),
    'notice' => dl_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'access', 'screen' => 'group-view', 'entity' => 'group', 'recordId' => (string) $id]);

function present_group_grant_row(array $g): array
{
    return present_grant($g);
}
