<?php
/**
 * Slice 4, proof 5 — the masks (access-policies.md "Proof" 5; access-and-policies §3): fifteen rows with an example each; email switched to
 * `hash` → the preview shows the keyed hash (the same value twice gives the same hash — joins still work); switched back; policies.manage only;
 * an agent's policy_set pauses (external_send).
 */
require __DIR__ . '/lib.php';
$orders = ds_id('curated.sales_orders');
pg("UPDATE masking_policies SET mask = 'partial_email' WHERE applies_to = 'email'");
$a0 = last_activity_id();
$j = get_json(1, '/access/policies')['json']['data'] ?? [];
ok(count($j['policies'] ?? []) === 15 && ($j['policies'][0]['applies_to'] ?? '') === 'email' && ($j['policies'][0]['example'] ?? '') === 'j***@example.com' && ($j['may_edit'] ?? false) === true,
   'the masks: fifteen rows in the screen\'s order, each with an example of the masked form; the admin may change them');
ok(get_json(27, '/access/policies')['code'] === 200 && (get_json(27, '/access/policies')['json']['data']['may_edit'] ?? true) === false && get_json(26, '/access/policies')['code'] === 403,
   'an engineer reads them (catalog.edit), may not change them; a Member does not open them');
$email = static function (): array { $p = preview_as(26, ds_id('curated.sales_orders')); $i = col_index($p, 'customer_email'); return [$p, $i === null ? [] : array_column($p['rows'] ?? [], $i)]; };
[$p0, $e0] = $email();
ok(($e0[0] ?? '') !== '' && preg_match('/^b\*\*\*@example\.invalid$/', (string) $e0[0]) === 1, 'Priya reads orders\' emails partial: ' . ($e0[0] ?? '?'));
ok(post_json(27, '/access/policies/save.php', ['applies_to' => 'email', 'mask' => 'hash'])['code'] === 403, 'policies.manage only: Marco 403');
$s = post_json(1, '/access/policies/save.php', ['applies_to' => 'email', 'mask' => 'hash']);
ok($s['code'] === 200 && pg1("SELECT mask || '|' || updated_by FROM masking_policies WHERE applies_to = 'email'") === 'hash|1', 'the admin sets email → hash');
[$p1, $e1] = $email();
$first = (string) ($e1[0] ?? '');
$same = array_values(array_filter(range(0, count($e1) - 1), static fn ($i) => $i > 0 && ($p1['rows'][$i][col_index($p1, 'order_id')] ?? 0) % 900 === ($p1['rows'][0][col_index($p1, 'order_id')] ?? 0) % 900));
ok(preg_match('/^h:[0-9a-f]{16}$/', $first) === 1 && in_array('customer_email', $p1['masked_columns'] ?? [], true), 'from her next preview the email is the keyed hash: ' . $first);
ok(count(array_unique($e1)) < count($e1) || $same === [] || ($e1[$same[0]] ?? '') === $first, 'the same email hashes the same — a join on it still works');
ok(post_json(1, '/access/policies/save.php', ['applies_to' => 'email', 'mask' => 'shout'])['code'] === 422 && post_json(1, '/access/policies/save.php', ['applies_to' => 'shoe_size', 'mask' => 'full'])['code'] === 422,
   'an unknown mask or subject is refused');
$log = activity('policy.set', $a0)[0] ?? [];
ok(str_contains((string) ($log['before'] ?? ''), 'partial_email') && str_contains((string) ($log['after'] ?? ''), '"hash"'), 'logged policy.set: what it applies to, the mask before and after');
ok(post_json(1, '/access/policies/save.php', ['applies_to' => 'email', 'mask' => 'partial_email'])['code'] === 200 && preg_match('/^b\*\*\*@/', (string) ($email()[1][0] ?? '')) === 1, 'switched back: partial again');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['policy_set']['approval'] ?? '') === 'external_send', 'an agent\'s policy_set pauses for a person (external_send)');
ok(agent_post(40, '/access/policies/save.php', ['applies_to' => 'email', 'mask' => 'full'])['code'] === 403, 'and relayed, the Expert (a Member) holds no policies.manage: 403');
finish();
