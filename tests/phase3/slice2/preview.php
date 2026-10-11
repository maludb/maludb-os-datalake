<?php
/**
 * Slice 2 proof — the Preview tab through the query service (uploads-loads.md "Proof" 6; query-service §2–§7): the uploader sees the email
 * column unmasked (own); a Member granted read sees it partial_email and "masked for you"; a Member without a grant sees Request access; a
 * discover-only reader is refused in the policy's words; every preview is a query_runs row (source preview) and a query.run activity row with
 * its id — never the SQL; 100 rows at most; the as-of picker for engineers; the protocol's refusals; the engine away → "The engine is not
 * answering", and back.
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$tag = run_tag();

echo "1. Masked as the person may see it\n";
[$u] = upload_file(27, "$fx/customers.csv");
wait_sniff($u);
$r = map_confirm_as(27, $u, ['target' => 'raw', 'raw_name' => "pv_$tag"]);
$ds = (int) $r['json']['dataset_id'];
$key = "raw.pv_$tag";
wait_load((int) $r['json']['record_id']);
pg("INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) VALUES ('dataset', $ds, 'member', 26, 'read', 'SMOKE slice 2 preview', 1)");
$runs0 = (int) pg1("SELECT count(*) FROM query_runs WHERE source = 'preview'");
$own = get_json(27, "/datasets/$ds/preview")['json']['data']['preview'] ?? [];
ok(($own['status'] ?? '') === 'ok' && ($own['rows'][0][2] ?? '') === 'ada.example@example.invalid' && ($own['masked_columns'] ?? ['x']) === [], 'Marco (the owner, own): the email column unmasked');
$pr = get_json(26, "/datasets/$ds/preview")['json']['data']['preview'] ?? [];
$emailCol = array_values(array_filter($pr['columns'] ?? [], static fn ($c) => $c['name'] === 'email'))[0] ?? [];
ok(($pr['rows'][0][2] ?? '') === 'a***@example.invalid' && ($emailCol['masked'] ?? false) === true && in_array('email', $pr['masked_columns'] ?? [], true), 'Priya (read): the email partial_email — a***@example.invalid — and marked masked');
ok(($pr['rows'][0][4] ?? '') !== '4111111111111111' && ($pr['rows'][0][0] ?? '') === '00042', '…the card number masked too, the id as it is');
$h = html_as(26, "/datasets/$ds/preview");
ok(str_contains($h['body'], 'id="preview-masked-email"') && str_contains($h['body'], 'title="masked for you: partial_email"') && str_contains($h['body'], 'masked for you'), 'her table\'s email header: "masked for you", the mask\'s kind on hover');
ok(str_contains($h['body'], 'id="preview-record-1"') && str_contains($h['body'], 'id="preview-next-1"'), 'at 375 one record at a time, Prev / Next (anchors — no new read)');
$h = html_as(31, "/datasets/$ds");
ok($h['code'] === 200 && str_contains($h['body'], 'Request access') && !str_contains($h['body'], 'ada.example'), 'Lee (no grant, a confidential dataset): the stub with Request access — no row');
ok(get_json(31, "/datasets/$ds/preview")['code'] === 404, '…and the preview door answers him nothing (404)');
$runs1 = (int) pg1("SELECT count(*) FROM query_runs WHERE source = 'preview'");
ok($runs1 - $runs0 === 3, 'every preview read is a query_runs row (source preview): 3 for 3');
$qr = pg("SELECT caller_member_id || '|' || status || '|' || rows_returned || '|' || masked_columns::text FROM query_runs WHERE source = 'preview' ORDER BY id DESC LIMIT 1");
ok(str_starts_with($qr[0] ?? '', '26|ok|7|') && str_contains($qr[0], '"email": "partial_email"'), 'Priya\'s row: her id, ok, 7 rows, the masks applied');
$act = activity('query.run');
$last = end($act) ?: [];
ok((int) $last['dataset_id'] === $ds && (int) $last['query_run_id'] > 0 && str_contains((string) $last['after'], '"source": "preview"') && !str_contains(strtoupper((string) $last['after']), 'SELECT'), 'query.run logged with the run\'s id and the dataset — never the SQL');

echo "2. Refused in the policy's words; 100 rows at most; the engineer's as-of\n";
[$u2] = upload_file(27, "$fx/orders_20mb.csv");
wait_sniff($u2);
$r = map_confirm_as(27, $u2, ['target' => 'raw', 'raw_name' => "pv_orders_$tag", 'columns[3][personal_kind]' => '']);
$dso = (int) $r['json']['dataset_id'];
wait_load((int) $r['json']['record_id'], 120);
$big = get_json(27, "/datasets/$dso/preview")['json']['data']['preview'] ?? [];
ok(count($big['rows'] ?? []) === 100, '464,305 rows in the dataset — the preview reads 100');
ok(pg1("SELECT classification FROM datasets WHERE id = $dso") === 'internal', 'an internal dataset (its email column cleared by Marco)…');
$lee = get_json(31, "/datasets/$dso/preview")['json']['data']['preview'] ?? [];
ok(($lee['status'] ?? '') === 'refused' && ($lee['refusal_rule'] ?? '') === 'discover_only' && str_contains((string) ($lee['message'] ?? ''), 'request access'), '…Lee may discover it but not read its rows: refused discover_only, in words');
$h = html_as(31, "/datasets/$dso/preview");
ok(str_contains($h['body'], 'id="preview-request-access"') && str_contains($h['body'], '/access/requests/new?dataset=' . $dso), 'his preview says so with Request access');
ok(pg1("SELECT status || '|' || refusal_rule FROM query_runs WHERE caller_member_id = 31 ORDER BY id DESC LIMIT 1") === 'refused|discover_only', 'the refusal is audited too');
$h = html_as(27, "/datasets/$ds/preview");
ok(str_contains($h['body'], 'id="preview-as-of"') && !str_contains(html_as(26, "/datasets/$ds/preview")['body'], 'id="preview-as-of"'), 'the as-of picker for an engineer (loads.manage), "latest" for a Member');

echo "3. The protocol, on the socket\n";
ok((qs(['kind' => 'preview', 'caller_member_id' => 27])['refusal_rule'] ?? '') === 'bad_request', 'a preview naming no dataset: bad_request');
$a = qs(['kind' => 'preview', 'caller_member_id' => 27, 'dataset' => 'raw.nothing_here', 'source' => 'preview']);
ok(($a['status'] ?? '') === 'refused' && ($a['refusal_rule'] ?? '') === 'no_such_dataset' && (int) ($a['query_run_id'] ?? 0) > 0, 'no such dataset: refused no_such_dataset — and audited (query_run_id)');
$a = qs(['kind' => 'preview', 'caller_member_id' => 26, 'dataset' => $key, 'source' => 'preview', 'policy' => ['datasets' => [$key => ['level' => 'read_unmasked']]]]);
ok(($a['rows'][0][2] ?? '') === 'a***@example.invalid', 'a policy sent by the caller is ignored — the service compiles its own (still masked for Priya)');
ok((qs(['kind' => 'product_rows', 'caller_member_id' => 27, 'dataset' => 'curated.x'])['message'] ?? '') === 'not built until slice 9', 'a kind not built yet: "not built until slice 9" (product_rows — check_row_filter is slice 4\'s, metric slice 7\'s)');

echo "4. The engine away — \"The engine is not answering\" — and back\n";
$state = need('DL_DEV_STATE');
foreach (glob("$state/query-*.pid") as $pf) {
    @posix_kill((int) trim((string) file_get_contents($pf)), SIGTERM);
}
wait_until(static fn (): bool => !file_exists(need('DL_QUERY_SOCKET') . '-1.sock') && !file_exists(need('DL_QUERY_SOCKET') . '-2.sock'), 10);
$h = html_as(26, "/datasets/$ds/preview");
ok(str_contains($h['body'], 'id="preview-engine-down"') && str_contains($h['body'], 'The engine is not answering'), 'the query service stopped: "The engine is not answering"');
$j = get_json(26, "/datasets/$ds/preview")['json']['data']['preview'] ?? [];
ok(($j['refusal_rule'] ?? '') === 'engine_unavailable' && array_key_exists('query_run_id', $j) && $j['query_run_id'] === null, 'JSON: engine_unavailable (nothing to audit — the service never heard it)');
foreach ([1, 2] as $n) {
    exec(sprintf('cd %s && nohup services/.venv/bin/python services/query/server.py --worker %d > %s 2>&1 & echo $!', escapeshellarg(dirname(__DIR__, 3)), $n, escapeshellarg("$state/query-$n.log")), $o);
    file_put_contents("$state/query-$n.pid", trim((string) end($o)));
}
wait_until(static fn (): bool => file_exists(need('DL_QUERY_SOCKET') . '-1.sock') && file_exists(need('DL_QUERY_SOCKET') . '-2.sock'), 15);
usleep(300000);
ok((get_json(26, "/datasets/$ds/preview")['json']['data']['preview']['status'] ?? '') === 'ok', 'started again: the preview reads');
finish();
