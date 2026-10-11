<?php
/**
 * Slice 5 proof 1 — contracts (contracts-quality.md "Proof" 1): a contract drafted from raw.orders' columns (the prefill: the names, the
 * mapping's types, the personal kind carried, the sources), the refusals (two raw inputs, a raw column that is not there, no key, a Member),
 * published 1.0.0 — the dataset's version, key and ordering recorded, the raw → curated edge; a published contract's edit refused (the PHP door
 * and the database's trigger); on curated.orders_v dropping a column as 1.1.0 is refused at publish (breaking), as 2.0.0 it is published with the
 * impact shown first; the breaking changes listed live (the form's fragment); every draft and publish logged.
 */
require __DIR__ . '/lib.php';
$raw = ds_id('raw.orders');
$cur = ds_id('curated.orders');
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');

echo "1. The draft's prefill from the raw input\n";
$f = get_json(27, "/datasets/curated.orders/contract/edit?raw=$raw");
$draft = $f['json']['data']['draft'] ?? [];
ok($f['code'] === 200 && count($draft['schema'] ?? []) === 6 && ($draft['raw'] ?? '') === 'raw.orders', 'Marco opens the draft from raw.orders: six columns, the raw input named');
$by = array_column($draft['schema'] ?? [], null, 'name');
ok(($by['order_id']['source'] ?? '') === 'raw.orders.order_id' && ($by['updated_at']['source'] ?? '') === 'raw.orders.updated_at', 'each column reads the raw column of its name (source = {raw key}.{column})');
ok(($by['customer_email']['personal_kind'] ?? null) === 'email' && ($by['customer_email']['classification'] ?? '') === 'confidential', 'the email column\'s personal kind and classification are carried from raw');
ok(!isset($by['_dl_load_id']) && ($draft['version'] ?? '') === '1.0.0', 'the provenance columns are left out; the first version suggested is 1.0.0');
ok(in_array('order_date', $f['json']['data']['raw_columns'] ?? [], true), 'the raw input\'s live columns come with it (the source select)');
ok(get_json(26, '/datasets/curated.orders/contract/edit')['code'] === 403, 'a Member may not draft a contract (contracts.manage): 403');
ok(get_json(27, '/datasets/raw.orders/contract/edit')['code'] === 422, 'a raw dataset has no contract (it is not promoted into): refused');

echo "2. The refusals in words, on their fields\n";
$two = orders_schema();
$two[1]['source'] = 'raw.crm_deals.buyer_email';
[$r] = contract_as(27, 'curated.orders', '1.0.0', $two, [], false);
ok($r['code'] === 422 && str_contains(message_of($r), 'promotes from one raw dataset — two inputs are a transform'), 'two raw inputs: "A curated dataset promotes from one raw dataset — two inputs are a transform"');
$gone = orders_schema();
$gone[2]['source'] = 'raw.orders.ordered_on';
[$r] = contract_as(27, 'curated.orders', '1.0.0', $gone, [], false);
ok($r['code'] === 422 && field_of($r) === 'columns.2.source' && str_contains(message_of($r), 'has no column ordered_on'), 'a raw column that is not there: refused on that column\'s source');
$nokey = array_map(static fn (array $c): array => ['primary_key' => false] + $c, orders_schema());
[$r] = contract_as(27, 'curated.orders', '1.0.0', $nokey, [], false);
ok($r['code'] === 422 && field_of($r) === 'key', 'no key: refused on the key (promotion keeps the latest row of each)');
[$r] = contract_as(27, 'curated.orders', '1.0', orders_schema(), [], false);
ok($r['code'] === 422 && field_of($r) === 'version', 'a version that is not major.minor.patch: refused on the version');
[$r] = contract_as(27, 'curated.orders', '1.0.0', orders_schema(), ['sla' => json_encode(['freshness' => 'one day'])], false);
ok($r['code'] === 422 && field_of($r) === 'sla.freshness', 'an SLA that is not an ISO 8601 duration: refused on its field');

echo "3. Saved, then published 1.0.0\n";
[$s, $p] = contract_as(27, 'curated.orders', '1.0.0', orders_schema(), ['sla' => json_encode(['freshness' => 'P1D', 'retention' => 'P2Y', 'time_to_detect' => 'PT1H'])]);
$cid = (int) ($s['json']['record_id'] ?? 0);
ok($s['code'] === 200 && $cid > 0 && ($s['json']['breaking'] ?? null) === [], 'the draft saved: its id, no breaking change (nothing active yet)');
ok(($p['code'] ?? 0) === 200 && ($p['json']['version'] ?? '') === '1.0.0', 'published 1.0.0');
ok(pg1("SELECT contract_version || '|' || array_to_string(dedupe_key, ',') || '|' || ordering_column FROM datasets WHERE id = $cur") === '1.0.0|order_id|updated_at',
   'the dataset records the version, the key and the ordering column (the database\'s publish)');
