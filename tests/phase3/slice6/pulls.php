<?php
/**
 * Slice 6 proof 2 — the PULL (docs/build-specs/sources-pulls.md "The pull", "Proof": http_file lands once, a second pull answers 304 and lands nothing, the same
 * bytes under a new ETag are refused by the idempotency key; robots-blocked pauses with the reason; five failures pause and notify; the lineage edge; the SFTP
 * connector through its transport seam; the worker's pass). A pull runs in a child PHP process as the worker runs one (cli_pull). Run through run.sh.
 */
require __DIR__ . '/lib.php';
$mk = static fn (array $f, int $as = 27): array => post_json($as, '/sources/save.php', $f);
$root = dirname(__DIR__, 3);
$sha = static fn (string $f): string => hash_file('sha256', fx_dir() . '/' . $f);
fx_files();

echo "1. A file at an address — lands once\n";
fx_state(['pull_sales.csv' => ['file' => 'sales_a.csv', 'etag' => '"v1"', 'last_modified' => 'Mon, 01 Sep 2026 10:00:00 GMT']]);
$r = $mk(['name' => 'Pull sales', 'connector' => 'http_file', 'url' => fx_url('pull_sales.csv'), 'format' => 'csv', 'target_name' => 'pull_sales', 'schedule_minutes' => '60']);
$id = (int) ($r['json']['record_id'] ?? 0);
$ds = ds_id('raw.pull_sales');
fx_log(true);
$p1 = cli_pull($id);
ok(($p1['status'] ?? '') === 'ok' && ($p1['items_seen'] ?? -1) === 1 && ($p1['items_landed'] ?? -1) === 1 && count($p1['load_ids'] ?? []) === 1 && ($p1['bytes'] ?? 0) >= 150, 'the first pull: ok, one file seen, one landed, one load — ' . json_encode($p1));
$load1 = (int) ($p1['load_ids'][0] ?? 0);
$log = fx_log();
ok(count($log) === 2 && $log[0]['path'] === '/robots.txt' && $log[1]['path'] === '/data/pull_sales.csv' && $log[1]['inm'] === null, 'robots.txt first, then the file — no conditional header on the first fetch');
ok(str_starts_with($log[1]['ua'], 'MaluDB-DataLake/1.0 (+http://127.0.0.1:9001)'), 'the honest user-agent names this installation: ' . $log[1]['ua']);
$blob = pg1("SELECT sha256 || '|' || byte_size || '|' || storage_path || '|' || ref_count FROM blobs WHERE sha256 = '" . $sha('sales_a.csv') . "'");
[$bsha, $bsize, $bpath, $bref] = explode('|', (string) $blob) + [null, null, null, null];
ok($bsha === $sha('sales_a.csv') && (int) $bsize === filesize(fx_dir() . '/sales_a.csv') && $bpath === 'landing/' . substr($bsha, 0, 2) . '/' . substr($bsha, 2, 2) . '/' . $bsha && (int) $bref >= 1, 'landed once by its hash: the blob, its size, landing/aa/bb/sha256, referred to by its loads');
ok(is_file(need('DL_STORAGE_DIR') . '/' . $bpath) && hash_file('sha256', need('DL_STORAGE_DIR') . '/' . $bpath) === $bsha, 'the landed file is the bytes as received');
$ld = pg1("SELECT source_id || '|' || pull_id || '|' || blob_id || '|' || landing_sha256 || '|' || mode || '|' || idempotency_key || '|' || (mapping ->> 'format') || '|' || (mapping -> 'read_options' ->> 'delim') FROM loads WHERE id = $load1");
ok($ld === "$id|{$p1['pull_id']}|" . pg1("SELECT id FROM blobs WHERE sha256 = '$bsha'") . "|$bsha|append|$ds:$bsha|csv|,", "the load: its source, its pull, its blob, the key '<dataset>:<sha256>', the format and delimiter it will be read with ($ld)");
ok(wait_load($load1) === 'loaded' && raw_count('raw.pull_sales') === 3, 'the writer loaded it: 3 rows in raw.pull_sales');
[$cols, $rows] = raw_rows('raw.pull_sales', 'order_id, region, amount', 'order_id');
ok($cols === ['order_id', 'region', 'amount'] && ($rows[0] ?? []) === ['SO-001', 'North', '120.50'] && ($rows[2] ?? []) === ['SO-003', 'East', '45.25'], 'raw is text as it arrived: 120.50 keeps its trailing zero');
ok((int) pg1("SELECT count(*) FROM lineage_edges WHERE from_source_id = $id AND to_dataset_id = $ds AND via = 'load'") === 1, 'the lineage edge from the source to its dataset');
$feeds = get_json(27, "/sources/$id")['json']['data']['feeds'] ?? [];
ok(count($feeds) === 1 && $feeds[0]['key'] === 'raw.pull_sales' && $feeds[0]['last_seen'] !== null, 'the Feeds tab names the dataset it feeds, from the lineage');
$row = source_row($id);
$pull = last_pull($id);
ok($row['last_ok_at'] !== null && $row['consecutive_failures'] === 0 && $row['paused'] === false && (int) $row['last_pull_id'] === (int) $p1['pull_id'], 'the source: last good pull set, no failures');
ok($pull['status'] === 'ok' && $pull['listings_seen'] === 1 && $pull['listings_new'] === 1 && $pull['listings_changed'] === 0 && $pull['http_requests'] === 2 && $pull['error'] === null, 'the pull row: counts and status');
ok(($pull['policy']['state']['http']['etag'] ?? '') === '"v1"' && ($pull['policy']['state']['http']['last_modified'] ?? '') === 'Mon, 01 Sep 2026 10:00:00 GMT' && ($pull['policy']['robots'] ?? '') === 'ok'
    && str_starts_with((string) ($pull['policy']['user_agent'] ?? ''), 'MaluDB-DataLake/1.0') && $pull['policy']['requests'] === [], 'its policy: the ETag and Last-Modified kept, robots ok, the user-agent, no request that went wrong');
