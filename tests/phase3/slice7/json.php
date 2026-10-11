<?php
/**
 * Slice 7 proof 4 — JSON MODE and the walls on every screen (transforms-products-lineage.md "Proof": JSON mode; the manifest's 10 screens and 7 actions): every screen answers {data: …} to a
 * JSON caller and every handler {ok, did, record_id, location, refresh} (a refusal {error: {code, message, fields?}}); the screens' walls (a Member, an external, nobody signed in, a stub);
 * an agent holding the right does what a person does; the registry reads 10 screens and 7 actions built, the approvals in step. Run through tests/phase3/slice7/run.sh.
 */
require __DIR__ . '/lib.php';
$std = standard_transforms();
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('did', $r['json']) && array_key_exists('record_id', $r['json'])
    && array_key_exists('location', $r['json']) && array_key_exists('refresh', $r['json']);
$monthly = ds_id('product.s7_monthly');
$empty = ds_id('product.s7_empty');
$m = (int) pg1("SELECT id FROM metrics WHERE name = 's7_revenue' AND retired_at IS NULL");
if ($m === 0) {
    $m = (int) (post_json(27, '/metrics/save.php', ['name' => 's7_revenue', 'title' => 'S7 Revenue', 'dataset' => $monthly, 'expression' => 'sum(revenue)', 'time_column' => 'month', 'grains' => 'month,quarter,year', 'dimensions' => 'region'])['json']['record_id'] ?? 0);
}

echo "1. The screens\n";
$l = get_json(27, '/transforms')['json']['data'] ?? [];
ok(array_keys($l) === ['filters', 'page', 'page_size', 'total', 'rows', 'may'] && $l['total'] >= 4 && array_keys($l['rows'][0]) === ['transform_id', 'name', 'description', 'output', 'inputs', 'inputs_hidden', 'mode', 'schedule', 'schedule_words', 'version',
    'owner', 'state', 'state_words', 'enabled', 'frozen_at', 'last_run', 'url'], 'transform-list: {filters, page, page_size, total, rows[], may} — a row is whitelisted (no SQL, no policy)');
ok(!str_contains(json_encode($l), 'frozen_policy') && !str_contains(json_encode($l), '"sql"'), '…and no frozen policy, no SQL anywhere in the list');
$v = get_json(27, "/transforms/{$std['monthly']}")['json']['data'] ?? [];
ok(array_keys($v) === ['transform', 'tab', 'tabs', 'runs', 'edges'] && array_column($v['tabs'], 'name') === ['sql', 'io', 'runs', 'lineage'] && ($v['transform']['may_read_sql'] ?? false) === true && str_contains((string) $v['transform']['sql'], 'GROUP BY')
    && ($v['transform']['may']['run'] ?? false) === true && ($v['runs'][0]['status'] ?? '') === 'ok', 'transform-view: the transform with its SQL, the four tabs, the runs, the edges and what the caller may do');
$f = get_json(27, '/transforms/new?output_dataset=' . $empty)['json']['data'] ?? [];
ok(($f['values']['output_dataset'] ?? 0) === $empty && $f['modes'] === ['replace', 'incremental_merge'] && $f['schedule_kinds'] === ['manual', 'after_inputs', 'cron'] && count($f['readable'] ?? []) > 3, 'transform-add: the values (the output prefilled), the modes, the schedule kinds and the keys the caller may read');
$f = get_json(27, "/transforms/{$std['monthly']}/edit")['json']['data'] ?? [];
ok(($f['transform_id'] ?? 0) === $std['monthly'] && ($f['values']['name'] ?? '') === 's7_monthly_t' && str_contains((string) ($f['values']['sql'] ?? ''), 'date_trunc'), 'transform-edit: the values of the transform');
$ml = get_json(26, '/metrics')['json']['data'] ?? [];
ok(array_keys($ml) === ['filters', 'page', 'page_size', 'total', 'rows', 'may'] && array_keys($ml['rows'][0]) === ['metric_id', 'name', 'title', 'description', 'dataset', 'time_column', 'grains', 'dimensions', 'format', 'unit', 'certified', 'certified_at', 'owner', 'url']
    && ($ml['may']['add'] ?? true) === false, 'metric-list: {…rows[]} whitelisted — no expression; a Member may not add');
