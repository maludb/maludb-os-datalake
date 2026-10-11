<?php
/**
 * Slice 2 proof — JSON mode and the HTMX/browser contract on the slice's six screens and six actions (uploads-loads.md "Proof" 12): every
 * screen answers {data: …} through a whitelisted presenter (never a sample to anyone but the uploader); every handler {ok, did, record_id,
 * location, refresh}; over HTMX a screen is the partial and a handler lands by HX-Location, a refusal in #flash; a browser's form POST is
 * redirected; the dataset page's JSON says its Preview tab is built; the registry claims the six screens, the six actions and one approval.
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$tag = run_tag();
[$u] = upload_file(26, "$fx/products.parquet");
wait_sniff($u);
$r = map_confirm_as(26, $u, ['target' => 'sandbox', 'name' => "products_$tag", 'save_template' => "Products $tag"]);
$load = (int) $r['json']['record_id'];
$ds = (int) $r['json']['dataset_id'];
wait_load($load);
$tid = (int) pg1("SELECT id FROM import_templates WHERE name = 'Products $tag'");

echo "12. JSON on every screen\n";
$screens = ['upload' => [26, '/upload', ['uploads', 'part_bytes', 'max_bytes']], 'upload-map' => [26, "/uploads/$u/map", ['upload', 'may']],
    'sandbox' => [26, '/sandbox', ['datasets', 'used_bytes', 'quota_bytes']], 'load-list' => [27, '/loads', ['rows', 'total', 'page']],
    'load-view' => [26, "/loads/$load", ['load', 'rejects']], 'templates' => [26, '/templates', ['rows']]];
$bad = [];
foreach ($screens as $screen => [$who, $url, $keys]) {
    $x = get_json($who, $url);
    if ($x['code'] !== 200 || array_diff($keys, array_keys($x['json']['data'] ?? [])) !== []) {
        $bad[] = "$screen ({$x['code']})";
    }
}
ok($bad === [], 'the six screens answer JSON with their data' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$p = get_json(26, "/datasets/$ds/preview")['json']['data']['preview'] ?? [];
ok(array_diff(['columns', 'rows', 'masked_columns', 'as_of', 'query_run_id'], array_keys($p)) === [] && count($p['rows']) === 3, 'the preview door: the dataset_preview tool\'s shape (columns, rows, masked_columns, as_of, query_run_id)');
$tabs = get_json(26, "/datasets/$ds")['json']['data']['tabs'] ?? [];
ok((array_column($tabs, 'built', 'name')['preview'] ?? false) === true, 'the dataset page\'s JSON: the Preview tab is built');
$s = get_json(26, '/sandbox')['json']['data'] ?? [];
$mine = array_values(array_filter($s['datasets'] ?? [], static fn ($d) => $d['dataset_id'] === $ds))[0] ?? [];
ok(($mine['made_from'] ?? '') === 'upload' && ($mine['rows'] ?? 0) === 3 && $s['quota_bytes'] === 5368709120, 'my_sandbox: the dataset (made from an upload, 3 rows) and the 5 GB quota');
ok(!array_key_exists('sample', get_json(26, '/upload')['json']['data']['uploads'][0] ?? []) && !str_contains(json_encode(get_json(26, '/upload')['json']), 'SKU-001'), 'the upload list carries no sample row');
ok(get_json(31, '/sandbox')['json']['data']['datasets'] === [] || !in_array($ds, array_column(get_json(31, '/sandbox')['json']['data']['datasets'], 'dataset_id'), true), 'another Member\'s sandbox never lists mine');
$found = get_json(31, '/search?q=products_' . $tag)['json']['data'] ?? [];
ok(get_json(31, "/datasets/$ds")['code'] === 404 && ($found['total'] ?? -1) === 0 && ($found['rows'] ?? [1]) === [], 'my sandbox dataset to another Member: 404 on the page, absent from search');

echo "12. JSON from every handler\n";
$checks = [];
[$u2] = upload_file(26, "$fx/events.ndjson");
wait_sniff($u2);
$x = post_json(26, '/uploads/discard.php', ['upload' => $u2]);
$checks['upload_discard'] = $x['code'] === 200 && ($x['json']['record_id'] ?? 0) === $u2 && isset($x['json']['did'], $x['json']['location']);
[$u3] = upload_file(26, "$fx/customers_next_month.csv");
wait_sniff($u3);
$x = map_confirm_as(26, $u3, ['target' => 'sandbox', 'name' => "next_$tag"]);
$checks['upload_map_confirm'] = $x['code'] === 200 && ($x['json']['record_id'] ?? 0) > 0 && ($x['json']['refresh'] ?? '') === 'jobQueued' && str_starts_with((string) ($x['json']['location'] ?? ''), '/loads/');
wait_load((int) $x['json']['record_id']);
[$u4] = upload_file(27, "$fx/customers_next_month.csv");
wait_sniff($u4);
$rl = map_confirm_as(27, $u4, ['target' => 'raw', 'raw_name' => "json_$tag"]);
$rload = (int) $rl['json']['record_id'];
wait_load($rload);
$x = post_json(27, '/loads/queue.php', ['load' => $rload]);
$checks['load_queue'] = $x['code'] === 200 && ($x['json']['record_id'] ?? 0) === $rload && ($x['json']['refresh'] ?? '') === 'jobQueued';
wait_load($rload, 60, false);
$x = post_json(27, '/loads/rollback.php', ['load' => $rload]);
$checks['load_rollback'] = $x['code'] === 200 && ($x['json']['record_id'] ?? 0) === $rload && isset($x['json']['job_id']);
$x = post_json(26, '/templates/save.php', ['template' => $tid, 'name' => "Products $tag", 'kind' => 'parquet']);
$checks['template_save'] = $x['code'] === 200 && ($x['json']['record_id'] ?? 0) === $tid && ($x['json']['refresh'] ?? '') === 'templateChanged';
$x = post_json(26, '/templates/delete.php', ['template' => $tid]);
$checks['template_delete'] = $x['code'] === 200 && ($x['json']['record_id'] ?? 0) === $tid;
ok(array_keys(array_filter($checks)) === array_keys($checks), 'the six handlers answer {ok, did, record_id, location, refresh}' . (array_filter($checks) !== $checks ? ' — wrong: ' . implode(', ', array_keys(array_diff_key($checks, array_filter($checks)))) : ''));
$x = post_json(26, "/uploads/$u3/map", ['target' => 'sandbox', 'name' => 'not a name!']);
ok($x['code'] === 422 && ($x['json']['error']['code'] ?? '') === 'invalid' && isset($x['json']['error']['fields']['name']), 'a refusal: {error: {code invalid, message, errors, fields}}');
ok(get_json(26, '/loads/999999')['json']['error']['code'] === 'not_found', 'a load that is not there: not_found');

echo "12. HTMX and the browser\n";
[$j] = actor(26);
$x = req('GET', '/sandbox', ['jar' => $j, 'headers' => ['HX-Request: true']]);
ok($x['code'] === 200 && !str_contains($x['body'], '<html') && str_contains($x['body'], 'id="sandbox-content"'), 'a screen over HTMX: the partial');
[$jm, $tm] = actor(27);
$x = req('POST', '/loads/queue.php', ['jar' => $jm, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $tm], 'form' => ['load' => $rload, 'csrf_token' => $tm]]);
ok(str_contains($x['headers'], 'HX-Location') || str_contains($x['headers'], 'HX-Retarget'), 'a handler over HTMX: HX-Location (or a refusal in #flash)');
$x = req('POST', '/templates/save.php', ['jar' => $j, 'headers' => ['HX-Request: true'], 'form' => ['name' => '', 'kind' => 'csv', 'mapping' => '{}', 'csrf_token' => actor(26)[1]]]);
ok($x['code'] === 422 && str_contains($x['headers'], 'HX-Retarget: #flash'), 'a refusal over HTMX lands in #flash');
$x = post_form(26, '/uploads/discard.php', ['upload' => $u2]);
ok($x['code'] === 302 && str_starts_with($x['location'], '/upload'), 'a browser\'s form POST is redirected');

echo "12. The registry\n";
exec('php bin/build_action_registry.php --check 2>&1', $out, $rc);
ok($rc === 0, 'the registry and maludb-os.json approvals[] match the manifest');
$reg = json_decode((string) file_get_contents('mcp/action_registry.json'), true) ?: [];
$builtScreens = array_keys(array_filter($reg['screens'] ?? [], static fn ($s) => !empty($s['built'])));
$builtActions = array_keys(array_filter($reg['actions'] ?? [], static fn ($a) => !empty($a['built'])));
$six = ['upload', 'upload-map', 'sandbox', 'load-list', 'load-view', 'templates'];
$acts = ['upload_map_confirm', 'upload_discard', 'load_queue', 'load_rollback', 'template_save', 'template_delete'];
ok(array_diff($six, $builtScreens) === [] && array_diff($acts, $builtActions) === [], 'the six screens and six actions are built in the registry');
ok(array_keys(array_filter($reg['actions'], static fn ($a, $k) => in_array($k, $acts, true) && !empty($a['approval']), ARRAY_FILTER_USE_BOTH)) === ['load_rollback'], 'one of them pauses an agent: load_rollback');
finish();
