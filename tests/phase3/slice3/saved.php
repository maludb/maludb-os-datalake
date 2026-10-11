<?php
/**
 * Slice 3 — saved queries (query-service-editor.md "Proof" 5): saved with a parameter ($region bound by the service as a literal — an injection
 * attempt in the value stays a string), the datasets it reads taken from the dry run, run from its page (the result only), shared and found by
 * a reader, not by someone who cannot read its dataset; a SQL the allow-list refuses is never saved, one the policy refuses today is saved and
 * says so; another's query is never changed (copy it); delete; the picker; logged without the SQL.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;
$tag = run_tag();

echo "5. Saved with a parameter, run from its page, bound as a literal\n";
$sql = 'SELECT region, count(*) AS n FROM curated.sales_orders WHERE region = $region GROUP BY region';
$s = post_json(30, '/queries/save.php', ['title' => "SMOKE orders by region $tag", 'question' => 'How many orders did a region take?', 'sql' => $sql,
    'parameters' => json_encode([['name' => 'region', 'type' => 'text', 'default' => 'EMEA', 'required' => false]]), 'shared' => 'yes']);
$id = (int) ($s['json']['record_id'] ?? 0);
ok($s['code'] === 200 && $id > 0 && ($s['json']['datasets'] ?? []) === ['curated.sales_orders'] && array_key_exists('refusal', $s['json']) && $s['json']['refusal'] === null,
   'saved (JSON {ok, record_id, datasets}): the datasets from the service\'s parse');
ok(pg1("SELECT dataset_ids::text FROM saved_queries WHERE id = $id") === '{' . ds_id('curated.sales_orders') . '}' && pg1("SELECT parameters->0->>'name' FROM saved_queries WHERE id = $id") === 'region',
   'saved_queries.dataset_ids and parameters stored');
$r = get_json(30, "/queries/$id?run=1&p[region]=AMER");
ok(($r['json']['data']['result']['rows'] ?? null) === [['AMER', 375]], 'run from its page with region = AMER: 375 orders');
$d = get_json(30, "/queries/$id?run=1");
ok(($d['json']['data']['result']['rows'] ?? null) === [['EMEA', 375]], '…an empty value takes the default (EMEA)');
$inj = get_json(30, "/queries/$id?run=1&p[region]=" . rawurlencode("EMEA' OR 1=1 --"));
ok(($inj['json']['data']['result']['status'] ?? '') === 'ok' && ($inj['json']['data']['result']['rows'] ?? null) === [], 'an injection attempt in the value stays a string: no rows, nothing else ran');
$qr = run_row((int) $inj['json']['data']['result']['query_run_id']);
ok($qr['saved_query_id'] === $id && $qr['sql'] === $sql, 'the run\'s audit carries the saved query\'s id and its SQL as written (the value bound apart)');
$h = html_as(30, "/queries/$id?run=1&partial=1&p[region]=AMER", ['HX-Request: true']);
ok(str_contains($h['body'], 'id="sql-result-card"') && !str_contains($h['body'], 'id="query-view-sql"'), 'Run shows the result only (the editor\'s partial)');
$v = html_as(30, "/queries/$id");
ok(str_contains($v['body'], 'id="query-form-field-region"') && str_contains($v['body'], 'id="query-view-sql"') && str_contains($v['body'], 'curated.sales_orders'),
   'its page: the SQL read-only, the parameter as a field (query-form-field-region), the dataset');
$typed = post_json(30, '/queries/save.php', ['title' => "SMOKE typed $tag", 'sql' => 'SELECT count(*) FROM curated.sales_orders WHERE order_id > $n AND ordered_on >= $since',
    'parameters' => json_encode([['name' => 'n', 'type' => 'integer', 'default' => '1000', 'required' => true], ['name' => 'since', 'type' => 'date', 'default' => '2026-01-01']])]);
$tid = (int) $typed['json']['record_id'];
ok((get_json(30, "/queries/$tid?run=1&p[n]=1490")['json']['data']['result']['rows'] ?? null) === [[10]], 'an integer and a date parameter, typed: order_id > 1490 → 10');
$bad = get_json(30, "/queries/$tid?run=1&p[n]=ten");
ok($bad['code'] === 422 && isset($bad['json']['error']['fields']['p_n']), '…"ten" for a whole number is refused on its field, nothing run');

echo "   shared: found by a reader, not by someone who cannot read its dataset\n";
$list = get_json(26, '/queries?q=' . rawurlencode("orders by region $tag"));
ok(($list['json']['data']['total'] ?? 0) === 1 && ($list['json']['data']['rows'][0]['saved_query_id'] ?? 0) === $id && ($list['json']['data']['rows'][0]['mine'] ?? true) === false,
   'Priya (a Member who reads orders) finds the shared query');
ok((get_json(26, "/queries/$id?run=1&p[region]=AMER")['json']['data']['result']['rows'] ?? null) === [['AMER', 375]], '…and runs it (queries.run)');
$hidden = post_json(30, '/queries/save.php', ['title' => "SMOKE region sales $tag", 'sql' => 'SELECT count(*) FROM curated.region_sales', 'shared' => 'yes']);
$hid = (int) $hidden['json']['record_id'];
ok(get_json(27, "/queries/$hid")['code'] === 200 && get_json(1, "/queries/$hid")['code'] === 404, 'a shared query over region sales: Marco (who reads it) opens it; the owner-admin (who only discovers it) gets 404');
$adminList = array_column(get_json(1, '/queries?q=' . rawurlencode($tag))['json']['data']['rows'] ?? [], 'saved_query_id');
ok(in_array($id, $adminList, true) && !in_array($hid, $adminList, true), 'the list follows the same wall (the orders query listed for the admin, the region one not)');
$mine = post_json(30, '/queries/save.php', ['title' => "SMOKE private $tag", 'sql' => 'SELECT 1 AS one FROM curated.sales_orders LIMIT 1']);
$pid = (int) $mine['json']['record_id'];
ok(get_json(27, "/queries/$pid")['code'] === 404 && get_json(30, "/queries/$pid")['code'] === 200, 'an unshared query is its owner\'s alone');

echo "   the dry run decides what is saved\n";
$no = post_json(30, '/queries/save.php', ['title' => "SMOKE bad $tag", 'sql' => "SELECT * FROM read_csv('/etc/passwd')"]);
ok($no['code'] === 422 && isset($no['json']['error']['fields']['sql']) && str_contains((string) $no['json']['error']['fields']['sql'], 'read_csv'),
   'a SQL the allow-list refuses is never saved — the service\'s sentence on `sql`');
$today = post_json(30, '/queries/save.php', ['title' => "SMOKE payroll $tag", 'sql' => 'SELECT count(*) FROM curated.payroll']);
ok($today['code'] === 200 && ($today['json']['refusal']['rule'] ?? '') === 'discover_only' && str_contains((string) $today['json']['location'], 'notice=refused'),
   'one the policy refuses today is saved and says so (refusal discover_only, the page\'s notice)');

echo "   another's query is never changed; copy; delete\n";
$other = post_json(27, '/queries/save.php', ['saved_query' => $id, 'title' => 'Hijacked', 'sql' => 'SELECT 1 AS one FROM curated.sales_orders LIMIT 1']);
ok($other['code'] === 404 && pg1("SELECT title FROM saved_queries WHERE id = $id") === "SMOKE orders by region $tag", 'Marco cannot change Dana\'s query (404; it stays as it was)');
ok(html_as(27, "/queries/$id/edit")['code'] === 404 && str_contains(html_as(27, "/queries/new?copy=$id")['body'], 'Copy of SMOKE orders by region'), '…its edit form is hers; Copy starts his own from it');
$ed = post_json(30, '/queries/save.php', ['saved_query' => $id, 'title' => "SMOKE orders by region $tag", 'shared' => 'yes']);
ok($ed['code'] === 200 && pg1("SELECT sql FROM saved_queries WHERE id = $id") === $sql, 'an update with the SQL left out keeps it (a field left out stays as it was)');
$del = post_json(30, '/queries/delete.php', ['saved_query' => $pid]);
ok($del['code'] === 200 && pg1("SELECT deleted_at IS NOT NULL FROM saved_queries WHERE id = $pid") === 't' && get_json(30, "/queries/$pid")['code'] === 404, 'delete: gone from its owner too (soft — deleted_at)');
ok(post_json(27, '/queries/delete.php', ['saved_query' => $id])['code'] === 404, '…another\'s cannot be deleted');

echo "   the picker; the log\n";
$pick = html_as(26, '/pick/?source=saved_query&q=' . rawurlencode("orders by region $tag"));
$pick2 = html_as(1, '/pick/?source=saved_query&q=' . rawurlencode("region sales $tag"));
ok($pick['code'] === 200 && str_contains($pick['body'], "SMOKE orders by region $tag") && !str_contains($pick2['body'], "SMOKE region sales $tag"),
   'the saved_query picker finds it for Priya — and hides the region one from the admin (the same wall)');
$log = activity('saved_query.save');
$last = json_decode((string) end($log)['after'], true) ?: [];
ok($log !== [] && !str_contains((string) json_encode(array_column($log, 'after')), 'FROM curated') && isset($last['datasets']), 'saved_query.save logged with the title and the dataset count — never the SQL');
finish();
