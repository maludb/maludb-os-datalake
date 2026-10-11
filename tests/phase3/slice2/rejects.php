<?php
/**
 * Slice 2 proof — rejected lines and cells (uploads-loads.md "Proof" 4; lake-engine §3): a typed sandbox load keeps every good row, refuses a
 * line with an extra field (its file line, its error, its raw text) and leaves a cell that does not cast empty with a CAST reject (the line,
 * the column, the cell's text); é survives the Windows-1252 file into the lake; the raw text is shown only at the dataset's write level.
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$tag = run_tag();

echo "1. A typed sandbox load of a CSV with a bad line and two bad cells\n";
[$u] = upload_file(26, "$fx/customers.csv");
wait_sniff($u);
$form = ['target' => 'sandbox', 'name' => "typed_$tag", 'columns[6][type]' => 'DATE', 'columns[7][type]' => 'DECIMAL(18,2)', 'columns[1][personal_kind]' => ''];
foreach (range(0, 7) as $i) {
    $form["columns[$i][skip]"] = '0';
}
$r = map_confirm_as(26, $u, $form);
$l = (int) $r['json']['record_id'];
$ds = (int) $r['json']['dataset_id'];
ok($r['code'] === 200 && wait_load($l) === 'loaded', 'loaded');
ok(pg1("SELECT rows_read || '/' || rows_loaded || '/' || rows_rejected FROM loads WHERE id = $l") === '8/7/1', 'read 8, loaded 7, one line refused');
$rej = pg("SELECT line || '|' || COALESCE(column_name, '') || '|' || error_type || '|' || COALESCE(message, '') FROM load_rejects WHERE load_id = $l ORDER BY line, column_name");
ok(($rej[0] ?? '') === '5||TOO MANY COLUMNS|Expected Number of Columns: 8 Found: 9', 'line 5: TOO MANY COLUMNS, with the parser\'s message');
ok(($rej[1] ?? '') === '8|balance|CAST|not a DECIMAL(18,2) — left empty' && ($rej[2] ?? '') === '8|signed_up|CAST|not a DATE — left empty', 'line 8: balance and signed_up did not cast — two CAST rejects, the file\'s line, the column');
ok(pg1("SELECT raw_line FROM load_rejects WHERE load_id = $l AND column_name = 'balance'") === 'abc' && str_contains((string) pg1("SELECT raw_line FROM load_rejects WHERE load_id = $l AND line = 5"), ',EXTRA'), 'each keeps its raw text (the cell, the line)');
$p = get_json(26, "/datasets/$ds/preview")['json']['data']['preview'] ?? [];
$fay = array_values(array_filter($p['rows'] ?? [], static fn ($r) => ($r[0] ?? '') === '00047'))[0] ?? [];
ok($fay !== [] && $fay[6] === null && $fay[7] === null && ($p['rows'][0][7] ?? '') === '120.50' && ($p['rows'][0][6] ?? '') === '2026-01-15', 'in the lake: the row is kept, its bad cells NULL; the good ones typed (120.50, 2026-01-15)');
ok(!str_contains((string) pg1("SELECT result::text FROM lake_jobs WHERE kind = 'load' AND ref_id = $l"), 'abc') && !str_contains((string) pg1("SELECT after::text FROM activity_log WHERE action = 'load.run' AND entity_id = $l"), 'abc'), 'no refused text in the job\'s result or the log');

echo "2. Who sees the raw text — the dataset's write level only\n";
$j = get_json(26, "/loads/$l")['json']['data'] ?? [];
ok(($j['rejects']['total'] ?? 0) === 3 && ($j['rejects']['rows'][1]['raw_line'] ?? null) === 'abc' && ($j['load']['cast_rejects'] ?? 0) === 2, 'Priya (own): three rejects with their raw text; 2 cells left empty');
pg("INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) VALUES ('dataset', $ds, 'member', 31, 'read', 'SMOKE slice 2 rejects', 1)");
$jl = get_json(31, "/loads/$l")['json']['data'] ?? [];
ok(($jl['rejects']['total'] ?? 0) === 3 && !array_key_exists('raw_line', $jl['rejects']['rows'][0] ?? ['raw_line' => 1]) && ($jl['load']['may']['raw_lines'] ?? true) === false, 'Lee (read, a dataset grant on her sandbox dataset): the lines and errors, never the raw text');
$h = html_as(31, "/loads/$l");
ok(str_contains($h['body'], 'id="load-rejects-table"') && !str_contains($h['body'], ',EXTRA') && str_contains($h['body'], 'can hold personal data'), 'his page says why the text is not shown');
$h = html_as(26, "/loads/$l");
ok(str_contains($h['body'], ',EXTRA') && str_contains($h['body'], 'id="load-cast-rejects"'), 'hers shows it, and the cells left empty');

echo "3. Windows-1252 into the lake — é survives\n";
[$u2] = upload_file(26, "$fx/suppliers_bad_cp1252.csv");
wait_sniff($u2);
$r = map_confirm_as(26, $u2, ['target' => 'sandbox', 'name' => "suppliers_$tag", 'columns[3][type]' => 'DECIMAL(18,2)']);
$l2 = (int) $r['json']['record_id'];
ok(wait_load($l2) === 'loaded' && pg1("SELECT rows_loaded || '/' || rows_rejected FROM loads WHERE id = $l2") === '3/1', 'loaded 3 rows, refused the line with an extra field');
$p = get_json(26, '/datasets/' . (int) $r['json']['dataset_id'] . '/preview')['json']['data']['preview'] ?? [];
ok(($p['rows'][0][1] ?? '') === 'Café Résumé Supplies' && ($p['rows'][2][1] ?? '') === 'Zoë Tools', 'the preview reads Café Résumé Supplies and Zoë Tools — é and ë survive');
ok(pg1("SELECT error_type FROM load_rejects WHERE load_id = $l2 AND column_name = 'amount'") === 'CAST', 'amount "abc" → a CAST reject');
finish();
