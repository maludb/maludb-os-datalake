<?php
/**
 * Slice 4, proof 3 — grants (access-policies.md "Proof" 3; access-and-policies §2): a department grant with `department_id = ANY({departments})`
 * checked, saved and the preview of a Sales member filtered; a bad filter refused on the field; the owner's reach (their datasets only — a zone
 * or a tag is grants.manage's, ★14); the levels the database admits for everyone; a duplicate refused; revoke writes the trail and gives nothing;
 * a revoked everyone grant turns revenue to discover; DELETE never possible; the ledger, the Access tab, My access.
 */
require __DIR__ . '/lib.php';
$deals = ds_id('raw.crm_deals');
$revenue = ds_id('product.monthly_revenue');
$payroll = ds_id('curated.payroll');
pg("UPDATE grants SET revoked_at = now() WHERE dataset_id = $deals AND principal_kind IN ('department', 'group') AND revoked_at IS NULL");
$a0 = last_activity_id();

echo "1. A department grant with a row filter: checked, saved, the Sales member's rows filtered\n";
$r = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'department', 'principal' => 8, 'level' => 'read',
                                               'row_filter' => 'department_id = ANY({departments})', 'until' => date('Y-m-d', time() + 60 * 86400), 'reason' => 'SMOKE Sales reads its own deals']);
$gid = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && pg1("SELECT row_filter || '|' || level || '|' || granted_by FROM grants WHERE id = $gid") === 'department_id = ANY({departments})|read|27',
   'Marco (the owner) grants Sales read on deals: the TEMPLATE stored, bound per reader at read');
$log = activity('grant.set', $a0)[0] ?? [];
$after = json_decode((string) ($log['after'] ?? '{}'), true);
ok(($after['row_filtered'] ?? null) === true && ($after['principal_kind'] ?? '') === 'department' && !str_contains((string) $log['after'], 'ANY('), 'logged grant.set: target, principal, level, rows filtered — never the predicate');
$pv = preview_as(26, $deals);
$di = col_index($pv, 'department_id');
ok(($pv['status'] ?? '') === 'ok' && count($pv['rows']) > 0 && array_values(array_unique(array_map(static fn ($x) => (int) $x[$di], $pv['rows']))) === [8]
   && in_array('buyer_email', $pv['masked_columns'] ?? [], true), 'Priya (Sales) previews deals: only her department\'s rows, the buyer\'s email masked');
$my = array_values(array_filter(get_json(26, '/access')['json']['data']['datasets'] ?? [], static fn ($d) => $d['dataset_id'] === $deals))[0] ?? [];
ok(($my['row_filtered'] ?? false) === true && ($my['through'] ?? '') === 'a grant to Sales (your department)' && ($my['masked_columns'] ?? 0) >= 1 && ($my['expires_at'] ?? null) !== null,
   'My access: deals — read, "Rows filtered", through "a grant to Sales (your department)", masked columns, the end date');
$tab = get_json(27, "/datasets/$deals")['json']['data']['access'] ?? [];
$row = array_values(array_filter($tab['readers'] ?? [], static fn ($x) => $x['grant_id'] === $gid))[0] ?? [];
ok(($tab['mode'] ?? '') === 'list' && ($row['row_filter'] ?? '') === 'department_id = ANY({departments})' && ($row['name'] ?? '') !== '' && ($row['masked_columns'] ?? 0) >= 1,
   'the Access tab (Marco, the owner): who reads it — Sales, read, the filter\'s template, the masked columns');
$ptab = get_json(26, "/datasets/$deals")['json']['data']['access'] ?? [];
ok(($ptab['mode'] ?? '') === 'explain' && str_contains(implode(' ', $ptab['explain']['reasons'] ?? []), 'member of Sales') && ($ptab['explain']['filter'] ?? null) !== null
   && str_starts_with((string) ($ptab['explain']['headline'] ?? ''), 'You read this dataset masked'), 'Priya\'s Access tab: why — "member of Sales → a grant on this dataset → read, rows filtered", masked');

echo "2. Who may grant what\n";
$z = post_json(27, '/access/grants/save.php', ['target' => 'zone', 'zone' => 'curated', 'principal_kind' => 'everyone', 'level' => 'discover']);
ok($z['code'] === 403, 'a zone grant by an owner: refused (grants.manage — ★14)');
$t = post_json(27, '/access/grants/save.php', ['target' => 'tag', 'tag' => (int) pg1("SELECT id FROM tags WHERE slug = 'finance'"), 'principal_kind' => 'everyone', 'level' => 'discover']);
ok($t['code'] === 403, 'a tag grant by an owner: refused');
ok(post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $payroll, 'principal_kind' => 'member', 'principal' => 30, 'level' => 'read'])['code'] === 404,
   'Marco on payroll (not his — a stub to him): nothing to a handler (404)');