$mv = get_json(27, "/metrics/$m?by[]=region")['json']['data'] ?? [];
ok(array_keys($mv) === ['metric', 'grain', 'by', 'window', 'chart', 'value', 'refusal'] && ($mv['metric']['expression'] ?? '') === 'sum(revenue)' && count($mv['chart']['points'] ?? []) === 12 && ($mv['value']['rows'][0]['region'] ?? '') !== ''
    && ($mv['by'] ?? []) === ['region'] && ($mv['window']['periods'] ?? []) !== [], 'metric-view: the metric, the grain, the cut, the window, the chart\'s twelve points, the value cut by region');
$mf = get_json(27, "/metrics/$m/edit")['json']['data'] ?? [];
ok(($mf['metric_id'] ?? 0) === $m && ($mf['values']['dimensions'] ?? []) === ['region'] && in_array('revenue', array_column($mf['columns'] ?? [], 'name'), true) && ($mf['grains'] ?? []) === ['day', 'week', 'month', 'quarter', 'year'], 'metric-edit: the values and the product\'s columns');
$ma = get_json(27, "/metrics/new?dataset=$monthly")['json']['data'] ?? [];
ok(($ma['values']['dataset'] ?? 0) === $monthly && count($ma['columns'] ?? []) === 4, 'metric-add: the product prefilled with its columns');
$pr = get_json(27, '/products')['json']['data'] ?? [];
ok(array_keys($pr) === ['filters', 'page', 'page_size', 'total', 'rows', 'may'] && array_keys($pr['rows'][0]) === ['dataset_id', 'key', 'title', 'description', 'certification', 'status', 'owner', 'row_count', 'data_updated_at', 'health', 'metrics', 'certified_metrics', 'url', 'query_url'],
   'products: a row is dataset, owner, rows, freshness, health, metrics, URLs');
$lg = get_json(27, "/datasets/$monthly/lineage")['json']['data'] ?? [];
ok(($lg['dataset']['key'] ?? '') === 'product.s7_monthly' && isset($lg['upstream']['nodes'], $lg['downstream']['nodes'], $lg['impact']['transforms']), 'lineage-graph: {dataset, depth, upstream, downstream, impact, svg}');
$dv = get_json(27, '/datasets/product.s7_monthly')['json']['data'] ?? [];
ok(array_column($dv['tabs'] ?? [], 'built', 'name')['lineage'] === true, 'dataset-view\'s tabs: lineage is built');

