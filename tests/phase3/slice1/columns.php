<?php
/*
 * Proof: a column's meaning (catalog.md "Proof" 7) — the business name, description, glossary term, critical and reference saved by the
 * steward; the name and type never (not in the form; forged → ignored; the database refuses the application's role); the reference's
 * column list follows the dataset (and is empty for a dataset one may not discover); the page's profile made safe — a personal value masked
 * even when an old profile held it raw, a confidential column's top values withheld, the profile over time; the Columns tab at both widths.
 */
require __DIR__ . '/lib.php';
$ids = seed_catalog();
$t = run_tag();
$orders = $ids['curated.sales_orders'];
$revenue = $ids['product.monthly_revenue'];
$region = (int) pg1("SELECT id FROM dataset_columns WHERE dataset_id = $orders AND name = 'region'");
$email = (int) pg1("SELECT id FROM dataset_columns WHERE dataset_id = $orders AND name = 'customer_email'");
$term = (int) (post_json(27, '/glossary/save.php', ['name' => 'Sales region ' . $t, 'definition' => 'Where an order was sold: EMEA, AMER, APAC.'])['json']['record_id'] ?? 0);

echo "7. A column described\n";
$r = post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'business_name' => 'Sales region', 'description' => 'The region the order was booked in.',
    'term' => $term, 'critical' => 'yes', 'references_dataset' => $revenue, 'references_column' => 'REGION', 'name' => 'renamed_by_hand', 'physical_type' => 'INTEGER',
    'classification' => 'public', 'personal_kind' => 'email']);
$c = q('SELECT name, physical_type, business_name, description, glossary_term_id, critical, references_dataset_id, references_column, classification, personal_kind FROM dataset_columns WHERE id = :i', ['i' => $region])[0];
ok($r['code'] === 200 && (int) ($r['json']['record_id'] ?? 0) === $region, 'the steward (Marco) saves the column by name: 200, record_id the column');
ok($c['business_name'] === 'Sales region' && $c['description'] === 'The region the order was booked in.' && (int) $c['glossary_term_id'] === $term && $c['critical'] === true,
    'its business name, description, glossary term and critical are saved');
ok((int) $c['references_dataset_id'] === $revenue && $c['references_column'] === 'region', 'the reference is the pair product.monthly_revenue.region (the column\'s own spelling)');
ok($c['name'] === 'region' && $c['physical_type'] === 'VARCHAR' && $c['classification'] === 'internal' && $c['personal_kind'] === null, 'a forged name, type, classification and personal kind are ignored (divergence 3)');
$a = activity('column.update');
$la = end($a);
ok((int) $la['dataset_id'] === $orders && $la['entity_type'] === 'column' && (int) $la['entity_id'] === $region && !str_contains((string) $la['after'], 'booked in'), 'logged column.update with the dataset_id, the column, the description by its length');
try {
    as_member(27);
    pdo()->exec("UPDATE dataset_columns SET name = 'by_hand' WHERE id = $region");
    ok(false, 'the database refuses a column\'s name from the application (it did NOT)');
} catch (PDOException $e) {
    ok($e->getCode() === '42501' && str_contains($e->getMessage(), 'name, type and profile are the engine'), 'the database refuses a column\'s name from the application\'s role');
}
$h = html_as(27, "/datasets/$orders/columns/region/edit")['body'];
ok(!str_contains($h, 'name="name"') && !str_contains($h, 'name="physical_type"') && !str_contains($h, 'name="classification"') && !str_contains($h, 'name="personal_kind"'), 'the form has no field for the name, type, classification or personal kind');
ok(str_contains($h, 'id="column-form-field-references_column"') && str_contains($h, '<option value="region" selected>region</option>'), 'the reference\'s select lists the referenced dataset\'s live columns, the chosen one selected');
$f = html_as(27, "/datasets/$orders/columns/region/edit?ref_columns=1&references_dataset=$orders", ['HX-Request: true'])['body'];
ok(str_contains($f, '<option value="order_id">') && str_contains($f, '<option value="customer_email">') && !str_contains($f, '_dl_load_id'), 'choosing another dataset reloads the select with ITS columns (no provenance column)');
$f = html_as(26, '/datasets/' . $ids['sandbox.m26_my_upload'] . '/columns/x/edit?ref_columns=1&references_dataset=' . $ids['curated.payroll'], ['HX-Request: true']);
ok($f['code'] === 404 || !str_contains($f['body'], 'member_name'), 'a dataset one may not discover answers no columns');
$f = html_as(27, "/datasets/$orders/columns/region/edit?ref_columns=1&references_dataset=" . $ids['curated.payroll'], ['HX-Request: true'])['body'];
ok(!str_contains($f, 'member_name') && str_contains($f, 'Choose the dataset first'), 'the payroll (Marco may not discover it) answers an empty select');
$r = post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'references_dataset' => $revenue, 'references_column' => 'no_such']);
ok($r['code'] === 422 && field_of($r) === 'references_column', 'a column the referenced dataset does not have: 422 on references_column');
$r = post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'references_dataset' => $ids['curated.payroll'], 'references_column' => 'salary']);
ok($r['code'] === 422 && field_of($r) === 'references_dataset', 'a referenced dataset one may not discover: 422 on references_dataset');
$r = post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => $region, 'references_dataset' => 'none']);
ok($r['code'] === 200 && pg1("SELECT references_dataset_id IS NULL AND references_column IS NULL FROM dataset_columns WHERE id = $region") === 't', 'none clears the pair (by the column\'s id)');
post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'references_dataset' => $revenue, 'references_column' => 'region']);
$r = post_json(26, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'business_name' => 'Mine']);
ok($r['code'] === 403, 'a Member who is neither owner nor steward may not describe a column: 403');
ok(post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'nope', 'business_name' => 'x'])['code'] === 404, 'a column that does not exist: 404');
$r = post_json(27, '/datasets/columns/save.php', ['dataset' => $orders, 'column' => 'region', 'term' => 'No such term ' . $t]);
ok($r['code'] === 422 && field_of($r) === 'term', 'a term that is not in the glossary: 422 on term');

