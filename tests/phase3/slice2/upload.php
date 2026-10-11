<?php
/**
 * Slice 2 proof — the upload doors (uploads-loads.md "Proof" 1, 2's refusal, 10): a 20 MB CSV in three parts of 8 MB; a broken connection
 * resumed at part 2; the same bytes again "already here" (one blob — its ref_count follows the loads); a program refused at finish; the
 * ceilings; a part that is not the caller's; the no-JavaScript whole-file form; discard; the worker's upload_sessions pass (an expired session,
 * a decided session's sample cleared).
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$big = "$fx/orders_20mb.csv";
$sha = hash_file('sha256', $big);
[$j, $t] = actor(26);
$hdr = ['Accept: application/json', 'X-CSRF-Token: ' . $t];

echo "1. A 20 MB CSV in three parts — a broken connection resumes at part 2\n";
[$u, $start, $none] = upload_file(26, $big, ['parts' => [1], 'skip_finish' => true]);
ok(($start['json']['parts_total'] ?? 0) === 3 && ($start['json']['part_bytes'] ?? 0) === 8388608 && $start['json']['resumed'] === false, 'start: three parts of 8 MB');
ok((int) pg1("SELECT parts_done FROM upload_sessions WHERE id = $u") === 1 && is_file(need('DL_STORAGE_DIR') . "/landing/.parts/$u/1"), 'part 1 arrived, then the connection broke');
[$u2, $start2, $fin] = upload_file(26, $big, ['upload' => $u]);
ok($u2 === $u && ($start2['json']['resumed'] ?? false) === true && ($start2['json']['parts_received'] ?? []) === [1], 'start again names the session: resumed, part 1 already there');
ok(($fin['code'] ?? 0) === 200 && ($fin['json']['deduped'] ?? null) === false && ($fin['json']['map_url'] ?? '') === "/uploads/$u/map", 'parts 2 and 3 sent, finish: landed, the map link');
$blob = pg("SELECT id || '|' || storage_path || '|' || ref_count || '|' || byte_size FROM blobs WHERE sha256 = '$sha'");
[$blobId, $path, $refs, $bytes] = explode('|', $blob[0] ?? '|||');
ok(count($blob) === 1 && $path === 'landing/' . substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha && (int) $bytes === filesize($big) && (int) $refs === 0, 'one blob at landing/<aa>/<bb>/<sha256>, every byte, no load yet');
ok(is_file(need('DL_STORAGE_DIR') . '/' . $path) && hash_file('sha256', need('DL_STORAGE_DIR') . '/' . $path) === $sha, 'the landed file is the uploaded bytes, byte for byte');
ok(!is_dir(need('DL_STORAGE_DIR') . "/landing/.parts/$u"), 'the parts are gone once assembled');
ok(pg1("SELECT status || '/' || sniff_status FROM upload_sessions WHERE id = $u") !== null && wait_sniff($u) === 'done', 'the sniff was queued and the writer read it');
$a = activity('upload.finish');
$last = end($a) ?: [];
ok(($last['entity_id'] ?? null) == $u && str_contains((string) $last['after'], '"extension": "csv"') && !str_contains((string) $last['after'], 'orders_20mb'), 'upload.finish logged with the extension and the size — never the file name');

echo "2. The same bytes again — already here, one blob; ref_count follows the loads\n";
[$u3, , $fin3] = upload_file(26, $big);
ok(($fin3['json']['deduped'] ?? null) === true && ($fin3['json']['did'] ?? '') === 'Already here — landed once', 'a second upload of the same file: deduped, "Already here"');
ok((int) pg1("SELECT count(*) FROM blobs WHERE sha256 = '$sha'") === 1 && (int) pg1("SELECT blob_id FROM upload_sessions WHERE id = $u3") === (int) $blobId, 'still one blob; both uploads point at it');
wait_sniff($u3);
$r1 = map_confirm_as(26, $u, ['target' => 'sandbox', 'name' => 'orders_big_' . run_tag()]);
$r2 = map_confirm_as(26, $u3, ['target' => 'sandbox', 'name' => 'orders_big2_' . run_tag()]);
ok($r1['code'] === 200 && $r2['code'] === 200 && (int) pg1("SELECT ref_count FROM blobs WHERE id = $blobId") === 2, 'two loads of it into two datasets: ref_count 2');
$l1 = (int) $r1['json']['record_id'];
ok(wait_load($l1, 120) === 'loaded' && (int) pg1("SELECT rows_loaded FROM loads WHERE id = $l1") > 300000, 'the 20 MB file loaded (' . pg1("SELECT rows_loaded FROM loads WHERE id = $l1") . ' rows, ' . pg1("SELECT extract(milliseconds FROM finished_at - started_at)::int FROM loads WHERE id = $l1") . ' ms in the writer)');
wait_load((int) $r2['json']['record_id'], 120);

echo "3. Refusals at the doors\n";
$r = upload_file(26, "$fx/run_me.csv")[2];
ok(($r['code'] ?? 0) === 422 && ($r['json']['error']['code'] ?? '') === 'executable' && str_contains((string) ($r['json']['error']['message'] ?? ''), 'program'), 'a program (an ELF binary named .csv) is refused at finish: 422 executable');
ok((int) pg1("SELECT count(*) FROM blobs WHERE sha256 = '" . hash_file('sha256', "$fx/run_me.csv") . "'") === 0, '…and never lands');
$r = req('POST', '/upload/start', ['jar' => $j, 'headers' => $hdr, 'form' => ['filename' => 'empty.csv', 'byte_size' => 0]]);
ok($r['code'] === 422 && (body_json($r)['error']['code'] ?? '') === 'empty', 'an empty file: 422 empty');
$r = req('POST', '/upload/start', ['jar' => $j, 'headers' => $hdr, 'form' => ['filename' => 'huge.csv', 'byte_size' => 6 * 1073741824]]);
ok($r['code'] === 413 && str_contains((string) (body_json($r)['error']['message'] ?? ''), 'at most'), 'over the ceiling (5 GB): 413 in words');
$s = body_json(req('POST', '/upload/start', ['jar' => $j, 'headers' => $hdr, 'form' => ['filename' => 'x.csv', 'byte_size' => 100]]));
$r = req('POST', '/upload/part?upload=' . $s['upload_id'] . '&n=1', ['jar' => $j, 'headers' => array_merge($hdr, ['Content-Type: application/octet-stream']), 'raw' => str_repeat('a', 99)]);
ok($r['code'] === 422 && (body_json($r)['error']['code'] ?? '') === 'bad_part', 'a part of the wrong size: 422 bad_part');
$r = req('POST', '/upload/finish', ['jar' => $j, 'headers' => $hdr, 'form' => ['upload' => $s['upload_id']]]);
ok($r['code'] === 422 && (body_json($r)['error']['missing'] ?? null) === [1], 'finish with a part missing: 422 missing_parts naming it');
[$jl, $tl] = actor(31);
$r = req('POST', '/upload/part?upload=' . $s['upload_id'] . '&n=1', ['jar' => $jl, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $tl, 'Content-Type: application/octet-stream'], 'raw' => str_repeat('a', 100)]);
ok($r['code'] === 404, 'another member\'s upload: 404 (nothing to them)');
$r = req('POST', '/upload/part?upload=' . $s['upload_id'] . '&n=1', ['jar' => $j, 'headers' => ['Accept: application/json', 'Content-Type: application/octet-stream'], 'raw' => str_repeat('a', 100)]);
ok($r['code'] === 403, 'a part without the CSRF token: 403');
[$jw] = sign_on(41);
ok(req('POST', '/upload/start', ['jar' => $jw, 'headers' => ['Accept: application/json'], 'form' => ['filename' => 'x.csv', 'byte_size' => 10]])['code'] !== 200, 'someone the lake does not admit cannot start an upload');

echo "4. Without JavaScript: the form posts the file whole, then the mapping\n";
$ch = curl_init(BASE . '/upload/start');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $j, CURLOPT_COOKIEFILE => $j,
    CURLOPT_POSTFIELDS => ['csrf_token' => $t, 'file' => new CURLFile("$fx/events.ndjson", 'application/x-ndjson', 'events.ndjson')]]);
$raw = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
ok($code === 302 && preg_match('#Location: (/uploads/(\d+)/map)#i', $raw, $m) === 1, 'a multipart POST of one file: 302 to its mapping');
$uw = (int) ($m[2] ?? 0);
ok($uw > 0 && wait_sniff($uw) === 'done' && pg1("SELECT sniff->>'format' FROM upload_sessions WHERE id = $uw") === 'json', 'it landed and was read (NDJSON)');

echo "5. Discard — an unmapped upload; a mapped one is refused (roll its load back instead)\n";
$r = post_json(26, '/uploads/discard.php', ['upload' => $uw]);
ok($r['code'] === 200 && pg1("SELECT status FROM upload_sessions WHERE id = $uw") === 'expired', 'discard: the session expires');
$r = post_json(26, '/uploads/discard.php', ['upload' => $u]);
ok($r['code'] === 422 && str_contains(message_of($r), 'roll the load back'), 'discard a loaded file: refused, "roll the load back instead"');
ok(post_json(31, '/uploads/discard.php', ['upload' => $u3])['code'] === 404, 'another member\'s upload: 404');
ok(count(activity('upload.discard')) >= 1 && count(activity('upload.start')) >= 3, 'upload.start and upload.discard logged');

echo "6. The worker's upload_sessions pass — expired sessions, decided samples cleared\n";
$open = body_json(req('POST', '/upload/start', ['jar' => $j, 'headers' => $hdr, 'form' => ['filename' => 'stale.csv', 'byte_size' => 20]]))['upload_id'];
req('POST', "/upload/part?upload=$open&n=1", ['jar' => $j, 'headers' => array_merge($hdr, ['Content-Type: application/octet-stream']), 'raw' => str_repeat('b', 20)]);
pg("UPDATE upload_sessions SET expires_at = now() - interval '1 minute' WHERE id = $open");
ok(pg1("SELECT jsonb_exists(sniff, 'sample') FROM upload_sessions WHERE id = $u") === 't', 'before the pass: the confirmed upload still holds its sample');
exec('php bin/worker.php --passes=upload_sessions 2>&1', $out, $rc);
$rep = json_decode((string) end($out), true) ?: [];
ok($rc === 0 && ($rep['ran']['upload_sessions']['expired'] ?? 0) >= 1 && ($rep['ran']['upload_sessions']['samples_cleared'] ?? 0) >= 1, 'the pass ran: ' . json_encode($rep['ran']['upload_sessions'] ?? null));
ok(pg1("SELECT status FROM upload_sessions WHERE id = $open") === 'expired' && !is_dir(need('DL_STORAGE_DIR') . "/landing/.parts/$open"), 'an open session past its time: expired, its parts removed');
ok(pg1("SELECT jsonb_exists(sniff, 'sample') FROM upload_sessions WHERE id = $u") === 'f' && pg1("SELECT jsonb_path_exists(sniff, '$.columns[*].sample') FROM upload_sessions WHERE id = $u") === 'f'
    && (int) pg1("SELECT jsonb_array_length(sniff->'columns') FROM upload_sessions WHERE id = $u") === 4, 'a confirmed session: the 50 rows and each column\'s samples gone; the columns\' names and types kept');
ok(pg1("SELECT jsonb_exists(sniff, 'sample') FROM upload_sessions WHERE id = $uw") === 'f', 'a discarded session: its sample gone too');
$m = map_json(26, $u);
ok(($m['json']['data']['upload']['sniff']['sample_cleared'] ?? null) === true && ($m['json']['data']['upload']['sniff']['sample'] ?? null) === [], 'the mapping screen says the sample was cleared');
finish();
