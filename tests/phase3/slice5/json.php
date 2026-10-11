<?php
/**
 * Slice 5 — JSON mode (contracts-quality.md "Proof" 9): every screen and the door answer {data: …} to a JSON caller; every handler answers
 * {ok, did, record_id, location, refresh} (and a refusal {error: {message, fields?}}) — the shape the kernel's actions server reads.
 */
require __DIR__ . '/lib.php';
$cur = ds_id('curated.orders');
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('did', $r['json']) && array_key_exists('record_id', $r['json'])
    && array_key_exists('location', $r['json']) && array_key_exists('refresh', $r['json']);

echo "1. The nine screens and the door\n";
$inc = (int) pg1("SELECT id FROM incidents WHERE dataset_id = $cur ORDER BY id LIMIT 1");
$chk = check_id('curated.orders', 'unique', 'order_id');
foreach (['quality' => ['/quality', 'failing'], 'incident-list' => ['/incidents', 'incidents'], 'incident-view' => ["/incidents/$inc", 'incident'],
          'checks' => ['/datasets/curated.orders/checks', 'checks'], 'check-add' => ['/datasets/curated.orders/checks/new?kind=unique', 'kinds'],
          'check-edit' => ["/checks/$chk/edit", 'check'], 'contract-view' => ['/datasets/curated.orders/contract', 'contract'],
          'contract-edit' => ['/datasets/curated.orders/contract/edit', 'draft'], 'quarantine' => ['/datasets/curated.orders/quarantine', 'quarantine'],
          'contract-odcs' => ['/datasets/curated.orders/contract.odcs.yaml', 'yaml'], 'the Quality tab' => ['/datasets/curated.orders?tab=quality', 'dataset']] as $name => [$url, $key]) {
    $r = get_json(27, $url);
    ok($r['code'] === 200 && array_key_exists($key, $r['json']['data'] ?? []), "$name: {data: {{$key}, …}}");
}

echo "2. Every handler's answer\n";
$c = post_json(27, '/quality/checks/save.php', ['dataset' => 'curated.orders', 'kind' => 'range', 'column' => 'amount', 'max' => '1', 'dimension' => 'accuracy', 'severity' => 'warn']);
$cid = (int) ($c['json']['record_id'] ?? 0);
ok($shape($c) && $cid > 0 && ($c['json']['refresh'] ?? '') === 'checksChanged', 'check_create');
ok($shape(post_json(27, '/quality/checks/save.php', ['check' => $cid, 'max' => '2'])), 'check_update');
ok($shape(post_json(27, '/quality/checks/disable.php', ['check' => $cid])) && $shape(post_json(27, '/quality/checks/disable.php', ['check' => $cid, 'enable' => '1'])), 'check_disable');
$run = post_json(27, '/quality/checks/run.php', ['dataset' => 'curated.orders', 'check' => $cid]);
ok($shape($run) && ($run['json']['job_id'] ?? 0) > 0, 'check_run (the job\'s id with it)');
wait_job((int) $run['json']['job_id'], 60);
$i = open_incident($cid);
$iid = (int) ($i[0] ?? 0);
ok($iid > 0, '(the narrow range failed and opened an incident)');
ok($shape(post_json(27, '/incidents/ack.php', ['incident' => $iid])), 'incident_ack');
ok($shape(post_json(27, '/incidents/assign.php', ['incident' => $iid, 'assignee' => 27])), 'incident_assign');
$n = post_json(27, '/incidents/note.php', ['incident' => $iid, 'body' => 'Checking.']);
ok($shape($n) && ($n['json']['note_id'] ?? 0) > 0, 'incident_note');
ok($shape(post_json(27, '/incidents/resolve.php', ['incident' => $iid, 'resolution' => 'The range was set wrong.'])), 'incident_resolve');
$run = post_json(27, '/quality/checks/run.php', ['dataset' => 'curated.orders', 'check' => $cid]);
wait_job((int) $run['json']['job_id'], 60);
$i2 = (int) (open_incident($cid)[0] ?? 0);
ok($shape(post_json(27, '/incidents/dismiss.php', ['incident' => $i2, 'reason' => 'A proof\'s check.'])), 'incident_dismiss');
ok($shape(post_json(27, '/quality/checks/delete.php', ['check' => $cid])), 'check_delete');
$sc = post_json(27, '/quality/checks/run.php', ['dataset' => 'curated.orders', 'check' => check_id('curated.orders', 'schema')]);   // drift.php decided every change: the schema check passes again (certify before the rebuild below: a rebuild re-reads raw's refused rows)
wait_job((int) ($sc['json']['job_id'] ?? 0), 60);
$m = get_json(27, '/datasets/curated.orders')['json']['data']['dataset']['certification_missing'] ?? ['?'];
$cert = pg1("SELECT certification FROM datasets WHERE id = $cur");
if ($cert !== 'none') {
    ok($shape(post_json(27, '/quality/uncertify.php', ['dataset' => 'curated.orders', 'reason' => 'A proof.'])), 'certify_remove');
    ok($shape(post_json(27, '/quality/certify.php', ['dataset' => 'curated.orders'])), 'certify');
} else {
    $c1 = post_json(27, '/quality/certify.php', ['dataset' => 'curated.orders']);
    ok($m === [] && $shape($c1), 'certify' . ($m === [] ? '' : ' — it lacks: ' . implode(', ', $m)));
    ok($shape(post_json(27, '/quality/uncertify.php', ['dataset' => 'curated.orders', 'reason' => 'A proof.'])), 'certify_remove');
}
$pr = post_json(27, '/quality/promote.php', ['dataset' => 'curated.orders']);
ok($shape($pr) && ($pr['json']['job_id'] ?? 0) > 0, 'promote_run');
wait_job((int) $pr['json']['job_id'], 90);
$rb = post_json(27, '/quality/rebuild.php', ['dataset' => 'curated.orders']);
ok($shape($rb), 'promote_rebuild');
wait_job((int) $rb['json']['job_id'], 90);
$d = post_json(27, '/quality/contracts/save.php', ['dataset' => 'curated.orders_v', 'version' => '2.2.0', 'schema' => json_encode(orders_schema()), 'ordering_column' => 'updated_at']);
ok($shape($d) && array_key_exists('breaking', $d['json']), 'contract_draft_save (its breaking changes with it)');
ok($shape(post_json(27, '/quality/contracts/publish.php', ['contract' => (int) $d['json']['record_id']])), 'contract_publish');
load_orders('orders_json.csv');
$sv = (int) pg1("SELECT id FROM schema_versions WHERE dataset_id = (SELECT id FROM datasets WHERE key = 'raw.orders') AND decision = 'pending' ORDER BY version DESC LIMIT 1");
ok($sv > 0 && $shape(post_json(27, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv, 'accept' => '0'])), 'drift_decide');

echo "3. A refusal's shape\n";
$bad = post_json(27, '/quality/checks/save.php', ['dataset' => 'curated.orders', 'kind' => 'regex', 'column' => 'order_id', 'pattern' => '', 'dimension' => 'conformity']);
ok($bad['code'] === 422 && ($bad['json']['error']['code'] ?? '') === 'invalid' && isset($bad['json']['error']['fields']['pattern']), 'a field refused: 422, {error: {code: invalid, message, fields: {pattern}}}');
$deny = post_json(26, '/quality/certify.php', ['dataset' => 'curated.orders']);
ok($deny['code'] === 403 && is_string($deny['json']['error']['message'] ?? null), 'a right refused: 403 with the sentence');
finish();
