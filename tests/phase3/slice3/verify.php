<?php
/**
 * Slice 3 — verified queries (query-service-editor.md "Proof" 5): an engineer verifies (the badge with his name); an Analyst may not; editing
 * the SQL drops the badge BY ITSELF ("Unverified — the SQL changed"); a title change keeps it; unverify removes it; an agent's query_verify
 * pauses for approval (`other` — the registry and maludb-os.json approvals[], the kernel's hook acts on them); query.verify logged.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;
$tag = run_tag();
$s = post_json(30, '/queries/save.php', ['title' => "SMOKE verify me $tag", 'sql' => 'SELECT region, count(*) FROM curated.sales_orders GROUP BY region', 'shared' => 'yes']);
$id = (int) $s['json']['record_id'];

echo "5. Verify by an engineer; an edit of the SQL drops the badge by itself\n";
ok(post_json(30, '/queries/verify.php', ['saved_query' => $id, 'verify' => 'yes'])['code'] === 403, 'an Analyst may not verify (queries.verify)');
$v = post_json(27, '/queries/verify.php', ['saved_query' => $id, 'verify' => 'yes']);
ok($v['code'] === 200 && pg1("SELECT verified_by FROM saved_queries WHERE id = $id") === '27', 'Marco (a Data engineer) verifies it');
$row = get_json(26, "/queries/$id")['json']['data']['saved_query'] ?? [];
ok(($row['verified'] ?? false) === true && ($row['verified_by_name'] ?? '') === 'SMOKE Marco', '…readers see "Verified by SMOKE Marco"');
ok(str_contains(html_as(26, '/queries?q=' . rawurlencode($tag))['body'], 'Verified by SMOKE Marco'), '…on its card');
post_json(30, '/queries/save.php', ['saved_query' => $id, 'title' => "SMOKE verify me $tag", 'description' => 'Only the words change.']);
ok(pg1("SELECT verified_at IS NOT NULL FROM saved_queries WHERE id = $id") === 't', 'a change of its words keeps the badge');
post_json(30, '/queries/save.php', ['saved_query' => $id, 'sql' => 'SELECT region, count(*) AS n FROM curated.sales_orders GROUP BY region']);
ok(pg1("SELECT verified_at IS NULL FROM saved_queries WHERE id = $id") === 't' && pg1("SELECT unverified_reason FROM saved_queries WHERE id = $id") === 'the SQL changed after it was verified',
   'editing the SQL drops the badge BY ITSELF (the database\'s trigger)');
$card = html_as(26, '/queries?q=' . rawurlencode($tag))['body'];
ok(str_contains($card, 'Unverified — the SQL changed'), '…its card says "Unverified — the SQL changed"');
post_json(27, '/queries/verify.php', ['saved_query' => $id, 'verify' => 'yes']);
$u = post_json(27, '/queries/verify.php', ['saved_query' => $id, 'verify' => 'no']);
ok($u['code'] === 200 && pg1("SELECT verified_at IS NULL AND unverified_reason = 'unverified by a person' FROM saved_queries WHERE id = $id") === 't', 'unverify removes the badge, in words');
$log = activity('query.verify');
ok(count($log) >= 3 && (json_decode((string) end($log)['after'], true)['verified'] ?? null) === false, 'query.verify logged on and off');

echo "   an agent's query_verify pauses (other)\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$app = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/maludb-os.json'), true);
$cats = [];
foreach ($app['approvals'] ?? [] as $a) {
    $cats[$a['action'] ?? $a['log_event'] ?? ''] = $a['category'] ?? null;
}
ok(($reg['actions']['query_verify']['approval'] ?? null) === 'other' && ($reg['actions']['query_verify']['log'] ?? $reg['actions']['query_verify']['log_event'] ?? '') !== '',
   'the registry gives query_verify the `other` category (the kernel\'s hook pauses an agent\'s)');
ok(in_array('other', array_values($cats), true) && (($cats['query_verify'] ?? $cats['query.verify'] ?? null) === 'other'), 'maludb-os.json approvals[] pauses it');
finish();
