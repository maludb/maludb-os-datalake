<?php
/*
 * Proof: the glossary (catalog.md "Proof" 9) — a term made as a draft, its name unique case aside (said on the field), a category with no
 * cycle, approved by a person (an agent holding the right is refused: `people`), deprecated (it leaves the picker and the search, its columns
 * keep the link); the term's columns list only the datasets the reader may discover; the cards grouped by category.
 */
require __DIR__ . '/lib.php';
$ids = seed_catalog();
$t = run_tag();
$orders = $ids['curated.sales_orders'];

echo "9. Terms\n";
$r = post_json(27, '/glossary/save.php', ['name' => 'Revenue ' . $t, 'definition' => 'Money billed for goods shipped, net of returns.', 'synonyms' => 'turnover, sales ' . $t]);
$cat = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $cat > 0 && pg1("SELECT status FROM glossary_terms WHERE id = $cat") === 'draft', 'an engineer adds a term: a draft');
ok(pg1("SELECT array_to_string(synonyms, '|') FROM glossary_terms WHERE id = $cat") === 'turnover|sales ' . $t, 'its synonyms from a comma-separated field');
$r = post_json(27, '/glossary/save.php', ['name' => 'REVENUE ' . strtoupper($t), 'definition' => 'Again.']);
ok($r['code'] === 422 && field_of($r) === 'name' && str_contains(message_of($r), 'There is already a term named'), 'the same name, case aside: 422 on the name field');
ok(post_json(26, '/glossary/save.php', ['name' => 'Mine ' . $t, 'definition' => 'x'])['code'] === 403, 'a Member may not add a term: 403');
ok(post_json(27, '/glossary/save.php', ['name' => 'Empty ' . $t, 'definition' => ''])['code'] === 422, 'a term needs a definition: 422');
$r = post_json(27, '/glossary/save.php', ['name' => 'Net revenue ' . $t, 'definition' => 'Revenue less discounts.', 'parent' => $cat, 'steward' => 30]);
$child = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && (int) pg1("SELECT parent_id FROM glossary_terms WHERE id = $child") === $cat && (int) pg1("SELECT steward_member_id FROM glossary_terms WHERE id = $child") === 30, 'a term in a category, with a steward');
$r = post_json(27, '/glossary/save.php', ['term' => $cat, 'parent' => $child]);
ok($r['code'] === 422 && field_of($r) === 'parent', 'a category may not sit under one of its own terms: 422 on parent');
$r = post_json(27, '/glossary/save.php', ['term' => $child, 'definition' => 'Revenue less discounts and rebates.']);
ok($r['code'] === 200 && pg1("SELECT definition FROM glossary_terms WHERE id = $child") === 'Revenue less discounts and rebates.', 'term_update changes only what it sends');
$l = activity('term.update');
ok(isset(json_decode((string) end($l)['after'], true)['definition']['length']) && !str_contains((string) end($l)['after'], 'rebates'), 'logged term.update with the definition by its length');
$g = html_as(26, '/glossary?q=' . rawurlencode($t))['body'];
ok(str_contains($g, 'id="term-card-' . $cat . '"') && str_contains($g, 'id="term-card-' . $child . '"') && preg_match('/>Revenue ' . $t . '<\/h6>.*term-card-' . $child . '/s', $g) === 1,
    'the glossary shows them as cards, grouped under the category');

echo "Approve — a person\n";
pg("UPDATE members SET roles = '{engineer,analyst,user}' WHERE id = 42");
set_run_facts(9101, 42, 'duty', null);
$r = req('POST', '/glossary/approve.php', ['headers' => as_agent(run_token(42, 9101)), 'form' => ['term' => $cat]]);
ok($r['code'] === 403 && str_contains((string) (body_json($r)['error']['message'] ?? ''), 'This is for people'), 'the Steward agent, even holding catalog.edit, may not approve: 403 (people)');
pg("UPDATE members SET roles = '{user}' WHERE id = 42");
ok(pg1("SELECT status FROM glossary_terms WHERE id = $cat") === 'draft', '…the term is still a draft');
ok(post_json(26, '/glossary/approve.php', ['term' => $cat])['code'] === 403, 'a Member may not approve: 403');
$r = post_json(27, '/glossary/approve.php', ['term' => $cat]);
$row = q('SELECT status, approved_by, approved_at FROM glossary_terms WHERE id = :i', ['i' => $cat])[0];
ok($r['code'] === 200 && $row['status'] === 'approved' && (int) $row['approved_by'] === 27 && $row['approved_at'] !== null, 'an engineer (a person) approves it');
ok(post_json(27, '/glossary/approve.php', ['term' => $cat])['code'] === 422, 'approving it again: 422 (only a draft is approved)');
ok(str_contains(html_as(26, "/glossary/$cat")['body'], 'id="term-approved"') && !str_contains(html_as(27, "/glossary/$cat")['body'], 'id="term-approve-btn"'), 'its page says who approved it; no Approve button any more');

