<?php
/**
 * Slice 2 proof — the sniff and the mapping (uploads-loads.md "Proof" 2, 3, 8): the sniff reads the Windows-1252 CSV (é survives), the
 * workbook's two sheets and its joined two-row header, NDJSON and Parquet; a header two rows down read again; the mapping renames, retypes,
 * keeps a leading-zero id as text, keeps one personal proposal and clears another; confirm makes sandbox.m{me}_{name} and the load; the same
 * file into the same dataset again answers "already loaded"; every wrong field named; templates saved from the mapping, applied to next month's
 * file in one step, changed and deleted by their maker only.
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$tag = run_tag();

echo "1. The sniff of each format\n";
[$uc] = upload_file(26, "$fx/suppliers_bad_cp1252.csv");
ok(wait_sniff($uc) === 'done', 'the Windows-1252 CSV was read');
$m = map_json(26, $uc)['json']['data']['upload']['sniff'] ?? [];
ok(($m['encoding'] ?? '') === 'cp1252' && str_contains(json_encode($m['sample'] ?? [], JSON_UNESCAPED_UNICODE), 'Café Résumé Supplies'), 'its encoding is cp1252 and é survives in the sample');
ok(in_array('not UTF-8: read as Windows-1252 and transcoded for loading', $m['warnings'] ?? [], true), '…and the screen says it was transcoded');
ok(($m['columns'][2]['proposed_personal_kind'] ?? null) === 'email' && ($m['columns'][2]['confidence'] ?? 0) >= 0.8, 'contact_email proposed as email addresses (' . ($m['columns'][2]['confidence'] ?? '?') . ')');
[$ux] = upload_file(26, "$fx/orders.xlsx");
ok(wait_sniff($ux) === 'done', 'the workbook was read');
$w = map_json(26, $ux)['json']['data']['upload']['sniff'] ?? [];
ok(($w['format'] ?? '') === 'workbook' && array_column($w['sheets'] ?? [], 'name') === ['Orders', 'Summary'] && ($w['sheet'] ?? '') === 'Orders', 'two sheets listed, Orders first');
$w2 = map_json(26, $ux, 'sheet=Summary')['json']['data']['upload']['sniff'] ?? [];
ok(array_column($w2['columns'] ?? [], 'name') === ['region', 'q1_units', 'q1_revenue', 'q2_units', 'q2_revenue'] && ($w2['sheets'][1]['header_rows'] ?? 0) === 2
    && str_contains(implode(' ', $w2['warnings'] ?? []), 'multi-row header'), 'the Summary sheet: the merged two-row header joined (q1_units …) and flagged');
ok(array_column($w['columns'] ?? [], 'type') === ['VARCHAR', 'VARCHAR', 'DATE', 'DOUBLE', 'VARCHAR'], 'Orders: types proposed (a date, a number), the leading-zero customer_id text');
[$uj] = upload_file(26, "$fx/events.ndjson");
wait_sniff($uj);
$jn = map_json(26, $uj)['json']['data']['upload']['sniff'] ?? [];
ok(($jn['format'] ?? '') === 'json' && array_column($jn['columns'] ?? [], 'name') === ['at', 'event_id', 'kind', 'member', 'meta'] && ($jn['columns'][3]['proposed_personal_kind'] ?? '') === 'email', 'NDJSON: five columns, the member column proposed as email');
[$up] = upload_file(26, "$fx/products.parquet");
wait_sniff($up);
$pq = map_json(26, $up)['json']['data']['upload']['sniff'] ?? [];
ok(($pq['format'] ?? '') === 'parquet' && array_column($pq['columns'] ?? [], 'type') === ['VARCHAR', 'VARCHAR', 'DOUBLE', 'DATE'] && count($pq['sample'] ?? []) === 3, 'Parquet: its own types, three sample rows');
[$ut] = upload_file(26, "$fx/report_with_title.csv");
wait_sniff($ut);
$r = post_json(26, "/uploads/$ut/sniff", ['header_row' => 3]);
ok($r['code'] === 200 && wait_sniff($ut) === 'done', 'a header two lines down: "Read it again from row 3" queued a new sniff');
$tt = map_json(26, $ut)['json']['data']['upload']['sniff'] ?? [];
ok(array_column($tt['columns'] ?? [], 'name') === ['region', 'units'] && ($tt['header_row'] ?? 0) === 3 && count($tt['sample'] ?? []) === 2, 'read from row 3: region, units, two rows');
$r = map_confirm_as(26, $ut, ['target' => 'sandbox', 'name' => "report_$tag", 'header_row' => 1]);
ok($r['code'] === 422 && field_of($r) === 'header_row', 'confirming with another header row than the sniff\'s: 422 on header_row (read it again first)');
ok(map_json(31, $ut)['code'] === 404, 'another member\'s upload: 404 — the sample is the uploader\'s alone');
ok(map_json(1, $ut)['code'] === 404, '…the super-admin\'s too');

echo "2. The mapping — rename, retype, keep text, keep one personal proposal, clear another\n";
[$u] = upload_file(26, "$fx/customers.csv");
wait_sniff($u);
$cols = [];
foreach ((map_json(26, $u)['json']['data']['upload']['sniff']['columns'] ?? []) as $i => $c) {
    $cols[$i] = ['name' => $c['name'], 'type' => $c['type'], 'personal_kind' => (string) ($c['proposed_personal_kind'] ?? ''), 'skip' => '0'];
}
ok(($cols[4]['type'] ?? '') === 'VARCHAR' && str_contains((string) (map_json(26, $u)['json']['data']['upload']['sniff']['columns'][4]['note'] ?? ''), 'identifier'), 'card_number: a long number kept as text, and the screen says why');
$cols[1]['personal_kind'] = '';                         // name: cleared
$cols[1]['name'] = 'full_name';                         // renamed
$cols[7]['type'] = 'DECIMAL(18,2)';                     // balance: an amount
$cols[6]['type'] = 'DATE';                              // signed_up: a date
$cols[5]['skip'] = '1';                                 // city: skipped
$name = "customers_$tag";
$form = ['target' => 'sandbox', 'name' => $name, 'mode' => 'append', 'dedupe_key' => ['#0'], 'save_template' => "Customers $tag"];
foreach ($cols as $i => $c) {
    foreach ($c as $k => $v) {
        $form["columns[$i][$k]"] = $v;
    }
}
$bad = $form;
$bad['columns[1][name]'] = 'email';
$r = map_confirm_as(26, $u, $bad);
ok($r['code'] === 422 && str_contains((string) field_of($r), 'name') && str_contains(message_of($r), 'Two columns are named email'), 'two columns of one name: 422 on the column');
foreach ([['columns[7][type]', 'MONEY', 'type'], ['columns[2][personal_kind]', 'eyes', 'personal_kind'], ['columns[0][name]', 'Bad Name', 'name']] as [$f, $v, $what]) {
    $b = $form;
    $b[$f] = $v;
    $r = map_confirm_as(26, $u, $b);
    ok($r['code'] === 422 && str_contains((string) field_of($r), $what), "a wrong $what ($v): 422 naming the column's field");
}
$r = map_confirm_as(26, $u, ['target' => 'raw', 'raw_name' => "nope_$tag"] + $form);
ok($r['code'] === 422 && field_of($r) === 'target', 'a Member asking for a raw dataset: 422 — loading into raw is an engineer\'s');
$r = map_confirm_as(26, $u, ['name' => 'Bad name'] + $form);
ok($r['code'] === 422 && field_of($r) === 'name', 'a dataset name that is not an identifier: 422 on name');
$r = map_confirm_as(26, $u, $form);
$key = "sandbox.m26_$name";
ok($r['code'] === 200 && ($r['json']['dataset'] ?? '') === $key && ($r['json']['created'] ?? false) === true && ($r['json']['location'] ?? '') === '/loads/' . ($r['json']['record_id'] ?? 0), "confirm: $key made, the load queued, landing on the load");
$ds = (int) $r['json']['dataset_id'];
$load = (int) $r['json']['record_id'];
ok(wait_load($load) === 'loaded', 'the writer loaded it');
$colRows = [];
foreach (pg("SELECT name || '|' || COALESCE(personal_kind, '') || '|' || classified_by || '|' || (classification_confirmed_at IS NOT NULL) || '|' || COALESCE(physical_type, '') FROM dataset_columns WHERE dataset_id = $ds AND NOT is_system ORDER BY ordinal") as $line) {
    [$n, $k, $by, $conf, $type] = explode('|', $line);
    $colRows[$n] = ['kind' => $k, 'by' => $by, 'confirmed' => $conf === 'true', 'type' => $type];
}
ok(array_keys($colRows) === ['customer_id', 'full_name', 'email', 'phone', 'card_number', 'signed_up', 'balance'], 'the columns as mapped: full_name renamed, city skipped');
ok(($colRows['email']['kind'] ?? '') === 'email' && $colRows['email']['by'] === 'person' && $colRows['email']['confirmed'], 'email: the proposal kept — personal, confirmed by the person');
ok(($colRows['full_name']['kind'] ?? 'x') === '' && $colRows['full_name']['by'] === 'person' && $colRows['full_name']['confirmed'], 'full_name: the proposal cleared — confirmed not personal (the classifier never overrides it)');
ok(($colRows['customer_id']['type'] ?? '') === 'VARCHAR' && ($colRows['balance']['type'] ?? '') === 'DECIMAL(18,2)' && ($colRows['signed_up']['type'] ?? '') === 'DATE', 'typed in the lake: the id text (leading zeros kept), balance DECIMAL(18,2), signed_up DATE');
ok(pg1("SELECT dedupe_key::text FROM datasets WHERE id = $ds") === '{customer_id}' && pg1("SELECT classification || '/' || has_personal_data FROM datasets WHERE id = $ds") === 'confidential/true', 'the key ticked by column; the dataset derived confidential with personal data');
ok(pg1("SELECT mapping->'read_options'->>'format' FROM loads WHERE id = $load") === 'csv' && pg1("SELECT mapping->'columns'->'city'->>'skip' FROM loads WHERE id = $load") === 'true', 'the load keeps its mapping (format, the skipped column) for a replay');
$a = activity('upload.map');
$last = end($a) ?: [];
ok(($last['entity_id'] ?? null) == $load && str_contains((string) $last['after'], '"columns": 7') && str_contains((string) $last['after'], $key) && !str_contains((string) $last['after'], 'example.invalid'), 'upload.map logged: the target key, the column count — never a value');
$r = map_confirm_as(26, $u, $form + []);
ok($r['code'] === 422 && str_contains(message_of($r), 'already loaded into ' . $key), 'the same file into the same dataset again: "This file is already loaded into ' . $key . '"');

echo "3. Templates — saved from the mapping, applied to next month's file in one step\n";
$tid = (int) pg1("SELECT id FROM import_templates WHERE name = 'Customers $tag'");
ok($tid > 0 && pg1("SELECT mapping->'columns'->'name'->>'name' FROM import_templates WHERE id = $tid") === 'full_name' && pg1("SELECT kind FROM import_templates WHERE id = $tid") === 'csv', 'saved as a template: the renamed column, kind csv');
[$un] = upload_file(26, "$fx/customers_next_month.csv");
wait_sniff($un);
$r = map_confirm_as(26, $un, ['target' => 'sandbox', 'name' => $name, 'template' => $tid]);
ok($r['code'] === 200 && ($r['json']['created'] ?? true) === false && ($r['json']['dataset'] ?? '') === $key, 'next month\'s file with the template alone: into the same dataset, nothing else said');
ok(wait_load((int) $r['json']['record_id']) === 'loaded' && (int) pg1("SELECT row_count FROM datasets WHERE id = $ds") === 9, 'loaded: 7 + 2 rows');
ok((int) pg1("SELECT count(*) FROM schema_versions WHERE dataset_id = $ds AND decision = 'pending'") === 0, 'no drift — the template named every column as before (full_name, city skipped)');
$t2 = post_json(26, '/templates/save.php', ['name' => "Products $tag", 'kind' => 'parquet', 'mapping' => json_encode(['sku' => ['name' => 'sku', 'type' => 'VARCHAR'], 'list_price' => ['name' => 'price', 'type' => 'DOUBLE']])]);
ok($t2['code'] === 200 && (int) ($t2['json']['record_id'] ?? 0) > 0, 'template_save: a mapping saved directly (JSON)');
$t2id = (int) $t2['json']['record_id'];
ok(post_json(26, '/templates/save.php', ['name' => "Customers $tag", 'kind' => 'csv', 'mapping' => '{}'])['code'] === 422, 'a second template of the same name: 422');
ok(post_json(26, '/templates/save.php', ['template' => $t2id, 'name' => "Products $tag v2", 'kind' => 'parquet'])['code'] === 200
    && pg1("SELECT name FROM import_templates WHERE id = $t2id") === "Products $tag v2", 'its maker renames it');
ok(post_json(31, '/templates/save.php', ['template' => $t2id, 'name' => 'Lee was here', 'kind' => 'csv'])['code'] === 403, 'another Member changing it: 403');
ok(post_json(31, '/templates/delete.php', ['template' => $t2id])['code'] === 403, 'another Member deleting it: 403');
ok(post_json(27, '/templates/delete.php', ['template' => $t2id])['code'] === 200 && pg1("SELECT count(*) FROM import_templates WHERE id = $t2id") === '0', 'a data engineer (loads.manage) may delete it');
$h = html_as(26, '/templates');
ok($h['code'] === 200 && str_contains($h['body'], 'id="template-card-' . $tid . '"') && str_contains($h['body'], 'Customers ' . $tag), 'the templates screen lists it as a card');
$h = html_as(26, "/uploads/$un/map?template=$tid");
ok(str_contains($h['body'], 'id="map-template-applied"') && str_contains($h['body'], 'value="full_name"'), 'the mapping screen applies a template: its names prefilled');
ok(count(activity('template.save')) >= 2 && count(activity('template.delete')) >= 1, 'template.save and template.delete logged');
finish();