$done = pg1("SELECT source || '|' || COALESCE(actor_member_id::text, 'none') || '|' || dataset_id || '|' || (after ->> 'status') || '|' || (after ->> 'items_landed') FROM activity_log WHERE action = 'source.pull_done' AND entity_id = $id ORDER BY id LIMIT 1");
ok($done === "cron|none|$ds|ok|1", "source.pull_done is written by the worker (source cron): $done");
$pj = get_json(27, "/sources/$id?tab=pulls")['json']['data']['pulls'][0] ?? [];
ok($pj['status'] === 'ok' && $pj['items_landed'] === 1 && $pj['loads'] === ['count' => 1, 'ids' => [$load1]], 'the Pulls tab (JSON): the pull and the load it made');
$ph = html_as(27, "/sources/$id?tab=pulls")['body'];
ok(str_contains($ph, 'id="pull-' . $p1['pull_id'] . '"') && str_contains($ph, 'href="/loads/' . $load1 . '"') && !str_contains($ph, 'hx-trigger="every 3s"'), 'the Pulls tab links the load and stops polling once the pull is done');

echo "2. Nothing changed — a 304; the same bytes under a new ETag — refused by the key\n";
fx_log(true);
$p2 = cli_pull($id);
ok(($p2['status'] ?? '') === 'ok' && ($p2['items_seen'] ?? -1) === 1 && ($p2['items_landed'] ?? -1) === 0 && ($p2['load_ids'] ?? [1]) === [], 'the second pull answers 304: ok, nothing landed — ' . json_encode($p2));
$log = fx_log();
ok(count($log) === 2 && $log[1]['inm'] === '"v1"' && $log[1]['ims'] === 'Mon, 01 Sep 2026 10:00:00 GMT', 'it sent If-None-Match and If-Modified-Since from the last pull\'s policy');
ok((int) pg1("SELECT count(*) FROM loads WHERE dataset_id = $ds") === 1 && (int) pg1("SELECT count(*) FROM blobs WHERE sha256 = '$bsha'") === 1, 'no new load, no new blob');
ok((last_pull($id)['policy']['state']['http']['etag'] ?? '') === '"v1"', 'the ETag is carried to the next pull');
fx_state(['pull_sales.csv' => ['file' => 'sales_a.csv', 'etag' => '"v2"']]);
fx_log(true);
$p3 = cli_pull($id);
ok(($p3['status'] ?? '') === 'ok' && ($p3['items_landed'] ?? -1) === 0 && ($p3['load_ids'] ?? [1]) === [], 'the same bytes under a new ETag: fetched, ok, and refused by the idempotency key — landed 0');
ok(fx_log()[1]['inm'] === '"v1"' && (int) pg1("SELECT count(*) FROM loads WHERE dataset_id = $ds") === 1 && raw_count('raw.pull_sales') === 3, '…one load still, three rows still');
ok((last_pull($id)['policy']['state']['http']['etag'] ?? '') === '"v2"' && last_pull($id)['listings_changed'] === 0, '…and the new ETag is remembered; a repeat counts as nothing changed');
fx_state(['pull_sales.csv' => ['file' => 'sales_b.csv', 'etag' => '"v3"']]);
$p4 = cli_pull($id);
$load2 = (int) ($p4['load_ids'][0] ?? 0);
ok(($p4['status'] ?? '') === 'ok' && ($p4['items_landed'] ?? -1) === 1 && $load2 > $load1 && wait_load($load2) === 'loaded' && raw_count('raw.pull_sales') === 5, 'new content under a new ETag: a second load, appended — 5 rows');
ok((int) pg1("SELECT count(*) FROM blobs WHERE sha256 IN ('" . $sha('sales_a.csv') . "', '" . $sha('sales_b.csv') . "')") === 2, 'each version is its own blob');

