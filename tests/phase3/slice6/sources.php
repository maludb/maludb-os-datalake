<?php
/**
 * Slice 6 proof 1 — the SOURCES (docs/build-specs/sources-pulls.md "Proof": a source per connector saved and shown; the credential sealed; Test answers the
 * probe without a pull; Pull now queues; Pause / Resume; Delete keeps the dataset; the walls). Run through tests/phase3/slice6/run.sh.
 */
require __DIR__ . '/lib.php';
$outDb = need('DL6_OUTSIDE_DB');
$outUser = need('DL6_OUTSIDE_USER');
$outPw = need('DL6_OUTSIDE_PW');
$mk = static fn (array $f, int $as = 27): array => post_json($as, '/sources/save.php', $f);
$root = dirname(__DIR__, 3);
$token = 'abc123SECRETQ';
$bearer = 'tok-' . bin2hex(random_bytes(6)) . 'wxyz';

echo "1. The walls\n";
foreach ([26 => 'a Member', 30 => 'an Analyst', 29 => 'an external', 28 => 'someone with no role'] as $m => $who) {
    ok(get_json($m, '/sources')['code'] === 403, "$who: /sources is 403");
    ok(post_json($m, '/sources/save.php', ['name' => 'x', 'connector' => 'upload'])['code'] === 403, "$who: source_create is 403");
}
ok(get_json(27, '/sources')['code'] === 200 && get_json(1, '/sources')['code'] === 200, 'a Data engineer and the Lake admin open /sources');
$rows = static fn (int $m): int => (int) pg1("SET ROLE datalake_records_ro; SET app.member_id = '$m'; SELECT count(*) FROM mcp_sources");
ok($rows(26) === 0 && $rows(30) === 0, 'mcp_sources shows a Member and an Analyst nothing (the records server\'s role, judged by the database)');
ok((int) pg1("SELECT count(*) FROM information_schema.columns WHERE table_name = 'mcp_sources' AND column_name IN ('ciphertext', 'user_agent', 'rate_per_second')") === 0, 'the view carries no ciphertext');

echo "2. A source per connector, saved and shown\n";
$http = $mk(['name' => 'Sales file', 'connector' => 'http_file', 'url' => fx_url('sales.csv') . '?token=' . $token, 'format' => 'csv', 'target_name' => 'sales_file', 'schedule_minutes' => '1440']);
$httpId = (int) ($http['json']['record_id'] ?? 0);
ok($http['code'] === 200 && $httpId > 0 && ($http['json']['refresh'] ?? '') === 'sourceChanged' && str_contains((string) ($http['json']['location'] ?? ''), "/sources/$httpId"), 'http_file saved: the record id and the location');
$sftp = $mk(['name' => 'SFTP sales', 'connector' => 'sftp_file', 'sftp_host' => 'sftp.example.invalid', 'sftp_port' => '22', 'sftp_user' => 'reports', 'sftp_path' => '/outgoing', 'pattern' => 'sales_*.csv', 'format' => 'csv', 'target_name' => 'sftp_sales', 'schedule_minutes' => '60']);
$sftpId = (int) ($sftp['json']['record_id'] ?? 0);
ok($sftp['code'] === 200 && $sftpId > 0, 'sftp_file saved');
$k7 = $mk(['name' => 'GL balances (proof)', 'connector' => 'k7', 'provider' => 'gl', 'tool' => 'account_balances', 'arguments' => '{"as_of": "<yesterday>"}', 'rows_path' => 'accounts', 'inject' => '{"as_of": "document"}', 'target_dataset' => 'raw.gl_balances_proof', 'schedule_minutes' => '1440']);
$k7Id = (int) ($k7['json']['record_id'] ?? 0);
ok($k7['code'] === 200 && $k7Id > 0, 'k7 saved (the target named as a key, made when new)');
$pgs = $mk(['name' => 'Outside orders', 'connector' => 'postgres', 'host' => '127.0.0.2', 'port' => '5432', 'dbname' => $outDb, 'user' => $outUser, 'sslmode' => 'prefer', 'table' => 'public.orders', 'watermark' => 'updated_at', 'key' => 'id', 'target_name' => 'outside_orders', 'schedule_minutes' => '1440']);
$pgId = (int) ($pgs['json']['record_id'] ?? 0);
ok($pgs['code'] === 200 && $pgId > 0, 'postgres saved (reached as 127.0.0.2: the guard refuses `localhost`, not an alias)');
$up = $mk(['name' => 'Monthly upload shape', 'connector' => 'upload', 'target_name' => 'upload_shape']);
$upId = (int) ($up['json']['record_id'] ?? 0);
ok($up['code'] === 200 && $upId > 0, 'upload saved (a named shape, nothing to pull)');
$roles = pg("SELECT id || ':' || role FROM sources WHERE id IN ($httpId, $k7Id, $pgId, $upId) ORDER BY id");
ok($roles === ["$httpId:public_data", "$k7Id:internal_app", "$pgId:outside_db", "$upId:manual"], 'the role follows the connector (the database forces internal_app and outside_db): ' . implode(' ', $roles));
$ds = ds_id('raw.sales_file');
ok(pg1("SELECT status || '|' || zone || '|' || evolution_mode || '|' || COALESCE(source_id::text, '') FROM datasets WHERE id = $ds") === "active|raw|rescue|$httpId", 'a new raw dataset is made active, rescue, linked to its source');
ok(pg1("SELECT settings ->> 'target_dataset_id' FROM sources WHERE id = $httpId") === (string) $ds, 'the source keeps its target in settings.target_dataset_id');
ok(pg1("SELECT base_url FROM sources WHERE id = $httpId") === 'http://127.0.0.1:' . FX_PORT, 'http_file: base_url is the origin only (no path, no query)');
$sch = pg1("SELECT schedule_minutes || '|' || rate_per_second FROM sources WHERE id = $sftpId");
ok($sch === '60|1.00', "sftp_file: the schedule and the default rate ($sch)");

