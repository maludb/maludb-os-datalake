<?php
/**
 * Proof: the engine runs empty under the shell (sso-shell.md divergence 1; lake-engine §1; query-service §2, §7, §8) and the dataset gate
 * (access-and-policies §1, §6 — K38): lake_ping() answers both services ok through the health endpoint; the query service answers `ping` on
 * every instance and refuses a kind its slice has not built, in words; a queued job of an unbuilt kind is finished `failed` "not built until
 * slice N" with no retry; with the sockets gone the client answers engine_unavailable (never an exception) and health says down without
 * failing; require_level() answers 404 below discover (the same nothing as no dataset), a stub to `discover` only, 403 with the sentence and
 * Request access below the need — and for an agent's run, the INTERSECTION with the person the kernel says asked (K38) or the fail-closed
 * ceiling on a kernel that does not say. Run through tests/phase2/run.sh after gates.php (the seeded lake).
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
$ids = seed_lake();
$orders = $ids['curated.sales_orders'];
$payroll = $ids['curated.payroll'];
$state = need('DL_DEV_STATE');
$prefix = need('DL_QUERY_SOCKET');
$php = static fn (string $code): string => (string) shell_exec('php -r ' . escapeshellarg('require "app/bootstrap.php"; ' . $code) . ' 2>&1');

echo "1. The engine answers — the writer connected, the query service on every socket\n";
$h = body_json(req('GET', '/api/v1/health'));
ok(($h['engine'] ?? null) == ['writer' => 'ok', 'query' => 'ok', 'query_instances' => 2] && ($h['ok'] ?? false) === true, 'health: the writer ok, the query service ok on both instances');
$p = json_decode($php('echo json_encode(lake_ping());'), true) ?: [];
ok(($p['writer'] ?? '') === 'ok' && ($p['query'] ?? '') === 'ok' && ($p['version'] ?? '') === '1.5.6', 'lake_ping(): both ok, the query service on DuckDB 1.5.6 (pinned)');
foreach ([1, 2] as $n) {
    $sock = "$prefix-$n.sock";
    $mode = file_exists($sock) ? substr(sprintf('%o', fileperms($sock)), -3) : 'none';
    ok(filetype($sock) === 'socket' && $mode === '660', "instance $n listens on its own Unix socket, mode 0660 ($mode)");
}
$a = json_decode($php('echo json_encode(lake_query(["kind" => "product_rows", "dataset" => "product.x", "caller_member_id" => 1], 5));'), true) ?: [];
ok(($a['status'] ?? '') === 'refused' && ($a['refusal_rule'] ?? '') === 'not_built' && ($a['message'] ?? '') === 'not built until slice 9', 'a kind its slice has not built is refused: not built until slice 9 (product_rows — check_row_filter is slice 4\'s, metric slice 7\'s: built)');
ok(trim($php('echo lake_refusal_message(lake_query(["kind" => "product_rows"], 5));')) === 'This is not built until slice 9.', '…said to a person in words (product_rows: slice 9 — preview is slice 2\'s, built)');
$c = stream_socket_client("unix://$prefix-1.sock", $en, $es, 2);
fwrite($c, json_encode(['v' => 9, 'kind' => 'sql', 'sql' => 'SELECT 1']) . "\n");
$ans = json_decode((string) fgets($c), true) ?: [];
fclose($c);
ok(($ans['refusal_rule'] ?? '') === 'bad_request', 'an unknown protocol version is refused');
$c = stream_socket_client("unix://$prefix-1.sock", $en, $es, 2);
fwrite($c, str_repeat('x', 1048600) . "\n");
$ans = json_decode((string) fgets($c), true) ?: [];
fclose($c);
ok(($ans['refusal_rule'] ?? '') === 'too_long', 'a request over 1 MB is refused too_long');

echo "2. The writer works the queue — a kind no slice has built fails once, naming its slice\n";
$jobs = [];
foreach (['retention' => 'slice 8', 'maintenance' => 'slice 8'] as $kind => $slice) {   // sniff/load/profile/rollback: slice 2's, export/sandbox_save: slice 3's, promote/checks: slice 5's, transform: slice 7's — built
    $jobs[$kind] = [(int) trim($php('echo lake_enqueue(db(), ' . var_export($kind, true) . ', null, null, ["proof" => true]);')), $slice];
}
ok(count(array_filter($jobs, static fn ($j) => $j[0] > 0)) === count($jobs), 'lake_enqueue() queued a job of each kind not built yet (dl_job_enqueue) as the application');
$deadline = time() + 15;
do { usleep(300000); $left = (int) one("SELECT count(*) FROM lake_jobs WHERE status IN ('queued', 'running') AND id = ANY (CAST(:ids AS bigint[]))", ['ids' => '{' . implode(',', array_column($jobs, 0)) . '}']); } while ($left > 0 && time() < $deadline);
$bad = [];
foreach ($jobs as $kind => [$id, $slice]) {
    $r = q('SELECT status, attempts, error FROM lake_jobs WHERE id = :i', ['i' => $id])[0] ?? [];
    if (($r['status'] ?? '') !== 'failed' || (int) ($r['attempts'] ?? 0) !== 1 || ($r['error'] ?? '') !== "not built until $slice") { $bad[] = "$kind: " . json_encode($r); }
}
ok($bad === [], 'each was finished failed at once, "not built until slice N", one attempt, no retry' . ($bad ? ' — ' . implode('; ', $bad) : ''));
ok(preg_match('/writer: connected as datalake-writer; \d+ job\(s\) at once; \d+ kind\(s\) built/', (string) @file_get_contents("$state/writer.log")) === 1, 'the writer says at start which kinds are built');

echo "3. The engine gone — the client answers in words, health says down without failing\n";
foreach ([1, 2] as $n) { if (is_file("$state/query-$n.pid")) { posix_kill((int) file_get_contents("$state/query-$n.pid"), SIGTERM); } }
usleep(800000);
$a = json_decode($php('echo json_encode(lake_query(["kind" => "sql", "sql" => "SELECT 1"], 5));'), true) ?: [];
ok(($a['status'] ?? '') === 'error' && ($a['refusal_rule'] ?? '') === 'engine_unavailable' && ($a['retry'] ?? false) === true && str_contains((string) ($a['message'] ?? ''), 'not answering'), 'a dead socket: engine_unavailable, retry, in words — never an exception');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && (body_json($h)['engine']['query'] ?? '') === 'down' && (body_json($h)['engine']['writer'] ?? '') === 'ok', 'health: the query service down, the check itself still 200');
posix_kill((int) file_get_contents("$state/writer.pid"), SIGTERM);
for ($i = 0; $i < 20 && (body_json(req('GET', '/api/v1/health'))['engine']['writer'] ?? '') !== 'down'; $i++) { usleep(300000); }
ok((body_json(req('GET', '/api/v1/health'))['engine']['writer'] ?? '') === 'down', 'the writer stopped: health says down');
// back up, as the units would restart them
$env = 'set -a; . ' . escapeshellarg(need('DL_DEV_ENV')) . '; set +a; ';
shell_exec($env . 'nohup services/.venv/bin/python services/lake/writer_main.py > ' . escapeshellarg("$state/writer.log") . ' 2>&1 & echo $! > ' . escapeshellarg("$state/writer.pid"));
foreach ([1, 2] as $n) {
    shell_exec($env . "nohup services/.venv/bin/python services/query/server.py --worker $n > " . escapeshellarg("$state/query-$n.log") . ' 2>&1 & echo $! > ' . escapeshellarg("$state/query-$n.pid"));
}
for ($i = 0; $i < 30 && (body_json(req('GET', '/api/v1/health'))['engine'] ?? []) != ['writer' => 'ok', 'query' => 'ok', 'query_instances' => 2]; $i++) { usleep(300000); }
ok((body_json(req('GET', '/api/v1/health'))['engine'] ?? []) == ['writer' => 'ok', 'query' => 'ok', 'query_instances' => 2], 'restarted (as systemd would): both ok again');

echo "4. The dataset gate — require_level()\n";
$gate = static fn (string $jar, string $ref, string $need, array $o = []): array => req('GET', '/__proof/gate.php?' . http_build_query(['ref' => $ref, 'need' => $need]), ['jar' => $jar] + $o);
[$jm, ] = sign_on(26);
[$ja, ] = sign_on(29);
[$jo, ] = sign_on(1);
$r = $gate($jm, 'curated.sales_orders', 'read', $json);
ok($r['code'] === 200 && (body_json($r)['data']['my_level'] ?? '') === 'read' && (body_json($r)['data']['id'] ?? 0) === $orders, 'a Member reads the orders by key: my_level read');
ok((body_json($gate($jm, (string) $orders, 'discover', $json))['data']['key'] ?? '') === 'curated.sales_orders', '…and by id');
$r = $gate($jm, 'curated.sales_orders', 'write', $json);
$e = body_json($r)['error'] ?? [];
ok($r['code'] === 403 && ($e['code'] ?? '') === 'not_allowed' && ($e['need'] ?? '') === 'write' && ($e['message'] ?? '') === 'You may not change curated.sales_orders — request access'
    && ($e['request_access'] ?? '') === "/access/requests/new?dataset=$orders&level=write", 'below the need: 403 not_allowed, the sentence, the level needed and the Request access link');
$b = $gate($jm, 'curated.sales_orders', 'write')['body'];
ok(str_contains($b, 'You may not change curated.sales_orders — request access') && str_contains($b, 'id="message-request-access-btn"'), '…and on a page, the sentence with a Request access button');
$none = $gate($jm, 'curated.no_such_thing', 'read');
$hidden = $gate($jm, 'curated.payroll', 'read');
ok($none['code'] === 404 && $hidden['code'] === 404 && $none['body'] === $hidden['body'], 'below discover: 404 — the same page, byte for byte, as a dataset that does not exist');
$r = $gate($jm, 'curated.payroll', 'discover', $json);
ok($r['code'] === 200 && (body_json($r)['data']['is_stub'] ?? false) === true && (body_json($r)['data']['my_level'] ?? '') === 'none', 'a stub passes `discover` only, marked is_stub (the dataset page\'s stub render)');
ok($gate($ja, 'curated.payroll', 'discover')['code'] === 404 && $gate($ja, 'curated.sales_orders', 'discover')['code'] === 404, 'an external gets no stub and nothing of the everyone grant: 404');
ok($gate($jm, 'curated.Bad-Key', 'read')['code'] === 404 && $gate($jm, '../etc', 'read')['code'] === 404, 'a malformed reference: 404');
ok((body_json($gate($jo, 'curated.payroll', 'own', $json))['data']['my_level'] ?? '') === 'own', 'the owner owns the payroll');
ok((body_json($gate($jm, 'sandbox.m26_my_upload', 'own', $json))['data']['my_level'] ?? '') === 'own' && $gate(sign_on(27)[0], 'sandbox.m26_my_upload', 'discover')['code'] === 404, 'Priya owns her sandbox dataset; Marco cannot see it');

echo "5. K38 — an agent's run acts for the person the kernel says asked\n";
$asRun = static function (int $run, string $ref, string $need) use ($json): array {
    return req('GET', '/__proof/gate.php?' . http_build_query(['ref' => $ref, 'need' => $need]), ['headers' => as_agent(run_token(40, $run))]);
};
set_run_facts(201, 40, 'chat', 26);
$r = $asRun(201, 'curated.sales_orders', 'read');
ok($r['code'] === 200 && (body_json($r)['data']['on_behalf_of'] ?? 0) === 26, 'a chat turn Priya asked for: the Expert reads the orders — both may (on_behalf_of 26)');
ok($asRun(201, 'curated.payroll', 'read')['code'] === 404, '…but not the payroll: the Expert holds a grant, Priya may not discover it — the intersection is nothing (404)');
set_run_facts(202, 40, 'chat', 1);
ok($asRun(202, 'curated.payroll', 'read')['code'] === 200, 'the same agent in a chat turn the owner asked for reads the payroll');
set_run_facts(203, 40, 'chat', 29);
ok($asRun(203, 'curated.sales_orders', 'discover')['code'] === 404, 'a turn an external asked for: the Expert sees nothing of the orders (Ann may not)');
set_run_facts(204, 40, 'chat', false);
$r = $asRun(204, 'curated.payroll', 'read');
ok($r['code'] === 403 && (body_json($r)['error']['code'] ?? '') === 'k38_pending' && str_contains((string) (body_json($r)['error']['message'] ?? ''), 'readable once the kernel says who asked (K38)'),
    'a kernel that does not say who asked a chat turn: the confidential payroll is refused k38_pending — fail closed');
$r = $asRun(204, 'curated.sales_orders', 'read');
ok($r['code'] === 200 && (body_json($r)['data']['ceiling'] ?? '') === 'internal_unpersonal', '…while the orders (not confidential, not personal) read under the ceiling internal_unpersonal');
set_run_facts(205, 40, 'duty', null);
$r = $asRun(205, 'curated.payroll', 'read');
$dd = body_json($r)['data'] ?? [];
ok($r['code'] === 200 && array_key_exists('on_behalf_of', $dd) && $dd['on_behalf_of'] === null && array_key_exists('ceiling', $dd) && $dd['ceiling'] === null, 'a duty run reads as the agent alone, with its own grants');
ok($asRun(299, 'curated.sales_orders', 'read')['code'] === 200 && (body_json($asRun(299, 'curated.sales_orders', 'read'))['data']['ceiling'] ?? '') === 'internal_unpersonal',
    'facts the kernel could not give (an unknown run): the ceiling — fail closed');
finish();
