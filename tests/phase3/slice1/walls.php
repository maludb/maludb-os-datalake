<?php
/*
 * Proof: the walls on the catalog's screens (catalog.md "Proof" 2, and access-and-policies §1 as slice 1 applies it) — a stub for a Member
 * on a confidential dataset (title, key, owner, department, Request access — no description, no columns, no tabs), the same 404 for a
 * hidden dataset and one that does not exist, nothing for an external, the Lake admin discovers every entry; Browse never lists a stub;
 * the pickers offer only what the person may discover; an agent's run reads the intersection (K38) and is held to the edit rule.
 */
require __DIR__ . '/lib.php';
$ids = seed_catalog();
$t = run_tag();
$orders = $ids['curated.sales_orders'];
$payroll = $ids['curated.payroll'];
$secret = 'slice1_bonus_' . $t;
pg("INSERT INTO datasets (key, zone, name, title, description, owner_member_id, department_id, status, classification_floor)
    VALUES ('curated.$secret', 'curated', '$secret', 'SMOKE Bonus pool $t', 'Who gets which bonus — confidential.', 27, 7, 'active', 'confidential')");
$bonus = ds_id("curated.$secret");

echo "2. The stub, the 404, the external, the admin\n";
$s = html_as(26, '/datasets/curated.payroll');
ok($s['code'] === 200 && str_contains($s['body'], 'id="dataset-stub"') && str_contains($s['body'], 'SMOKE Payroll') && str_contains($s['body'], 'id="dataset-stub-key"')
    && str_contains($s['body'], 'id="dataset-stub-owner"') && str_contains($s['body'], 'id="dataset-stub-request-btn"'), 'a Member on a confidential dataset: a stub — title, key, owner, Request access');
ok(!str_contains($s['body'], 'What each person is paid') && !str_contains($s['body'], 'dataset-tab-') && !str_contains($s['body'], 'member_name') && !str_contains($s['body'], 'notes-panel'),
    '…no description, no tabs, no columns, no notes');
$j = get_json(26, "/datasets/$payroll")['json']['data']['dataset'] ?? [];
ok(($j['is_stub'] ?? false) === true && array_diff(array_keys($j), ['dataset_id', 'key', 'zone', 'title', 'is_stub', 'owner', 'department', 'certification', 'request_access']) === []
    && str_contains((string) ($j['request_access'] ?? ''), "dataset=$payroll"), 'JSON: the stub\'s fields and nothing else');
$hidden = html_as(29, '/datasets/curated.payroll');
$none = html_as(29, '/datasets/curated.no_such_' . $t);
ok($hidden['code'] === 404 && $none['code'] === 404 && $hidden['body'] === $none['body'], 'an external: 404 — the same page, byte for byte, as a dataset that does not exist');
ok(html_as(29, "/datasets/$orders")['code'] === 404 && html_as(29, '/browse')['code'] === 200 && !str_contains(html_as(29, '/browse')['body'], 'browse-card-'), 'an external sees nothing of the everyone grant: the orders 404, Browse empty');
$a = html_as(1, "/datasets/curated.$secret");
ok($a['code'] === 200 && str_contains($a['body'], 'id="dataset-tab-columns"') && str_contains($a['body'], 'Who gets which bonus'), 'the Lake admin discovers a confidential dataset they hold nothing on: the full page');
ok(str_contains(html_as(26, "/datasets/curated.$secret")['body'], 'id="dataset-stub"') && html_as(26, "/datasets/$bonus/edit")['code'] === 404, 'Priya: a stub of it, and its edit form is nothing (404)');
ok(html_as(26, "/datasets/$bonus?tab=columns")['code'] === 200 && !str_contains(html_as(26, "/datasets/$bonus?tab=columns")['body'], 'id="dataset-tab-columns"'), '…a stub is a stub whatever tab is asked for');

echo "Browse never lists a stub\n";
$b = html_as(26, '/browse')['body'];
ok(str_contains($b, 'id="browse-card-' . $orders . '"') && !str_contains($b, 'id="browse-card-' . $payroll . '"') && !str_contains($b, 'id="browse-card-' . $bonus . '"'), 'Priya\'s Browse: the orders, never the payroll or the bonus pool');
$bj = get_json(26, '/browse?zone=curated')['json']['data'] ?? [];
ok(!in_array($payroll, array_column($bj['rows'] ?? [], 'dataset_id'), true) && ($bj['facets']['zones']['curated'] ?? 0) >= 1 && !in_array(true, array_column($bj['rows'] ?? [], 'is_stub'), true),
    'JSON: no stub row; the facets count only what she may discover');
$adm = get_json(1, '/browse?zone=curated')['json']['data'] ?? [];
ok(in_array($bonus, array_column($adm['rows'] ?? [], 'dataset_id'), true), 'the admin\'s Browse lists the bonus pool');
$d = get_json(26, '/browse?department=7')['json']['data'] ?? [];
ok(!in_array($bonus, array_column($d['rows'] ?? [], 'dataset_id'), true), 'browsing by its department does not reveal it either');

echo "The pickers offer only what one may discover\n";
$p = html_as(26, '/pick/?source=dataset&q=payroll')['body'];
ok(!str_contains($p, 'SMOKE Payroll') && !str_contains(html_as(26, '/pick/?source=dataset&q=bonus')['body'], 'Bonus pool'), 'the dataset picker never offers a stub');
ok(str_contains(html_as(1, '/pick/?source=dataset&q=bonus')['body'], 'Bonus pool ' . $t), '…the admin\'s does');
ok(!str_contains(html_as(27, '/pick/?source=dataset&q=sales&except=' . $orders)['body'], 'SMOKE Sales orders'), 'except leaves a dataset out (not its own successor)');
$st = html_as(27, '/pick/?source=steward&q=SMOKE')['body'];
ok(str_contains($st, 'SMOKE Marco') && str_contains($st, 'SMOKE Owner') && !str_contains($st, 'SMOKE Lee') && !str_contains($st, 'SMOKE Ann'), 'the steward picker: members holding catalog.edit or owning a dataset — not Lee, not Ann');
ok(html_as(29, '/pick/?source=dataset&q=sales')['code'] === 200 && !str_contains(html_as(29, '/pick/?source=dataset&q=sales')['body'], 'Sales orders'), 'an external\'s dataset picker: nothing');

echo "Agents — the intersection (K38) and the edit rule\n";
set_run_facts(9201, 40, 'chat', 26);
$r = req('GET', "/datasets/$orders", ['headers' => as_agent(run_token(40, 9201))]);
ok($r['code'] === 200 && (body_json($r)['data']['dataset']['key'] ?? '') === 'curated.sales_orders', 'the Expert in a chat turn Priya asked for reads the orders\' page (JSON)');
$r = req('GET', "/datasets/$payroll", ['headers' => as_agent(run_token(40, 9201))]);
ok($r['code'] === 200 && (body_json($r)['data']['dataset']['is_stub'] ?? false) === true, '…the payroll (the Expert holds a grant; Priya may not): only the stub — the intersection');
set_run_facts(9202, 40, 'chat', 29);
ok(req('GET', "/datasets/$orders", ['headers' => as_agent(run_token(40, 9202))])['code'] === 404, 'a turn an external asked for: the orders are nothing (404)');
set_run_facts(9203, 40, 'duty', null);
$r = req('POST', '/datasets/save.php', ['headers' => as_agent(run_token(40, 9203)), 'form' => ['dataset' => $orders, 'title' => 'Renamed by an agent']]);
ok($r['code'] === 403 && pg1("SELECT title FROM datasets WHERE id = $orders") === 'SMOKE Sales orders', 'the Expert (a Member) may not describe the orders: 403, nothing changed');
$r = req('POST', '/datasets/follow.php', ['headers' => as_agent(run_token(40, 9203)), 'form' => ['dataset' => $orders]]);
ok($r['code'] === 200 && pg1("SELECT count(*) FROM dataset_follows WHERE member_id = 40 AND dataset_id = $orders") === '1', 'an agent follows like a person (follow.use)');
$l = activity('dataset.follow');
ok(end($l)['source'] === 'agent' && (int) end($l)['agent_run_id'] === 9203, '…logged with source agent and its run');
kernel_state(function (array $s): array { $s['facts']['9204'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 9204, 'trigger' => 'eval', 'is_eval' => true, 'run_status' => 'running', 'endpoints' => []]; return $s; });
ok(req('POST', '/datasets/unfollow.php', ['headers' => as_agent(run_token(40, 9204)), 'form' => ['dataset' => $orders]])['code'] === 403
    && pg1("SELECT count(*) FROM dataset_follows WHERE member_id = 40 AND dataset_id = $orders") === '1', 'an evaluation run changes nothing (403)');
req('POST', '/datasets/unfollow.php', ['headers' => as_agent(run_token(40, 9203)), 'form' => ['dataset' => $orders]]);
finish();