$list = get_json(27, '/sources');
$d = $list['json']['data'] ?? [];
ok($list['code'] === 200 && ($d['total'] ?? 0) >= 5 && count($d['rows'] ?? []) >= 5 && count($d['connectors'] ?? []) === 5, 'the list (JSON): at least five sources, the five connectors');
$byId = array_column($d['rows'] ?? [], null, 'source_id');
ok(($byId[$httpId]['connector'] ?? '') === 'http_file' && ($byId[$httpId]['target']['key'] ?? '') === 'raw.sales_file' && ($byId[$httpId]['schedule'] ?? '') === 'Every day' && ($byId[$httpId]['state'] ?? '') === 'active', 'a card row: connector, the target\'s key, the schedule in words, the state');
$html = html_as(27, '/sources');
ok($html['code'] === 200 && str_contains($html['body'], 'id="source-list"') && str_contains($html['body'], 'data-screen="source-list"'), 'the list renders, stamped source-list');
foreach ([$httpId, $sftpId, $k7Id, $pgId, $upId] as $id) {
    ok(str_contains($html['body'], 'id="source-card-' . $id . '"'), "the list has the card source-card-$id");
}
ok(str_contains($html['body'], 'id="source-list-add-btn"') && str_contains($html['body'], 'id="source-list-facet-connector-k7"'), 'Add source and the connector chips');
$filtered = get_json(27, '/sources?connector=k7');
ok(count($filtered['json']['data']['rows'] ?? []) >= 1 && array_unique(array_column($filtered['json']['data']['rows'], 'connector')) === ['k7'], 'the connector filter');
$none = get_json(27, '/sources?paused=yes');
ok(($none['json']['data']['total'] ?? -1) === 0, 'the paused filter: none paused yet');
foreach ([$httpId => 'sales.csv?…', $sftpId => 'sftp.example.invalid', $k7Id => 'account_balances', $pgId => 'public.orders', $upId => 'Saved mapping'] as $id => $needle) {
    $v = html_as(27, "/sources/$id");
    ok($v['code'] === 200 && str_contains($v['body'], 'id="source-view-header"') && str_contains($v['body'], 'id="source-tab-pulls-link"') && str_contains($v['body'], 'id="source-settings-dl"') && str_contains($v['body'], $needle), "the page of source $id renders its Settings ($needle)");
}
$v = html_as(27, "/sources/$httpId?tab=pulls");
ok(str_contains($v['body'], 'id="source-tab-pulls" role="tabpanel"') && str_contains($v['body'], 'class="tab-pane fade show active" id="source-tab-pulls"') && str_contains($v['body'], 'id="source-pulls-empty"'), '?tab=pulls shows the Pulls pane (empty yet)');
$g = get_json(27, "/sources/$httpId");
$s = $g['json']['data']['source'] ?? [];
ok($g['code'] === 200 && ($s['settings']['url'] ?? '') === fx_url('sales.csv') . '?…' && ($s['settings']['format'] ?? '') === 'csv', 'the page (JSON): the URL without its query string');
ok(!str_contains(html_as(27, "/sources/$httpId")['body'], $token) && !str_contains(json_encode($g['json']), $token) && !str_contains(html_as(27, "/sources/$httpId/edit")['body'] === '' ? '' : json_encode(get_json(27, "/sources/$httpId/edit")['json']), $token),
    'the query string (a token somebody pasted) is in no page and no JSON answer');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE COALESCE(before::text, '') || COALESCE(after::text, '') LIKE '%$token%' OR COALESCE(route, '') LIKE '%$token%'") === 0, 'the activity log never holds a URL\'s query string');
$act = pg1("SELECT action || '|' || COALESCE(dataset_id::text, '') || '|' || (after -> 'settings' ->> 'url') FROM activity_log WHERE action = 'source.create' AND entity_id = $httpId");
ok($act === "source.create|$ds|" . fx_url('sales.csv') . '?…', "source.create is logged with the target's dataset and the redacted URL ($act)");

