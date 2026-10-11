<?php
/**
 * Slice 6 proof 5 — JSON MODE (docs/build-specs/sources-pulls.md "Proof": JSON mode on every action): every screen answers {data: …} to a JSON caller and every handler
 * {ok, did, record_id, location, refresh} (a refusal {error: {code, message, fields?}}) — the shape the kernel's actions server reads; an agent holding the right
 * does everything but seal a credential (people only). Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$shape = static fn (array $r): bool => $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && array_key_exists('did', $r['json']) && array_key_exists('record_id', $r['json'])
    && array_key_exists('location', $r['json']) && array_key_exists('refresh', $r['json']);
fx_state(['json_sales.csv' => ['file' => 'sales_a.csv', 'etag' => '"j1"']]);

echo "1. The screens\n";
$mk = post_json(27, '/sources/save.php', ['name' => 'JSON mode file', 'connector' => 'http_file', 'url' => fx_url('json_sales.csv'), 'target_name' => 'json_mode', 'schedule_minutes' => '60']);
$id = (int) ($mk['json']['record_id'] ?? 0);
ok($shape($mk) && ($mk['json']['refresh'] ?? '') === 'sourceChanged' && ($mk['json']['location'] ?? '') === "/sources/$id?notice=created", 'source_create: {ok, did, record_id, location, refresh}');
foreach (['source-list' => ['/sources', 'rows'], 'source-view' => ["/sources/$id", 'source'], 'source-add (step 1)' => ['/sources/new', 'connectors'], 'source-add (a connector)' => ['/sources/new?connector=postgres', 'values'],
          'source-edit' => ["/sources/$id/edit", 'values']] as $name => [$url, $key]) {
    $r = get_json(27, $url);
    ok($r['code'] === 200 && array_key_exists($key, $r['json']['data'] ?? []), "$name: {data: {{$key}, …}}");
}
$v = get_json(27, "/sources/$id")['json']['data'];
ok(array_keys($v) === ['source', 'tab', 'tabs', 'pulls', 'feeds', 'connector'] && array_column($v['tabs'], 'name') === ['settings', 'pulls', 'feeds'] && ($v['source']['may']['pull'] ?? false) === true, 'source-view: the source, the tabs, the pulls, the feeds, the connector, and what the caller may do');
$list = get_json(27, '/sources')['json']['data'];
ok(array_keys($list['rows'][0]) === ['source_id', 'name', 'connector', 'connector_label', 'schedule_minutes', 'schedule', 'state', 'paused_reason', 'consecutive_failures', 'backoff_until', 'last_ok_at', 'last_pull', 'target', 'has_credential', 'url']
    && !isset($list['rows'][0]['settings']), 'a list row is whitelisted: no settings, no credential — only whether there is one');
ok(!str_contains(json_encode(get_json(27, "/sources/$id")['json']), 'ciphertext'), 'no ciphertext anywhere');
$a = get_json(27, '/sources/new')['json']['data']['connectors'];
ok(array_column($a, 'key') === ['upload', 'http_file', 'sftp_file', 'k7', 'postgres'] && array_column($a, 'pulls') === [false, true, true, true, true], 'the connectors: key, label, description, whether they pull, the credential kinds they take');
ok(get_json(26, '/sources')['code'] === 403 && ($r = get_json(26, '/sources')['json'])['error']['code'] === 'forbidden', 'a Member: 403 {error: {code: forbidden}}');
[$j] = [jar()];
$anon = req('GET', '/sources', ['jar' => $j, 'headers' => ['Accept: application/json']]);
ok($anon['code'] === 401 && (json_decode($anon['body'], true)['error']['code'] ?? '') === 'unauthorized', 'nobody signed in: 401 {error: {code: unauthorized}}');
ok(get_json(27, '/sources/99999999')['code'] === 404 && (get_json(27, '/sources/99999999')['json']['error']['code'] ?? '') === 'not_found', 'an unknown source: 404 not_found');

echo "2. Every action's answer\n";
$u = post_json(27, '/sources/save.php', ['source' => $id, 'name' => 'JSON mode file (renamed)', 'schedule_minutes' => '120']);
ok($shape($u) && ($u['json']['record_id'] ?? 0) === $id, 'source_update');
$c = post_json(27, '/sources/credential.php', ['source' => $id, 'kind' => 'bearer', 'secret' => 'json-secret-4444']);
ok($shape($c) && ($c['json']['record_id'] ?? 0) === $id && !str_contains($c['body'], 'json-secret-4444'), 'source_credential_set (and the answer does not echo the secret)');
$t = post_json(27, '/sources/test.php', ['source' => $id]);
ok($shape($t) && in_array($t['json']['state'] ?? '', ['ok', 'blocked', 'misconfigured'], true) && is_string($t['json']['message'] ?? null) && is_array($t['json']['facts'] ?? null), 'source_test: the state, the sentence and the facts of the probe');
$p = post_json(27, '/sources/pull.php', ['source' => $id]);
ok($shape($p) && ($p['json']['pull_id'] ?? 0) > 0, 'source_pull_now: the pull\'s id with it');
$pull = (int) $p['json']['pull_id'];
$ran = cli_pull($id, ['queued' => $pull]);
ok(($ran['status'] ?? '') === 'ok' && ($ran['items_landed'] ?? 0) === 1, '(the queued pull run as the worker would: ok)');
wait_loads($ran['load_ids'] ?? []);
$ps = post_json(27, '/sources/pause.php', ['source' => $id, 'reason' => 'JSON proof']);
ok($shape($ps), 'source_pause');
$rs = post_json(27, '/sources/resume.php', ['source' => $id]);
ok($shape($rs), 'source_resume');
$pj = get_json(27, "/sources/$id?tab=pulls")['json']['data'];
ok($pj['pulls'][0]['status'] === 'ok' && $pj['pulls'][0]['loads']['count'] === 1 && $pj['feeds'][0]['key'] === 'raw.json_mode' && ($pj['source']['credential']['kind'] ?? '') === 'bearer' && ($pj['source']['credential']['last4'] ?? '') === '4444', 'the page afterwards: the pull and its load, the feed, the credential\'s kind and last four');
$d = post_json(27, '/sources/delete.php', ['source' => $id]);
ok($shape($d) && ($d['json']['location'] ?? '') === '/sources?notice=deleted', 'source_delete');
ok(get_json(27, "/sources/$id")['code'] === 404, '…and it is gone');

echo "3. Refusals speak JSON\n";
$e = post_json(27, '/sources/save.php', ['name' => '', 'connector' => 'http_file', 'url' => 'nope']);
ok($e['code'] === 422 && ($e['json']['error']['code'] ?? '') === 'invalid' && isset($e['json']['error']['fields']['name']) && isset($e['json']['error']['fields']['url']) && count($e['json']['error']['errors'] ?? []) >= 2, '422 {error: {code: invalid, message, errors[], fields{}}} — every field that is wrong, named');
$e = post_json(27, '/sources/save.php', ['name' => 'x', 'connector' => 'rest_json', 'target_name' => 'zzz']);
ok($e['code'] === 422 && str_contains((string) ($e['json']['error']['fields']['connector'] ?? ''), 'connector_extended'), 'an Extended connector: connector_extended on the field connector');
$e = post_json(27, '/sources/credential.php', ['source' => 99999999, 'kind' => 'bearer', 'secret' => 'x']);
ok($e['code'] === 404 && ($e['json']['error']['code'] ?? '') === 'not_found', 'a credential for a source that is not there: 404');
$no = req('POST', '/sources/save.php', ['jar' => $j, 'headers' => ['Accept: application/json'], 'form' => ['name' => 'x', 'connector' => 'upload']]);
ok($no['code'] === 401, 'a POST with no session: 401, not a bare 403');
$noCsrf = req('POST', '/sources/save.php', ['jar' => actor(27)[0], 'headers' => ['Accept: application/json'], 'form' => ['name' => 'x', 'connector' => 'upload', 'target_name' => 'nocsrf']]);
ok($noCsrf['code'] === 403 && (int) pg1("SELECT count(*) FROM sources WHERE name = 'x'") === 0, 'a POST without the CSRF token: refused, nothing made');
ok(get_json(27, '/sources/save.php')['code'] === 405, 'a GET of a handler: 405');

echo "4. An agent\n";
$ag = agent_post(40, '/sources/save.php', ['name' => 'Made by the Expert', 'connector' => 'http_file', 'url' => fx_url('json_sales.csv'), 'target_name' => 'by_expert', 'schedule_minutes' => '0']);
$aid = (int) ($ag['json']['record_id'] ?? 0);
ok($shape($ag) && $aid > 0, 'an agent holding sources.manage creates a source: the same answer');
ok(pg1("SELECT source FROM activity_log WHERE action = 'source.create' AND entity_id = $aid") === 'agent', '…logged as source agent');
$agp = agent_post(40, '/sources/pull.php', ['source' => $aid]);
ok($shape($agp), 'an agent queues a pull (free)');
pg("DELETE FROM source_pulls WHERE source_id = $aid");
ok(agent_post(40, '/sources/credential.php', ['source' => $aid, 'kind' => 'bearer', 'secret' => 'x'])['code'] === 403, 'an agent may not seal a credential: people only (403)');
ok(agent_post(42, '/sources/save.php', ['name' => 'By the Steward', 'connector' => 'upload', 'target_name' => 'by_steward'])['code'] === 403, 'an agent without the right: 403');
ok(agent_post(41, '/sources', [])['code'] !== 200, 'the Watcher (not admitted) gets nothing');
ok($shape(agent_post(40, '/sources/delete.php', ['source' => $aid])), 'an agent deletes (the kernel\'s approval stands in front of it: category deletion)');
finish();
