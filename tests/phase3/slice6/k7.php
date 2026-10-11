<?php
/**
 * Slice 6 proof 4 — the K7 connector and the owner's FIVE reads (docs/build-specs/sources-pulls.md "The owner's five reads", "Proof": the five K7 sources seeded and
 * pulled — raw.inventory_sales_closed gets both pages, as_of injected into the GL rows, the window moves forward, no_connection pauses without a failure count).
 * The shares are answered by the fake kernel on :9002 (POST /api/v1/apps/read.php; tests/fake_kernel.php, state.reads). The seeding script and the worker's pass run as
 * child processes, as they run in production. Run through run.sh.
 */
require __DIR__ . '/lib.php';
$root = dirname(__DIR__, 3);
$mk = static fn (array $f, int $as = 27): array => post_json($as, '/sources/save.php', $f);
$today = gmdate('Y-m-d');
$yesterday = gmdate('Y-m-d', time() - 86400);
$from92 = gmdate('Y-m-d', time() - 92 * 86400);
pg("DELETE FROM sources WHERE name = 'GL balances (proof)'");          // sources.php's own k7 source would make the seed skip the GL balances read

echo "1. The five reads, seeded\n";
[$rc, $o] = cli_shell('php bin/seed_k7_sources.php --owner=27');
$lines = array_map(static fn (string $l): array => json_decode($l, true) ?: [], array_values(array_filter(explode("\n", trim($o)))));
ok($rc === 0 && count($lines) === 5 && array_unique(array_column($lines, 'seeded')) === [true], 'bin/seed_k7_sources.php seeds five sources: ' . implode(', ', array_column($lines, 'source')));
[$rc, $o] = cli_shell('php bin/seed_k7_sources.php --owner=27');
$again = array_map(static fn (string $l): array => json_decode($l, true) ?: [], array_values(array_filter(explode("\n", trim($o)))));
ok($rc === 0 && count($again) === 5 && array_unique(array_column($again, 'seeded')) === [false] && (int) pg1("SELECT count(*) FROM sources WHERE connector = 'k7' AND settings ->> 'provider' IN ('gl', 'inventory', 'sales_crm')") === 5, 'idempotent: a second run leaves the five as they are');
[$rc, $o] = cli_shell('php bin/seed_k7_sources.php --dry-run');
ok($rc === 0, '--dry-run, and the owner found from the right (sources.manage) when none is given');
$ids = [];
foreach (['GL account balances' => 'raw.gl_account_balances', 'GL receivables aging' => 'raw.gl_ar_aging', 'GL payables due' => 'raw.gl_ap_due', 'Inventory sales closed' => 'raw.inventory_sales_closed', 'CRM deals won' => 'raw.crm_deals_won'] as $name => $key) {
    $ids[$name] = source_id($name);
    $d = pg1("SELECT status || '|' || zone || '|' || COALESCE(source_id::text, '') || '|' || owner_member_id FROM datasets WHERE key = '$key'");
    ok($ids[$name] !== null && $d === 'active|raw|' . $ids[$name] . '|27', "$name → $key made, active, linked to its source, Marco's");
}
$row = source_row($ids['Inventory sales closed']);
ok($row['role'] === 'internal_app' && $row['schedule_minutes'] === 1440 && ($row['settings']['provider'] ?? '') === 'inventory' && ($row['settings']['tool'] ?? '') === 'sales_closed' && ($row['settings']['rows_path'] ?? '') === 'orders'
    && ($row['settings']['paging'] ?? '') === 'offset' && ($row['settings']['page_size'] ?? 0) === 500 && ($row['settings']['window'] ?? '') === 'date', 'sales_closed: paged by offset 500 rows a page, a date window, rows in "orders", daily');