echo "3. A protected file, a saved mapping, other formats\n";
fx_state(['secret.csv' => ['file' => 'sales_a.csv', 'etag' => '"s1"', 'auth' => ['bearer' => 'pull-bearer-5678']]]);
$r = $mk(['name' => 'Protected file', 'connector' => 'http_file', 'url' => fx_url('secret.csv'), 'target_name' => 'protected_file', 'schedule_minutes' => '0']);
$pid = (int) $r['json']['record_id'];
$f1 = cli_pull($pid);
ok(($f1['status'] ?? '') === 'failed' && str_contains((string) $f1['error'], '401') && ($f1['consecutive_failures'] ?? 0) === 1 && ($f1['paused'] ?? true) === false, 'without its credential the file answers 401: failed, one failure — ' . ($f1['error'] ?? ''));
post_json(27, '/sources/credential.php', ['source' => $pid, 'kind' => 'bearer', 'secret' => 'pull-bearer-5678']);
fx_log(true);
$f2 = cli_pull($pid);
ok(($f2['status'] ?? '') === 'ok' && ($f2['items_landed'] ?? 0) === 1 && ($f2['consecutive_failures'] ?? -1) === 0 && fx_log()[1]['auth'] === 'yes', 'with the bearer credential: ok, the failures cleared, the Authorization header went');
wait_loads($f2['load_ids']);
post_json(27, '/sources/credential.php', ['source' => $pid, 'kind' => 'api_key', 'secret' => 'pull-apikey-4321']);
pg("UPDATE source_pulls SET policy = '{}'::jsonb WHERE source_id = $pid");      // forget the ETag, so the file is asked for whole again
fx_state(['secret.csv' => ['file' => 'sales_a.csv', 'auth' => ['key' => 'pull-apikey-4321']]]);
fx_log(true);
$f3 = cli_pull($pid);
ok(($f3['status'] ?? '') === 'ok' && fx_log()[1]['key'] === 'pull-apikey-4321' && fx_log()[1]['auth'] === null, 'an API-key credential goes in X-API-Key (and no Authorization with it)');
// a saved mapping: renames and skips
pg("INSERT INTO import_templates (name, kind, mapping, created_by) VALUES ('proof sales map', 'csv', '{\"sheet\": null, \"header_row\": 1, \"columns\": {\"order_id\": {\"name\": \"order_number\", \"type\": \"VARCHAR\", \"personal_kind\": null, \"skip\": false}, \"customer_email\": {\"name\": \"customer_email\", \"type\": \"VARCHAR\", \"personal_kind\": \"email\", \"skip\": true}}, \"dedupe_key\": [\"order_number\"]}', 27)");
$tid = (int) pg1("SELECT id FROM import_templates WHERE name = 'proof sales map'");
fx_state(['mapped.csv' => ['file' => 'sales_a.csv']]);
$r = $mk(['name' => 'Mapped file', 'connector' => 'http_file', 'url' => fx_url('mapped.csv'), 'template_id' => (string) $tid, 'target_name' => 'mapped_file', 'schedule_minutes' => '0']);
$mp = cli_pull((int) $r['json']['record_id']);
ok(($mp['status'] ?? '') === 'ok' && wait_loads($mp['load_ids'] ?? []) === [(int) ($mp['load_ids'][0] ?? 0) => 'loaded'], 'a source with a saved mapping pulls and loads');
[$cols] = raw_rows('raw.mapped_file', 'order_number, region, amount', 'order_number');
$live = pg("SELECT string_agg(name, ',' ORDER BY ordinal) FROM dataset_columns WHERE dataset_id = " . ds_id('raw.mapped_file') . " AND removed_snapshot_id IS NULL AND NOT is_system");
ok($cols === ['order_number', 'region', 'amount'] && ($live[0] ?? '') === 'order_number,region,amount', 'the mapping applies: order_id renamed order_number, customer_email left out — ' . ($live[0] ?? ''));
fx_state(['book.xlsx' => ['file' => 'book.xlsx'], 'events.ndjson' => ['file' => 'events.ndjson'], 'semi.csv' => ['file' => 'semi.csv'], 'sales.tsv' => ['file' => 'sales_a.csv']]);
$r = $mk(['name' => 'Workbook', 'connector' => 'http_file', 'url' => fx_url('book.xlsx'), 'format' => 'auto', 'target_name' => 'a_workbook', 'schedule_minutes' => '0']);
$wb = cli_pull((int) $r['json']['record_id']);
ok(($wb['status'] ?? '') === 'ok' && wait_loads($wb['load_ids'] ?? []) !== [] && pg1("SELECT mapping ->> 'format' FROM loads WHERE id = " . (int) $wb['load_ids'][0]) === 'workbook' && (raw_count('raw.a_workbook') ?? 0) > 0,
    'an Excel workbook, format found out from its bytes: read as a workbook (' . raw_count('raw.a_workbook') . ' rows)');
