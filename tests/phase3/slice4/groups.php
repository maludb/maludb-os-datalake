<?php
/**
 * Slice 4, proof 4 — groups (access-policies.md "Proof" 4): a custom group made by the admin, an owner adding a member; a department mirror
 * follows the directory sync (a member leaves the department → leaves the group); the grants a group holds reach its members and stop when they
 * leave; a mirror's members never changed here; archive.
 */
require __DIR__ . '/lib.php';
$deals = ds_id('raw.crm_deals');
$tag = run_tag();
pg("UPDATE groups SET archived_at = now() WHERE department_id = 8 AND archived_at IS NULL");      // a re-run makes the mirror again
$a0 = last_activity_id();

echo "1. A custom group: the admin makes it, an owner adds a member\n";
ok(post_json(26, '/groups/save.php', ['name' => 'SMOKE Not mine'])['code'] === 403, 'making a group is grants.manage\'s (Priya: 403)');
$c = post_json(1, '/groups/save.php', ['name' => "SMOKE Night shift $tag", 'description' => 'Who closes the books at night.']);
$gid = (int) ($c['json']['record_id'] ?? 0);
ok($c['code'] === 200 && $gid > 0 && pg1("SELECT kind || '|' || key FROM groups WHERE id = $gid") === "custom|smoke-night-shift-$tag", 'the admin makes a custom group — its key from its name');
ok((int) pg1("SELECT count(*) FROM group_members WHERE group_id = $gid AND left_at IS NULL") === 0, 'it starts empty — its maker is no member (a group\'s grants never reach the admin by making it)');
ok(post_json(1, '/groups/members/add.php', ['group' => $gid, 'member' => 26, 'role' => 'owner'])['code'] === 200, 'the admin makes Priya its owner');
$add = post_json(26, '/groups/members/add.php', ['group' => $gid, 'member' => 30]);
ok($add['code'] === 200 && pg1("SELECT role FROM group_members WHERE group_id = $gid AND member_id = 30 AND left_at IS NULL") === 'member', 'Priya, its owner, adds Dana');
ok(post_json(30, '/groups/members/add.php', ['group' => $gid, 'member' => 27])['code'] === 403, 'Dana (a member, not an owner) may not');
ok(post_json(26, '/groups/archive.php', ['group' => $gid])['code'] === 403, 'archiving is the admin\'s: Priya 403');
$ed = post_json(26, '/groups/save.php', ['group' => $gid, 'name' => "SMOKE Night closers $tag"]);
ok($ed['code'] === 200 && pg1("SELECT name FROM groups WHERE id = $gid") === "SMOKE Night closers $tag", 'its owner renames it (group_update)');
$view = get_json(26, "/groups/$gid")['json']['data'] ?? [];
ok(count($view['members'] ?? []) === 2 && ($view['may_manage'] ?? false) === true && ($view['group']['kind'] ?? '') === 'custom', 'the group\'s page: two members, Priya may manage it');

echo "2. The grants a group holds reach its members — and stop when they leave\n";
$g = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'group', 'principal' => $gid, 'level' => 'read', 'reason' => 'SMOKE the night shift reads deals']);
$grant = (int) ($g['json']['record_id'] ?? 0);
ok($g['code'] === 200 && ref_level($deals, 30) === 'read' && (preview_as(30, $deals)['status'] ?? '') === 'ok', 'Marco grants the group read on deals: Dana reads them');
$pg = get_json(26, "/groups/$gid")['json']['data']['grants'] ?? [];
ok($pg === [], 'the group\'s owner sees no grant on its page she may not see (mcp_grants: the ledger is the owner\'s and the admin\'s)');
ok(in_array($grant, array_column(get_json(1, "/groups/$gid")['json']['data']['grants'] ?? [], 'grant_id'), true), 'the admin sees the grant the group holds');
$rm = post_json(26, '/groups/members/remove.php', ['group' => $gid, 'member' => 30]);
ok($rm['code'] === 200 && pg1("SELECT (left_at IS NOT NULL)::text FROM group_members WHERE group_id = $gid AND member_id = 30") === 'true', 'Priya removes Dana — left_at, never deleted');
ok(ref_level($deals, 30) === 'none' && get_json(30, "/datasets/$deals/preview")['code'] === 404, '…and the group\'s grant stops reaching her at once');
ok(post_json(1, '/groups/members/add.php', ['group' => $gid, 'member' => 30])['code'] === 200 && ref_level($deals, 30) === 'read', 'added back: she reads again');

