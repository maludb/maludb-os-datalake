<?php
declare(strict_types=1);
/** /groups/new (screen `group-add`, grants.manage) and /groups/{id}/edit (screen `group-edit`, grants.manage or an owner of a custom group). JSON: the values. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/access/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/queries.php';
require_login();
require_right('catalog.browse');
$pdo = db();
$id = request_integer('id');
$g = null;
if ($id === null) {
    require_right('grants.manage');
    $kind = request_string('kind') === 'department' ? 'department' : 'custom';
    $v = ['kind' => $kind, 'department_id' => request_integer('department'), 'name' => mb_substr(trim(request_string('name')), 0, 120), 'key' => '', 'description' => ''];
} else {
    $g = find_group($pdo, $id) ?? respond_not_found('No such group.');
    if (!may_manage_group($pdo, $g, (int) current_member_id())) {
        refuse(403, 'Only a Lake admin or an owner of the group changes it.');
    }
    $v = ['kind' => $g['kind'], 'department_id' => $g['department_id'], 'name' => $g['name'], 'key' => $g['key'], 'description' => $g['description']];
}
$screen = $id === null ? 'group-add' : 'group-edit';
log_screen_view($pdo, $screen, $id === null ? [] : ['entity_type' => 'group', 'entity_id' => $id]);
if (wants_json()) {
    respond_screen(['group_id' => $id, 'values' => $v, 'kinds' => array_keys(GROUP_KINDS)]);
}
render_screen($id === null ? 'New group' : 'Edit ' . $g['name'], view('groups/form.php', ['g' => $g, 'v' => $v, 'errors' => []]),
    ['activeNav' => 'access', 'screen' => $screen, 'entity' => 'group', 'recordId' => $id === null ? '' : (string) $id]);
