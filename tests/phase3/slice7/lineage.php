<?php
/**
 * Slice 7 proof 3 — lineage (transforms-products-lineage.md "Proof"): the dataset page's Lineage tab shows one hop each way — the dataset and the edge's via, "1 hidden" for an input the viewer
 * may not discover; the graph's SVG has five layered columns (depth −2 … +2), sources as rounded nodes at depth 3, the hidden ones collapsed into one node per column; a transform that stops
 * reading an input loses that edge; `impact` lists the metric and the transform downstream (hidden ones counted, the applications named only to the owner); the walls (an external,
 * a stub); the JSON of the tab and the graph.
 */
require __DIR__ . '/lib.php';
$std = standard_transforms();
$m = (int) pg1("SELECT id FROM metrics WHERE name = 's7_revenue' AND retired_at IS NULL");
if ($m === 0) {
    $m = (int) (post_json(27, '/metrics/save.php', ['name' => 's7_revenue', 'title' => 'S7 Revenue', 'dataset' => ds_id('product.s7_monthly'), 'expression' => 'sum(revenue)', 'time_column' => 'month', 'grains' => 'month,quarter,year', 'dimensions' => 'region'])['json']['record_id'] ?? 0);
}
$ord = ds_id('curated.s7_orders');
$monthly = ds_id('product.s7_monthly');
$regions = ds_id('product.s7_regions');
$top = ds_id('product.s7_top');
$blend = ds_id('product.s7_blend');
$tab = static fn (int $who, string $key): array => get_json($who, "/datasets/$key?tab=lineage")['json']['data'] ?? [];

echo "1. The Lineage tab — one hop each way\n";
$h = html_as(27, '/datasets/product.s7_regions?tab=lineage');
ok($h['code'] === 200 && str_contains($h['body'], 'id="dataset-lineage"') && str_contains($h['body'], 'id="lineage-up"') && str_contains($h['body'], 'id="lineage-down"') && !str_contains($h['body'], 'Built by slice 7'), 'the tab is built (no "Built by slice 7")');
ok(str_contains($h['body'], 'id="lineage-up-' . $monthly . '"') && str_contains($h['body'], 'id="lineage-down-' . $top . '"') && !str_contains($h['body'], 'id="lineage-up-' . $ord . '"'),
   'product.s7_regions: one hop up is product.s7_monthly, one hop down is product.s7_top — not the orders two hops away');
ok(str_contains($h['body'], 'id="dataset-lineage-graph-link"') && str_contains($h['body'], 'href="/datasets/' . $regions . '/lineage"') && str_contains($h['body'], 'id="dataset-lineage-maker"') && str_contains($h['body'], 's7_regions_t'),
   'Open the graph, and the transform that makes it, are there');
$j = $tab(27, 'product.s7_regions');
$tabs = array_column($j['tabs'] ?? [], 'built', 'name');
ok(($tabs['lineage'] ?? false) === true, 'the dataset page\'s JSON says the Lineage tab is built');
$m1 = get_json(27, "/datasets/$monthly/lineage?depth=1")['json']['data'] ?? [];
$up = $m1['upstream']['nodes'] ?? [];
$dn = $m1['downstream']['nodes'] ?? [];
ok(count($up) === 1 && $up[0]['key'] === 'curated.s7_orders' && $up[0]['via'] === 'transform' && ($up[0]['transform']['name'] ?? '') === 's7_monthly_t' && $up[0]['columns_traced'] >= 3 && $up[0]['last_seen'] !== null,
   'product.s7_monthly one hop up: curated.s7_orders via the transform s7_monthly_t, columns traced, last seen');
ok(count($dn) === 1 && $dn[0]['key'] === 'product.s7_regions' && $dn[0]['depth'] === 1, 'one hop down: product.s7_regions');
ok(($m1['upstream']['hidden'] ?? -1) === 0 && ($m1['downstream']['hidden'] ?? -1) === 0, 'nothing hidden');

