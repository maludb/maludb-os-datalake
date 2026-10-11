<?php
/**
 * Slice 3 — the audit and the usage it feeds (query-service-editor.md "Proof" 8, 9): a run's page — its caller sees the SQL, the owner of a
 * dataset it read sees the run without it ("hidden"), audit.read sees it, anyone else 404; the masks and filters by name only. The worker's
 * usage_rollup (once a day, after 01:00 — DL_WORKER_NOW moves the clock) fills dataset_usage_daily, the Usage tab and dl_usage_30d; search
 * ranks the used dataset above an unused one; the home's Recent tile lists what I queried; nothing of a value or the SQL in activity.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;

echo "8. The run page\n";
$x = result_of(sql_as(30, 'SELECT order_id, customer_email FROM curated.sales_orders WHERE region = \'AMER\' LIMIT 3'));
$id = (int) $x['query_run_id'];
$mine = get_json(30, "/runs/$id")['json']['data']['run'] ?? [];
ok(($mine['sql'] ?? '') === "SELECT order_id, customer_email FROM curated.sales_orders WHERE region = 'AMER' LIMIT 3" && (static function (array $m): bool { sort($m); return $m === ['curated.sales_orders.customer_email', 'curated.sales_orders.margin']; })($mine['masked_columns'] ?? [])
   && $mine['rows'] === 3 && $mine['source'] === 'screen', 'its caller sees the SQL, the masks by name, the rows, the source');
$owner = get_json(27, "/runs/$id");
ok($owner['code'] === 200 && array_key_exists('sql', $owner['json']['data']['run'] ?? []) && $owner['json']['data']['run']['sql'] === null && ($owner['json']['data']['run']['sql_hidden'] ?? false) === true, 'Marco (the dataset\'s owner) sees the run — the SQL hidden');
ok(str_contains(html_as(27, "/runs/$id")['body'], 'id="run-view-sql-hidden"'), '…the page says "Hidden"');
ok((get_json(1, "/runs/$id")['json']['data']['run']['sql'] ?? '') !== '', 'audit.read (the Lake admin) sees the SQL');
ok(get_json(26, "/runs/$id")['code'] === 404, 'anyone else: 404');
$rf = result_of(sql_as(30, 'SELECT count(*) FROM curated.region_sales'));
$fr = get_json(30, '/runs/' . $rf['query_run_id'])['json']['data']['run'] ?? [];
ok(($fr['row_filtered_datasets'] ?? []) === ['curated.region_sales'] && !str_contains((string) json_encode($fr), 'ANY('), 'a row filter shown by the dataset it bounds — never its text');
$h = html_as(30, "/runs/$id");
ok(str_contains($h['body'], 'id="run-view"') && str_contains($h['body'], 'id="run-view-masks"'), 'the page (run-view): who, source, datasets, masks, filters, rows, time');

echo "9. The usage rollup fills the Usage tab and ranks search\n";
for ($i = 0; $i < 3; $i++) {
    sql_as(30, 'SELECT count(*) FROM curated.twin_a');
}
$tomorrow = (new DateTimeImmutable('tomorrow 02:00'))->format(DATE_ATOM);
$out = [];
exec('DL_WORKER_NOW=' . escapeshellarg($tomorrow) . ' php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=usage_rollup', $out);
$rep = json_decode((string) end($out), true) ?: [];
ok(($rep['ran']['usage_rollup']['days'] ?? 0) === 1 && ($rep['ran']['usage_rollup']['datasets'] ?? 0) >= 3, 'the pass rolled today (as "yesterday" of tomorrow 02:00): ' . json_encode($rep['ran']['usage_rollup'] ?? null));
$orders = ds_id('curated.sales_orders');
ok((int) pg1("SELECT queries FROM dataset_usage_daily WHERE dataset_id = $orders AND day = current_date") >= 5
   && (int) pg1("SELECT people FROM dataset_usage_daily WHERE dataset_id = $orders AND day = current_date") >= 2, 'dataset_usage_daily: today\'s queries and people on orders');
$exp = [];
exec('DL_WORKER_NOW=' . escapeshellarg($tomorrow) . ' php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php'), $exp);
$rep2 = json_decode((string) end($exp), true) ?: [];
ok(isset($rep2['ran']['usage_rollup']['done']), 'run again the same day without --passes: already done (once a day)');
ok((int) (pg1("SELECT dl_usage_30d($orders)")) >= 5 && str_contains(html_as(30, '/datasets/curated.sales_orders?tab=usage')['body'], 'id="dataset-usage'), 'the Usage tab and dl_usage_30d now count them');
as_member(30);
$twins = q("SELECT entity_id, usage_30d FROM dl_search('Twin events', '{}'::jsonb, 10, 0) WHERE entity_kind = 'dataset'");
$pair = [ds_id('curated.twin_a'), ds_id('curated.twin_b')];
$twins = array_values(array_filter($twins, static fn ($r) => in_array((int) $r['entity_id'], $pair, true)));
$ids = array_map(static fn ($r) => (int) $r['entity_id'], $twins);
ok(count($ids) === 2 && $ids[0] === ds_id('curated.twin_a') && (int) $twins[0]['usage_30d'] >= 3 && (int) $twins[1]['usage_30d'] === 0,
   'search ranks the twin queried three times above its unqueried twin (search_index.usage_30d refreshed by the pass)');

echo "   the home's Recent tile\n";
$home = get_json(30, '/');
$recent = array_column($home['json']['data']['recent'] ?? [], 'key');
ok(in_array('curated.sales_orders', $recent, true) && in_array('curated.region_sales', $recent, true) && !in_array('curated.payroll', $recent, true), 'Dana\'s Recent: what she queried (never what was refused)');
ok(!array_key_exists('recent', $home['json']['data']['pending'] ?? []), '…the tile is built (no longer pending)');
finish();