echo "The column's page — the profile made safe\n";
$p = html_as(26, "/datasets/curated.sales_orders/columns/customer_email");
ok($p['code'] === 200 && str_contains($p['body'], 'id="column-profile"') && str_contains($p['body'], 'a***@example.com') && !str_contains($p['body'], 'alice@example.com') && !str_contains($p['body'], 'zoe@example.com'),
    'a personal column: its range and top values masked (a***@example.com), the raw values of an old profile never shown');
ok(!str_contains($p['body'], 'bob@example.com') && str_contains($p['body'], 'id="column-profile-history"'), '…its profile over time masked too');
$m = html_as(26, "/datasets/curated.sales_orders/columns/margin")['body'];
ok(str_contains($m, 'id="column-profile-top-withheld"') && !str_contains($m, 'id="column-profile-top"') && !str_contains($m, '>12.00<'), 'a confidential column: the top values withheld');
$g = html_as(26, "/datasets/$orders/columns/region")['body'];
ok(str_contains($g, 'EMEA') && str_contains($g, 'id="column-term"') && str_contains($g, 'product.monthly_revenue.region') && preg_match('/id="column-profile-history".*?<tr id="column-profile-\d+">.*?<tr id="column-profile-\d+">/s', $g) === 1,
    'an internal column: its top values, its term, its reference, two profiles over time');
$j = get_json(26, "/datasets/$orders/columns/customer_email");
$enc = json_encode($j['json']);
ok($j['code'] === 200 && ($j['json']['data']['column']['profile']['masked'] ?? false) === true && !str_contains($enc, 'alice@') && !str_contains($enc, 'bob@'), 'JSON: the same profile, masked — no raw personal value in the answer');
ok(($j['json']['data']['column']['personal_kind'] ?? '') === 'email' && !array_key_exists('top_raw', $j['json']['data']['column']['profile'] ?? []), '…the personal kind named');
ok(html_as(26, '/datasets/curated.payroll/columns/salary')['code'] === 404 && html_as(29, "/datasets/$orders/columns/region")['code'] === 404, 'a stub\'s column and an external: 404');
ok((int) one("SELECT count(*) FROM activity_log WHERE screen = 'column-view' AND dataset_id = :d", ['d' => $orders]) >= 3, 'the column page logs screen.view with the dataset_id');

echo "The Columns tab\n";
$h = html_as(26, "/datasets/$orders?tab=columns")['body'];
ok(str_contains($h, 'id="column-row-' . $region . '"') && str_contains($h, 'id="column-card-' . $region . '"') && !str_contains($h, '_dl_load_id'), 'a row per live column (a table at 1280, a card at 375), the provenance column left out');
ok(preg_match('/id="column-row-' . $email . '".*?email · proposed|id="column-row-' . $email . '".*?text-bg-danger">email</s', $h) === 1, 'the personal kind as a badge');
ok(str_contains($h, '→ <a href="/datasets/product.monthly_revenue"') || str_contains($h, '→ <a href="/datasets/product.monthly_revenue"'), 'the reference "→ product.monthly_revenue.region"');
finish();
