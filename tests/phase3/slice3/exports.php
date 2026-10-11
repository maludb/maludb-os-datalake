<?php
/**
 * Slice 3 — exports (query-service-editor.md "Proof" 7): CSV, XLSX and Parquet made by the writer through MY views; the 100,000-row cap →
 * truncated; the masked flag; the download door for the owner alone (Content-Disposition attachment, nosniff), logged; expired → 404 and the
 * file unlinked by the writer's sweep (the worker queues it); delete → 404 at once, the file swept; an agent's export_make pauses
 * (external_send); a Member may export (export.own) only what she may read.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;
$store = need('DL_STORAGE_DIR');

echo "7. CSV, XLSX and Parquet\n";
$make = static function (int $m, array $form): array {
    $r = post_json($m, '/exports/make.php', $form);
    $id = (int) ($r['json']['record_id'] ?? 0);
    $j = isset($r['json']['job_id']) ? wait_job((int) $r['json']['job_id'], 120) : ['status' => 'none', 'error' => '', 'result' => []];
    return [$r, $id, $j];
};
[$c, $cid, $cj] = $make(26, ['format' => 'csv', 'dataset' => 'curated.sales_orders']);
ok($c['code'] === 200 && $cj['status'] === 'done' && pg1("SELECT status || '/' || row_count || '/' || masked FROM exports WHERE id = $cid") === 'done/1500/true', 'Priya (a Member) exports orders as CSV: 1,500 rows, masked');
$csv = file("$store/exports/$cid.csv");
ok(str_starts_with($csv[0], 'order_id,customer_email') && str_contains($csv[1], 'b***@example.invalid') && !str_contains(implode('', $csv), 'buyer1@'), '…the file holds the email masked, never a raw address');
[$x, $xid, $xj] = $make(30, ['format' => 'xlsx', 'sql' => 'SELECT order_id, customer_email, amount, ordered_on FROM curated.sales_orders ORDER BY order_id LIMIT 50']);
ok($xj['status'] === 'done' && is_file("$store/exports/$xid.xlsx") && substr((string) file_get_contents("$store/exports/$xid.xlsx"), 0, 2) === 'PK' && pg1("SELECT row_count FROM exports WHERE id = $xid") === '50',
   'an XLSX of a result (50 rows) — a real workbook');
[$p, $pid, $pj] = $make(30, ['format' => 'parquet', 'sql' => 'SELECT region, sum(revenue) AS total FROM product.monthly_revenue GROUP BY region']);
ok($pj['status'] === 'done' && substr((string) file_get_contents("$store/exports/$pid.parquet"), 0, 4) === 'PAR1' && pg1("SELECT masked FROM exports WHERE id = $pid") === 'f',
   'a Parquet of an aggregate over a product with no mask (PAR1) — not flagged masked');
[$b, $bid, $bj] = $make(30, ['format' => 'csv', 'dataset' => 'curated.big_events']);
ok($bj['status'] === 'done' && pg1("SELECT row_count || '/' || truncated FROM exports WHERE id = $bid") === '100000/true' && count(file("$store/exports/$bid.csv")) === 100001,
   '120,000 rows → the first 100,000 (query_rows_download) and truncated');
$sq = post_json(30, '/queries/save.php', ['title' => 'SMOKE export me ' . run_tag(), 'sql' => 'SELECT count(*) AS n FROM curated.sales_orders WHERE region = $r',
    'parameters' => json_encode([['name' => 'r', 'type' => 'text', 'default' => 'EMEA']])]);
[$q, $qid, $qj] = $make(30, ['format' => 'csv', 'saved_query' => $sq['json']['record_id'], 'p[r]' => 'AMER']);
ok($qj['status'] === 'done' && trim(file("$store/exports/$qid.csv")[1] ?? '') === '375', 'a saved query exported with its parameter bound (AMER → 375)');
ok(run_row((int) $cj['result']['query_run_id'])['source'] === 'export', 'each read audited by the writer (source export)');
ok((int) pg1("SELECT count(*) FROM notifications WHERE kind = 'export_ready' AND member_id = 26 AND record_id = $cid") === 1, 'Priya is told it is ready (export_ready)');

echo "   the download door\n";
[$j26, ] = actor(26);
$d = req('GET', "/exports/$cid/download", ['jar' => $j26, 'headers' => ['Accept: */*']]);
ok($d['code'] === 200 && strlen($d['body']) === filesize("$store/exports/$cid.csv"), 'the owner downloads it whole');
$hdr = shell_exec('curl -s -D - -o /dev/null -b ' . escapeshellarg($j26) . " http://127.0.0.1:9001/exports/$cid/download");
ok(stripos((string) $hdr, 'Content-Disposition: attachment') !== false && stripos((string) $hdr, 'X-Content-Type-Options: nosniff') !== false && stripos((string) $hdr, 'text/csv') !== false,
   '…as an attachment, nosniff, text/csv');
