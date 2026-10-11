<?php
declare(strict_types=1);
/**
 * Actions `group_create` (log group.create — grants.manage): **name**, key, description, kind (custom or department), department (for a mirror —
 * one live mirror per department; its members follow the directory); and `group_update` (log group.update — grants.manage or an owner of a
 * custom group): **group**, name, description. Undo: group_archive / the prior words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/groups/write.php';
dl_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$gid = req_has('group') && req_val('group') !== '' ? (int) req_val('group') : null;
if ($gid === null) {
    require_right('grants.manage');
    $kind = dl_choice('kind', 'custom', array_keys(GROUP_KINDS), 'The kind', $errors, false) ?? 'custom';
    $dep = null;
    if ($kind === 'department') {
        $dep = dl_ref($pdo, 'department', null, 'SELECT 1 FROM mcp_departments WHERE department_id = :id AND archived_at IS NULL', 'department', $errors, false);
        if ($dep !== null && db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM mcp_groups WHERE department_id = :d AND archived_at IS NULL)', ['d' => $dep])) {
            $errors['department'] = 'That department already has its group.';
        }
    }
    $name = dl_text('name', null, 120, 'The name', $errors);
    if ($name === null && $kind === 'department' && $dep !== null) {
        $name = (string) one_value($pdo, 'SELECT name FROM mcp_departments WHERE department_id = :d', ['d' => $dep]);
    }
    if ($name === null && !isset($errors['name'])) {
        $errors['name'] = 'The name is required.';
    }
    $key = dl_text('key', null, 60, 'The key', $errors);
    if ($key !== null && !preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', $key)) {
        $errors['key'] = 'A key is lowercase letters, digits, - and _ (up to 60).';
    } elseif ($key !== null && db_bool($pdo, 'SELECT EXISTS (SELECT 1 FROM groups WHERE key = :k)', ['k' => $key])) {
        $errors['key'] = 'That key is taken.';
    }
    $description = dl_text('description', null, 2000, 'The description', $errors);
    if ($errors !== []) {
        dl_refuse_fields($errors);
    }
    $key ??= group_key_from($pdo, (string) $name);
    $id = dl_guard($pdo, static function () use ($pdo, $name, $key, $description, $kind, $dep, $me): int {
        $pdo->beginTransaction();
        $id = group_save($pdo, null, ['name' => $name, 'key' => $key, 'description' => $description, 'kind' => $kind, 'department_id' => $dep], $me);
        log_activity($pdo, 'group.create', 'group', $id, ['after' => ['name' => $name, 'key' => $key, 'kind' => $kind, 'department_id' => $dep]]);
        $pdo->commit();
        return $id;
    });
    dl_done('Made the group ' . $name, $id, dl_land('/groups/' . $id, 'created'), 'groupChanged');
}
$g = find_group($pdo, $gid) ?? respond_not_found('No such group.');
if (!may_manage_group($pdo, $g, $me)) {
    refuse(403, 'Only a Lake admin or an owner of the group changes it.');
}
if ($g['archived_at'] !== null) {
    refuse(422, 'An archived group does not change.');
}
$name = dl_text('name', (string) $g['name'], 120, 'The name', $errors, true);
$description = dl_text('description', $g['description'], 2000, 'The description', $errors);
if ($errors !== []) {
    dl_refuse_fields($errors);
}
$diff = dl_diff(['name' => $g['name'], 'description' => $g['description']], ['name' => $name, 'description' => $description]);
dl_guard($pdo, static function () use ($pdo, $gid, $name, $description, $me, $diff): void {
    $pdo->beginTransaction();
    group_save($pdo, $gid, ['name' => $name, 'description' => $description], $me);
    log_activity($pdo, 'group.update', 'group', $gid, ['before' => $diff['before'], 'after' => array_map(static fn ($v) => is_string($v) && mb_strlen($v) > 200 ? ['length' => mb_strlen($v)] : $v, $diff['after'])]);
    $pdo->commit();
});
dl_done('Saved ' . $name, $gid, dl_land('/groups/' . $gid, 'saved'), 'groupChanged');
