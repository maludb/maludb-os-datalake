<?php
/**
 * Slice 4, proof 6 — classification and personal data (access-policies.md "Proof" 6; ★3, ★15): a column set restricted → empty below
 * read_unmasked and the dataset reclassified; the classifier's ≥ 0.8 mask confirmed and a proposal rejected by a person; an agent's
 * personal_confirm refused (people only); an agent's column_classify pauses; the personal-data map.
 */
require __DIR__ . '/lib.php';
$orders = ds_id('curated.sales_orders');
$deals = ds_id('raw.crm_deals');
pg("UPDATE dataset_columns SET classification = 'internal', classified_by = 'rule', classification_confirmed_by = NULL, classification_confirmed_at = NULL WHERE dataset_id = $orders AND name = 'region'");
pg("UPDATE dataset_columns SET personal_kind = 'email', classified_by = 'rule', classification_confirmed_by = NULL, classification_confirmed_at = NULL WHERE dataset_id = $deals AND name = 'buyer_email'");
pg("UPDATE dataset_columns SET personal_kind = NULL, proposed_personal_kind = 'phone', proposed_confidence = 0.6, classified_by = 'rule', classification_confirmed_by = NULL, classification_confirmed_at = NULL WHERE dataset_id = $deals AND name = 'contact'");
$a0 = last_activity_id();

echo "1. A column classified restricted: empty below read unmasked, the dataset reclassified\n";
$before = pg1("SELECT classification FROM datasets WHERE id = $orders");
$c = post_json(27, '/access/columns/classify.php', ['dataset' => $orders, 'column' => 'region', 'classification' => 'restricted']);
ok($c['code'] === 200 && pg1("SELECT classification || '|' || classified_by || '|' || (classification_confirmed_at IS NOT NULL) FROM dataset_columns WHERE dataset_id = $orders AND name = 'region'") === 'restricted|person|true',
   'Marco (catalog.edit) sets orders.region restricted — by a person, confirmed');
ok(pg1("SELECT classification FROM datasets WHERE id = $orders") === 'restricted' && $before !== 'restricted', "the dataset is reclassified $before → restricted (derived)");
$pv = preview_as(26, $orders);
$ri = col_index($pv, 'region');
ok(($pv['status'] ?? '') === 'ok' && $ri !== null && array_unique(array_column($pv['rows'], $ri)) === [null], 'Priya (read, masked) previews orders: region empty (restricted → null)');
$log = activity('column.classify', $a0)[0] ?? [];
ok(str_contains((string) $log['before'], '"internal"') && str_contains((string) $log['after'], '"restricted"'), 'logged column.classify: from internal to restricted');
ok(post_json(26, '/access/columns/classify.php', ['dataset' => $orders, 'column' => 'region', 'classification' => 'public'])['code'] === 403, 'a Member may not classify (catalog.edit): 403');
$k = post_json(27, '/access/columns/classify.php', ['dataset' => $orders, 'column' => 'region', 'classification' => 'public', 'personal_kind' => 'address']);
ok($k['code'] === 200 && pg1("SELECT classification || '|' || personal_kind FROM dataset_columns WHERE dataset_id = $orders AND name = 'region'") === 'confidential|address',
   'a personal kind keeps a column at least confidential — the database raises "public" (as Marco asked) to confidential');
post_json(27, '/access/columns/classify.php', ['dataset' => $orders, 'column' => 'region', 'classification' => 'internal', 'personal_kind' => 'none']);
ok(pg1("SELECT classification || '|' || COALESCE(personal_kind, '-') FROM dataset_columns WHERE dataset_id = $orders AND name = 'region'") === 'internal|-', 'set back: internal, no personal kind');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['column_classify']['approval'] ?? '') === 'external_send' && ($reg['actions']['personal_confirm']['agent'] ?? true) === false,
   'an agent\'s column_classify pauses (external_send); personal_confirm is for people');

echo "2. Personal data: confirmed and rejected by a person — never an agent\n";
$map = get_json(27, "/access/personal-data?dataset=$deals")['json']['data']['columns'] ?? [];
$by = array_column($map, null, 'name');
ok(($by['buyer_email']['personal_kind'] ?? '') === 'email' && ($by['buyer_email']['classified_by'] ?? '') === 'rule' && ($by['buyer_email']['may_confirm'] ?? false) === true
   && ($by['contact']['proposed_kind'] ?? '') === 'phone' && abs(($by['contact']['proposed_confidence'] ?? 0) - 0.6) < 0.001, 'the map: the email masked by the rule (★3, waiting for a person), the phone proposed at 60%');
$ag = agent_post(42, '/access/columns/confirm.php', ['dataset' => $deals, 'column' => 'buyer_email', 'accept' => 'yes']);
ok($ag['code'] === 403, 'the Steward (an agent) confirming: 403 — people only (★15)');
$ok1 = post_json(27, '/access/columns/confirm.php', ['dataset' => $deals, 'column' => 'buyer_email', 'accept' => 'yes']);
ok($ok1['code'] === 200 && pg1("SELECT personal_kind || '|' || classified_by || '|' || classification_confirmed_by FROM dataset_columns WHERE dataset_id = $deals AND name = 'buyer_email'") === 'email|person|27',
   'Marco (its owner) confirms the email — by a person');
$no = post_json(27, '/access/columns/confirm.php', ['dataset' => $deals, 'column' => 'contact', 'accept' => 'no']);
ok($no['code'] === 200 && pg1("SELECT COALESCE(personal_kind, '-') || '|' || COALESCE(proposed_personal_kind, '-') || '|' || (classification_confirmed_at IS NOT NULL) FROM dataset_columns WHERE dataset_id = $deals AND name = 'contact'") === '-|-|true',
   'he rejects the phone proposal: not personal, confirmed so (the classifier never overrides it)');
ok(post_json(26, '/access/columns/confirm.php', ['dataset' => ds_id('product.monthly_revenue'), 'column' => 'region', 'accept' => 'yes', 'personal_kind' => 'address'])['code'] === 403,
   'Priya (neither owner, steward nor engineer) may not confirm');
$waiting = get_json(1, '/access/personal-data?confirmed=no')['json']['data']['columns'] ?? [];
ok(!in_array('buyer_email', array_column(array_filter($waiting, static fn ($x) => $x['dataset']['id'] === $deals), 'name'), true), 'the email leaves "waiting for a person"');
$cl = activity('column.confirm', $a0);
ok(count($cl) === 2 && (json_decode((string) $cl[0]['after'], true)['accepted'] ?? null) === true && (json_decode((string) $cl[0]['after'], true)['personal_kind'] ?? '') === 'email'
   && (json_decode((string) $cl[1]['after'], true)['accepted'] ?? null) === false, 'logged column.confirm: accepted (the kind) and rejected');
ok(get_json(29, '/access/personal-data')['code'] === 403, 'an external does not open the map');
finish();