ok(pg1("SELECT count(*) FROM lineage_edges WHERE from_dataset_id = $raw AND to_dataset_id = $cur AND via = 'promote'") === '1', 'the lineage edge raw.orders → curated.orders (promote) is recorded');
$v = get_json(26, '/datasets/curated.orders/contract')['json']['data'] ?? [];
ok(($v['contract']['status'] ?? '') === 'active' && ($v['contract']['raw'] ?? '') === 'raw.orders' && count($v['contract']['schema'] ?? []) === 6
   && str_contains((string) ($v['contract']['sla_words'] ?? ''), 'Fresh within 1 day'), 'a Member reads the contract: active, its raw input, six columns, the SLA in words');
ok(($v['may']['edit'] ?? true) === false && ($v['impact'] ?? null) === null, '…and may not edit it; the impact is the engineer\'s');

echo "4. A published contract is frozen\n";
[$r] = contract_as(27, 'curated.orders', '1.0.1', orders_schema(), ['contract' => $cid], false);
ok($r['code'] === 422 && str_contains(message_of($r), 'published'), 'saving over the published contract is refused — draft a new version');
$frozen = '';
try {
    pdo()->exec("UPDATE contracts SET key = '{customer_email}' WHERE id = $cid");
} catch (PDOException $e) {
    $frozen = $e->getMessage();
}
ok(str_contains($frozen, 'A published contract is frozen'), 'the database refuses it too (the trigger: "A published contract is frozen — draft a new version")');

echo "5. Versions on curated.orders_v: a breaking change is a new major\n";
[, $p1] = contract_as(27, 'curated.orders_v', '1.0.0', orders_schema());
ok(($p1['code'] ?? 0) === 200, 'curated.orders_v 1.0.0 published');
$live = get_json(27, '/datasets/curated.orders_v/contract/edit?fragment=breaking&' . http_build_query(['schema' => json_encode(orders_schema('raw.orders', ['status'])), 'key' => ['order_id']]));
ok(($live['json']['data']['breaking'][0]['kind'] ?? '') === 'removed' && ($live['json']['data']['breaking'][0]['column'] ?? '') === 'status'
   && ($live['json']['data']['suggested_version'] ?? '') === '2.0.0', 'the form lists the breaking change live (status removed) and suggests 2.0.0');
$html = html_as(27, '/datasets/curated.orders_v/contract/edit?fragment=breaking&' . http_build_query(['schema' => json_encode(orders_schema('raw.orders', ['status'])), 'key' => ['order_id']]));
ok(str_contains($html['body'], 'id="contract-breaking"') && str_contains($html['body'], 'status removed'), '…as the fragment the form swaps in (contract-breaking)');
[$s11, $p11] = contract_as(27, 'curated.orders_v', '1.1.0', orders_schema('raw.orders', ['status']));
ok($s11['code'] === 200 && ($s11['json']['breaking'][0]['kind'] ?? '') === 'removed', 'the 1.1.0 draft without status saves, its breaking change returned');
ok(($p11['code'] ?? 0) === 422 && str_contains(message_of($p11), 'is a new major version'), 'publishing 1.1.0 is refused in the database\'s words: a breaking change is a new major version');
$view = get_json(27, '/datasets/curated.orders_v/contract')['json']['data'] ?? [];
ok(is_array($view['impact'] ?? null) && array_key_exists('saved_queries', $view['impact']) && ($view['draft']['version'] ?? '') === '1.1.0'
   && ($view['draft_breaking'][0]['column'] ?? '') === 'status', 'the contract page shows the draft, its breaking change and its impact before Publish');
$page = html_as(27, '/datasets/curated.orders_v/contract');
ok(str_contains($page['body'], 'id="contract-impact"') && strpos($page['body'], 'id="contract-impact"') < strpos($page['body'], 'id="contract-publish"'), '…the impact printed above the Publish button');
[$s2, $p2] = contract_as(27, 'curated.orders_v', '2.0.0', orders_schema('raw.orders', ['status']));
ok($s2['code'] === 200 && (int) $s2['json']['record_id'] === (int) $s11['json']['record_id'], 'the same draft saved again as 2.0.0 (one draft at a time)');
ok(($p2['code'] ?? 0) === 200 && ($p2['json']['version'] ?? '') === '2.0.0', '2.0.0 published');
$vs = get_json(27, '/datasets/curated.orders_v/contract')['json']['data']['versions'] ?? [];
ok(array_map(static fn (array $x): string => $x['version'] . ':' . $x['status'] . ':' . ($x['breaking'] ? 'b' : '-'), $vs) === ['2.0.0:active:b', '1.0.0:deprecated:-'],
   'the versions: 2.0.0 active and breaking, 1.0.0 deprecated');
ok(get_json(27, '/datasets/curated.orders_v/contract?version=1.0.0')['json']['data']['contract']['status'] === 'deprecated', 'an old version is readable by its number');

echo "6. Logged\n";
$drafts = activity('contract.draft', $a0);
$pubs = activity('contract.publish', $a0);
ok(count($drafts) === 4 && count($pubs) === 3, 'contract.draft and contract.publish logged (' . count($drafts) . ' drafts, ' . count($pubs) . ' publishes)');
$last = json_decode((string) end($pubs)['after'], true) ?: [];
ok(($last['version'] ?? '') === '2.0.0' && ($last['breaking'] ?? 0) === 1, '…the publish names the version and how many breaking changes');
finish();