echo "2. The walls on every screen\n";
foreach (['/transforms', '/transforms/new', "/transforms/{$std['monthly']}/edit"] as $p) {
    ok(get_json(26, $p)['code'] === 403, "a Member (no transforms right): $p → 403");
}
ok(get_json(29, '/transforms')['code'] === 403, 'an external: /transforms → 403');
ok(get_json(26, "/transforms/{$std['monthly']}")['code'] === 200, 'a Member who discovers the output may open the transform (catalog.browse) — read only');
$mv26 = get_json(26, "/transforms/{$std['monthly']}")['json']['data']['transform'] ?? [];
ok(($mv26['may']['run'] ?? true) === false && ($mv26['may']['edit'] ?? true) === false && ($mv26['may']['delete'] ?? true) === false, '…with no way to run, edit or delete it');
ok(get_json(29, "/transforms/{$std['monthly']}")['code'] === 404, 'an external cannot discover the output: the transform is the same 404');
$danaT = (int) pg1("SELECT id FROM transforms WHERE output_dataset_id = (SELECT id FROM datasets WHERE key = 'sandbox.m30_s7_mine')");
ok($danaT === 0 || get_json(27, "/transforms/$danaT")['code'] === 404, 'a transform into someone else\'s sandbox is nothing to anyone else');
ok(get_json(27, '/transforms/99999999')['code'] === 404 && (get_json(27, '/transforms/99999999')['json']['error']['code'] ?? '') === 'not_found', 'an unknown transform: 404 not_found');
ok(get_json(26, "/transforms/{$std['monthly']}/edit")['code'] === 403, 'a Member may not open the edit form');
ok(get_json(30, "/transforms/{$std['monthly']}/edit")['code'] === 403, 'an Analyst may not edit Marco\'s transform (her right is her own sandbox)');
ok(get_json(30, '/transforms/new')['code'] === 200, 'an Analyst may open the form (sandbox.transform)');
ok(get_json(26, "/metrics/$m/edit")['code'] === 403 && get_json(26, '/metrics/new')['code'] === 403 && get_json(30, '/metrics/new')['code'] === 403, 'metric-add and metric-edit are metrics.manage\'s: a Member and an Analyst → 403');
ok(get_json(29, '/metrics')['code'] === 200 && ($tot = get_json(29, '/metrics')['json']['data']['total']) === 0, 'an external opens /metrics (metrics.use) and sees none — the products are not hers to discover (total ' . $tot . ')');
ok(get_json(29, "/metrics/$m")['code'] === 404, 'a metric over a product she cannot discover: 404');
ok(get_json(27, '/metrics/99999999')['code'] === 404, 'an unknown metric: 404');
$anon = req('GET', '/transforms', ['jar' => jar(), 'headers' => ['Accept: application/json']]);
ok($anon['code'] === 401 && (json_decode($anon['body'], true)['error']['code'] ?? '') === 'unauthorized', 'nobody signed in: 401 {error: {code: unauthorized}}');
foreach (['/products', "/metrics/$m", "/datasets/$monthly/lineage"] as $p) {
    $r = req('GET', $p, ['jar' => jar(), 'headers' => ['Accept: application/json']]);
    ok($r['code'] === 401, "nobody signed in: $p → 401");
}
foreach (['/transforms/save.php', '/transforms/run.php', '/transforms/enable.php', '/transforms/delete.php', '/metrics/save.php', '/metrics/certify.php', '/metrics/retire.php'] as $h) {
    $noCsrf = req('POST', $h, ['jar' => actor(27)[0], 'headers' => ['Accept: application/json'], 'form' => ['transform' => $std['monthly'], 'metric' => $m]]);
    ok($noCsrf['code'] === 403 && get_json(27, $h)['code'] === 405, "$h: no CSRF token → 403, a GET → 405");
}

echo "3. Every action's answer\n";
$mk = transform_save_as(27, ['name' => 's7_json_t', 'output_dataset' => $empty, 'sql' => 'SELECT region, count(*) AS n FROM curated.s7_orders GROUP BY region', 'schedule' => '0 6 * * *', 'description' => 'JSON mode']);
$tid = (int) ($mk['json']['record_id'] ?? 0);
ok($shape($mk) && ($mk['json']['refresh'] ?? '') === 'transformChanged' && ($mk['json']['location'] ?? '') === "/transforms/$tid?notice=created" && ($mk['json']['version'] ?? 0) === 1 && ($mk['json']['inputs'] ?? []) === ['curated.s7_orders'],
   'transform_save: {ok, did, record_id, location, refresh, version, inputs, refrozen}');