$ev = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'everyone', 'level' => 'read_unmasked']);
ok($ev['code'] === 422 && field_of($ev) === 'level', 'everyone at read unmasked: refused on the level (everyone reads at most masked)');
$zf = post_json(1, '/access/grants/save.php', ['target' => 'zone', 'zone' => 'product', 'principal_kind' => 'department', 'principal' => 7, 'level' => 'read', 'row_filter' => 'region = \'EMEA\'']);
ok($zf['code'] === 422 && field_of($zf) === 'row_filter', 'a row filter on a zone grant: refused (a filter belongs to one dataset\'s columns)');
$df = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'member', 'principal' => 30, 'level' => 'discover', 'row_filter' => 'amount > 1']);
ok($df['code'] === 422 && field_of($df) === 'row_filter', 'a row filter at discover: refused (a filter narrows a read)');
$dup = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'department', 'principal' => 8, 'level' => 'read',
                                                 'row_filter' => 'department_id = ANY({departments})']);
ok($dup['code'] === 422 && str_contains(message_of($dup), 'already there'), 'the same grant twice: "That grant is already there"');
$zg = post_json(1, '/access/grants/save.php', ['target' => 'zone', 'zone' => 'product', 'principal_kind' => 'group', 'principal' => (int) pg1("SELECT id FROM groups WHERE key = 'smoke-finance-analysts'"),
                                               'level' => 'discover', 'reason' => 'SMOKE analysts know the products']);
$zid = (int) ($zg['json']['record_id'] ?? 0);
ok($zg['code'] === 200 && pg1("SELECT target_kind || ':' || zone FROM grants WHERE id = $zid") === 'zone:product', 'the admin grants a zone (product) to a group');
$led = get_json(1, '/access/grants?zone=product')['json']['data']['grants'] ?? [];
ok(in_array($zid, array_column($led, 'grant_id'), true) && ($led[0]['target']['kind'] ?? '') === 'zone', 'the ledger by zone lists it');
$mled = array_column(get_json(27, '/access/grants?include_revoked=1')['json']['data']['grants'] ?? [], 'grant_id');
ok(in_array($gid, $mled, true) && !in_array($zid, $mled, true), 'Marco\'s ledger: the grants on his datasets — not the admin\'s zone grant');
ok(post_json(27, '/access/grants/revoke.php', ['grant' => $zid])['code'] === 404 && post_json(1, '/access/grants/revoke.php', ['grant' => $zid])['code'] === 200,
   'a zone grant: Marco cannot (he cannot see it: 404); the admin revokes it');

echo "3. Revoke: the trail, nothing given, never deleted\n";
$rv = post_json(27, '/access/grants/revoke.php', ['grant' => $gid]);
ok($rv['code'] === 200 && pg1("SELECT (revoked_at IS NOT NULL AND revoked_by = 27)::text FROM grants WHERE id = $gid") === 'true', 'Marco revokes it — revoked_at, revoked_by (the row stays)');
ok((int) pg1("SELECT count(*) FROM access_changes WHERE grant_id = $gid AND new_level IS NULL AND old_level = 'read'") === 1, 'the trail: a row read → nothing (access_changes)');
ok(get_json(26, "/datasets/$deals/preview")['code'] === 404 && ref_level($deals, 26) === 'none', 'a revoked grant gives nothing: Priya is back to the stub (the preview is nothing to her)');
ok(post_json(27, '/access/grants/revoke.php', ['grant' => $gid])['code'] === 422, 'revoking it again: "already revoked"');
$deleted = true;
try {
    pdo()->exec("DELETE FROM grants WHERE id = $gid");
} catch (PDOException $e) {
    $deleted = false;
}
ok(!$deleted && pg1("SELECT count(*) FROM grants WHERE id = $gid") === '1', 'DELETE as the application\'s role: refused — a grant is revoked, never deleted');
$everyone = (int) pg1("SELECT id FROM grants WHERE dataset_id = $revenue AND principal_kind = 'everyone' AND revoked_at IS NULL");
ok(post_json(1, '/access/grants/revoke.php', ['grant' => $everyone])['code'] === 200 && ref_level($revenue, 26) === 'discover'
   && (get_json(26, "/datasets/$revenue")['json']['data']['dataset']['my_level'] ?? '') === 'discover', 'a revoked everyone grant turns revenue to DISCOVER for Priya (the catalog still shows it)');
$back = post_json(1, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $revenue, 'principal_kind' => 'everyone', 'level' => 'read', 'reason' => 'SMOKE for everyone']);
ok($back['code'] === 200 && ref_level($revenue, 26) === 'read', '…granted again: read');
ok(count(activity('grant.revoke', $a0)) === 3 && count(activity('grant.set', $a0)) === 3, 'every grant and revocation logged');
finish();