$row = source_row($ids['GL account balances']);
ok(($row['settings']['arguments']['as_of'] ?? '') === '<yesterday>' && ($row['settings']['inject']['as_of'] ?? '') === 'document' && ($row['settings']['paging'] ?? '') === 'none', 'account_balances: as_of is yesterday, injected from the document');
ok(pg1("SELECT dedupe_key::text FROM datasets WHERE key = 'raw.gl_ap_due'") === '{as_of,bucket,currency}' && (source_row($ids['GL payables due'])['settings']['inject']['as_of'] ?? '') === 'pull_date', 'ap_due: the pull\'s date is the as_of; the contract\'s key is recorded on the dataset');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.create' AND (after ->> 'seeded')::boolean AND source = 'cron'") === 5, 'five source.create rows, seeded (source cron)');
$page = html_as(27, '/sources');
ok(str_contains($page['body'], 'GL account balances') && str_contains($page['body'], 'raw.crm_deals_won') && str_contains($page['body'], 'Every day'), 'the five show on the Sources page');

echo "2. The first pass — four answer, one connection is not approved\n";
$orders = [];
foreach ([1, 2, 3] as $n) {
    $orders[] = ['number' => "SO-$n", 'date' => gmdate('Y-m-d', time() - $n * 86400), 'customer' => ['name' => "Customer $n"], 'lines' => [['sku' => "SKU-$n", 'qty' => $n, 'unit_price' => number_format($n * 10.5, 2, '.', '')], ['sku' => 'FREIGHT', 'qty' => 1, 'unit_price' => '5.00']], 'tax' => '1.0' . $n, 'total' => number_format($n * 10.5 + 6, 2, '.', '')];
}
$docs = [
    'gl/account_balances' => ['mode' => 'ok', 'doc' => ['schema' => 'gl.account-balances/1', 'as_of' => $yesterday, 'accounts' => [
        ['code' => '1000', 'name' => 'Cash', 'account_type' => 'asset', 'subtype' => 'cash', 'balance' => '1500.00'], ['code' => '4000', 'name' => 'Sales', 'account_type' => 'income', 'subtype' => null, 'balance' => '-9800.50']]]],
    'gl/ar_aging_summary' => ['mode' => 'ok', 'doc' => ['schema' => 'gl.ar-aging-summary/1', 'as_of' => $yesterday, 'currencies' => [
        ['currency' => 'USD', 'current_due' => '100.00', 'days_1_30' => '50.00', 'days_31_60' => '0.00', 'days_61_90' => '0.00', 'days_over_90' => '0.00', 'total' => '150.00', 'open_invoices' => 3]]]],
    'gl/ap_due_summary' => ['mode' => 'ok', 'doc' => ['schema' => 'gl.ap-due-summary/1', 'weeks' => 8, 'buckets' => [
        ['bucket' => 'overdue', 'from_date' => null, 'to_date' => $yesterday, 'currency' => 'USD', 'amount' => '75.00', 'bills' => 2], ['bucket' => 'week 1', 'from_date' => $today, 'to_date' => gmdate('Y-m-d', time() + 6 * 86400), 'currency' => 'USD', 'amount' => '20.25', 'bills' => 1]]]],
    'inventory/sales_closed' => ['mode' => 'ok', 'rows_key' => 'orders', 'doc' => ['schema' => 'os.inventory-sales/1', 'totals' => ['orders' => 3], 'orders' => $orders]],
    'sales_crm/deals_won' => ['mode' => 'no_connection'],
];
k7_shares($docs);
$post = post_json(27, '/sources/save.php', ['source' => $ids['Inventory sales closed'], 'page_size' => '2']);
ok($post['code'] === 200 && (source_row($ids['Inventory sales closed'])['settings']['page_size'] ?? 0) === 2, '(the sales read set to two rows a page, so three orders are two pages)');
isolate_sources(array_values($ids));
k7_log(true);
$last = last_notice_id();
$rep = worker_schedules();
$sr = $rep['ran']['schedules']['sources'] ?? [];
ok(($rep['_rc'] ?? 1) === 0 && ($sr['due'] ?? 0) === 5 && ($sr['pulls'] ?? 0) === 5 && ($sr['ok'] ?? 0) === 4 && ($sr['blocked'] ?? 0) === 1 && ($sr['failed'] ?? 1) === 0 && ($sr['errors'] ?? 1) === 0, 'the worker\'s pass: five due, four ok, one blocked — ' . json_encode($sr));
$crm = source_row($ids['CRM deals won']);
ok($crm['paused'] === true && $crm['paused_reason'] === 'no_connection' && $crm['consecutive_failures'] === 0 && $crm['backoff_until'] === null, 'no_connection: the source PAUSES with the reason — no failure counted, no back-off');
ok(last_pull($ids['CRM deals won'])['status'] === 'blocked' && str_contains((string) last_pull($ids['CRM deals won'])['error'], 'not approved yet'), 'the pull is blocked, in words: ' . last_pull($ids['CRM deals won'])['error']);
$n = last_notice(27, 'load_failed', $last);
ok($n !== null && str_contains($n[0], 'CRM deals won was paused'), 'its keepers are told: ' . ($n[0] ?? 'nothing'));
$log = k7_log();
$by = [];
foreach ($log as $l) {
    $by[$l['provider'] . '/' . $l['tool']][] = $l['arguments'];
}
ok(($by['gl/account_balances'] ?? []) === [['as_of' => $yesterday]], 'account_balances was asked for yesterday ("<yesterday>" became ' . $yesterday . ') — nothing else in the arguments');
ok(($by['gl/ap_due_summary'] ?? []) === [['weeks' => 8]], 'ap_due_summary: {"weeks": 8}');
ok(($by['inventory/sales_closed'] ?? []) === [['from' => $from92, 'to' => $yesterday, 'offset' => 0, 'limit' => 2], ['from' => $from92, 'to' => $yesterday, 'offset' => 2, 'limit' => 2]],
    'sales_closed: the window reaches 92 days back to yesterday, two pages (offset 0, then 2) — the second shorter than the page, so the last');
