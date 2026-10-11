<?php
/**
 * Slice 3 — the refusals (query-service-editor.md "Proof" 3, 4; query-service.md §4, §9): every forbidden form refused with its rule through
 * the editor's door, nothing run, each a `refused` query_runs row; a dataset I may not read with Request access, one I only discover
 * (discover_only), a hidden one exactly as a missing one (no_such_dataset); Cancel stops a long query within 2 s, another's request id is
 * not_yours; the timeout stops one at the setting; the admission — a second query at once beyond the setting answers `concurrency`, an agent
 * over its rate `rate`; K38 through the saved-query door: the Expert acting for Priya reads no more than Priya, an old kernel's chat turn
 * k38_pending; the activity log never holds the SQL.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;
$long = 'SELECT sum(a.id * b.id) FROM curated.big_events a, curated.big_events b WHERE a.kind <> b.kind';

echo "3. Every forbidden form refused with its rule, nothing run, each audited `refused`\n";
$forms = [
    ['SELECT 1; SELECT 2', 'one_statement'], ["COPY (SELECT 1) TO '/tmp/x.csv'", 'select_only'], ["ATTACH '/tmp/x.db' AS x", 'select_only'],
    ["SELECT * FROM read_csv('/etc/passwd')", 'function'], ['SELECT * FROM duckdb_databases()', 'function'], ["SELECT * FROM glob('/*')", 'function'],
    ['SET enable_external_access = true', 'select_only'], ["SELECT * FROM '/etc/passwd'", 'unknown_table'], ['PRAGMA database_list', 'select_only'],
    ['INSTALL httpfs', 'select_only'], ['LOAD httpfs', 'select_only'], ["SELECT getenv('HOME')", 'function'], ['SELECT * FROM lake.curated.sales_orders', 'catalog_reference'],
    ['SELECT * FROM curated.sales_orders AT (VERSION => 1)', 'time_travel'], ['SELECT * FROM curated.sales_orders WHERE region = $r', 'unbound_parameter'],
    ['DELETE FROM curated.sales_orders', 'select_only'], ["SELECT * FROM read_parquet('/srv/lake/x.parquet')", 'function'], ['', 'empty'],
];
$before = last_run_id();
$allRefused = true;
$allAudited = true;
foreach ($forms as [$sql, $rule]) {
    $x = result_of(sql_as(30, $sql));
    $good = ($x['status'] ?? '') === 'refused' && ($x['refusal']['rule'] ?? '') === $rule && ($x['rows'] ?? null) === [];
    $allRefused = $allRefused && $good;
    if (!$good) {
        echo "     ($rule expected for " . substr($sql, 0, 50) . ': ' . json_encode($x['refusal'] ?? $x) . ")\n";
    }
    $allAudited = $allAudited && isset($x['query_run_id']) && run_row((int) $x['query_run_id'])['status'] === 'refused';
}
ok($allRefused, count($forms) . ' forbidden forms, each refused by its rule: one_statement, select_only (COPY, ATTACH, SET, PRAGMA, INSTALL, LOAD, DELETE), function (read_csv, read_parquet, glob, getenv, duckdb_*), unknown_table (a path), catalog_reference, time_travel, unbound_parameter, empty');
ok($allAudited && (int) pg1("SELECT count(*) FROM query_runs WHERE id > $before AND status = 'refused' AND rows_returned = 0") === count($forms), '…every one a query_runs row `refused`, nothing returned');
$p = post_form(30, '/sql/run', ["sql" => "SELECT * FROM read_csv('/etc/passwd')"], ['HX-Request: true']);
ok(str_contains($p['body'], 'id="sql-refusal"') && str_contains($p['body'], 'data-rule="function"') && str_contains($p['body'], 'The function read_csv() is not allowed.'), 'the refusal partial shows the service\'s own sentence and its rule');

$nr = result_of(sql_as(30, 'SELECT * FROM curated.hr_reviews'));
ok($nr['refusal']['rule'] === 'not_readable' && $nr['refusal']['dataset'] === 'curated.hr_reviews' && $nr['refusal']['request_access'] === '/access/requests/new?dataset=curated.hr_reviews&level=read',
   'a dataset I may not read: not_readable, named, Request access linked');
$pr = post_form(30, '/sql/run', ['sql' => 'SELECT * FROM curated.hr_reviews'], ['HX-Request: true']);
ok(str_contains($pr['body'], 'id="sql-request-access"') && str_contains($pr['body'], 'href="/access/requests/new?dataset=curated.hr_reviews&amp;level=read"'), '…the partial\'s Request access button');
$dq = result_of(sql_as(30, 'SELECT * FROM curated.payroll'));
ok($dq['refusal']['rule'] === 'discover_only' && str_contains($dq['refusal']['message'], 'discover'), 'a dataset I only discover: discover_only');
$ns = result_of(sql_as(30, 'SELECT * FROM sandbox.m26_my_upload'));
$nx = result_of(sql_as(30, 'SELECT * FROM curated.does_not_exist'));
ok($ns['refusal']['rule'] === 'no_such_dataset' && $nx['refusal']['rule'] === 'no_such_dataset' && $ns['refusal']['request_access'] === null
   && preg_replace('/\S+$/', '', $ns['refusal']['message']) === preg_replace('/\S+$/', '', $nx['refusal']['message']),
   'another\'s sandbox answers exactly as a dataset that does not exist (no_such_dataset, no Request access) — a key confirms nothing');
$ann = qs_any(['kind' => 'sql', 'caller_member_id' => 29, 'sql' => 'SELECT * FROM curated.payroll', 'source' => 'screen']);
ok(($ann['refusal_rule'] ?? '') === 'no_such_dataset', 'to an external, payroll is no_such_dataset (the service itself, db/022)');
ok(qs_any(['kind' => 'sql', 'caller_member_id' => 30, 'source' => 'screen'])['refusal_rule'] === 'bad_request', 'a request without SQL is a bad_request (nothing audited)');

echo "4. Cancel within 2 s; another's request id is not_yours; the timeout at the setting\n";
[$j, $t] = actor(30);
$rid = 'sql-30-' . bin2hex(random_bytes(6));
$out = tempnam(sys_get_temp_dir(), 'dl3');
$t0 = microtime(true);
exec(sprintf("curl -s -b %s -c %s -H 'Accept: application/json' -H 'X-CSRF-Token: %s' --data-urlencode %s --data-urlencode %s http://127.0.0.1:9001/sql/run > %s 2>&1 &",
    escapeshellarg($j), escapeshellarg($j), $t, escapeshellarg('sql=' . $long), escapeshellarg('request_id=' . $rid), escapeshellarg($out)));
usleep(1200000);
$other = post_json(27, '/sql/cancel', ['request_id' => $rid]);
ok(($other['json']['data']['cancelled'] ?? null) === false && ($other['json']['data']['rule'] ?? '') === 'not_yours', 'Marco cancelling Dana\'s run: not_yours — it keeps running');
$tc = microtime(true);
$c = post_json(30, '/sql/cancel', ['request_id' => $rid]);
for ($i = 0; $i < 60 && trim((string) @file_get_contents($out)) === ''; $i++) {
    usleep(100000);
}
$stopped = microtime(true) - $tc;
$ans = json_decode((string) file_get_contents($out), true)['data']['result'] ?? [];
ok(($c['json']['data']['cancelled'] ?? null) === true && ($ans['status'] ?? '') === 'cancelled' && $stopped < 2.0, sprintf('Dana\'s Cancel stops her cross join in %.2f s (under 2 s): status cancelled', $stopped));
ok(run_row((int) $ans['query_run_id'])['status'] === 'cancelled' && run_row((int) $ans['query_run_id'])['request_id'] === $rid, '…audited `cancelled` under the request id the page named');
ok(count(activity('query.cancel')) >= 2, 'each Cancel logged query.cancel');
$old = setting('query_timeout_screen_s', 2);
$t0 = microtime(true);
$to = result_of(sql_as(30, $long));
$took = microtime(true) - $t0;
setting('query_timeout_screen_s', (int) $old);
ok($to['status'] === 'timeout' && $to['refusal']['rule'] === 'timeout' && $took < 6, sprintf('the watchdog stops it at the setting (2 s): status timeout in %.1f s', $took));
$tp = post_form(30, '/sql/run', ['sql' => 'SELECT 1 FROM curated.big_events LIMIT 1'], ['HX-Request: true']);

echo "   the admission (db/022): a person's concurrency, an agent's rate\n";
$oldc = setting('query_concurrency_person', 1);
$q1 = 'r-adm-' . bin2hex(random_bytes(4));
$bg = popen('php -r ' . escapeshellarg('require "' . __DIR__ . '/lib.php"; echo json_encode(qs(["kind"=>"sql","caller_member_id"=>30,"sql"=>"' . $long . '","source"=>"screen","request_id"=>"' . $q1 . '"], 1));'), 'r');
usleep(1500000);
$second = qs(['kind' => 'sql', 'caller_member_id' => 30, 'sql' => 'SELECT 1 FROM curated.big_events LIMIT 1', 'source' => 'screen'], 2);
ok(($second['refusal_rule'] ?? '') === 'concurrency' && run_row((int) $second['query_run_id'])['status'] === 'refused',
   'a second query of Dana\'s while one runs (the setting 1): refused `concurrency` on the other instance — and audited');
$marco = qs(['kind' => 'sql', 'caller_member_id' => 27, 'sql' => 'SELECT 1 AS one FROM curated.big_events LIMIT 1', 'source' => 'screen'], 2);
ok(($marco['status'] ?? '') === 'ok', '…Marco is not held up by Dana\'s slot');
qs(['kind' => 'cancel', 'caller_member_id' => 30, 'request_id' => $q1], 1);
pclose($bg);
$third = qs(['kind' => 'sql', 'caller_member_id' => 30, 'sql' => 'SELECT 1 AS one FROM curated.big_events LIMIT 1', 'source' => 'screen'], 2);
ok(($third['status'] ?? '') === 'ok', '…her slot is released when the run ends (cancelled), the next is admitted');
setting('query_concurrency_person', (int) $oldc);
$oldr = setting('query_rate_agent_per_minute', 2);
$rates = [];
for ($i = 0; $i < 3; $i++) {
    $rates[] = qs_any(['kind' => 'sql', 'caller_member_id' => 40, 'sql' => 'SELECT 1 AS one FROM product.monthly_revenue LIMIT 1', 'source' => 'agent'])['refusal_rule'] ?? 'ok';
}
setting('query_rate_agent_per_minute', (int) $oldr);
ok(in_array('rate', $rates, true), 'an agent over query_rate_agent_per_minute (2): refused `rate` (' . implode(', ', $rates) . ')');
$busy = [];
$h1 = popen('php -r ' . escapeshellarg('require "' . __DIR__ . '/lib.php"; echo json_encode(qs(["kind"=>"sql","caller_member_id"=>27,"sql"=>"' . $long . '","source"=>"screen","request_id"=>"r-b1"], 1));'), 'r');
$h2 = popen('php -r ' . escapeshellarg('require "' . __DIR__ . '/lib.php"; echo json_encode(qs(["kind"=>"sql","caller_member_id"=>1,"sql"=>"' . $long . '","source"=>"screen","request_id"=>"r-b2"], 2));'), 'r');
usleep(1500000);
$b = qs(['kind' => 'sql', 'caller_member_id' => 30, 'sql' => 'SELECT 1', 'source' => 'screen'], 1);
qs(['kind' => 'cancel', 'caller_member_id' => 27, 'request_id' => 'r-b1'], 1);
qs(['kind' => 'cancel', 'caller_member_id' => 1, 'request_id' => 'r-b2'], 2);
pclose($h1);
pclose($h2);
ok(($b['refusal_rule'] ?? '') === 'busy', 'an instance answering a request answers another `busy` at once (never queued — the client tries the next)');
ok(qs(['kind' => 'ping'], 1)['status'] === 'ok' && in_array('cancel', qs(['kind' => 'ping'], 1)['kinds'] ?? [], true), 'ping lists the kinds built: cancel, preview, saved_query, sql');

echo "   K38 through the saved-query door: an agent acting for Priya reads no more than Priya\n";
$sq = post_json(30, '/queries/save.php', ['title' => 'SMOKE orders ' . run_tag(), 'sql' => 'SELECT order_id, customer_email FROM curated.sales_orders ORDER BY order_id LIMIT 2', 'shared' => 'yes']);
$sid = (int) $sq['json']['record_id'];
$run = 9300 + random_int(1, 600);
set_run_facts($run, 40, 'chat', 26);
$ag = req('GET', "/queries/$sid?run=1", ['headers' => as_agent(run_token(40, $run))]);
$ar = json_decode($ag['body'], true)['data']['result'] ?? [];
ok(($ar['status'] ?? '') === 'ok' && ($ar['rows'][0][1] ?? '') === 'b***@example.invalid', 'the Expert in Priya\'s chat turn runs the shared query: the email masked, as Priya reads it');
$qr = run_row((int) $ar['query_run_id']);
ok($qr['caller_member_id'] === 40 && $qr['on_behalf_of'] === 26 && $qr['source'] === 'agent', '…audited as the Expert on Priya\'s behalf (source agent)');
set_run_facts($run + 1, 40, 'chat', 27);
$am = json_decode(req('GET', "/queries/$sid?run=1", ['headers' => as_agent(run_token(40, $run + 1))])['body'], true)['data']['result'] ?? [];
$mm = result_of(sql_as(27, 'SELECT order_id, customer_email FROM curated.sales_orders ORDER BY order_id LIMIT 2'));
ok(($am['rows'][0][1] ?? '') === 'b***@example.invalid' && ($mm['rows'][0][1] ?? '') === 'buyer1@example.invalid',
   'acting for Marco (the owner) the Expert still reads it masked — the intersection: never more than the agent may either');
set_run_facts($run + 2, 40, 'chat', false);
$ak = json_decode(req('GET', "/queries/$sid?run=1", ['headers' => as_agent(run_token(40, $run + 2))])['body'], true)['data']['result'] ?? [];
ok(($ak['refusal']['rule'] ?? '') === 'k38_pending', 'a chat turn whose run facts name nobody (a kernel without K38): k38_pending — fail closed');
$k1 = qs_any(['kind' => 'sql', 'caller_member_id' => 40, 'on_behalf_of' => 26, 'sql' => 'SELECT count(*) FROM curated.region_sales', 'source' => 'agent']);
ok(($k1['status'] ?? '') === 'refused' && in_array($k1['refusal_rule'] ?? '', ['discover_only', 'not_readable', 'no_such_dataset'], true),
   'the Expert for Priya on region sales (Priya reads it, the Expert only discovers it): refused ' . ($k1['refusal_rule'] ?? '?') . ' — the lower level of the two');

echo "   the activity log never holds the SQL\n";
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action IN ('query.run', 'query.cancel', 'saved_query.save') AND (after::text ILIKE '%big_events a,%' OR after::text ILIKE '%select %')") === 0,
   'no query.run, query.cancel or saved_query.save row carries SQL');
finish();