echo "2. \"1 hidden\" for an input the viewer may not discover\n";
$h = html_as(27, '/datasets/product.s7_blend?tab=lineage');
ok(str_contains($h['body'], 'id="lineage-up-hidden"') && str_contains($h['body'], '1 hidden') && str_contains($h['body'], 'id="lineage-up-' . $ord . '"') && !str_contains($h['body'], 's7_secret'),
   'product.s7_blend as Marco: curated.s7_orders and curated.s7_customers are named; the secret table is "1 hidden" and never named');
$jb = get_json(27, "/datasets/$blend/lineage?depth=1")['json']['data'] ?? [];
ok(($jb['upstream']['hidden'] ?? 0) === 1 && count($jb['upstream']['nodes'] ?? []) === 2 && !str_contains(json_encode($jb), 's7_secret'), 'the JSON says hidden: 1, and says nothing of what it is');
$ja = get_json(1, "/datasets/$blend/lineage?depth=1")['json']['data'] ?? [];
ok(($ja['upstream']['hidden'] ?? -1) === 0 && count($ja['upstream']['nodes'] ?? []) === 3, 'the owner sees all three inputs, none hidden');
ok(str_contains(html_as(26, '/datasets/product.s7_blend?tab=lineage')['body'], '1 hidden'), 'Priya (a Member) sees "1 hidden" too');
$ann = get_json(29, "/datasets/$blend/lineage");
ok(in_array($ann['code'], [403, 404], true), 'an external may not open the graph of a dataset she cannot discover: ' . $ann['code']);
$stub = get_json(26, '/datasets/' . ds_id('curated.s7_secret') . '/lineage');
ok($stub['code'] === 404, 'a stub (Priya may know the secret table exists, not open it): the graph is the same 404');

echo "3. The graph\n";
$g = html_as(27, "/datasets/$monthly/lineage");
ok($g['code'] === 200 && str_contains($g['body'], 'id="lineage-graph-svg"') && str_contains($g['body'], 'data-columns="5"') && substr_count($g['body'], 'class="lg-column"') === 5, 'the SVG has five layered columns');
foreach (['curated.s7_orders' => -1, 'raw.s7_orders_raw' => -2, 'product.s7_regions' => 1, 'product.s7_top' => 2] as $key => $col) {
    ok(str_contains($g['body'], 'id="lg-node-d' . ds_id($key) . '"'), "$key is in the graph (column $col)");
}
ok(str_contains($g['body'], 'id="lg-node-c' . $monthly . '"') && str_contains($g['body'], 'lg-centre'), 'the dataset itself in the middle');
ok(substr_count($g['body'], '<path class="lg-edge') >= 4 && str_contains($g['body'], 'lg-via-transform') && str_contains($g['body'], 'lg-via-promote'), 'its edges are drawn: transform and promote');
ok(!str_contains($g['body'], 'id="lg-node-s'), 'two hops: no source yet');
$g3 = html_as(27, "/datasets/$monthly/lineage?depth=3");
ok(str_contains($g3['body'], 'data-columns="7"') && str_contains($g3['body'], 'lg-source') && str_contains($g3['body'], 'SMOKE S7 feed'), 'depth 3: seven columns, and the source as a rounded grey node with its name (Marco may see sources)');
$g3p = html_as(26, "/datasets/$monthly/lineage?depth=3");
ok(str_contains($g3p['body'], 'lg-source') && !str_contains($g3p['body'], 'SMOKE S7 feed') && str_contains($g3p['body'], 'a source'), 'Priya, who may not see sources, gets the node without its name');
$g1 = html_as(27, "/datasets/$monthly/lineage?depth=1");
ok(str_contains($g1['body'], 'data-columns="3"') && substr_count($g1['body'], 'class="lg-column"') === 3, 'depth 1: three columns');
$gb = html_as(27, "/datasets/$blend/lineage");
ok(str_contains($gb['body'], 'class="lg-node lg-hidden"') && str_contains($gb['body'], '1 hidden') && !str_contains($gb['body'], 's7_secret'), 'product.s7_blend\'s graph: a dashed "1 hidden" node in its column — the hidden dataset never named');
ok(preg_match('/<svg id="lineage-graph-svg"[^>]* viewBox="0 0 \d+ \d+"/', $g['body']) === 1 && str_contains($g['body'], 'role="img"') && str_contains($g['body'], 'aria-label="Lineage of product.s7_monthly'), 'the SVG scales by its viewBox and has a text alternative');
ok(str_contains($g['body'], 'id="lineage-graph-up"') && str_contains($g['body'], 'id="lineage-graph-down"') && str_contains($g['body'], 'id="lineage-impact"'), 'the hop lists and the impact sit under the graph (what a phone reads)');
ok(substr_count(html_as(27, "/datasets/$monthly/lineage?depth=9")['body'], 'class="lg-column"') === 9, 'depth is capped at 4 (nine columns)');

