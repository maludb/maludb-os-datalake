<?php
/**
 * Slice 5 proof 2 — promotion (contracts-quality.md "Proof" 2; lake-engine §4): raw.orders → curated.orders by the REAL writer, typed by the
 * active contract — the bad date quarantined with `cast:order_date`, the duplicate collapsed latest-wins, the rows typed; the quarantine screen
 * through the query service (the reason first, the count per reason, a filter); a re-run changes nothing; after a new load only the new rows
 * (incremental over the change feed); a rebuild whole; promotion without a contract refused with its sentence; the walls of the doors; the
 * writer's runs, logs and the catalog's numbers.
 */
require __DIR__ . '/lib.php';
$cur = ds_id('curated.orders');
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');

echo "1. The first promotion\n";
[$r, $job] = promote_as(27, 'curated.orders');
$res = job_result($job);
ok($r['code'] === 200 && ($r['json']['job_id'] ?? 0) > 0 && ($job['status'] ?? '') === 'done', 'Marco promotes curated.orders: a promote job, done by the writer');
ok(($res['rows_in'] ?? 0) === 8 && ($res['rows_promoted'] ?? 0) === 6 && ($res['rows_quarantined'] ?? 0) === 1 && ($res['rows_deduped'] ?? 0) === 1,
   'eight raw rows: six promoted, one quarantined, one duplicate collapsed (' . json_encode(array_intersect_key($res, array_flip(['rows_in', 'rows_promoted', 'rows_quarantined', 'rows_deduped']))) . ')');
ok(($res['reasons'] ?? []) === ['cast:order_date' => 1] && ($res['contract'] ?? '') === '1.0.0', 'the reason: cast:order_date (the bad date); typed by contract 1.0.0');
$rows = curated_rows();
ok(array_keys($rows) === ['SO-001', 'SO-002', 'SO-004', 'SO-005', 'SO-006', 'SO-007'], 'curated.orders holds six orders — SO-003 held back');
ok(($rows['SO-002']['amount'] ?? null) === '85.00' && ($rows['SO-002']['updated_at'] ?? '') === '2026-09-06T10:00:00', 'SO-002 twice in raw: the later one wins (85.00, updated 2026-09-06)');
ok(($rows['SO-001']['order_date'] ?? '') === '2026-09-01' && array_key_exists('amount', $rows['SO-004'] ?? []) && $rows['SO-004']['amount'] === null, 'typed: the date a DATE, the empty amount NULL (not a cast failure)');
$types = pg("SELECT name || ':' || physical_type FROM dataset_columns WHERE dataset_id = $cur AND NOT is_system AND removed_snapshot_id IS NULL ORDER BY ordinal");
ok($types === ['order_id:VARCHAR', 'customer_email:VARCHAR', 'order_date:DATE', 'amount:DECIMAL(12,2)', 'status:VARCHAR', 'updated_at:TIMESTAMP'], 'the catalog\'s columns carry the contract\'s types (the writer synced them)');
ok(pg1("SELECT personal_kind || '|' || (description IS NOT NULL) || '|' || nullable FROM dataset_columns WHERE dataset_id = $cur AND name = 'customer_email'") === 'email|true|true'
   && pg1("SELECT is_key::text || '|' || nullable::text FROM dataset_columns WHERE dataset_id = $cur AND name = 'order_id'") === 'true|false',
   'the contract\'s words reach the catalog: the email is personal, every column described, order_id the key and required');
ok(pg1("SELECT row_count || '|' || (data_updated_at IS NOT NULL) FROM datasets WHERE id = $cur") === '6|true', 'the catalog row: six rows, "data updated" (the writer\'s numbers)');
ok(pg1("SELECT count(*) FROM lineage_runs WHERE job_kind = 'promote' AND event IN ('START', 'COMPLETE') AND job_name = 'promote.orders'") === '2', 'one lineage run: START and COMPLETE');
$w = activity('promote.run', $a0);
$writer = array_values(array_filter($w, static fn (array $x): bool => $x['source'] === 'system'));
$person = array_values(array_filter($w, static fn (array $x): bool => $x['source'] !== 'system'));
ok(count($person) === 1 && (json_decode((string) $person[0]['after'], true)['phase'] ?? '') === 'queued' && count($writer) === 1
   && (json_decode((string) $writer[0]['after'], true)['rows_quarantined'] ?? null) === 1 && (int) $writer[0]['actor_member_id'] === 27,
   'promote.run logged twice: Marco\'s (queued) and the writer\'s (the counts, Marco the actor, source system)');
$poll = get_json(27, "/quality/promote.php?dataset=$cur")['json']['data']['promotion'] ?? [];
ok(($poll['status'] ?? '') === 'done' && str_contains((string) ($poll['words'] ?? ''), 'Promoted 6 rows (1 held back, 1 duplicates collapsed)'), 'the Quality tab\'s door says it in words: "Promoted 6 rows (1 held back, 1 duplicates collapsed) — contract 1.0.0."');