ok(count($by['sales_crm/deals_won'] ?? []) === 1 && ($by['sales_crm/deals_won'][0]['from'] ?? '') === $from92 && ($by['sales_crm/deals_won'][0]['limit'] ?? 0) === 500, 'deals_won: asked once, refused');
$sent = array_merge(...array_map(static fn (array $l): array => array_keys($l['arguments']), $log));
ok(array_intersect($sent, ['consumer', 'consumer_agent_id', 'as_agent', 'scope_id']) === [], 'no reserved argument was ever sent (the kernel sets them)');
$loads = [];
foreach (['GL account balances', 'GL receivables aging', 'GL payables due', 'Inventory sales closed'] as $name) {
    $loads = array_merge($loads, array_map('intval', pg("SELECT id FROM loads WHERE source_id = {$ids[$name]} ORDER BY id")));
}
ok(count($loads) === 5 && array_unique(array_values(wait_loads($loads))) === ['loaded'], 'one load for each GL read and two for the sales (the two pages): five loads, all loaded');
ok(raw_count('raw.gl_account_balances') === 2 && raw_count('raw.gl_ar_aging') === 1 && raw_count('raw.gl_ap_due') === 2 && raw_count('raw.inventory_sales_closed') === 3, 'rows: 2 accounts, 1 currency, 2 buckets, 3 orders (both pages)');
[$cols, $rows] = raw_rows('raw.gl_account_balances', 'code, name, balance, subtype, as_of', 'code');
ok($cols === ['code', 'name', 'balance', 'subtype', 'as_of'] && ($rows[0] ?? []) === ['1000', 'Cash', '1500.00', 'cash', $yesterday] && array_key_exists(3, $rows[1] ?? []) && $rows[1][3] === null && ($rows[1][2] ?? '') === '-9800.50', 'GL balances: the document\'s as_of is on every row; text as it came (1500.00, -9800.50, a null stays null)');
[, $rows] = raw_rows('raw.gl_ap_due', 'bucket, as_of', 'bucket');
ok(($rows[0] ?? []) === ['overdue', $today] && ($rows[1] ?? []) === ['week 1', $today], 'ap_due carries no date of its own: as_of is the pull\'s date');
[, $rows] = raw_rows('raw.inventory_sales_closed', 'number, tax, lines', 'number');
ok(($rows[0][0] ?? '') === 'SO-1' && ($rows[0][1] ?? '') === '1.01' && ($rows[2][0] ?? '') === 'SO-3', 'sales: the three orders (tax 1.01 is text)');
ok(is_array(json_decode((string) ($rows[0][2] ?? ''), true)) && json_decode($rows[0][2], true)[0]['sku'] === 'SKU-1' && count(json_decode($rows[2][2], true)) === 2, 'a nested array (lines) lands as JSON text in raw — the contract types it later');
ok((int) sql_as(27, 'SELECT count(DISTINCT _dl_load_id) FROM raw.inventory_sales_closed')['json']['data']['result']['rows'][0][0] === 2, 'the three orders came in two loads, one for each page');
$blob = pg1("SELECT b.storage_path FROM loads l JOIN blobs b ON b.id = l.blob_id WHERE l.source_id = {$ids['GL account balances']} LIMIT 1");
$first = (string) strtok((string) file_get_contents(need('DL_STORAGE_DIR') . '/' . $blob), "\n");
$want = json_encode($docs['gl/account_balances']['doc']['accounts'][0] + ['as_of' => $yesterday], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
ok($first === $want, 'the landed NDJSON is the share\'s row, byte for byte, with the injected field last');
$pol = last_pull($ids['Inventory sales closed'])['policy'];
$doc = $pol['document'] ?? [];
ksort($doc);
ok($doc === ['schema' => 'os.inventory-sales/1', 'totals' => ['orders' => 3]] && ($pol['state']['window_to'] ?? '') === $yesterday, 'the document\'s other keys are kept with the pull (policy.document), and so is the window\'s end (policy.state.window_to)');
$pol = last_pull($ids['GL account balances'])['policy'];
$doc = $pol['document'] ?? [];
ksort($doc);
ok($doc === ['as_of' => $yesterday, 'schema' => 'gl.account-balances/1'], '…for the GL read: its schema and as_of');
ok((int) pg1("SELECT count(*) FROM lineage_edges WHERE from_source_id IN (" . implode(',', array_slice($ids, 0, 4)) . ") AND via = 'load'") === 4, 'a lineage edge from each source that pulled');
$sh = pg("SELECT after ->> 'provider' || '/' || (after ->> 'tool') || '|' || (after ->> 'status') || '|' || COALESCE(after ->> 'code', '-') || '|' || COALESCE(after ->> 'rows', '-') FROM activity_log WHERE action = 'share.read' ORDER BY id");
ok(in_array('gl/account_balances|200|-|2', $sh, true) && in_array('inventory/sales_closed|200|-|2', $sh, true) && in_array('inventory/sales_closed|200|-|1', $sh, true) && in_array('sales_crm/deals_won|403|no_connection|-', $sh, true), 'share.read is logged by the kernel client: the provider, the tool, the outcome and the row count (' . count($sh) . ' rows)');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action = 'share.read' AND (after::text LIKE '%Cash%' OR after::text LIKE '%Customer 1%' OR after::text LIKE '%SKU-1%')") === 0, '…and never a row of the answer');

echo "3. The window moves forward\n";
k7_log(true);
$tomorrow = gmdate('c', time() + 86400 + 120);
$sid = $ids['Inventory sales closed'];
$w2 = cli_pull($sid, ['now' => $tomorrow]);
$l = k7_log();
ok(($w2['status'] ?? '') === 'ok' && count($l) === 2 && $l[0]['arguments']['from'] === $today && $l[0]['arguments']['to'] === $today, 'a day later the window is the day after the last pull\'s end: from = to = today — ' . json_encode(array_column($l, 'arguments')));
ok(($w2['items_landed'] ?? -1) === 0 && ($w2['load_ids'] ?? [1]) === [], '(the stub returned the same three orders: both pages are repeats, refused by the key — landed 0)');
ok((last_pull($sid)['policy']['state']['window_to'] ?? '') === $today, 'policy.state.window_to moved to today');
k7_log(true);
$w3 = cli_pull($sid, ['now' => $tomorrow]);
ok(($w3['status'] ?? '') === 'ok' && ($w3['items_seen'] ?? -1) === 0 && k7_log() === [] && str_contains((string) (last_pull($sid)['policy']['note'] ?? ''), 'up to date'), 'a third pull the same day has nothing to ask for: up to date, the share is not called');

echo "4. A connection a super-admin approves, and what the share can get wrong\n";
$cid = $ids['CRM deals won'];
k7_shares(['sales_crm/deals_won' => ['mode' => 'ok', 'rows_key' => 'deals', 'doc' => ['schema' => 'os.sales-crm-deals-won/1', 'deals' => [['deal_id' => 11, 'title' => 'Big one', 'value' => '12000.00', 'products' => [['name' => 'Plan', 'quantity' => 1]]], ['deal_id' => 12, 'title' => 'Small one', 'value' => '800.00', 'products' => []]]]]] + $docs);
ok(post_json(27, '/sources/resume.php', ['source' => $cid])['code'] === 200, 'approved and resumed');
$q = post_json(27, '/sources/pull.php', ['source' => $cid]);
ok($q['code'] === 200, 'Pull now');
$rep = worker_schedules();
ok(($rep['ran']['schedules']['sources']['queued'] ?? 0) === 1 && source_row($cid)['paused'] === false && last_pull($cid)['status'] === 'ok', 'the worker takes it: ok');
wait_loads(array_map('intval', pg("SELECT id FROM loads WHERE source_id = $cid")));
[, $rows] = raw_rows('raw.crm_deals_won', 'deal_id, title, value, products', 'CAST(deal_id AS INTEGER)');
ok(count($rows) === 2 && $rows[0][0] === '11' && $rows[0][2] === '12000.00' && json_decode((string) $rows[0][3], true)[0]['name'] === 'Plan', 'raw.crm_deals_won: two deals, products as JSON text');
$ar = $ids['GL receivables aging'];
k7_shares(['gl/ar_aging_summary' => ['mode' => 'ok', 'doc' => ['schema' => 'gl.ar-aging-summary/1', 'currencies' => [['currency' => 'USD', 'total' => '1.00']]]]] + $docs);
$f = cli_pull($ar);
ok(($f['status'] ?? '') === 'failed' && str_contains((string) $f['error'], 'no "as_of" to put in "as_of"') && ($f['consecutive_failures'] ?? 0) === 1 && ($f['items_landed'] ?? 1) === 0, 'an answer without the key to inject: failed, nothing landed — ' . ($f['error'] ?? ''));
k7_shares(['gl/ar_aging_summary' => ['mode' => 'ok', 'doc' => ['schema' => 'gl.ar-aging-summary/1', 'as_of' => $yesterday, 'rows' => []]]] + $docs);
$f = cli_pull($ar);
ok(($f['status'] ?? '') === 'failed' && str_contains((string) $f['error'], 'without a list at "currencies"') && ($f['consecutive_failures'] ?? 0) === 2, 'an answer without the list: failed — ' . ($f['error'] ?? ''));
k7_shares(['gl/ar_aging_summary' => ['mode' => 'provider_failed']] + $docs);
$f = cli_pull($ar);
ok(($f['status'] ?? '') === 'failed' && str_contains((string) $f['error'], 'failed to answer') && ($f['paused'] ?? true) === false && ($f['consecutive_failures'] ?? 0) === 3, 'the provider failing (502): a failure, back-off — not a pause — ' . ($f['error'] ?? ''));
k7_shares(['gl/ar_aging_summary' => ['mode' => 'invalid']] + $docs);
$f = cli_pull($ar);
ok(($f['status'] ?? '') === 'failed' && str_contains((string) $f['error'], 'refused the read (invalid)'), 'a 422 from the kernel: failed, in its code — ' . ($f['error'] ?? ''));
k7_shares(['gl/ar_aging_summary' => ['mode' => 'not_shared']] + $docs);
$f = cli_pull($ar);
ok(($f['status'] ?? '') === 'blocked' && ($f['paused'] ?? false) === true && $f['paused_reason'] === 'not_shared', 'a tool the application does not share: blocked and paused (not_shared)');
k7_shares($docs);
post_json(27, '/sources/resume.php', ['source' => $ar]);
$f = cli_pull($ar);
ok(($f['status'] ?? '') === 'ok' && ($f['consecutive_failures'] ?? 1) === 0 && ($f['items_landed'] ?? 0) === 0, 'back to normal: ok (the same document as before — a repeat, landed 0)');
$view = html_as(27, "/sources/$sid?tab=settings")['body'];
ok(str_contains($view, 'inventory · sales_closed') && str_contains($view, 'from the day after the last pull') && str_contains($view, 'page by page, 2 rows a page'), 'the source page says what it reads and how');
finish();