echo "The columns that carry it — discoverable datasets only\n";
post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'term' => $child]);
pg("UPDATE dataset_columns SET glossary_term_id = $child WHERE dataset_id = {$ids['curated.payroll']} AND name = 'salary'");
$v = get_json(26, "/glossary/$child")['json']['data'] ?? [];
$keys = array_column($v['columns'] ?? [], 'key');
ok(in_array('curated.sales_orders', $keys, true) && !in_array('curated.payroll', $keys, true), 'Priya sees the orders\' region carry it, never the payroll\'s salary (a stub to her)');
$h = html_as(26, "/glossary/$child")['body'];
ok(str_contains($h, 'curated.sales_orders.region') && !str_contains($h, 'payroll') && !str_contains($h, 'salary'), '…on the page too');
$v = get_json(1, "/glossary/$child")['json']['data'] ?? [];
ok(in_array('curated.payroll', array_column($v['columns'] ?? [], 'key'), true), 'the payroll\'s owner sees both');
ok((int) ($v['term']['column_count'] ?? 0) === 2 && ($v['term']['status'] ?? '') === 'draft', 'the count is the term\'s (get_term), the status draft');
ok(str_contains(html_as(26, '/datasets/curated.sales_orders')['body'], 'id="dataset-term-' . $child . '"'), 'the dataset\'s About lists the glossary terms of its columns');

echo "Deprecate\n";
$pick = static fn (): string => html_as(27, '/pick/?source=term&q=' . rawurlencode('net revenue ' . $t))['body'];
ok(str_contains($pick(), 'Net revenue ' . $t), 'the term picker offers it');
$found = static fn (): bool => in_array($child, array_map(static fn (array $r): int => (int) $r['entity_id'], array_filter(get_json(26, '/search?kind=glossary_term&q=' . rawurlencode('net revenue ' . $t))['json']['data']['rows'] ?? [],
    static fn (array $r): bool => ($r['kind'] ?? $r['entity_kind'] ?? '') === 'glossary_term')), true);
ok($found(), 'the search finds it');
ok(post_json(26, '/glossary/deprecate.php', ['term' => $child])['code'] === 403, 'a Member may not deprecate: 403');
$r = post_json(27, '/glossary/deprecate.php', ['term' => $child]);
ok($r['code'] === 200 && pg1("SELECT status FROM glossary_terms WHERE id = $child") === 'deprecated', 'an engineer deprecates it');
ok(!str_contains($pick(), 'Net revenue ' . $t) && !$found(), 'it leaves the picker and the search');
ok((int) pg1("SELECT glossary_term_id FROM dataset_columns WHERE dataset_id = $orders AND name = 'region'") === $child, 'its columns keep the link');
ok(!str_contains(html_as(26, '/glossary?q=' . rawurlencode($t))['body'], 'id="term-card-' . $child . '"') && str_contains(html_as(26, '/glossary?status=deprecated&q=' . rawurlencode($t))['body'], 'id="term-card-' . $child . '"'),
    'the glossary leaves it out unless asked for the deprecated');
ok(post_json(27, '/glossary/deprecate.php', ['term' => $child])['code'] === 422, 'deprecating it again: 422');
$r = post_json(27, '/glossary/save.php', ['name' => 'Net revenue ' . $t, 'definition' => 'The name is free again once the old term is deprecated.']);
ok($r['code'] === 200, 'a deprecated term\'s name may be used again (the unique index is on live names)');
$missing = array_filter(['term.create', 'term.update', 'term.approve', 'term.deprecate'], static fn (string $ev): bool => ($a = activity($ev)) === [] || end($a)['entity_type'] !== 'glossary_term');
ok($missing === [], 'logged term.create, term.update, term.approve and term.deprecate on the term' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''));
finish();
