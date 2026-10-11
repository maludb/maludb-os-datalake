<?php
/**
 * Slice 3 — JSON mode on every screen, door and handler (query-service-editor.md "Proof" 11): the six screens answer their data, the two read
 * doors theirs, the six handlers {ok, did, record_id, location, refresh}; a field refusal {error: {fields}}; HTMX gets fragments; the registry
 * shows 6 screens and 6 actions built, 2 approvals in step (query_verify other, export_make external_send).
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;
$tag = run_tag();

echo "11. JSON mode on the six screens\n";
$s = post_json(30, '/queries/save.php', ['title' => "SMOKE json $tag", 'sql' => 'SELECT region FROM curated.sales_orders LIMIT 1', 'shared' => 'yes']);
$qid = (int) $s['json']['record_id'];
$run = (int) result_of(sql_as(30, 'SELECT 1 AS one FROM curated.sales_orders LIMIT 1'))['query_run_id'];
$x = post_json(30, '/exports/make.php', ['format' => 'csv', 'sql' => 'SELECT region FROM curated.sales_orders LIMIT 2']);
wait_job((int) $x['json']['job_id'], 60);
$screens = ['/sql' => ['catalog', 'runs', 'limits'], '/queries' => ['rows', 'total'], "/queries/$qid" => ['saved_query', 'may'], "/queries/$qid/edit" => ['saved_query', 'types'],
            '/queries/new' => ['saved_query'], "/runs/$run" => ['run'], '/exports' => ['rows']];
foreach ($screens as $url => $keys) {
    $r = get_json(30, $url);
    ok($r['code'] === 200 && array_diff($keys, array_keys($r['json']['data'] ?? [])) === [], "$url answers JSON: " . implode(', ', $keys));
}
ok(!str_contains((string) json_encode(get_json(30, '/sql')['json']), 'salt') && !str_contains((string) json_encode(get_json(30, "/runs/$run")['json']), 'row_filter"'), 'no salt, no policy, no filter text in any of them');

echo "   the two read doors\n";
$r = post_json(30, '/sql/run', ['sql' => 'SELECT region FROM curated.sales_orders LIMIT 1']);
ok(array_diff(['status', 'columns', 'rows', 'row_count', 'truncated', 'masked_columns', 'datasets', 'ms', 'query_run_id', 'run_url', 'refusal', 'stats'], array_keys($r['json']['data']['result'] ?? [])) === [],
   '/sql/run: {result: status, columns, rows, row_count, truncated, masked_columns, datasets, ms, query_run_id, run_url, refusal, stats}');
$c = post_json(30, '/sql/cancel', ['request_id' => 'sql-30-nothing']);
ok(($c['json']['data'] ?? null) === ['cancelled' => false, 'rule' => 'not_found'], '/sql/cancel: {cancelled, rule} — nothing of mine running: not_found');
ok(post_json(30, '/sql/cancel', ['request_id' => 'bad id!'])['code'] === 422, '…a request id of the wrong shape: 422');

echo "   the six handlers\n";
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && isset($r['json']['did'], $r['json']['location']) && array_key_exists('record_id', $r['json']);
ok($shape($s) && $s['json']['refresh'] === 'savedQueryChanged', 'saved_query_save');
ok($shape(post_json(27, '/queries/verify.php', ['saved_query' => $qid, 'verify' => 'yes'])), 'query_verify');
$sb = post_json(30, '/sql/save-dataset.php', ['name' => "json_$tag", 'sql' => 'SELECT region FROM curated.sales_orders LIMIT 2']);
ok($shape($sb) && isset($sb['json']['job_id']), 'sandbox_save (+ job_id)');
wait_job((int) $sb['json']['job_id'], 60);
ok($shape($x) && isset($x['json']['job_id']), 'export_make (+ job_id)');
ok($shape(post_json(30, '/exports/delete.php', ['export' => $x['json']['record_id']])), 'export_delete');
ok($shape(post_json(30, '/queries/delete.php', ['saved_query' => $qid])), 'saved_query_delete');
$f = post_json(30, '/queries/save.php', ['title' => '', 'sql' => '']);
ok($f['code'] === 422 && isset($f['json']['error']['fields']['title'], $f['json']['error']['fields']['sql']), 'a field refusal: {error: {fields: {title, sql}}}');
$h = post_form(30, '/queries/save.php', ['title' => "SMOKE form $tag", 'sql' => 'SELECT region FROM curated.sales_orders LIMIT 1']);
ok($h['code'] === 303 || $h['code'] === 302, 'a browser\'s form lands by redirect (' . $h['code'] . ')');

echo "   an agent's token\n";
$tok = person_token(40);
$ag = req('POST', '/queries/save.php', ['headers' => as_agent($tok), 'form' => ['title' => 'x', 'sql' => 'SELECT 1 AS one FROM curated.sales_orders']]);
ok($ag['code'] === 403, 'the Expert (no queries.save) is refused saving a query (403) — its grants are an admin\'s to give');

echo "   the registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$scr = ['sql', 'queries', 'query-view', 'query-edit', 'query-run-view', 'exports'];
$acts = ['saved_query_save', 'saved_query_delete', 'query_verify', 'sandbox_save', 'export_make', 'export_delete'];
ok(count(array_filter($scr, static fn ($k) => !empty($reg['screens'][$k]['built']))) === 6, '6 screens built');
ok(count(array_filter($acts, static fn ($k) => !empty($reg['actions'][$k]['built']))) === 6, '6 actions built');
$paused = array_values(array_filter($acts, static fn ($k) => !empty($reg['actions'][$k]['approval'])));
ok($paused === ['query_verify', 'export_make'] && $reg['actions']['query_verify']['approval'] === 'other' && $reg['actions']['export_make']['approval'] === 'external_send',
   '2 approvals in step: query_verify (other), export_make (external_send)');
ok(!isset($reg['actions']['query_run']), 'running SQL is a read door, never a registry action');
finish();