echo "4. Impact\n";
$io = get_json(27, "/datasets/$ord/lineage")['json']['data']['impact'] ?? [];
ok(in_array('s7_monthly_t', array_column($io['transforms'] ?? [], 'name'), true) && in_array('s7_regions_t', array_column($io['transforms'] ?? [], 'name'), true), 'impact of the orders lists the transforms downstream (s7_monthly_t, s7_regions_t)');
ok(in_array('s7_revenue', array_column($io['metrics'] ?? [], 'name'), true), 'and the metric defined over the product (s7_revenue)');
ok(array_intersect(['product.s7_monthly', 'product.s7_regions', 'product.s7_top'], array_column($io['datasets'] ?? [], 'key')) === ['product.s7_monthly', 'product.s7_regions', 'product.s7_top'], 'and the datasets: monthly, regions, top');
$ip = get_json(26, "/datasets/$ord/lineage")['json']['data']['impact'] ?? [];
ok(in_array('product.s7_blend', array_column($ip['datasets'] ?? [], 'key'), true) && !in_array('sandbox.m30_s7_mine', array_column($ip['datasets'] ?? [], 'key'), true),
   'Priya sees the blend downstream (everyone reads it) — and not Dana\'s sandbox cut, which is counted in `hidden` when it is there');
pg("INSERT INTO saved_queries (owner_member_id, title, sql, dataset_ids, shared) VALUES (1, 'SMOKE S7 private', 'SELECT 1', ARRAY[$monthly]::bigint[], false)");
$io2 = get_json(26, "/datasets/$ord/lineage")['json']['data']['impact'] ?? [];
$io3 = get_json(1, "/datasets/$ord/lineage")['json']['data']['impact'] ?? [];
ok(($io2['hidden'] ?? 0) >= 1 && !in_array('SMOKE S7 private', array_column($io2['saved_queries'] ?? [], 'title'), true) && in_array('SMOKE S7 private', array_column($io3['saved_queries'] ?? [], 'title'), true),
   'a private saved query of the owner\'s is counted, not named, for Priya — named for its owner');
$gl = get_json(27, '/datasets/' . ds_id('product.monthly_revenue') . '/lineage')['json']['data']['impact'] ?? [];
$glp = get_json(26, '/datasets/' . ds_id('product.monthly_revenue') . '/lineage')['json']['data']['impact'] ?? [];
ok(($gl['consumers'] ?? null) === ['gl'] && array_key_exists('consumers', $glp) && $glp['consumers'] === null && ($glp['consumers_count'] ?? 0) === 1, 'the application that reads a product (gl) is named to its owner, only counted for Priya');
$hp = html_as(27, '/datasets/' . ds_id('product.monthly_revenue') . '/lineage');
ok(str_contains($hp['body'], 'id="lineage-impact-consumers"') && str_contains($hp['body'], '<code>gl</code>'), 'the page shows it to the owner');