$r = $mk(['name' => 'Events', 'connector' => 'http_file', 'url' => fx_url('events.ndjson'), 'format' => 'ndjson', 'target_name' => 'some_events', 'schedule_minutes' => '0']);
$ev = cli_pull((int) $r['json']['record_id']);
$nl = count(array_filter(explode("\n", (string) file_get_contents(fx_dir() . '/events.ndjson'))));
ok(($ev['status'] ?? '') === 'ok' && wait_loads($ev['load_ids'] ?? []) !== [] && raw_count('raw.some_events') === $nl, "JSON lines: $nl rows");
$r = $mk(['name' => 'Semicolons', 'connector' => 'http_file', 'url' => fx_url('semi.csv'), 'format' => 'auto', 'target_name' => 'semicolons', 'schedule_minutes' => '0']);
$sc = cli_pull((int) $r['json']['record_id']);
wait_loads($sc['load_ids'] ?? []);
[$cols, $rows] = raw_rows('raw.semicolons', 'code, label, amount', 'code');
ok($cols === ['code', 'label', 'amount'] && ($rows[0] ?? []) === ['A1', 'First', '10,50'], 'auto finds the delimiter: a semicolon file reads as three columns');
$r = $mk(['name' => 'Tab separated', 'connector' => 'http_file', 'url' => fx_url('sales.tsv'), 'format' => 'tsv', 'target_name' => 'tab_sep', 'schedule_minutes' => '0']);
$tb = cli_pull((int) $r['json']['record_id']);
ok(pg1("SELECT mapping -> 'read_options' ->> 'delim' FROM loads WHERE id = " . (int) ($tb['load_ids'][0] ?? 0)) === "\t", 'tsv reads with a tab (the comma file is then one column — the proof is the setting, not the data)');
wait_loads($tb['load_ids'] ?? []);
// two sources into one dataset: the key is per dataset and bytes
$r = $mk(['name' => 'Pull sales (twin)', 'connector' => 'http_file', 'url' => fx_url('pull_sales.csv'), 'target_dataset' => 'raw.pull_sales', 'schedule_minutes' => '0']);
$twin = cli_pull((int) $r['json']['record_id']);
ok(($twin['status'] ?? '') === 'ok' && ($twin['items_landed'] ?? -1) === 0 && raw_count('raw.pull_sales') === 5, 'a second source reading the same bytes into the same dataset: refused by the key, 5 rows still');
// a failed load of bytes already in the lake is queued again by the next pull of them
pg("UPDATE loads SET status = 'failed', error = 'proof' WHERE id = $load2");
pg("UPDATE source_pulls SET policy = '{}'::jsonb WHERE source_id = $id");
$again = cli_pull($id);
ok(($again['items_landed'] ?? -1) === 0, 'the same bytes again: landed 0 (the key knows them)');
ok(pg1("SELECT status FROM loads WHERE id = $load2") !== 'failed' && wait_load($load2) === 'no_op' && raw_count('raw.pull_sales') === 5, '…but a FAILED load of them is queued again, and the writer answers "already loaded" (5 rows)');

echo "4. Blocked means blocked\n";
fx_state(['nope.csv' => ['file' => 'sales_a.csv'], 'wall.csv' => ['file' => 'sales_a.csv', 'status' => 403, 'wall' => true], 'busy.csv' => ['file' => 'sales_a.csv', 'status' => 429]]);
$r = $mk(['name' => 'Robots says no', 'connector' => 'http_file', 'url' => fx_url('nope.csv', 'blocked'), 'target_name' => 'robots_no', 'schedule_minutes' => '60']);
$rid = (int) $r['json']['record_id'];
fx_log(true);
$last = last_notice_id();
$b = cli_pull($rid);
ok(($b['status'] ?? '') === 'blocked' && ($b['paused'] ?? false) === true && str_contains((string) $b['paused_reason'], 'robots.txt disallows /blocked/nope.csv') && ($b['consecutive_failures'] ?? -1) === 0,
    'robots.txt disallows the path: the pull is blocked, the source PAUSES with the reason, no failure is counted — ' . ($b['paused_reason'] ?? ''));