echo "3. What a source refuses\n";
$cases = [
    ['no name' => ['connector' => 'http_file', 'url' => fx_url('x.csv'), 'target_name' => 'x_a'], 'name'],
    ['no address' => ['name' => 'N', 'connector' => 'http_file', 'target_name' => 'x_b'], 'url'],
    ['an ftp address' => ['name' => 'N', 'connector' => 'http_file', 'url' => 'ftp://files.example.invalid/f.csv', 'target_name' => 'x_c'], 'url'],
    ['a password in the address' => ['name' => 'N', 'connector' => 'http_file', 'url' => 'http://u:p@127.0.0.1:9004/data/x.csv', 'target_name' => 'x_d'], 'url'],
    ['an unknown format' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv'), 'format' => 'docx', 'target_name' => 'x_e'], 'format'],
    ['a header the client owns' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv'), 'api_key_header' => 'Host', 'target_name' => 'x_f'], 'api_key_header'],
    ['no target' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv')], 'target_dataset'],
    ['a bad new dataset name' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv'), 'target_name' => 'Bad Name'], 'target_dataset'],
    ['a schedule that is not a number' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv'), 'target_name' => 'x_g', 'schedule_minutes' => 'daily'], 'schedule_minutes'],
    ['a rate beyond ten' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv'), 'target_name' => 'x_h', 'rate_per_second' => '50'], 'rate_per_second'],
    ['a saved mapping that is not there' => ['name' => 'N', 'connector' => 'http_file', 'url' => fx_url('x.csv'), 'target_name' => 'x_i', 'template_id' => '99999'], 'template_id'],
    ['sftp: a host with a space' => ['name' => 'N', 'connector' => 'sftp_file', 'sftp_host' => 'bad host;rm', 'sftp_user' => 'u', 'sftp_path' => '/a', 'target_name' => 'x_j'], 'sftp_host'],
    ['sftp: a path that climbs' => ['name' => 'N', 'connector' => 'sftp_file', 'sftp_host' => 'h.example.invalid', 'sftp_user' => 'u', 'sftp_path' => '/a/../etc', 'target_name' => 'x_k'], 'sftp_path'],
    ['sftp: a pattern with a slash' => ['name' => 'N', 'connector' => 'sftp_file', 'sftp_host' => 'h.example.invalid', 'sftp_user' => 'u', 'sftp_path' => '/a', 'pattern' => '../*', 'target_name' => 'x_l'], 'pattern'],
    ['k7: no provider' => ['name' => 'N', 'connector' => 'k7', 'tool' => 'account_balances', 'rows_path' => 'accounts', 'target_name' => 'x_m'], 'provider'],
    ['k7: arguments not JSON' => ['name' => 'N', 'connector' => 'k7', 'provider' => 'gl', 'tool' => 't', 'rows_path' => 'a', 'arguments' => 'not json', 'target_name' => 'x_n'], 'arguments'],
    ['k7: an argument the kernel sets' => ['name' => 'N', 'connector' => 'k7', 'provider' => 'gl', 'tool' => 't', 'rows_path' => 'a', 'arguments' => '{"as_agent": 5}', 'target_name' => 'x_o'], 'arguments'],
    ['k7: no list path' => ['name' => 'N', 'connector' => 'k7', 'provider' => 'gl', 'tool' => 't', 'target_name' => 'x_p'], 'rows_path'],
    ['k7: a page of 900' => ['name' => 'N', 'connector' => 'k7', 'provider' => 'gl', 'tool' => 't', 'rows_path' => 'a', 'paging' => 'offset', 'page_size' => '900', 'target_name' => 'x_q'], 'page_size'],
    ['k7: an inject that comes from nowhere' => ['name' => 'N', 'connector' => 'k7', 'provider' => 'gl', 'tool' => 't', 'rows_path' => 'a', 'inject' => '{"as_of": "the moon"}', 'target_name' => 'x_r'], 'inject'],
    ['postgres: this server\'s own database over localhost' => ['name' => 'N', 'connector' => 'postgres', 'host' => 'localhost', 'dbname' => $outDb, 'user' => 'u', 'table' => 'public.orders', 'key' => 'id', 'target_name' => 'x_s'], 'host'],
    ['postgres: …over 127.0.0.1' => ['name' => 'N', 'connector' => 'postgres', 'host' => '127.0.0.1', 'dbname' => 'postgres', 'user' => 'u', 'table' => 'public.t', 'key' => 'id', 'target_name' => 'x_t'], 'host'],
    ['postgres: a table with a space' => ['name' => 'N', 'connector' => 'postgres', 'host' => '127.0.0.2', 'dbname' => 'd', 'user' => 'u', 'table' => 'public.or ders', 'key' => 'id', 'target_name' => 'x_u'], 'table'],
    ['postgres: neither a watermark nor a key' => ['name' => 'N', 'connector' => 'postgres', 'host' => '127.0.0.2', 'dbname' => 'd', 'user' => 'u', 'table' => 'public.t', 'target_name' => 'x_v'], 'key'],
    ['postgres: an unknown connection mode' => ['name' => 'N', 'connector' => 'postgres', 'host' => '127.0.0.2', 'dbname' => 'd', 'user' => 'u', 'table' => 'public.t', 'key' => 'id', 'sslmode' => 'disable', 'target_name' => 'x_w'], 'sslmode'],
    ['an Extended connector' => ['name' => 'N', 'connector' => 'rest_json', 'target_name' => 'x_x'], 'connector'],
    ['a connector nobody built' => ['name' => 'N', 'connector' => 'hadoop', 'target_name' => 'x_y'], 'connector'],
];
foreach ($cases as $case) {
    $label = (string) array_key_first($case);
    $form = $case[$label];
    $field = $case[0];
    $r = $mk($form);
    ok($r['code'] === 422 && field_of($r) === $field, "$label: 422 on $field" . ($r['code'] === 422 ? '' : ' — got ' . $r['code'] . ' ' . json_encode($r['json'])));
}
$ext = $mk(['name' => 'N', 'connector' => 'rest_json', 'target_name' => 'x_ext']);
ok(str_contains(message_of($ext), 'connector_extended'), 'an Extended connector answers connector_extended: ' . message_of($ext));
$guard = $mk(['name' => 'N', 'connector' => 'postgres', 'host' => 'localhost', 'dbname' => 'postgres', 'user' => 'u', 'table' => 'public.t', 'key' => 'id', 'target_name' => 'x_guard']);
ok(str_contains(message_of($guard), 'never one of this server'), 'the guard\'s sentence, from the database: ' . message_of($guard));
ok(pg1("SELECT count(*) FROM datasets WHERE key = 'raw.x_guard'") === '0' && pg1("SELECT count(*) FROM sources WHERE name = 'N'") === '0', 'a refused source leaves nothing behind (no source, no dataset made for it)');
pg("INSERT INTO datasets (key, zone, name, title, status, owner_member_id) VALUES ('curated.src_curated', 'curated', 'src_curated', 'Curated', 'active', 27), ('raw.src_hidden', 'raw', 'src_hidden', 'Hidden raw', 'active', 1), ('raw.src_readonly', 'raw', 'src_readonly', 'Read only raw', 'active', 1) ON CONFLICT DO NOTHING");
pg("INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) SELECT 'dataset', id, 'member', 27, 'read', 'SMOKE read only', 1 FROM datasets WHERE key = 'raw.src_readonly'");
$r = $mk(['name' => 'N', 'connector' => 'upload', 'target_dataset' => 'curated.src_curated']);
ok($r['code'] === 422 && field_of($r) === 'target_dataset' && str_contains(message_of($r), 'RAW'), 'a source lands in a RAW dataset: ' . message_of($r));
$r = $mk(['name' => 'N', 'connector' => 'upload', 'target_dataset' => 'raw.src_readonly']);
ok($r['code'] === 422 && field_of($r) === 'target_dataset' && str_contains(message_of($r), 'may not change'), 'a raw dataset the engineer may only read: ' . message_of($r));
$r = $mk(['name' => 'N', 'connector' => 'upload', 'target_dataset' => 'raw.src_hidden']);
ok($r['code'] === 422 && field_of($r) === 'target_dataset' && str_contains(message_of($r), 'may not change'), 'a raw dataset the engineer may only know exists (a stub): ' . message_of($r));
pg("INSERT INTO datasets (key, zone, name, title, status, owner_member_id) VALUES ('sandbox.m26_private', 'sandbox', 'm26_private', 'Priya private', 'active', 26) ON CONFLICT DO NOTHING");
$r = $mk(['name' => 'N', 'connector' => 'upload', 'target_dataset' => (string) ds_id('sandbox.m26_private')]);
ok($r['code'] === 422 && field_of($r) === 'target_dataset' && message_of($r) === 'That dataset is not here.', 'a dataset the engineer cannot see at all is "not here" — the gate\'s rule, no zone confirmed: ' . message_of($r));
$r = $mk(['name' => 'N', 'connector' => 'upload', 'target_dataset' => '99999999']);
ok($r['code'] === 422 && message_of($r) === 'That dataset is not here.', 'an id that names nothing is the same words');
// https only, and nothing that is this server: judged in a child process with the proofs' dev switch OFF
[, $o] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/write.php"; putenv("DL_FETCH_ALLOW_INSECURE=0"); $c = new DlConnectorHttpFile();
    $out = []; foreach (["http://203.0.113.5/f.csv", "https://127.0.0.1/f.csv", "https://169.254.169.254/latest", "https://[::1]/f.csv", "https://10.1.2.3/f.csv", "https://203.0.113.5/f.csv"] as $u) { [, $e] = $c->validate(["url" => $u]); $out[$u] = isset($e["url"]); }
    echo json_encode($out);', ['DL6_ROOT' => $root]);
$j = json_decode($o, true) ?: [];
ok(($j['http://203.0.113.5/f.csv'] ?? null) === true && ($j['https://127.0.0.1/f.csv'] ?? null) === true && ($j['https://169.254.169.254/latest'] ?? null) === true && ($j['https://[::1]/f.csv'] ?? null) === true,
    'outside the proofs: http is refused, and so are loopback and the cloud metadata address');
ok(($j['https://10.1.2.3/f.csv'] ?? null) === false && ($j['https://203.0.113.5/f.csv'] ?? null) === false, '…but a business\'s own private LAN address, and a public one, are fine');
// the same host policy for the connectors that dial a server (the planning model's review): dl_sources_guard refuses `localhost` by NAME, so an alias —
// 127.0.0.2, this machine's own address — is judged in PHP by what it resolves to, at the saving and again at every pull
[, $o] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/write.php"; putenv("DL_FETCH_ALLOW_INSECURE=0");
    $self = null; foreach (net_get_interfaces() as $i) { foreach ($i["unicast"] ?? [] as $u) { $a = (string) ($u["address"] ?? ""); if ($self === null && filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE)) { $self = $a; } } }
    $pg = new DlConnectorPostgres(); $sf = new DlConnectorSftpFile(); $hf = new DlConnectorHttpFile(); $out = ["self" => $self];
    foreach (["127.0.0.2", "localhost", (string) $self, "169.254.169.254", "203.0.113.5"] as $h) {
        [, $e] = $pg->validate(["host" => $h, "dbname" => "d", "user" => "u", "table" => "public.t", "key" => "id"]); $out["pg:$h"] = isset($e["host"]);
        [, $e] = $sf->validate(["sftp_host" => $h, "sftp_user" => "u", "sftp_path" => "/out"]); $out["sftp:$h"] = isset($e["sftp_host"]);
    }
    [, $e] = $hf->validate(["url" => "https://" . $self . "/f.csv"]); $out["http:self"] = isset($e["url"]);
    try { DlConnectorPostgres::connect(["settings" => ["host" => "127.0.0.2", "dbname" => "d", "user" => "u"], "credential" => ["kind" => "database_password", "password" => "x"]]); $out["connect"] = "connected"; }
    catch (DlMisconfigured $x) { $out["connect"] = $x->getMessage(); }
    $out["sftp_target"] = dl_sftp_target(["settings" => ["sftp" => ["host" => "127.0.0.2", "user" => "u", "path" => "/out"]], "credential" => ["kind" => "sftp_password", "password" => "x"]])["error"] ?? null;
    echo json_encode($out);', ['DL6_ROOT' => $root]);
$j = json_decode($o, true) ?: [];
$self = (string) ($j['self'] ?? '');
ok($self !== '' && ($j['pg:127.0.0.2'] ?? null) === true && ($j['pg:localhost'] ?? null) === true && ($j["pg:$self"] ?? null) === true && ($j['pg:169.254.169.254'] ?? null) === true,
    "outside the proofs: a postgres source at 127.0.0.2, localhost or this machine's own address ($self) is refused at its saving — the guard's name check and its aliases");
ok(($j['sftp:127.0.0.2'] ?? null) === true && ($j['sftp:localhost'] ?? null) === true && ($j["sftp:$self"] ?? null) === true && ($j['http:self'] ?? null) === true,
    '…and so is an SFTP source, and a file at this machine\'s own address');
ok(($j['pg:203.0.113.5'] ?? null) === false && ($j['sftp:203.0.113.5'] ?? null) === false, '…while a server elsewhere is fine');
ok(str_contains((string) ($j['connect'] ?? ''), 'this server') && str_contains((string) ($j['sftp_target'] ?? ''), 'this server'),
    'judged again at the pull: a saved postgres or SFTP source whose host has come to be this server does not connect');

echo "4. Changing a source — a field left out stays\n";
$u = $mk(['source' => $httpId, 'name' => 'Sales file (daily)', 'schedule_minutes' => '360']);
ok($u['code'] === 200 && ($u['json']['record_id'] ?? 0) === $httpId, 'source_update: 200');
$row = source_row($httpId);
ok($row['name'] === 'Sales file (daily)' && $row['schedule_minutes'] === 360 && ($row['settings']['url'] ?? '') === fx_url('sales.csv') . '?token=' . $token && ($row['settings']['format'] ?? '') === 'csv'
    && ($row['settings']['target_dataset_id'] ?? 0) === $ds, 'the name and the schedule changed; the address, the format and the target stayed');
$u = $mk(['source' => $httpId, 'settings' => json_encode(['format' => 'tsv'])]);
$row = source_row($httpId);
ok($u['code'] === 200 && ($row['settings']['format'] ?? '') === 'tsv' && ($row['settings']['url'] ?? '') !== '', 'settings as a JSON object (an agent): the format changed, the address stayed');
$u = $mk(['source' => $httpId, 'format' => 'csv']);
$bf = pg1("SELECT before::text FROM activity_log WHERE action = 'source.update' AND entity_id = $httpId ORDER BY id DESC LIMIT 1");
ok($u['code'] === 200 && str_contains((string) $bf, '"name"') && !str_contains((string) $bf, $token), 'source.update logs the before and the after (no query string)');
ok($mk(['source' => $httpId, 'connector' => 'k7'])['code'] === 422, 'a source\'s connector never changes');
ok($mk(['source' => 99999999, 'name' => 'x'])['code'] === 404, 'an unknown source is 404');
ok($mk(['source' => $httpId, 'schedule_minutes' => '-5'])['code'] === 422, 'a negative schedule is refused');
$e = get_json(27, "/sources/$httpId/edit");
ok($e['code'] === 200 && ($e['json']['data']['values']['name'] ?? '') === 'Sales file (daily)' && ($e['json']['data']['connector'] ?? '') === 'http_file', 'the edit form (JSON): the values');
$f = html_as(27, "/sources/$httpId/edit");
ok(str_contains($f['body'], 'id="source-form"') && str_contains($f['body'], 'id="source-form-field-url"') && str_contains($f['body'], 'id="source-form-save-btn"') && str_contains($f['body'], 'data-screen="source-edit"'), 'the edit form renders, stamped source-edit');
$a = html_as(27, '/sources/new');
foreach (['http_file', 'sftp_file', 'k7', 'postgres', 'upload'] as $c) {
    ok(str_contains($a['body'], 'id="source-connector-' . $c . '"'), "step 1 offers the $c card");
}
ok(str_contains($a['body'], 'data-screen="source-add"'), 'Add source is stamped source-add');
$fx = html_as(27, '/sources/new?connector=k7');
ok(str_contains($fx['body'], 'id="source-form-field-provider"') && str_contains($fx['body'], 'id="source-form-field-inject"') && str_contains($fx['body'], 'record-picker-field'), 'step 2 (k7): its fields, and the target is chosen with the record picker');

echo "5. The credential — sealed, never shown again\n";
$c = post_json(27, '/sources/credential.php', ['source' => $httpId, 'kind' => 'bearer', 'secret' => $bearer, 'label' => 'Proof bearer']);
ok($c['code'] === 200 && ($c['json']['record_id'] ?? 0) === $httpId, 'source_credential_set: 200');
$cred = pg1("SELECT kind || '|' || label || '|' || last4 || '|' || left(convert_from(ciphertext, 'UTF8'), 3) FROM source_credentials WHERE source_id = $httpId");
ok($cred === 'bearer|Proof bearer|wxyz|v1.', "the credential row: kind, label, last four, a v1 seal ($cred)");
ok(pg1("SELECT (position('$bearer' in convert_from(ciphertext, 'UTF8')) = 0)::text FROM source_credentials WHERE source_id = $httpId") === 'true', 'the ciphertext is not the secret, nor does it contain it');
ok(pg1("SELECT (credential_id = (SELECT id FROM source_credentials WHERE source_id = $httpId))::text FROM sources WHERE id = $httpId") === 'true', 'the source points at its credential');
$page = html_as(27, "/sources/$httpId");
ok(str_contains($page['body'], 'id="source-credential-last4">wxyz<') && str_contains($page['body'], 'Proof bearer') && !str_contains($page['body'], $bearer), 'the page shows the kind, the label and the LAST FOUR — not the secret');
$gj = get_json(27, "/sources/$httpId");
ok(($gj['json']['data']['source']['credential']['last4'] ?? '') === 'wxyz' && !str_contains(json_encode($gj['json']), $bearer), 'the JSON says the last four and nothing more');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE COALESCE(before::text, '') || COALESCE(after::text, '') LIKE '%$bearer%' OR COALESCE(route, '') LIKE '%$bearer%'") === 0
    && (int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.credential_set' AND entity_id = $httpId AND after ->> 'kind' = 'bearer' AND (after ->> 'rotated')::boolean = false") === 1, 'source.credential_set is logged (kind, label, rotated) — the secret is nowhere in the log');
$c2 = post_json(27, '/sources/credential.php', ['source' => $httpId, 'kind' => 'bearer', 'secret' => 'second-secret-' . 'ABCD', 'label' => 'Replacement']);
ok($c2['code'] === 200 && (int) pg1("SELECT count(*) FROM source_credentials WHERE source_id = $httpId") === 1 && pg1("SELECT last4 FROM source_credentials WHERE source_id = $httpId") === 'ABCD'
    && (int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.credential_set' AND entity_id = $httpId AND (after ->> 'rotated')::boolean") === 1, 'a new credential replaces the old (one row left, rotated logged)');
ok(post_json(27, '/sources/credential.php', ['source' => $httpId, 'kind' => 'sftp_key', 'secret' => 'x'])['code'] === 422, 'a kind the connector does not take: 422');
$r = post_json(27, '/sources/credential.php', ['source' => $httpId, 'kind' => 'bearer', 'secret' => '']);
ok($r['code'] === 422 && field_of($r) === 'secret', 'no secret: 422 on secret');
ok(post_json(27, '/sources/credential.php', ['source' => $k7Id, 'kind' => 'bearer', 'secret' => 'x'])['code'] === 422, 'a k7 source takes no credential');
$ak = agent_post(40, '/sources/credential.php', ['source' => $httpId, 'kind' => 'bearer', 'secret' => 'agent-secret-ZZZZ']);
ok($ak['code'] === 403 && pg1("SELECT last4 FROM source_credentials WHERE source_id = $httpId") === 'ABCD', 'an agent may not set a credential (people only): 403, nothing changed');
ok(post_json(26, '/sources/credential.php', ['source' => $httpId, 'kind' => 'bearer', 'secret' => 'x'])['code'] === 403, 'a Member may not either');
$pw = post_json(27, '/sources/credential.php', ['source' => $pgId, 'kind' => 'database_password', 'secret' => $outPw, 'label' => 'Outside reader']);
ok($pw['code'] === 200 && pg1("SELECT kind FROM source_credentials WHERE source_id = $pgId") === 'database_password', 'a database password sealed for the postgres source');
$pem = 'OPENSSH PRIVATE ' . 'KEY';    // a truncated, fake key — written in two halves so no secret scanner takes the fixture for a real one
$key = "-----BEGIN $pem-----\nb3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW\n-----END $pem-----";
$kk = post_json(27, '/sources/credential.php', ['source' => $sftpId, 'kind' => 'sftp_key', 'secret_key' => $key, 'label' => 'Reports key']);
ok($kk['code'] === 200 && preg_match('/^[0-9a-f]{4}$/', (string) pg1("SELECT last4 FROM source_credentials WHERE source_id = $sftpId")) && !str_contains(html_as(27, "/sources/$sftpId")['body'], 'OPENSSH'), 'an SFTP private key (the textarea): its last "four" is a fingerprint prefix, the key is never shown');
$sp = post_json(27, '/sources/credential.php', ['source' => $sftpId, 'kind' => 'sftp_password', 'secret' => 'hunter2-sftp', 'label' => 'Reports password']);
ok($sp['code'] === 200 && pg1("SELECT kind FROM source_credentials WHERE source_id = $sftpId") === 'sftp_password' && (int) pg1("SELECT count(*) FROM source_credentials WHERE source_id = $sftpId") === 1, 'then a password instead (the key row is gone)');
[, $o] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/write.php"; putenv("DL_SECRETS_KEY="); unset($_ENV["DL_SECRETS_KEY"]);
    $out = ["present" => dl_secrets_key_present()]; $pdo = db(); $pdo->beginTransaction();
    try { source_credential_set($pdo, (int) getenv("DL6_SOURCE"), "bearer", "zzzz-no-key", "x", 27); $out["msg"] = "sealed"; } catch (DomainException $e) { $out["msg"] = $e->getMessage(); }
    $pdo->rollBack(); echo json_encode($out);', ['DL6_ROOT' => $root, 'DL6_SOURCE' => $httpId]);
$j = json_decode($o, true) ?: [];
ok(($j['present'] ?? true) === false && str_starts_with((string) ($j['msg'] ?? ''), 'secrets_key_missing'), 'without DL_SECRETS_KEY a credential cannot be kept: secrets_key_missing — ' . substr((string) ($j['msg'] ?? ''), 0, 60));

echo "6. Test asks, and lands nothing\n";
fx_state(['sales.csv' => ['file' => 'sales_a.csv', 'etag' => '"v1"', 'auth' => ['bearer' => 'second-secret-ABCD']], 'gone.csv' => ['file' => 'sales_a.csv', 'status' => 404], 'blocked.csv' => ['file' => 'sales_a.csv']]);
fx_log(true);
$pulls0 = (int) pg1('SELECT count(*) FROM source_pulls');
$t = post_json(27, '/sources/test.php', ['source' => $httpId]);
$state = $t['json']['state'] ?? '';
ok($t['code'] === 200 && $state === 'ok' && str_contains((string) ($t['json']['message'] ?? ''), 'answers') && ($t['json']['facts']['status'] ?? 0) === 200, 'Test on the file: ok — ' . ($t['json']['message'] ?? ''));
$log = fx_log();
ok(count(array_filter($log, static fn (array $l): bool => $l['method'] === 'HEAD' && $l['path'] === '/data/sales.csv' && $l['auth'] === 'yes' && str_starts_with((string) $l['ua'], 'MaluDB-DataLake/1.0'))) === 1, 'the probe is one HEAD with the credential and the honest user-agent');
ok((int) pg1('SELECT count(*) FROM source_pulls') === $pulls0 && (int) pg1("SELECT count(*) FROM loads WHERE source_id = $httpId") === 0, 'Test counts no pull and lands nothing');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.test' AND entity_id = $httpId AND after ->> 'state' = 'ok'") === 1, 'source.test is logged with the state');
$tp = html_as(27, "/sources/$httpId?notice=test_ok&said=" . rawurlencode('The file answers (HTTP 200).'));
ok(str_contains($tp['body'], 'id="notice-banner"') && str_contains($tp['body'], 'The file answers (HTTP 200).'), 'without JavaScript the answer is the notice on the source page');
$bk = $mk(['name' => 'Robots-blocked file', 'connector' => 'http_file', 'url' => fx_url('blocked.csv', 'blocked'), 'target_name' => 'blocked_file']);
$bkId = (int) $bk['json']['record_id'];
fx_log(true);
$t = post_json(27, '/sources/test.php', ['source' => $bkId]);
ok(($t['json']['state'] ?? '') === 'blocked' && str_contains((string) $t['json']['message'], 'robots.txt'), 'a path robots.txt forbids: Test says blocked — ' . ($t['json']['message'] ?? ''));
ok(count(array_filter(fx_log(), static fn (array $l): bool => str_starts_with($l['path'], '/blocked/'))) === 0, '…and the forbidden path was never requested');
$gn = $mk(['name' => 'Missing file', 'connector' => 'http_file', 'url' => fx_url('gone.csv'), 'target_name' => 'missing_file']);
$t = post_json(27, '/sources/test.php', ['source' => (int) $gn['json']['record_id']]);
ok(($t['json']['state'] ?? '') === 'misconfigured' && str_contains((string) $t['json']['message'], '404'), 'a missing file: misconfigured (HTTP 404)');
k7_shares([]);
$t = post_json(27, '/sources/test.php', ['source' => $k7Id]);
ok(($t['json']['state'] ?? '') === 'blocked' && str_contains((string) $t['json']['message'], 'not_shared'), 'a k7 source with no share: blocked (not_shared) — ' . ($t['json']['message'] ?? ''));
k7_shares(['gl/account_balances' => ['mode' => 'ok', 'doc' => ['as_of' => '2026-10-01', 'accounts' => [['code' => '1000', 'balance' => '10.00']]]]]);
$t = post_json(27, '/sources/test.php', ['source' => $k7Id]);
ok(($t['json']['state'] ?? '') === 'ok' && str_contains((string) $t['json']['message'], '1 row'), 'with the share answering: ok — ' . ($t['json']['message'] ?? ''));
k7_shares(['gl/account_balances' => ['mode' => 'no_connection']]);
$t = post_json(27, '/sources/test.php', ['source' => $k7Id]);
ok(($t['json']['state'] ?? '') === 'blocked' && str_contains((string) $t['json']['message'], 'no_connection'), 'no_connection: blocked — ' . ($t['json']['message'] ?? ''));
$t = post_json(27, '/sources/test.php', ['source' => $pgId]);
ok(($t['json']['state'] ?? '') === 'ok' && str_contains((string) $t['json']['message'], 'public.orders'), 'the outside database: ok — ' . ($t['json']['message'] ?? ''));
ok((int) ($t['json']['facts']['estimated_rows'] ?? -1) >= 0 && ($t['json']['facts']['columns'] ?? 0) === 5, 'the probe knows the table\'s columns');
$t = post_json(27, '/sources/test.php', ['source' => $sftpId]);
ok(($t['json']['state'] ?? '') === 'misconfigured', 'an SFTP server that is not there: misconfigured, quickly — ' . substr((string) ($t['json']['message'] ?? ''), 0, 90));
$t = post_json(27, '/sources/test.php', ['source' => $upId]);
ok(($t['json']['state'] ?? '') === 'ok' && str_contains((string) $t['json']['message'], 'fetches nothing'), 'an upload source fetches nothing');
ok((int) pg1('SELECT count(*) FROM source_pulls') === $pulls0, 'none of the tests made a pull row');
$ta = agent_post(40, '/sources/test.php', ['source' => $upId]);
ok($ta['code'] === 200, 'an agent holding sources.manage may test (free)');

echo "7. Pull now, Pause, Resume\n";
$pn = post_json(27, '/sources/pull.php', ['source' => $httpId]);
$pullId = (int) ($pn['json']['pull_id'] ?? 0);
ok($pn['code'] === 200 && $pullId > 0, 'source_pull_now: 200 with the pull\'s id');
$pr = pg1("SELECT status || '|' || kind || '|' || (policy ->> 'queued') || '|' || started_by FROM source_pulls WHERE id = $pullId");
ok($pr === 'running|manual|true|27', "a queued pull: running, manual, policy.queued, by Marco ($pr)");
ok(pg1("SELECT last_pull_id FROM sources WHERE id = $httpId") === (string) $pullId, 'the source\'s last pull is it');
$again = post_json(27, '/sources/pull.php', ['source' => $httpId]);
ok($again['code'] === 422 && str_contains(message_of($again), 'already'), 'a second Pull now while one is queued: 422 — ' . message_of($again));
ok(str_contains(html_as(27, "/sources/$httpId?tab=pulls")['body'], 'hx-trigger="every 3s"'), 'the Pulls tab polls itself while a pull is queued or running');
$part = get_json(27, "/sources/$httpId");
ok(($part['json']['data']['pulls'][0]['status'] ?? '') === 'running', 'the page lists it Running');
pg("DELETE FROM source_pulls WHERE id = $pullId");
$ps = post_json(27, '/sources/pause.php', ['source' => $httpId, 'reason' => 'Holiday']);
ok($ps['code'] === 200 && source_row($httpId)['paused'] === true && source_row($httpId)['paused_reason'] === 'Holiday', 'source_pause: paused with the reason');
$page = html_as(27, "/sources/$httpId");
ok(str_contains($page['body'], 'id="source-view-state"') && str_contains($page['body'], 'Paused') && str_contains($page['body'], 'Holiday') && str_contains($page['body'], 'id="source-' . $httpId . '-resume"') && !str_contains($page['body'], 'id="source-' . $httpId . '-pull"'), 'the page says Paused and why, offers Resume, not Pull now');
ok(str_contains(html_as(27, '/sources?paused=yes')['body'], 'id="source-card-' . $httpId . '"') && str_contains(html_as(27, '/sources')['body'], 'id="source-card-' . $httpId . '-why">Holiday<'), 'the card carries the badge and the reason; the paused filter finds it');
$pp = post_json(27, '/sources/pull.php', ['source' => $httpId]);
ok($pp['code'] === 422 && str_contains(message_of($pp), 'paused'), 'Pull now on a paused source: 422 — ' . message_of($pp));
ok(post_json(27, '/sources/pause.php', ['source' => $httpId])['code'] === 422, 'pausing a paused source: 422');
pg("UPDATE sources SET consecutive_failures = 3, backoff_until = now() + interval '1 hour' WHERE id = $httpId");
$rs = post_json(27, '/sources/resume.php', ['source' => $httpId]);
$row = source_row($httpId);
ok($rs['code'] === 200 && $row['paused'] === false && $row['consecutive_failures'] === 0 && $row['backoff_until'] === null, 'source_resume: running again, the ladder cleared');
ok(post_json(27, '/sources/resume.php', ['source' => $httpId])['code'] === 422, 'resuming a source that is not paused: 422');
$mp = post_json(27, '/sources/pull.php', ['source' => $upId]);
ok($mp['code'] === 422 && str_contains(message_of($mp), 'manual_only'), 'Pull now on an upload source: manual_only — ' . message_of($mp));
ok(post_json(26, '/sources/pull.php', ['source' => $httpId])['code'] === 403 && post_json(26, '/sources/pause.php', ['source' => $httpId])['code'] === 403, 'a Member may do none of it: 403');
ok(post_json(27, '/sources/pause.php', ['source' => 99999999])['code'] === 404, 'an unknown source: 404');
$ar = agent_post(40, '/sources/pause.php', ['source' => $sftpId, 'reason' => 'The agent paused it']);
ok($ar['code'] === 200 && source_row($sftpId)['paused'] === true, 'an agent holding the right may pause (free)');
agent_post(40, '/sources/resume.php', ['source' => $sftpId]);

echo "8. Delete keeps the dataset\n";
$dl = $mk(['name' => 'To be deleted', 'connector' => 'http_file', 'url' => fx_url('sales.csv'), 'target_name' => 'delete_me', 'schedule_minutes' => '0']);
$delId = (int) $dl['json']['record_id'];
post_json(27, '/sources/credential.php', ['source' => $delId, 'kind' => 'api_key', 'secret' => 'key-to-delete-9999']);
fx_state(['sales.csv' => ['file' => 'sales_a.csv', 'etag' => '"v1"', 'auth' => ['key' => 'key-to-delete-9999']]]);
$pull = cli_pull($delId);
$loads = wait_loads($pull['load_ids'] ?? []);
ok(($pull['status'] ?? '') === 'ok' && array_values($loads) === ['loaded'], 'a source with an API-key credential pulled and loaded before it is deleted');
$delDs = ds_id('raw.delete_me');
ok((int) pg1("SELECT count(*) FROM lineage_edges WHERE from_source_id = $delId") === 1, 'the lineage edge from the source to its dataset exists');
pg("INSERT INTO source_pulls (source_id, kind, status) VALUES ($delId, 'manual', 'running')");
$dr = post_json(27, '/sources/delete.php', ['source' => $delId]);
ok($dr['code'] === 422 && str_contains(message_of($dr), 'running'), 'Delete while a pull runs: 422 — ' . message_of($dr));
pg("DELETE FROM source_pulls WHERE source_id = $delId AND status = 'running'");
$loadIds = implode(',', $pull['load_ids']);
$dr = post_json(27, '/sources/delete.php', ['source' => $delId]);
ok($dr['code'] === 200, 'source_delete: 200');
ok((int) pg1("SELECT count(*) FROM sources WHERE id = $delId") === 0 && (int) pg1("SELECT count(*) FROM source_pulls WHERE source_id = $delId") === 0 && (int) pg1("SELECT count(*) FROM source_credentials WHERE source_id = $delId") === 0,
    'the source, its pulls and its sealed credential are gone');
ok(pg1("SELECT status || '|' || COALESCE(source_id::text, 'null') FROM datasets WHERE id = $delDs") === 'active|null', 'the dataset stays, its source link cleared');
ok(pg1("SELECT string_agg(COALESCE(source_id::text, 'null') || ':' || COALESCE(pull_id::text, 'null'), ',') FROM loads WHERE id IN ($loadIds)") === 'null:null' && raw_count('raw.delete_me') === 3, 'its loads stay (they lose the source) and so do the 3 rows it landed');
ok((int) pg1("SELECT count(*) FROM lineage_edges WHERE from_source_id = $delId") === 0, 'the lineage edge from the source goes with it');
$lg = pg1("SELECT (before ->> 'name') || '|' || (after ->> 'pulls_deleted') || '|' || (after ->> 'loads_kept') FROM activity_log WHERE action = 'source.delete' AND entity_id = $delId");
ok($lg === 'To be deleted|1|1', "source.delete is logged (the pulls deleted, the loads kept): $lg");
ok(post_json(27, '/sources/delete.php', ['source' => $delId])['code'] === 404, 'deleting it again: 404');
ok(str_contains(html_as(27, '/sources?notice=deleted')['body'], 'Deleted. The dataset it fed is still there.'), 'the list says so after a delete');

echo "9. The registry\n";
$reg = json_decode((string) file_get_contents($root . '/mcp/action_registry.json'), true) ?: [];
$acts = $reg['actions'] ?? [];
$want = ['source_create', 'source_update', 'source_credential_set', 'source_test', 'source_pull_now', 'source_pause', 'source_resume', 'source_delete'];
ok(count(array_filter($want, static fn (string $a): bool => ($acts[$a]['built'] ?? false) === true)) === 8, 'the registry holds the eight source actions, built');
ok(count(array_filter(['source-list', 'source-view', 'source-add', 'source-edit'], static fn (string $s): bool => ($reg['screens'][$s]['built'] ?? false) === true)) === 4, '…and the four screens, built');
ok(($acts['source_delete']['approval'] ?? '') === 'deletion' && ($acts['source_credential_set']['approval'] ?? '') === 'other' && ($acts['source_credential_set']['agent'] ?? true) === false && ($acts['source_pull_now']['approval'] ?? null) === null,
    'source_delete pauses for an agent (deletion), source_credential_set is people only (other); Pull now is free');
finish();