[$j30, ] = actor(30);
ok(req('GET', "/exports/$cid/download", ['jar' => $j30])['code'] === 404, 'anyone else: 404');
ok(count(activity('export.download')) >= 1 && pg1("SELECT downloaded_at IS NOT NULL FROM exports WHERE id = $cid") === 't', 'export.download logged, downloaded_at kept');
$list = get_json(26, '/exports');
ok(($list['json']['data']['rows'][0]['download'] ?? '') === "/exports/$cid/download" && str_contains(html_as(26, '/exports')['body'], "id=\"export-row-$cid\""), 'my exports list it, Ready, with its door');

echo "   expiry and delete: the writer owns exports/\n";
pg("UPDATE exports SET expires_at = now() - interval '1 minute' WHERE id = $cid");
ok(req('GET', "/exports/$cid/download", ['jar' => $j26])['code'] === 404, 'expired → 404 at once');
exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ' --passes=exports', $out);
$rep = json_decode((string) end($out), true) ?: [];
$sweep = (int) ($rep['ran']['exports']['sweep_job'] ?? 0);
ok(($rep['ran']['exports']['expired'] ?? 0) >= 1 && $sweep > 0, 'the worker\'s exports pass queues the writer\'s sweep');
$sj = wait_job($sweep, 60);
clearstatcache();
ok($sj['status'] === 'done' && !is_file("$store/exports/$cid.csv") && pg1("SELECT storage_path IS NULL AND status = 'done' FROM exports WHERE id = $cid") === 't',
   'the sweep unlinked the file; the row keeps its status, its path gone (' . json_encode($sj['result']) . ')');
ok(str_contains(html_as(26, '/exports')['body'], 'Expired'), 'the list says Expired');
$del = post_json(30, '/exports/delete.php', ['export' => $xid]);
ok($del['code'] === 200 && (int) pg1("SELECT count(*) FROM exports WHERE id = $xid") === 0 && req('GET', "/exports/$xid/download", ['jar' => $j30])['code'] === 404, 'delete: the row gone, the door 404 at once');
$sw = (int) pg1("SELECT max(id) FROM lake_jobs WHERE kind = 'export' AND params->>'op' = 'sweep'");
wait_job($sw, 60);
clearstatcache();
ok(!is_file("$store/exports/$xid.xlsx"), '…the file swept by the writer');
ok(post_json(26, '/exports/delete.php', ['export' => $bid])['code'] === 404, 'another\'s export cannot be deleted');

echo "   refusals; an agent pauses\n";
$no = post_json(26, '/exports/make.php', ['format' => 'csv', 'dataset' => 'curated.payroll']);
ok($no['code'] === 404 || $no['code'] === 403, 'a dataset I only discover cannot be exported (the gate)');
$no2 = post_json(30, '/exports/make.php', ['format' => 'csv', 'sql' => 'SELECT * FROM curated.hr_reviews']);
ok($no2['code'] === 422 && str_contains((string) ($no2['json']['error']['fields']['sql'] ?? ''), 'may not read'), 'a SQL over a dataset I may not read: refused on `sql`, nothing made');
ok(post_json(30, '/exports/make.php', ['format' => 'pdf', 'sql' => 'SELECT 1 AS one FROM curated.sales_orders'])['code'] === 422, 'pdf is not a format here');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['export_make']['approval'] ?? null) === 'external_send', 'an agent\'s export_make pauses (external_send — the registry the kernel\'s hook reads)');
finish();