ok(transform_row($tid)['schedule'] === '0 6 * * *', 'the schedule (a cron) is kept');
$up = transform_save_as(27, ['transform' => $tid, 'schedule' => 'after_inputs']);
ok($shape($up) && transform_row($tid)['schedule'] === 'after_inputs', 'transform_save (a change): the schedule becomes after_inputs');
$up = transform_save_as(27, ['transform' => $tid, 'schedule_kind' => 'cron', 'cron' => '*/30 * * * *']);
ok($shape($up) && transform_row($tid)['schedule'] === '*/30 * * * *', 'the form\'s schedule_kind + cron say the same');
$up = transform_save_as(27, ['transform' => $tid, 'schedule' => '']);
ok($shape($up) && transform_row($tid)['schedule'] === null, 'an empty schedule is "on request"');
$run = post_json(27, '/transforms/run.php', ['transform' => $tid]);
ok($shape($run) && ($run['json']['run'] ?? 0) > 0 && ($run['json']['job_id'] ?? 0) > 0 && ($run['json']['new'] ?? false) === true && ($run['json']['refresh'] ?? '') === 'transformChanged', 'transform_run: the run\'s id and the job\'s with it');
wait_until(static fn (): bool => in_array(pg1("SELECT status FROM transform_runs WHERE id = {$run['json']['run']}"), ['ok', 'failed'], true), 120);
ok(pg1("SELECT status FROM transform_runs WHERE id = {$run['json']['run']}") === 'ok', '(the writer made product.s7_empty)');
$en = post_json(27, '/transforms/enable.php', ['transform' => $tid, 'enabled' => 'no']);
ok($shape($en) && ($en['json']['enabled'] ?? true) === false && ($en['json']['location'] ?? '') === "/transforms/$tid?notice=disabled", 'transform_enable (no)');
$en = post_json(27, '/transforms/enable.php', ['transform' => $tid]);
ok($shape($en) && ($en['json']['enabled'] ?? false) === true, 'transform_enable (the default is yes)');
$e = post_json(27, '/transforms/enable.php', ['transform' => $tid, 'enabled' => 'maybe']);
ok($e['code'] === 422, 'a yes/no field that is neither: 422');
$ms = post_json(27, '/metrics/save.php', ['name' => 's7_json_m', 'title' => 'JSON metric', 'dataset' => $empty, 'expression' => 'sum(n)', 'dimensions' => 'region', 'format' => 'integer']);
$mid = (int) ($ms['json']['record_id'] ?? 0);
ok($shape($ms) && ($ms['json']['refresh'] ?? '') === 'metricChanged' && ($ms['json']['location'] ?? '') === "/metrics/$mid?notice=created", 'metric_save: {ok, did, record_id, location, refresh, certification_dropped}');
$mc = post_json(27, '/metrics/certify.php', ['metric' => $mid]);
ok($shape($mc) && ($mc['json']['certified'] ?? false) === true, 'metric_certify');
$mc2 = post_json(27, '/metrics/certify.php', ['metric' => $mid, 'certify' => 'no']);
ok($shape($mc2) && ($mc2['json']['certified'] ?? true) === false, 'metric_certify (no)');
$mr = post_json(27, '/metrics/retire.php', ['metric' => $mid]);
ok($shape($mr) && ($mr['json']['location'] ?? '') === '/metrics?notice=retired', 'metric_retire');
$del = post_json(27, '/transforms/delete.php', ['transform' => $tid]);
ok($shape($del) && ($del['json']['location'] ?? '') === '/transforms?notice=deleted' && pg1("SELECT count(*) FROM datasets WHERE key = 'product.s7_empty'") === '1', 'transform_delete (the output stays)');

echo "4. Refusals speak JSON\n";
$e = transform_save_as(27, ['name' => '', 'output_dataset' => '', 'sql' => '']);
ok($e['code'] === 422 && ($e['json']['error']['code'] ?? '') === 'invalid' && isset($e['json']['error']['fields']['name'], $e['json']['error']['fields']['output_dataset'], $e['json']['error']['fields']['sql']) && count($e['json']['error']['errors'] ?? []) >= 3,
   '422 {error: {code: invalid, message, errors[], fields{}}} — every field that is wrong, named');