ok(array_column(fx_log(), 'path') === ['/robots.txt'], 'the forbidden path was never requested — only robots.txt');
ok(pg1("SELECT robots_state FROM sources WHERE id = $rid") === 'blocked' && last_pull($rid)['status'] === 'blocked' && (last_pull($rid)['policy']['robots'] ?? '') === 'blocked', 'the source and the pull record robots: blocked');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.paused_auto' AND entity_id = $rid AND after ->> 'reason' LIKE 'robots.txt disallows%'") === 1, 'source.paused_auto is logged with the reason');
$n = last_notice(27, 'load_failed', $last);
ok($n !== null && str_contains($n[0], 'Robots says no was paused') && $n[1] === 'source' && (int) $n[2] === $rid, 'the creator is told: ' . ($n[0] ?? 'nothing'));
$ref = cli_pull($rid);
ok(str_contains((string) ($ref['refused'] ?? ''), 'paused'), 'a paused source is not pulled, even on request — ' . ($ref['refused'] ?? json_encode($ref)));
ok(post_json(27, '/sources/resume.php', ['source' => $rid])['code'] === 200 && (cli_pull($rid)['status'] ?? '') === 'blocked' && source_row($rid)['paused'] === true, 'resumed and pulled again: blocked and paused again — blocked means blocked');
foreach (['wall.csv' => ['http_403', 'Cloudflare wall'], 'busy.csv' => ['http_429', 'rate limit']] as $file => [$reason, $what]) {
    $r = $mk(['name' => "Host says $reason", 'connector' => 'http_file', 'url' => fx_url($file), 'target_name' => 'host_' . substr($reason, 5), 'schedule_minutes' => '0']);
    $w = cli_pull((int) $r['json']['record_id']);
    ok(($w['status'] ?? '') === 'blocked' && ($w['paused'] ?? false) === true && str_contains((string) $w['paused_reason'], $reason) && ($w['consecutive_failures'] ?? -1) === 0, "a $what ($reason): blocked and paused — " . ($w['paused_reason'] ?? ''));
}

echo "5. Five failures pause the source and tell its keepers\n";
fx_state(['gone.csv' => ['file' => 'sales_a.csv', 'status' => 404]]);
$r = $mk(['name' => 'Failing file', 'connector' => 'http_file', 'url' => fx_url('gone.csv'), 'target_name' => 'failing_file', 'schedule_minutes' => '5']);
$fid = (int) $r['json']['record_id'];
$last = last_notice_id();
foreach ([1 => [1.0, 2.2], 2 => [3.0, 4.2], 3 => [7.0, 8.2], 4 => [15.0, 16.2]] as $n => [$lo, $hi]) {
    $f = cli_pull($fid);
    $mins = (float) pg1("SELECT EXTRACT(epoch FROM backoff_until - now()) / 60 FROM sources WHERE id = $fid");
    ok(($f['status'] ?? '') === 'failed' && ($f['consecutive_failures'] ?? 0) === $n && $mins >= $lo && $mins <= $hi && ($f['paused'] ?? true) === false, "failure $n: failed, backing off about " . 2 ** $n . ' minutes (' . round($mins, 1) . ')');
}
ok(!in_array($fid, due_ids(gmdate('c', time() + 60)), true) && in_array($fid, due_ids(gmdate('c', time() + 3600)), true), 'a source backing off is not due; once the back-off has passed it is');
ok((get_json(27, "/sources/$fid")['json']['data']['source']['state'] ?? '') === 'backing_off', 'the page says "Backing off"');
$f = cli_pull($fid);
ok(($f['status'] ?? '') === 'failed' && ($f['consecutive_failures'] ?? 0) === 5 && ($f['paused'] ?? false) === true && str_starts_with((string) $f['paused_reason'], '5 failures in a row: HTTP 404'), 'the fifth failure pauses it — ' . ($f['paused_reason'] ?? ''));
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.paused_auto' AND entity_id = $fid AND (after ->> 'consecutive_failures')::int = 5") === 1, 'source.paused_auto {consecutive_failures: 5, reason}');
$told = pg("SELECT member_id FROM notifications WHERE kind = 'load_failed' AND record_type = 'source' AND record_id = $fid AND id > $last ORDER BY member_id");
ok($told === ['1', '27'], 'the creator and every holder of sources.manage are told, once each: ' . implode(',', $told));
ok(str_contains((string) pg1("SELECT body FROM notifications WHERE kind = 'load_failed' AND record_id = $fid AND member_id = 27 AND id > $last"), '5 failures in a row'), '…with the reason');
ok(!in_array($fid, due_ids(gmdate('c', time() + 86400)), true), 'a paused source is never due');
cli_pull($fid);
ok((int) pg1("SELECT count(*) FROM notifications WHERE kind = 'load_failed' AND record_type = 'source' AND record_id = $fid AND id > $last") === 2, 'a refused pull of a paused source tells nobody again');
$fl = $mk(['name' => 'Flaky file', 'connector' => 'http_file', 'url' => fx_url('flaky.csv'), 'target_name' => 'flaky_file', 'schedule_minutes' => '0']);
$flid = (int) $fl['json']['record_id'];
fx_state(['flaky.csv' => ['file' => 'sales_a.csv', 'status' => 500]]);
cli_pull($flid);
cli_pull($flid);
ok(source_row($flid)['consecutive_failures'] === 2 && source_row($flid)['backoff_until'] !== null, 'a flaky file: two failures');
fx_state(['flaky.csv' => ['file' => 'sales_a.csv']]);
$ok = cli_pull($flid);
$row = source_row($flid);
ok(($ok['status'] ?? '') === 'ok' && $row['consecutive_failures'] === 0 && $row['backoff_until'] === null, 'a good pull clears the ladder');
ok(post_json(27, '/sources/resume.php', ['source' => $fid])['code'] === 200 && source_row($fid)['consecutive_failures'] === 0, 'Resume clears the failures too');

