<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-datalake.conf; the manifest's canonical URLs). Meaningful under DL_APP=apache
 * tests/phase2/run.sh, where a real Apache serves the template rendered as the installer renders it; under php -S the same checks run
 * against tests/dev_router.php, which mirrors the rewrites. The receivers at their extension-less names, the shell's screens and the
 * placeholders, a dataset by its id or its key (slice 1's page — a key names its zone), the upload parts and the record pages (404 until their
 * slice), /api/v1/health with the engine and nothing else under /api until slice 9, /pick/, the MCP proxies (under Apache), the manifest and
 * the assets with their types, nothing outside html/.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/settings/')['code'] === 200 && page($j, '/settings')['code'] === 200, '/settings/ and /settings both reach settings/index.php');
foreach (['glossary', 'upload', 'loads', 'sql', 'queries', 'access', 'quality', 'sources', 'products', 'metrics', 'transforms', 'ask', 'steward'] as $d) {
    ok(page($j, "/$d")['code'] === 200 && page($j, "/$d/")['code'] === 200, "/$d and /$d/ both reach $d/index.php (its screen or its placeholder)");
}
ok(page($j, '/browse')['code'] === 200 && page($j, '/sandbox')['code'] === 200, '/browse and /sandbox reach browse.php and sandbox.php');
[$jo, ] = sign_on(1);
ok(page($jo, '/admin/settings')['code'] === 200 && page($jo, '/admin/settings/')['code'] === 200, '/admin/settings reaches admin/settings/index.php (the placeholder)');
ok(page($j, '/search')['code'] === 200 && page($j, '/activity')['code'] === 200 && page($j, '/notifications')['code'] === 200, '/search, /activity and /notifications resolve to their own files');
$built = is_file(dirname(__DIR__, 2) . '/html/datasets/view.php');
$orders = (int) (pg("SELECT id FROM datasets WHERE key = 'curated.sales_orders'")[0] ?? 0);
if ($built && $orders > 0) {
    $byId = page($j, "/datasets/$orders");
    ok($byId['code'] === 200 && page($j, '/datasets/curated.sales_orders')['code'] === 200 && str_contains(page($j, '/datasets/curated.sales_orders/columns')['body'], 'class="tab-pane fade show active" id="dataset-tab-columns"'),
        'a dataset by its id, by its key and with a tab: datasets/view.php (slice 1)');
    ok(page($j, '/datasets/no_zone.sales_orders')['code'] === 404 && req('GET', '/datasets/save.php', ['jar' => $j])['code'] === 405, 'a key names its zone — /datasets/save.php is a handler, never a key');
    ok(page($j, '/datasets/curated.sales_orders/columns/region')['code'] !== 500 && page($j, "/datasets/$orders/columns/nope")['code'] === 404, 'a column\'s page by the dataset\'s id or key: datasets/columns/view.php (slice 1)');
} else {
    ok(page($j, '/datasets/1')['code'] === 404 && page($j, '/datasets/curated.sales_orders')['code'] === 404 && page($j, '/datasets/curated.sales_orders/quality')['code'] === 404,
        'a dataset by its id, by its key and with a tab: rewritten to datasets/view.php, which slice 1 builds — 404 until then');
}
ok(page($j, '/incidents/1')['code'] === 404 && page($j, '/incidents/1/resolve')['code'] === 404, 'a record and a page of a record: 404 until their slice');
ok(req('POST', '/upload/start', ['jar' => $j])['code'] === 404 && req('POST', '/upload/part', ['jar' => $j])['code'] === 404, 'the upload parts are rewritten to their files, which slice 2 builds: 404 until then');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
$h = req('GET', '/api/v1/health');
$hb = body_json($h);
ok($h['code'] === 200 && ($hb['application'] ?? '') === 'datalake' && ($hb['storage'] ?? '') === 'ok' && isset($hb['engine']['writer'], $hb['engine']['query']), '/api/v1/health answers, with storage ok and the engine\'s two services');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/datasets')['code'] === 404 && req('GET', '/api/v1/datasets/curated.sales_orders/rows')['code'] === 404 && req('POST', '/api/v1/sql')['code'] === 404,
    'the lake\'s API is rewritten to files slice 9 builds: 404 until then');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
$p = req('GET', '/pick/?source=member');
ok($p['code'] === 302 && $p['location'] === LAUNCHER, '/pick/ without a session goes to the launcher');
if (getenv('DL_APP') === 'apache') {
    ok(in_array(req('GET', '/mcp/records')['code'], [502, 503], true) && in_array(req('GET', '/mcp/activity')['code'], [502, 503], true), 'the MCP proxies are in the vhost and answer 502 until Phase 4 starts the servers');
    $i = req('GET', 'http://127.0.0.1:9007/api/v1/health');
    ok($i['code'] === 200 && (body_json($i)['application'] ?? '') === 'datalake', 'the internal vhost (the kernel\'s actions server\'s door) answers health on its loopback port');
    $conf = (string) file_get_contents(need('DL_DEV_STATE') . '/apache/site.conf');
    ok(str_contains($conf, 'LimitRequestBody 73400320') && str_contains($conf, 'upload_max_filesize 64M') && str_contains($conf, 'post_max_size 72M'), 'the rendered vhost carries LimitRequestBody 70 MB and the PHP upload limits deploy says');
    $hd = req('GET', '/api/v1/health')['headers'];
    ok(preg_match('/^X-Content-Type-Options: nosniff/mi', $hd) === 1 && preg_match('/^X-Frame-Options: SAMEORIGIN/mi', $hd) === 1, 'Apache sends nosniff and the frame option (mod_headers, guarded by <IfModule>)');
    ok(!str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/deploy/apache-datalake.conf'), '__proof'), 'the proofs\' fixture door is the proof\'s alone — never in the deploy file');
}
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1 && (json_decode($m['body'], true)['name'] ?? '') === 'Data Lake', '/manifest.webmanifest is served as JSON and names the Data Lake');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh', '/services/lake/engine.py', '/var/pass-dl_dev2/lake.pgpass'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE') && !str_contains($r['body'], 'datalake_lake'), "$p is not served ({$r['code']})");
}
finish();