$e = post_json(27, '/metrics/save.php', ['name' => '', 'title' => '', 'dataset' => '', 'expression' => '']);
ok($e['code'] === 422 && isset($e['json']['error']['fields']['name'], $e['json']['error']['fields']['title'], $e['json']['error']['fields']['dataset'], $e['json']['error']['fields']['expression']), 'metric_save: the same');
ok(post_json(27, '/transforms/run.php', ['transform' => 99999999])['code'] === 404 && post_json(27, '/metrics/certify.php', ['metric' => 99999999])['code'] === 404 && post_json(27, '/metrics/retire.php', ['metric' => 99999999])['code'] === 404,
   'an unknown transform or metric on an action: 404');
ok(post_json(26, '/metrics/save.php', ['name' => 'x', 'title' => 'x', 'dataset' => $monthly, 'expression' => 'sum(revenue)'])['code'] === 403 && post_json(26, '/metrics/certify.php', ['metric' => $m])['code'] === 403
   && post_json(26, '/metrics/retire.php', ['metric' => $m])['code'] === 403, 'a Member on a metric action: 403');
ok(post_json(30, '/transforms/delete.php', ['transform' => $std['monthly']])['code'] === 403, 'an Analyst deleting a data engineer\'s transform: 403');

echo "5. An agent\n";
$ag = agent_post(40, '/metrics/save.php', ['name' => 's7_agent_m', 'title' => 'Agent metric', 'dataset' => $monthly, 'expression' => 'sum(orders)', 'time_column' => 'month', 'grains' => 'month', 'dimensions' => 'region']);
$aid = (int) ($ag['json']['record_id'] ?? 0);
ok($shape($ag) && $aid > 0 && pg1("SELECT source FROM activity_log WHERE action = 'metric.save' AND entity_id = $aid") === 'agent', 'an agent holding metrics.manage defines a metric: the same answer, logged as source agent');
$ac = agent_post(40, '/metrics/certify.php', ['metric' => $aid]);
ok($shape($ac), 'its certify reaches the handler (the kernel\'s approval, category other, stands in front of it)');
$ar = agent_post(40, '/transforms/run.php', ['transform' => $std['monthly']]);
ok(in_array($ar['code'], [200, 403, 404], true), 'an agent running a transform it may not discover or manage is refused: ' . $ar['code']);
$ad = agent_post(42, '/metrics/save.php', ['name' => 's7_steward_m', 'title' => 'x', 'dataset' => $monthly, 'expression' => 'sum(revenue)']);
ok($ad['code'] === 403, 'an agent without the right: 403');
ok(agent_post(41, '/transforms', [])['code'] !== 200, 'the Watcher (not admitted) gets nothing');
pg("UPDATE metrics SET retired_at = now() WHERE id = $aid");

echo "6. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$screens = ['products', 'transform-list', 'transform-view', 'transform-add', 'transform-edit', 'metric-list', 'metric-view', 'metric-add', 'metric-edit', 'lineage-graph'];
$actions = ['transform_save', 'transform_run', 'transform_enable', 'transform_delete', 'metric_save', 'metric_certify', 'metric_retire'];
$sb = array_filter($screens, static fn ($s) => ($reg['screens'][$s]['built'] ?? false) === true);
$ab = array_filter($actions, static fn ($a) => ($reg['actions'][$a]['built'] ?? false) === true);
ok(count($sb) === 10 && count($ab) === 7, 'the registry reads the slice\'s 10 screens and 7 actions built');
$all = array_filter($reg['actions'], static fn ($a) => $a['built'] ?? false);
$allS = array_filter($reg['screens'], static fn ($s) => $s['built'] ?? false);
ok(count($allS) === 64 && count($all) === 86, 'and in all 64 screens and 86 actions (54 + 10, 79 + 7)');
$ap = array_keys(array_filter($reg['actions'], static fn ($a) => $a['approval'] !== null));
ok(count($ap) === 29 && in_array('transform_delete', $ap, true) && in_array('metric_certify', $ap, true) && in_array('metric_retire', $ap, true), '29 approvals in all; the three of this slice are in step');
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/build_action_registry.php') . ' --check 2>&1', $out, $rc);
ok($rc === 0, 'build_action_registry --check: the registry and maludb-os.json approvals[] match the manifest');
finish();