echo "6. SFTP — through the transport seam, with a directory\n";
$dir = need('DL_DEV_STATE') . '/sftp';
@mkdir($dir, 0777, true);
array_map('unlink', glob("$dir/*") ?: []);
copy(fx_dir() . '/sales_a.csv', "$dir/sales_2026-09-01.csv");
copy(fx_dir() . '/sales_b.csv', "$dir/sales_2026-09-02.csv");
file_put_contents("$dir/notes.txt", "not a sales file\n");
touch("$dir/sales_2026-09-01.csv", time() - 7200);
touch("$dir/sales_2026-09-02.csv", time() - 3600);
$log = need('DL_DEV_STATE') . '/sftp.log';
@unlink($log);
$o = ['sftp_dir' => $dir, 'sftp_log' => $log, 'password' => 'sftp-pw-9876'];
$r = $mk(['name' => 'SFTP daily', 'connector' => 'sftp_file', 'sftp_host' => 'sftp.example.invalid', 'sftp_port' => '2222', 'sftp_user' => 'reports', 'sftp_path' => '/outgoing', 'pattern' => 'sales_*.csv', 'format' => 'csv', 'target_name' => 'sftp_daily', 'schedule_minutes' => '60']);
$sid = (int) $r['json']['record_id'];
$s0 = cli_pull($sid, $o);
ok(($s0['status'] ?? '') === 'blocked' && ($s0['paused'] ?? false) === true && str_starts_with((string) $s0['paused_reason'], 'credential:') && ($s0['consecutive_failures'] ?? -1) === 0, 'an SFTP source with no credential pauses (no failure counted): ' . ($s0['paused_reason'] ?? ''));
$c = post_json(27, '/sources/credential.php', ['source' => $sid, 'kind' => 'sftp_password', 'secret' => 'sftp-pw-9876']);
ok($c['code'] === 200 && source_row($sid)['paused'] === false, 'sealing a credential resumes a source paused for want of one');
$s1 = cli_pull($sid, $o);
ok(($s1['status'] ?? '') === 'ok' && ($s1['items_seen'] ?? -1) === 2 && ($s1['items_landed'] ?? -1) === 2 && count($s1['load_ids'] ?? []) === 2, 'the first pull: two files match sales_*.csv, both land — notes.txt does not — ' . json_encode($s1));
ok(pg("SELECT page_cursor FROM loads WHERE pull_id = {$s1['pull_id']} ORDER BY id") === ['sales_2026-09-01.csv', 'sales_2026-09-02.csv'], 'oldest first: one blob and one load per file');
wait_loads($s1['load_ids']);
ok(raw_count('raw.sftp_daily') === 5, '5 rows from the two files');
$plans = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($log)))));
ok(array_column($plans, 'op') === ['list', 'list', 'get', 'get'] || array_column($plans, 'op') === ['list', 'get', 'get'], 'the plans: a listing, then a get for each new file (' . implode(',', array_column($plans, 'op')) . ')');
$lp = array_values(array_filter($plans, static fn (array $p): bool => $p['op'] === 'get'))[0] ?? [];
ok(($lp['tool'] ?? '') === 'curl' && in_array('--netrc-file', $lp['argv'] ?? [], true) && in_array('--insecure', $lp['argv'] ?? [], true) && in_array('sftp://sftp.example.invalid:2222/outgoing/sales_2026-09-01.csv', $lp['argv'] ?? [], true),
    'curl with a netrc file and the file\'s URL (host key not pinned: --insecure)');
