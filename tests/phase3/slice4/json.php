<?php
/**
 * Slice 4, proof 9 (the JSON half) — every one of the twelve screens answers JSON with its data, every one of the fifteen actions answers
 * {ok, did, record_id, location, refresh}; the registry reads the slice's 12 screens and 15 actions built, the six approvals in step.
 */
require __DIR__ . '/lib.php';
$deals = ds_id('raw.crm_deals');
$hr = ds_id('curated.hr_reviews');
$orders = ds_id('curated.sales_orders');
$products = ds_id('curated.products');
pg("UPDATE access_requests SET status = 'withdrawn' WHERE status = 'pending'");
pg("UPDATE admin_reads SET started_at = now() - interval '3 hours', expires_at = now() - interval '2 hours' WHERE expires_at > now()");
$tag = run_tag();

echo "1. The twelve screens answer JSON\n";
$gid0 = (int) pg1("SELECT id FROM groups WHERE key = 'smoke-finance-analysts'");
$screens = [
    'my-access' => [26, '/access', 'datasets'], 'requests' => [1, '/access/requests?box=history', 'requests'], 'request-add' => [26, "/access/requests/new?dataset=$hr", 'values'],
    'grants' => [1, '/access/grants', 'grants'], 'grant-add' => [27, "/access/grants/new?dataset=$deals", 'columns'], 'groups' => [1, '/groups', 'groups'],
    'group-view' => [1, "/groups/$gid0", 'members'], 'group-add' => [1, '/groups/new', 'values'], 'group-edit' => [1, "/groups/$gid0/edit", 'values'],
    'policies' => [1, '/access/policies', 'policies'], 'personal-data' => [27, '/access/personal-data', 'columns'], 'admin-read' => [1, '/access/break-glass', 'open'],
];
foreach ($screens as $id => [$m, $path, $key]) {
    $r = get_json($m, $path);
    ok($r['code'] === 200 && array_key_exists($key, $r['json']['data'] ?? []), "$id ($path): 200, data.$key");
}
$t = get_json(27, "/datasets/$deals")['json']['data']['access'] ?? [];
ok(($t['mode'] ?? '') === 'list' && isset($t['readers'], $t['explain']['headline']), 'the dataset page\'s JSON carries the Access tab (who reads it, and my own reasons)');
$cols = get_json(27, "/access/grants/new?dataset=$deals")['json']['data']['columns'] ?? [];
ok(in_array('department_id', $cols, true) && !in_array('_dl_load_id', $cols, true), 'the grant form lists the dataset\'s columns (not the provenance columns) for the row filter');

echo "2. The fifteen actions answer {ok, did, record_id, location, refresh}\n";
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && ($r['json']['did'] ?? '') !== '' && array_key_exists('record_id', $r['json'])
    && str_starts_with((string) ($r['json']['location'] ?? ''), '/') && array_key_exists('refresh', $r['json']);
$acts = [];
$acts['access_request_create'] = $c = post_json(30, '/access/requests/save.php', ['dataset' => $hr, 'level' => 'read', 'reason' => 'The reviews for my team.']);
$acts['access_request_withdraw'] = post_json(30, '/access/requests/withdraw.php', ['access_request' => (int) ($c['json']['record_id'] ?? 0)]);
$acts['access_request_draft'] = $d = agent_post(40, '/access/requests/draft.php', ['dataset' => $hr, 'level' => 'read', 'reason' => 'Dana asked me for the reviews.'], 30);
$acts['access_decide'] = post_json(1, '/access/requests/decide.php', ['access_request' => (int) ($d['json']['record_id'] ?? 0), 'approve' => 'no', 'note' => 'Reviews stay with HR.']);
$acts['grant_set'] = $g = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $products, 'principal_kind' => 'member', 'principal' => 30, 'level' => 'read', 'reason' => "SMOKE json $tag"]);
$acts['grant_revoke'] = post_json(27, '/access/grants/revoke.php', ['grant' => (int) ($g['json']['record_id'] ?? 0)]);
$acts['group_create'] = $gc = post_json(1, '/groups/save.php', ['name' => "SMOKE JSON group $tag"]);
$gid = (int) ($gc['json']['record_id'] ?? 0);
$acts['group_update'] = post_json(1, '/groups/save.php', ['group' => $gid, 'name' => "SMOKE JSON group $tag", 'description' => 'For the JSON proof.']);
$acts['group_member_add'] = post_json(1, '/groups/members/add.php', ['group' => $gid, 'member' => 26]);
$acts['group_member_remove'] = post_json(1, '/groups/members/remove.php', ['group' => $gid, 'member' => 26]);
$acts['group_archive'] = post_json(1, '/groups/archive.php', ['group' => $gid]);
$acts['policy_set'] = post_json(1, '/access/policies/save.php', ['applies_to' => 'phone', 'mask' => 'partial']);
$acts['column_classify'] = post_json(27, '/access/columns/classify.php', ['dataset' => $orders, 'column' => 'region', 'classification' => 'internal']);
$acts['personal_confirm'] = post_json(27, '/access/columns/confirm.php', ['dataset' => $deals, 'column' => 'buyer_email', 'accept' => 'yes', 'personal_kind' => 'email']);
$acts['admin_read_open'] = post_json(1, '/access/break-glass.php', ['dataset' => $products, 'reason' => 'The JSON proof opens the products for a minute.']);
pg("UPDATE admin_reads SET started_at = now() - interval '3 hours', expires_at = now() - interval '2 hours' WHERE expires_at > now()");
foreach ($acts as $a => $r) {
    ok($shape($r), "$a: " . ($shape($r) ? (string) $r['json']['did'] : $r['code'] . ' ' . substr($r['body'], 0, 160)));
}
$bad = post_json(26, '/access/requests/save.php', ['dataset' => $hr, 'level' => 'read']);
ok($bad['code'] === 422 && ($bad['json']['error']['code'] ?? '') === 'invalid' && isset($bad['json']['error']['fields']['reason']), 'a refusal in JSON: 422 {error: invalid, fields: {reason}}');

echo "3. The registry\n";
exec('php bin/build_action_registry.php --check 2>&1', $o, $rc);
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$sec = 'Access, policies, groups and personal data (slice 4)';
$sc = array_filter($reg['screens'], static fn ($s) => $s['section'] === $sec);
$ac = array_filter($reg['actions'], static fn ($x) => $x['section'] === $sec);
$ap = array_keys(array_filter($ac, static fn ($x) => $x['approval'] !== null));
sort($ap);
ok($rc === 0, 'build_action_registry --check: the registry and maludb-os.json approvals[] match the manifest');
ok(count($sc) === 12 && count(array_filter($sc, static fn ($s) => $s['built'])) === 12, 'the slice\'s 12 screens are built');
ok(count($ac) === 15 && count(array_filter($ac, static fn ($x) => $x['built'])) === 15, 'the slice\'s 15 actions are built');
ok($ap === ['access_decide', 'admin_read_open', 'column_classify', 'grant_revoke', 'grant_set', 'policy_set'], 'six pause for an agent: ' . implode(', ', $ap));
finish();