echo "2. The quarantine, through the query service\n";
$q = get_json(27, '/datasets/curated.orders/quarantine');
$qq = $q['json']['data']['quarantine'] ?? [];
$cols = array_map(static fn ($c) => $c['name'], $qq['columns'] ?? []);
$row = array_combine($cols, $qq['rows'][0] ?? array_fill(0, count($cols), null));
ok($q['code'] === 200 && ($qq['status'] ?? '') === 'ok' && count($qq['rows'] ?? []) === 1 && ($row['_dl_reason'] ?? '') === 'cast:order_date' && ($row['order_id'] ?? '') === 'SO-003',
   'the quarantine holds SO-003 with its reason');
ok(($row['order_date'] ?? '') === 'not-a-date' && ($qq['reasons'] ?? []) === ['cast:order_date' => 1] && ($qq['query_run_id'] ?? 0) > 0, 'as it arrived (the text), the count per reason, an audited read');
ok(pg1('SELECT status || \'|\' || array_to_string(datasets, \',\') FROM query_runs WHERE id = ' . (int) $qq['query_run_id']) === 'ok|curated.orders,quarantine.orders', '…audited under the curated key and its quarantine');
$f = get_json(27, '/datasets/curated.orders/quarantine?reason=' . rawurlencode('not_null:amount'))['json']['data']['quarantine'] ?? [];
ok(($f['status'] ?? '') === 'ok' && ($f['rows'] ?? null) === [], 'filtered by another reason: nothing');
$page = html_as(27, '/datasets/curated.orders/quarantine');
ok(str_contains($page['body'], 'id="quarantine-table"') && strpos($page['body'], '<th class="text-nowrap">_dl_reason') < strpos($page['body'], '>order_id'), 'the screen: the reason first, then the columns');

echo "3. A re-run changes nothing; a new load promotes only its rows\n";
[, $job2] = promote_as(27, 'curated.orders');
ok((job_result($job2)['status'] ?? '') === 'nothing_new' && count(curated_rows()) === 6, 'promoted again with nothing new in raw: nothing_new, six rows (idempotent)');
$load2 = load_orders('orders_2.csv');
[, $job3] = promote_as(27, 'curated.orders');
$r3 = job_result($job3);
ok(($r3['rows_in'] ?? 0) === 2 && ($r3['rows_promoted'] ?? 0) === 2 && ($r3['since'] ?? null) === (job_result($job2)['raw_snapshot'] ?? -1), 'after the next load: only its two rows read — from the last promotion\'s raw snapshot (the change feed)');
$rows = curated_rows();
ok(count($rows) === 7 && ($rows['SO-001']['amount'] ?? '') === '130.00' && ($rows['SO-001']['status'] ?? '') === 'shipped' && isset($rows['SO-008']), 'SO-008 added, SO-001 updated (the later row wins): seven orders');

echo "4. A rebuild, whole\n";
[$rb, $job4] = promote_as(27, 'curated.orders', true);
$r4 = job_result($job4);
ok($rb['code'] === 200 && ($r4['full'] ?? false) === true && ($r4['rows_in'] ?? 0) === 10 && ($r4['rows_promoted'] ?? 0) === 7 && ($r4['rows_quarantined'] ?? 0) === 1,
   'rebuilt from all of raw: ten rows read, seven promoted, one held back');
ok(count(curated_rows()) === 7 && count(get_json(27, '/datasets/curated.orders/quarantine')['json']['data']['quarantine']['rows'] ?? []) === 1, 'the same seven orders; the quarantine rebuilt too (one row, not two)');
ok(count(activity('promote.rebuild', $a0)) === 2, 'promote.rebuild logged (the person\'s and the writer\'s)');

echo "5. Refusals\n";
$nc = post_json(27, '/quality/promote.php', ['dataset' => 'curated.no_contract']);
ok($nc['code'] === 422 && message_of($nc) === 'Publish a contract first — promotion types by it', 'a curated dataset with no contract: "Publish a contract first — promotion types by it"');
$rw = post_json(27, '/quality/promote.php', ['dataset' => 'raw.orders']);
ok($rw['code'] === 422 && field_of($rw) === 'dataset', 'a raw dataset is not promoted: refused on the dataset');
ok(post_json(26, '/quality/promote.php', ['dataset' => 'curated.orders'])['code'] === 403, 'a Member may not promote (promote.run): 403');
ok(post_json(30, '/quality/rebuild.php', ['dataset' => 'curated.orders'])['code'] === 403, 'an Analyst may not rebuild: 403');
ok(post_json(29, '/quality/promote.php', ['dataset' => 'curated.orders'])['code'] === 403, 'Ann (external) is refused at the right before the dataset is named: 403');
ok(post_json(27, '/quality/promote.php', ['dataset' => 'curated.hr_reviews'])['code'] === 404, 'a dataset Marco may not see: 404 — the same as none');
finish();