ok(array_filter($plans, static fn (array $p): bool => $p['password_on_argv']) === [] && ($lp['password_in_netrc'] ?? false) === true && ($lp['file_names'] ?? []) === ['netrc'], 'the password is in the 0600 netrc file and on no command line');
$sj = last_pull($sid);
ok(count($sj['policy']['state']['seen'] ?? []) === 2 && ($sj['policy']['files_taken'] ?? 0) === 2, 'the two files are remembered (name | size | modified)');
$s2 = cli_pull($sid, $o);
ok(($s2['status'] ?? '') === 'ok' && ($s2['items_seen'] ?? -1) === 2 && ($s2['items_landed'] ?? -1) === 0 && !in_array('get', array_column(array_map(static fn (string $l): array => json_decode($l, true), array_slice(array_values(array_filter(explode("\n", (string) file_get_contents($log)))), count($plans))), 'op'), true),
    'the second pull: nothing new, nothing fetched');
file_put_contents("$dir/sales_2026-09-03.csv", "order_id,region,amount,customer_email\nSO-006,South,15.00,fa.buyer@example.invalid\n");
copy(fx_dir() . '/sales_a.csv', "$dir/sales_2026-09-04_copy.csv");
touch("$dir/sales_2026-09-03.csv", time() - 600);
touch("$dir/sales_2026-09-04_copy.csv", time() - 300);
$s3 = cli_pull($sid, $o);
ok(($s3['status'] ?? '') === 'ok' && ($s3['items_landed'] ?? -1) === 1 && count($s3['load_ids']) === 1, 'a new file lands; the same bytes under a new name are refused by the key (landed 1 of 2 new)');
wait_loads($s3['load_ids']);
ok(raw_count('raw.sftp_daily') === 6, 'the dataset now holds 6 rows');
$pin = str_repeat('A', 43);
$pinned = $mk(['source' => $sid, 'host_key_sha256' => $pin]);
ok($pinned['code'] === 200, 'a host key fingerprint pinned');
@unlink($log);
cli_pull($sid, $o);
$pp = json_decode((string) strtok((string) file_get_contents($log), "\n"), true) ?: [];
ok(in_array('--hostpubsha256', $pp['argv'] ?? [], true) && in_array($pin, $pp['argv'] ?? [], true) && !in_array('--insecure', $pp['argv'] ?? [], true), 'with the key pinned curl is told the fingerprint and not --insecure');
ok($mk(['source' => $sid, 'host_key_sha256' => 'short'])['code'] === 422, 'a fingerprint of the wrong length is refused');
ok(post_json(27, '/sources/pause.php', ['source' => $sid])['code'] === 200 && post_json(27, '/sources/resume.php', ['source' => $sid])['code'] === 200, '(paused and resumed)');
// the listing curl and sftp print
[, $o2] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/write.php";
    $t = "drwxr-xr-x    2 reports reports      4096 Sep  1 10:00 archive\n-rw-r--r--    1 reports reports       205 Sep  1 10:00 sales_2026-09-01.csv\n-rw-r--r--    1 reports reports      1234 Jan  5  2025 old report.csv\nlrwxrwxrwx    1 reports reports         9 Sep  1 10:00 latest -> sales_2026-09-01.csv\nsftp> ls -l\n";
    echo json_encode(dl_sftp_parse_listing($t, new DateTimeImmutable("2026-10-11T12:00:00+00:00")));', ['DL6_ROOT' => $root]);
$pl = json_decode($o2, true) ?: [];
ok(count($pl) === 2 && $pl[0]['name'] === 'sales_2026-09-01.csv' && $pl[0]['size'] === 205 && $pl[0]['stamp'] === 'Sep 1 10:00' && $pl[1]['name'] === 'old report.csv' && $pl[1]['size'] === 1234 && $pl[1]['mtime'] === strtotime('2025-01-05 00:00 UTC'),
    'the ls -l listing is read: regular files only (a directory, a link and the sftp> echo are skipped), names with a space, a year instead of a time');