echo "5. A transform that stops reading an input loses its edge\n";
$blendEdges = static fn (): int => (int) pg1("SELECT count(*) FROM lineage_edges WHERE to_dataset_id = $blend AND via = 'transform'");
ok($blendEdges() === 3, 'the blend has three edges (orders, secret, customers)');
transform_save_as(1, ['transform' => $std['blend'], 'sql' => 'SELECT o.order_id, o.region, c.email FROM curated.s7_orders o LEFT JOIN curated.s7_customers c ON c.customer_id = o.order_id']);
[, $rr] = run_transform(1, $std['blend']);
ok($rr['status'] === 'ok' && $blendEdges() === 2 && pg1("SELECT count(*) FROM lineage_edges WHERE to_dataset_id = $blend AND from_dataset_id = " . ds_id('curated.s7_secret')) === '0', 'rewritten without the secret table and run: its edge is pruned (2 left)');
ok(($tab(27, 'product.s7_blend')['dataset']['key'] ?? '') === 'product.s7_blend' && str_contains(html_as(27, '/datasets/product.s7_blend?tab=lineage')['body'], 'id="lineage-up-hidden"') === false, 'and nothing is hidden from Marco any more');
ok(pg1("SELECT count(*) FROM transforms WHERE id = {$std['blend']} AND version >= 2") === '1', '(version 2)');

echo "6. Json\n";
$d = get_json(27, "/datasets/$regions/lineage?depth=2")['json']['data'] ?? [];
ok(array_keys($d) === ['dataset', 'depth', 'upstream', 'downstream', 'impact', 'svg'] && ($d['svg']['columns'] ?? 0) === 5 && ($d['upstream']['direction'] ?? '') === 'up' && ($d['downstream']['direction'] ?? '') === 'down',
   'the graph\'s JSON: dataset, depth, upstream, downstream, impact, svg.columns');
$node = $d['upstream']['nodes'][0] ?? [];
ok(array_keys($node) === ['kind', 'depth', 'via', 'last_seen', 'dataset_id', 'key', 'title', 'zone', 'certification', 'transform', 'columns_traced', 'url'], 'a node: kind, depth, via, last_seen, dataset, key, title, zone, certification, transform, columns_traced, url');
ok(!str_contains(json_encode($d), 'frozen_policy') && !str_contains(json_encode($d), 'column_map'), 'no policy, no raw column map');
$d1 = get_json(27, "/transforms/{$std['regions']}")['json']['data'] ?? [];
ok(count($d1['edges'] ?? []) === 1 && ($d1['edges'][0]['from'] ?? '') === 'product.s7_monthly' && ($d1['edges'][0]['columns_traced'] ?? 0) >= 1, 'a transform\'s JSON lists the edges it makes with the columns traced');
$tv = html_as(27, "/transforms/{$std['regions']}?tab=lineage");
ok(str_contains($tv['body'], 'id="transform-edges"') && str_contains($tv['body'], 'product.s7_monthly'), 'and its Lineage tab shows them');
$tvb = html_as(26, "/transforms/{$std['monthly']}");
ok($tvb['code'] === 200 && str_contains($tvb['body'], 'id="transform-sql"'), 'Priya, who reads the orders, sees the SQL');
// a transform whose inputs the viewer may not read hides its SQL and counts the inputs
pg("UPDATE transforms SET input_dataset_ids = input_dataset_ids || ARRAY[" . ds_id('curated.s7_secret') . "]::bigint[] WHERE id = {$std['monthly']}");
$tvh = html_as(26, "/transforms/{$std['monthly']}");
$tj = get_json(26, "/transforms/{$std['monthly']}")['json']['data']['transform'] ?? [];
ok(str_contains($tvh['body'], 'id="transform-sql-hidden"') && !str_contains($tvh['body'], 'id="transform-sql"') && array_key_exists('sql', $tj) && $tj['sql'] === null && ($tj['inputs_hidden'] ?? 0) === 1 && ($tj['may_read_sql'] ?? true) === false,
   'an input she may not read: the SQL is not shown (page and JSON) and the input is counted, "1 hidden"');
pg("UPDATE transforms SET input_dataset_ids = array_remove(input_dataset_ids, " . ds_id('curated.s7_secret') . ") WHERE id = {$std['monthly']}");
finish();
