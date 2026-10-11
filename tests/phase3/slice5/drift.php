<?php
/**
 * Slice 5 proof 3 — drift (contracts-quality.md "Proof" 3; divergence 2): a pending added column of the raw input accepted → the contract's next
 * version DRAFTED as a minor (never published); a removed column accepted → the draft becomes a major without the column that read it; a third
 * change rejected → recorded, nothing drafted; an agent's drift_decide refused (people); a Member's too; the schema check fails while a
 * breaking change waits; a rescued column is refused with what to do.
 */
require __DIR__ . '/lib.php';
$raw = ds_id('raw.orders');
$cur = ds_id('curated.orders');
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');
$pending = static fn (): array => pg("SELECT id || '|' || version || '|' || changes::text || '|' || breaking FROM schema_versions WHERE dataset_id = $raw AND decision = 'pending' ORDER BY version");

echo "1. A rescued column cannot be accepted as it is\n";
load_orders('orders_channel.csv');
$p = $pending();
ok(count($p) === 1 && str_contains($p[0], '"to": "_dl_rescued"'), 'raw.orders keeps new columns aside by default: channel rescued, a change pending');
$sv = (int) explode('|', $p[0])[0];
$r = post_json(27, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv, 'accept' => '1']);
ok($r['code'] === 422 && str_contains(message_of($r), 'was rescued') && str_contains(message_of($r), 'add new columns'), 'accepting it: refused, saying to let raw add new columns and replay');
ok(pg1("SELECT decision FROM schema_versions WHERE id = $sv") === 'pending', '…nothing recorded');
pg("UPDATE datasets SET evolution_mode = 'add' WHERE id = $raw; UPDATE schema_versions SET decision = 'rejected', decided_at = now() WHERE id = $sv;");

echo "2. An added column accepted → a minor version drafted\n";
load_orders('orders_channel_b.csv');
$p = $pending();
$sv = (int) explode('|', $p[0] ?? '0')[0];
ok(count($p) === 1 && str_contains($p[0], '"kind": "added"') && str_contains($p[0], 'channel') && !str_contains($p[0], '_dl_rescued'), 'raw.orders now adds the column: channel added, pending');
$tab = html_as(27, '/datasets/curated.orders?tab=quality');
ok(str_contains($tab['body'], 'id="quality-drift"') && str_contains($tab['body'], 'id="drift-' . $sv . '-accept"'), 'the curated dataset\'s Quality tab shows the change with Accept / Reject');
ok(agent_post(42, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv, 'accept' => '1'])['code'] === 403, 'an agent may not decide drift (people): 403');
ok(post_json(26, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv, 'accept' => '1'])['code'] === 403, 'a Member may not (contracts.manage): 403');
$ac = post_json(27, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv, 'accept' => '1']);
$draftId = (int) ($ac['json']['draft_contract_id'] ?? 0);
ok($ac['code'] === 200 && $draftId > 0 && pg1("SELECT decision || '|' || decided_by FROM schema_versions WHERE id = $sv") === 'accepted|27', 'Marco accepts: the decision recorded, a draft made');
$d = get_json(27, '/datasets/curated.orders/contract')['json']['data'] ?? [];
$names = array_column($d['draft']['schema'] ?? [], 'source', 'name');
ok(($d['draft']['version'] ?? '') === '1.1.0' && ($names['channel'] ?? '') === 'raw.orders.channel' && ($d['contract']['version'] ?? '') === '1.0.0',
   'the draft is 1.1.0 with channel (VARCHAR, reading raw.orders.channel); 1.0.0 stays active — nothing published');

echo "3. A removed column accepted → the draft becomes a major\n";
load_orders('orders_nostatus.csv');
$p = $pending();
$sv2 = (int) explode('|', $p[0] ?? '0')[0];
ok(count($p) === 1 && str_contains($p[0], '"kind": "removed"') && str_ends_with($p[0], '|true'), 'a file without status: removed, pending — breaking');
$run = post_json(27, '/quality/checks/run.php', ['dataset' => 'curated.orders', 'check' => check_id('curated.orders', 'schema')]);
wait_job((int) ($run['json']['job_id'] ?? 0), 60);
ok(pg1('SELECT passed::text || \'|\' || value FROM check_results WHERE check_id = ' . check_id('curated.orders', 'schema') . ' ORDER BY id DESC LIMIT 1') === 'false|1',
   'the schema check fails while the breaking change waits (one pending, on the raw input)');
$ac2 = post_json(27, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv2, 'accept' => '1']);
$d = get_json(27, '/datasets/curated.orders/contract')['json']['data'] ?? [];
$names = array_column($d['draft']['schema'] ?? [], 'name');
ok($ac2['code'] === 200 && (int) $ac2['json']['draft_contract_id'] === $draftId && ($d['draft']['version'] ?? '') === '2.0.0' && !in_array('status', $names, true) && in_array('channel', $names, true),
   'the same draft becomes 2.0.0: status gone, channel kept');
ok(($d['draft_breaking'][0]['kind'] ?? '') === 'removed' && ($d['draft_breaking'][0]['column'] ?? '') === 'status', '…its breaking change: status removed');

echo "4. A change rejected\n";
load_orders('orders_gift.csv');
$p = $pending();
$sv3 = (int) explode('|', $p[0] ?? '0')[0];
$rj = post_json(27, '/quality/drift.php', ['dataset' => 'curated.orders', 'schema_version' => $sv3, 'accept' => '0']);
$d = get_json(27, '/datasets/curated.orders/contract')['json']['data'] ?? [];
ok($rj['code'] === 200 && array_key_exists('draft_contract_id', $rj['json']) && $rj['json']['draft_contract_id'] === null && pg1("SELECT decision FROM schema_versions WHERE id = $sv3") === 'rejected'
   && !in_array('gift', array_column($d['draft']['schema'] ?? [], 'name'), true), 'gift rejected: recorded, the draft unchanged');
$log = activity('drift.decide', $a0);
$l0 = json_decode((string) ($log[0]['after'] ?? '{}'), true) ?: [];
ok(count($log) === 3 && ($l0['decision'] ?? '') === 'accepted' && ($l0['changes'] ?? []) === ['added'], 'drift.decide logged with the decision and the kinds of change');
finish();