// the secrets key missing, and a credential that does not open
$kk = cli_pull($sid, $o + ['no_key' => true]);
ok(($kk['status'] ?? '') === 'blocked' && ($kk['paused'] ?? false) === true && str_contains((string) $kk['paused_reason'], 'secrets key') && ($kk['consecutive_failures'] ?? -1) === 0, 'without DL_SECRETS_KEY a source that needs a credential pauses: ' . ($kk['paused_reason'] ?? ''));
post_json(27, '/sources/resume.php', ['source' => $sid]);
pg("UPDATE source_credentials SET ciphertext = convert_to('v1.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'UTF8') WHERE source_id = $sid");
$tk = cli_pull($sid, $o);
ok(($tk['status'] ?? '') === 'blocked' && str_contains((string) $tk['paused_reason'], 'cannot be opened'), 'a credential that does not open (tampered): blocked and paused — ' . ($tk['paused_reason'] ?? ''));

echo "7. What is due, and the worker's pass\n";
$dueNow = gmdate('c');
$later = gmdate('c', time() + 7200);
$mid = gmdate('c', time() + 1800);
ok(!in_array($id, due_ids($dueNow), true) && !in_array($id, due_ids($mid), true) && in_array($id, due_ids($later), true), 'a source on a 60-minute schedule is due 60 minutes after its last good pull, not before');
$never = $mk(['name' => 'Never pulled', 'connector' => 'http_file', 'url' => fx_url('pull_sales.csv'), 'target_name' => 'never_pulled', 'schedule_minutes' => '1440']);
$nid = (int) $never['json']['record_id'];
$manual = $mk(['name' => 'Manual only', 'connector' => 'http_file', 'url' => fx_url('pull_sales.csv'), 'target_name' => 'manual_only', 'schedule_minutes' => '0']);
$mid2 = (int) $manual['json']['record_id'];
$upl = $mk(['name' => 'An upload shape', 'connector' => 'upload', 'target_name' => 'shape_only']);
$due = due_ids($dueNow);
ok(in_array($nid, $due, true) && !in_array($mid2, $due, true) && !in_array((int) $upl['json']['record_id'], $due, true) && !in_array($rid, due_ids($later), true), 'never pulled: due; manual (0 minutes), an upload shape, a paused source: never');
$cu = cli_pull((int) $upl['json']['record_id']);
ok(str_contains((string) ($cu['refused'] ?? ''), 'manual_only'), 'an upload source is never pulled: manual_only');
isolate_sources([$id, $nid]);
pg("UPDATE sources SET last_ok_at = now() - interval '3 hours' WHERE id = $id");
$qp = post_json(27, '/sources/pull.php', ['source' => $nid]);
$qpid = (int) ($qp['json']['pull_id'] ?? 0);
fx_state(['pull_sales.csv' => ['file' => 'sales_b.csv', 'etag' => '"v3"']]);
$rep = worker_schedules();
$sr = $rep['ran']['schedules']['sources'] ?? [];
ok(($rep['_rc'] ?? 1) === 0 && ($sr['queued'] ?? 0) === 1 && ($sr['due'] ?? 0) >= 1 && ($sr['pulls'] ?? 0) >= 2 && ($sr['errors'] ?? 1) === 0, 'the worker\'s pass: the queued pull first, then what is due — ' . json_encode($sr));
ok(pg1("SELECT status || '|' || (policy ->> 'queued') FROM source_pulls WHERE id = $qpid") !== 'running|true' && pg1("SELECT status FROM source_pulls WHERE id = $qpid") === 'ok', 'the queued pull was taken and finished');
ok(pg1("SELECT kind FROM source_pulls WHERE source_id = $id ORDER BY id DESC LIMIT 1") === 'scheduled' && source_row($id)['last_ok_at'] > date('c', time() - 300), 'the due source was pulled as a scheduled pull');
$rep2 = worker_schedules();
ok((($rep2['ran']['schedules']['sources']['pulls'] ?? -1) === 0), 'the next pass finds nothing due and nothing queued');
$stuck = pg1("INSERT INTO source_pulls (source_id, kind, status, started_at) VALUES ($nid, 'manual', 'running', now() - interval '7 hours') RETURNING id");
$qq = post_json(27, '/sources/pull.php', ['source' => $nid]);
ok($qq['code'] === 200, 'a pull "running" for seven hours no longer blocks a new one');
worker_schedules();
ok(pg1("SELECT status || '|' || error FROM source_pulls WHERE id = $stuck") === 'failed|Abandoned — the worker stopped before it finished.' && pg1("SELECT status FROM source_pulls WHERE id = " . (int) $qq['json']['pull_id']) === 'ok', 'the worker marks it abandoned and takes the new one');
release_sources();
ok(cli_pull($nid, ['queued' => 99999999]) === ['refused' => 'That queued pull is gone.'], 'a queued pull that is gone is refused, not made up');
finish();
