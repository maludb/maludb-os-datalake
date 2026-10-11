<?php
/**
 * Slice 3 — the editor (query-service-editor.md "Proof" 1, 2): the editor lists only the datasets I may read; a Member without sql.editor gets
 * 403; a SELECT answers within the cap, 1,001 rows truncate with the notice; the email column is marked and masked for an Analyst with read,
 * the owner sees it unmasked; a row filter (Sales reads its own department's rows) holds; quick stats on the shown rows; my runs listed; the
 * dataset header's Query this prefills the editor; a dry run (Explain) answers the plan and the datasets, no rows; as_of reads a snapshot.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;

echo "1. The editor lists only the datasets I may read; a Member without sql.editor gets 403\n";
$s = get_json(30, '/sql');
$keys = array_column($s['json']['data']['catalog'] ?? [], 'key');
ok($s['code'] === 200 && in_array('curated.sales_orders', $keys, true) && in_array('curated.region_sales', $keys, true) && !in_array('curated.payroll', $keys, true)
   && !in_array('curated.hr_reviews', $keys, true) && !in_array('sandbox.m26_my_upload', $keys, true), 'Dana (Analyst): orders, her department\'s region sales — never payroll (discover only), HR reviews or Priya\'s sandbox');
$orders = array_values(array_filter($s['json']['data']['catalog'], static fn ($d) => $d['key'] === 'curated.sales_orders'))[0] ?? [];
$em = array_values(array_filter($orders['columns'] ?? [], static fn ($c) => $c['name'] === 'customer_email'))[0] ?? [];
ok(($em['masked'] ?? null) === true && !in_array('_dl_load_id', array_column($orders['columns'] ?? [], 'name'), true), 'the catalog marks customer_email masked for her; provenance columns are not offered');
ok(html_as(26, '/sql')['code'] === 403 && post_json(26, '/sql/run', ['sql' => 'SELECT 1'])['code'] === 403, 'Priya (a Member) — 403 on /sql and on its run door');
ok(html_as(29, '/sql')['code'] === 403, 'Ann (external) — 403');
$m = get_json(27, '/sql');
ok(in_array('curated.payroll', array_column($m['json']['data']['catalog'] ?? [], 'key'), true) === false && in_array('curated.big_events', array_column($m['json']['data']['catalog'] ?? [], 'key'), true),
   'Marco (engineer) — what he may read, not what he only discovers');
$h = html_as(30, '/sql?dataset=curated.sales_orders');
ok(str_contains($h['body'], 'SELECT * FROM curated.sales_orders LIMIT 100') && str_contains($h['body'], 'id="sql-completion"'), '?dataset= prefills SELECT * FROM {key} LIMIT 100; the completion list rides in the page');
$ta = static fn (string $html): string => preg_match('#<textarea[^>]*id="sql-editor"[^>]*>(.*?)</textarea>#s', $html, $mm) ? html_entity_decode($mm[1]) : '?';
ok($ta(html_as(30, '/sql?dataset=curated.payroll')['body']) === '' && $ta(html_as(30, '/sql?dataset=curated.hr_reviews')['body']) === '', '…never for a dataset I may not read (the editor starts empty)');
$v = html_as(30, '/datasets/curated.sales_orders');
ok(str_contains($v['body'], 'id="dataset-query-btn"') && str_contains($v['body'], 'href="/sql?dataset=curated.sales_orders"'), 'the dataset header\'s Query this is live for her');
ok(!str_contains(html_as(26, '/datasets/curated.sales_orders')['body'], 'id="dataset-query-btn"'), '…and absent for a Member without the editor');

echo "2. A SELECT within the cap; 1,001 rows truncate; masks; the owner unmasked; the row filter\n";
$r = sql_as(30, 'SELECT order_id, customer_email, region FROM curated.sales_orders ORDER BY order_id LIMIT 5');
$x = result_of($r);
ok($r['code'] === 200 && $x['status'] === 'ok' && $x['row_count'] === 5 && $x['truncated'] === false, 'five rows, not truncated');
ok(($x['rows'][0][1] ?? '') === 'b***@example.invalid' && $x['columns'][1]['masked'] === true && in_array('curated.sales_orders.customer_email', $x['masked_columns'], true),
   'the email arrives masked for Dana (read) and its column is marked');
ok(isset($x['stats']['region']) && $x['stats']['region']['distinct'] === 4 && $x['stats']['order_id']['min'] === '1' && $x['stats']['order_id']['max'] === '5',
   'quick stats on the shown rows: distinct, min and max as numbers');
ok(($x['run_url'] ?? '') === '/runs/' . $x['query_run_id'] && run_row((int) $x['query_run_id'])['status'] === 'ok', 'the run is linked and audited (query_runs, source screen)');
$u = result_of(sql_as(27, 'SELECT customer_email FROM curated.sales_orders ORDER BY order_id LIMIT 1'));
ok(($u['rows'][0][0] ?? '') === 'buyer1@example.invalid' && $u['masked_columns'] === [], 'Marco owns it: unmasked');
$t = result_of(sql_as(30, 'SELECT id FROM curated.big_events ORDER BY id LIMIT 1001'));
ok($t['row_count'] === 1000 && $t['truncated'] === true, '1,001 rows → the first 1,000 and truncated');
$html = post_form(30, '/sql/run', ['sql' => 'SELECT id FROM curated.big_events ORDER BY id LIMIT 1001'], ['HX-Request: true']);
ok(str_contains($html['body'], 'id="sql-truncated"') && str_contains($html['body'], 'Showing the first 1,000 rows'), 'the partial says "Showing the first 1,000 rows"');
$hm = post_form(30, '/sql/run', ['sql' => 'SELECT order_id, customer_email FROM curated.sales_orders LIMIT 3'], ['HX-Request: true']);
ok(str_contains($hm['body'], 'id="sql-masked-customer_email"') && str_contains($hm['body'], 'id="sql-stats-order_id"') && !str_contains($hm['body'], '<html'),
   'the result partial: the masked column marked (sql-masked-{column}), stats under each header (sql-stats-{column}) — a fragment, not a page');
$rf = result_of(sql_as(30, 'SELECT department_id, count(*) AS n FROM curated.region_sales GROUP BY 1 ORDER BY 1'));
ok($rf['rows'] === [[8, 200]], 'the row filter: Dana (Sales) reads only department 8\'s rows of region sales');
$rm = result_of(sql_as(27, 'SELECT department_id, count(*) AS n FROM curated.region_sales GROUP BY 1 ORDER BY 1'));
ok($rm['rows'] === [[7, 200], [8, 200]], '…Marco (its owner) every department\'s');
ok(str_contains((string) json_encode(run_row((int) $rf['query_run_id'])['row_filters']), 'ANY'), 'the audit keeps the filter as applied (department_id = ANY(…))');
$nojs = post_form(30, '/sql/run', ['sql' => 'SELECT 1 AS one FROM curated.sales_orders LIMIT 1']);
ok($nojs['code'] === 200 && str_contains($nojs['body'], '<html') && str_contains($nojs['body'], 'id="sql-result-table"') && str_contains($nojs['body'], 'id="sql-editor"'),
   'without JavaScript the form posts and the whole screen comes back with the result');

echo "3. Explain (a dry run): the plan and the datasets, no rows, audited\n";
$d = result_of(sql_as(30, 'SELECT region, sum(amount) FROM curated.sales_orders GROUP BY region', ['dry_run' => 1]));
ok($d['status'] === 'ok' && $d['dry_run'] === true && $d['rows'] === [] && $d['datasets'] === ['curated.sales_orders'] && str_contains((string) $d['plan'], 'GROUP_BY'),
   'the plan, the datasets it reads, no rows');
ok(run_row((int) $d['query_run_id'])['rows_returned'] === 0 && run_row((int) $d['query_run_id'])['status'] === 'ok', '…audited ok with 0 rows');

echo "4. As of a snapshot\n";
$snap = (int) pg1("SELECT last_snapshot_id FROM datasets WHERE key = 'curated.sales_orders'");
$a = result_of(sql_as(30, 'SELECT count(*) FROM curated.sales_orders', ['as_of' => (string) $snap]));
ok($a['status'] === 'ok' && $a['rows'] === [[1500]] && $a['as_of'] === $snap, 'as_of = the dataset\'s snapshot reads it then');
$b = result_of(sql_as(30, 'SELECT count(*) FROM curated.sales_orders', ['as_of' => '2001-01-01']));
ok($b['status'] === 'refused' && $b['refusal']['rule'] === 'absent_as_of', 'a time before the lake held it: absent_as_of, in words');

echo "5. My runs\n";
$s = get_json(30, '/sql');
$runs = $s['json']['data']['runs'] ?? [];
ok(count($runs) >= 5 && $runs[0]['url'] === '/runs/' . $runs[0]['query_run_id'] && !isset($runs[0]['sql']), 'my last runs (newest first), each linked — the SQL\'s first line, never a value');
ok(($s['json']['data']['limits']['rows_shown'] ?? 0) === 1000, 'the display cap is the setting (1,000)');
finish();