echo "3. A department's mirror follows the directory\n";
$m = post_json(1, '/groups/save.php', ['kind' => 'department', 'department' => 8]);
$mid = (int) ($m['json']['record_id'] ?? 0);
$members = static fn (int $g): array => array_map('intval', pg("SELECT member_id FROM group_members WHERE group_id = $g AND left_at IS NULL ORDER BY member_id"));
ok($m['code'] === 200 && pg1("SELECT kind || '|' || name FROM groups WHERE id = $mid") === 'department|Sales' && $members($mid) === [26, 30, 31],
   'a mirror of Sales: named for it, its members the department\'s (Priya, Dana, Lee)');
ok(post_json(1, '/groups/save.php', ['kind' => 'department', 'department' => 8])['code'] === 422, 'one live mirror per department: a second refused');
$ad = post_json(1, '/groups/members/add.php', ['group' => $mid, 'member' => 27]);
ok($ad['code'] === 422 && str_contains(message_of($ad), 'follows the department'), 'a mirror\'s members are not added here: "follows the department"');
$mg = post_json(1, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'group', 'principal' => $mid, 'level' => 'discover', 'reason' => 'SMOKE Sales knows the deals']);
kernel_state(function (array $s): array {
    foreach ($s['feed']['memberships'] as &$x) {
        if ((int) $x['member_id'] === 30 && (int) $x['department_id'] === 8) {
            $x['left_at'] = gmdate('Y-m-d\TH:i:s\Z');
        }
    }
    return $s;
});
shell_exec('php bin/directory_sync.php --full 2>/dev/null');
ok($members($mid) === [26, 31], 'Dana leaves Sales in the directory → after the sync she has left the mirror');
pg("UPDATE grants SET revoked_at = now() WHERE id = $grant");
ok(ref_level($deals, 30) === 'none' && ref_level($deals, 26) !== 'none', '…the mirror\'s grant no longer reaches her (Priya, still in Sales, keeps it)');
kernel_state(function (array $s): array {
    foreach ($s['feed']['memberships'] as &$x) {
        if ((int) $x['member_id'] === 30 && (int) $x['department_id'] === 8) {
            $x['left_at'] = null;
        }
    }
    return $s;
});
shell_exec('php bin/directory_sync.php --full 2>/dev/null');
ok($members($mid) === [26, 30, 31], 'back in Sales → back in the mirror');
pg('UPDATE grants SET revoked_at = now() WHERE id = ' . (int) ($mg['json']['record_id'] ?? 0));

echo "4. Archive\n";
$ar = post_json(1, '/groups/archive.php', ['group' => $gid]);
ok($ar['code'] === 200 && pg1("SELECT (archived_at IS NOT NULL)::text FROM groups WHERE id = $gid") === 'true', 'the admin archives the night shift');
ok(!in_array($gid, array_column(get_json(1, '/groups')['json']['data']['groups'] ?? [], 'group_id'), true), 'it leaves the groups\' cards (Archived shows it)');
ok(post_json(1, '/groups/members/add.php', ['group' => $gid, 'member' => 27])['code'] === 422, 'an archived group does not change');
ok(count(activity('group.create', $a0)) === 2 && count(activity('group.member_add', $a0)) === 3 && count(activity('group.member_remove', $a0)) === 1
   && count(activity('group.update', $a0)) === 1 && count(activity('group.archive', $a0)) === 1, 'every change logged: group.create, update, member_add, member_remove, archive');
ok(get_json(29, '/groups')['code'] === 403, 'an external sees no groups');
finish();
