<?php
/**
 * Slice 6 proof 3 — the POSTGRES connector (docs/build-specs/sources-pulls.md divergence 3 and "Proof": the postgres source pulls by watermark in pages; the weekly
 * reconciliation queues a reload when the counts differ). The "outside" database is dl_dev6_outside, reached as 127.0.0.2 with a role of its own; a table of a hundred
 * and twenty orders read in pages of fifty (DL_PG_PAGE_ROWS, honoured outside production only). A pull runs in a child PHP process. Run through run.sh.
 */
require __DIR__ . '/lib.php';
$outDb = need('DL6_OUTSIDE_DB');
$outUser = need('DL6_OUTSIDE_USER');
$outPw = need('DL6_OUTSIDE_PW');
$root = dirname(__DIR__, 3);
$mk = static fn (array $f, int $as = 27): array => post_json($as, '/sources/save.php', $f);
/** SQL as postgres on the outside database. */
$out = static function (string $sql) use ($outDb): array {
    $p = proc_open(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', $outDb], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $o = stream_get_contents($pipes[1]);
    $e = stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) { fwrite(STDERR, "outside psql failed: $e\n"); }
    return array_values(array_filter(explode("\n", (string) $o), static fn ($l) => $l !== ''));
};
/** The next Sunday at 03:30 UTC after now, plus $weeks weeks (the business's time zone is UTC here). */
$sunday = static function (int $weeks = 0): string {
    $d = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $d = $d->modify('next sunday')->setTime(3, 30)->modify("+$weeks weeks");
    return $d->format('c');
};

echo "1. The first pull — the whole table, in pages\n";
$r = $mk(['name' => 'Outside orders (pull)', 'connector' => 'postgres', 'host' => '127.0.0.2', 'port' => '5432', 'dbname' => $outDb, 'user' => $outUser, 'sslmode' => 'prefer', 'table' => 'public.orders',
    'watermark' => 'updated_at', 'key' => 'id', 'target_name' => 'pg_orders', 'schedule_minutes' => '0']);
$id = (int) ($r['json']['record_id'] ?? 0);
$ds = ds_id('raw.pg_orders');
ok($r['code'] === 200 && $id > 0 && pg1("SELECT dedupe_key::text FROM datasets WHERE id = $ds") === '{id}', 'the postgres source saved; its key is the dataset\'s dedupe key');
$np = cli_pull($id);
ok(($np['status'] ?? '') === 'blocked' && ($np['paused'] ?? false) === true && str_starts_with((string) $np['paused_reason'], 'credential:') && ($np['consecutive_failures'] ?? -1) === 0, 'with no database password the source pauses (no failure counted): ' . ($np['paused_reason'] ?? json_encode($np)));
ok(post_json(27, '/sources/credential.php', ['source' => $id, 'kind' => 'database_password', 'secret' => $outPw])['code'] === 200 && source_row($id)['paused'] === false, 'the password sealed — the source resumes');
$p1 = cli_pull($id);
ok(($p1['status'] ?? '') === 'ok' && ($p1['items_seen'] ?? -1) === 3 && ($p1['items_landed'] ?? -1) === 3 && count($p1['load_ids'] ?? []) === 3 && ($p1['http_requests'] ?? 0) === 3 && ($p1['bytes'] ?? 0) > 3000, 'a hundred and twenty rows in pages of fifty: three pages, three blobs, three loads — ' . json_encode($p1));
$ids1 = $p1['load_ids'] ?? [];
$modes = pg("SELECT page_cursor || ':' || mode || ':' || (mapping ->> 'format') FROM loads WHERE pull_id = " . (int) $p1['pull_id'] . ' ORDER BY id');
ok($modes === ['p1:append:json', 'p2:append:json', 'p3:append:json'], 'each page is its own load: append (a watermark pulls only what is new), read as JSON — ' . implode(' ', $modes));
ok(array_values(wait_loads($ids1)) === ['loaded', 'loaded', 'loaded'] && raw_count('raw.pg_orders') === 120, 'the writer loaded them: 120 rows');
[$cols, $rows] = raw_rows('raw.pg_orders', 'id, customer, amount, note', "CAST(id AS INTEGER)");
ok(($rows[0] ?? []) === ['1', 'Customer 1', '3.10', 'note 1'] && ($rows[9] ?? [])[3] === null && ($rows[119] ?? [])[0] === '120', 'raw is text as the database printed it: numeric(12,2) 3.10 keeps its zero, a NULL stays NULL');
[, $ts] = raw_rows('raw.pg_orders', 'updated_at', 'updated_at LIMIT 1');
ok(isset($ts[0][0]) && str_starts_with((string) $ts[0][0], '2026-09-01 00:01:00'), 'a timestamp keeps the database\'s own spelling (' . ($ts[0][0] ?? '?') . ') — only an ISO-8601 "T" string is retyped by the writer\'s JSON reader');
$maxTs = $out(['SELECT to_char(max(updated_at) AT TIME ZONE \'UTC\', \'YYYY-MM-DD HH24:MI:SS\') FROM public.orders'][0])[0];
$pol = last_pull($id)['policy'];
ok(str_starts_with((string) ($pol['state']['watermark'] ?? ''), $maxTs), 'the watermark kept for the next pull is the highest updated_at read: ' . ($pol['state']['watermark'] ?? '?'));
ok(($pol['rows'] ?? 0) === 120 && (int) pg1("SELECT count(*) FROM lineage_edges WHERE from_source_id = $id AND to_dataset_id = $ds") === 1, 'the pull counted 120 rows; the lineage edge exists');
$leak = (int) pg1("SELECT count(*) FROM activity_log WHERE COALESCE(before::text, '') || COALESCE(after::text, '') LIKE '%$outPw%'") + (int) pg1("SELECT count(*) FROM source_pulls WHERE policy::text LIKE '%$outPw%' OR error LIKE '%$outPw%'");
ok($leak === 0, 'the database password is in no log row and no pull');
[, $o] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/write.php";
    $s = dl_source_for_connector(db(), (int) getenv("DL6_SOURCE")); $pdo = DlConnectorPostgres::connect($s);
    $ro = $pdo->query("SHOW default_transaction_read_only")->fetchColumn(); $app = $pdo->query("SHOW application_name")->fetchColumn();
    try { $pdo->exec("CREATE TABLE public.nope (a int)"); $w = "wrote"; } catch (Throwable $e) { $w = "refused"; }
    echo json_encode([$ro, $app, $w]);', ['DL6_ROOT' => $root, 'DL6_SOURCE' => $id]);
ok((json_decode($o, true) ?: []) === ['on', 'datalake-pull', 'refused'], 'the connection is read-only and says who it is (application_name datalake-pull): nothing can be written to the outside database');

echo "2. The next pull — only what changed since the watermark\n";
$out("INSERT INTO public.orders SELECT g, 'Customer ' || g, (g * 3.10)::numeric(12,2), 'new ' || g, timestamptz '2026-09-02 00:00:00+00' + (g || ' minutes')::interval FROM generate_series(121, 125) g;
      UPDATE public.orders SET amount = amount + 1, updated_at = timestamptz '2026-09-03 00:00:00+00' WHERE id IN (5, 7);");
$p2 = cli_pull($id);
$l2 = (int) ($p2['load_ids'][0] ?? 0);
ok(($p2['status'] ?? '') === 'ok' && ($p2['items_landed'] ?? -1) === 1 && wait_load($l2) === 'loaded', 'five new rows and two updated: one page, one load');
[, $new] = raw_rows('raw.pg_orders', 'CAST(id AS INTEGER)', 'CAST(id AS INTEGER)', 27);
$got = array_map(static fn ($r) => (int) $r[0], raw_rows('raw.pg_orders', 'id', "CAST(id AS INTEGER), _dl_load_id", 27)[1]);
[, $byLoad] = raw_rows('raw.pg_orders', 'CAST(id AS INTEGER)', 'CAST(id AS INTEGER)');
$page2 = array_map(static fn ($r) => (int) $r[0], sql_as(27, "SELECT CAST(id AS INTEGER) FROM raw.pg_orders WHERE _dl_load_id = $l2 ORDER BY 1")['json']['data']['result']['rows'] ?? []);
ok($page2 === [5, 7, 121, 122, 123, 124, 125] && raw_count('raw.pg_orders') === 127, 'that load holds exactly rows 5, 7 and 121–125 — raw is history, the two updated orders now have two rows each (127)');
$p3 = cli_pull($id);
ok(($p3['status'] ?? '') === 'ok' && ($p3['items_seen'] ?? -1) === 0 && ($p3['load_ids'] ?? [1]) === [] && raw_count('raw.pg_orders') === 127, 'nothing new since: ok, no page, no load');
ok(str_starts_with((string) (last_pull($id)['policy']['state']['watermark'] ?? ''), '2026-09-03 00:00:00'), 'the watermark moved to the updates\' time (…09-03)');
$src = last_pull($id);
$act = (int) pg1("SELECT count(*) FROM activity_log WHERE action = 'source.pull_done' AND entity_id = $id AND source = 'cron'");
ok($act === 4, 'every pull is logged source.pull_done by the worker (4)');

echo "3. A source with no watermark replaces the dataset each pull\n";
$r = $mk(['name' => 'Outside orders (whole)', 'connector' => 'postgres', 'host' => '127.0.0.2', 'dbname' => $outDb, 'user' => $outUser, 'sslmode' => 'prefer', 'table' => 'public.orders', 'key' => 'id',
    'target_name' => 'pg_orders_whole', 'schedule_minutes' => '0']);
$wid = (int) $r['json']['record_id'];
post_json(27, '/sources/credential.php', ['source' => $wid, 'kind' => 'database_password', 'secret' => $outPw]);
$w1 = cli_pull($wid);
$modes = pg("SELECT page_cursor || ':' || mode FROM loads WHERE pull_id = " . (int) $w1['pull_id'] . ' ORDER BY id');
ok(($w1['status'] ?? '') === 'ok' && $modes === ['p1:replace', 'p2:append', 'p3:append'], 'no watermark: the whole table each time — the first page replaces, the rest append — ' . implode(' ', $modes));
wait_loads($w1['load_ids']);
ok(raw_count('raw.pg_orders_whole') === 125, '125 rows');
$w2 = cli_pull($wid);
wait_loads($w2['load_ids'] ?? []);
ok(($w2['status'] ?? '') === 'ok' && ($w2['items_landed'] ?? -1) === 3 && raw_count('raw.pg_orders_whole') === 125, 'pulled again with nothing changed: still 125, not 250, not 50 — the replace happened and all three pages loaded');
$same = true;
foreach (['p1', 'p2', 'p3'] as $pg) {
    $a = pg1("SELECT landing_sha256 FROM loads WHERE pull_id = " . (int) $w1['pull_id'] . " AND page_cursor = '$pg'");
    $b = pg1("SELECT landing_sha256 FROM loads WHERE pull_id = " . (int) $w2['pull_id'] . " AND page_cursor = '$pg'");
    $same = $same && $a !== null && $b !== null && $a !== $b;
}
ok($same, 'every page of a replacing pull carries its pull\'s marker: the three pages\' bytes differ from last time\'s (so none is taken for a repeat), the rows are the same');
ok(raw_count('raw.pg_orders_whole') === 125 && (int) sql_as(27, 'SELECT count(DISTINCT CAST(id AS INTEGER)) FROM raw.pg_orders_whole')['json']['data']['result']['rows'][0][0] === 125, '…125 rows, 125 different orders: nothing lost from the pages that append');

echo "4. The weekly reconciliation\n";
$out('DELETE FROM public.orders WHERE id IN (3, 4);');
$rec = static function (string $now, bool $force = false) use ($root): array {
    [, $o] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/write.php"; $GLOBALS["__public_door"] = "cron";
        echo json_encode(dl_reconcile_postgres(db(), new DateTimeImmutable(getenv("DL6_NOW")), getenv("DL6_FORCE") === "1"));', ['DL6_ROOT' => $root, 'DL6_NOW' => $now, 'DL6_FORCE' => $force ? '1' : '0']);
    return json_decode($o, true) ?: ['_raw' => $o];
};
isolate_sources([$id]);
$monday = (new DateTimeImmutable($sunday()))->modify('+1 day')->format('c');
$early = (new DateTimeImmutable($sunday()))->setTime(2, 30)->format('c');
ok(($rec($monday)['due'] ?? true) === false && ($rec($early)['due'] ?? true) === false, 'it runs on Sundays from 03:00 business time — not on a Monday, not at 02:30');
$sun = $sunday();
$r1 = $rec($sun);
ok(($r1['checked'] ?? 0) === 1 && ($r1['reloads'] ?? 0) === 1, 'Sunday 03:30: one source checked (the other postgres sources have not loaded yet), the counts differ — one reload queued: ' . json_encode($r1));
$lg = pg1("SELECT (after ->> 'source_count') || '|' || (after ->> 'lake_count') || '|' || (after ->> 'reload') FROM activity_log WHERE action = 'source.reconcile' AND entity_id = $id ORDER BY id DESC LIMIT 1");
ok($lg === '123|127|true', "source.reconcile {source_count, lake_count, reload}: $lg");
$q = pg1("SELECT id FROM source_pulls WHERE source_id = $id AND status = 'running' AND policy ->> 'queued' = 'true' AND policy ->> 'reload' = 'true'");
ok($q !== null, 'the reload is a queued pull');
$r2 = $rec($sun);
ok(($r2['checked'] ?? -1) === 0 && ($r2['skipped'] ?? 0) >= 1, 'asked again the same Sunday it does nothing — once a week');
$rl = cli_pull($id, ['queued' => (int) $q]);
$modes = pg("SELECT page_cursor || ':' || mode FROM loads WHERE pull_id = " . (int) $rl['pull_id'] . ' ORDER BY id');
ok(($rl['status'] ?? '') === 'ok' && $modes === ['p1:replace', 'p2:append', 'p3:append'] && ($rl['items_landed'] ?? 0) === 3, 'the reload: the first page replaces, then the rest append — ' . implode(' ', $modes));
wait_loads($rl['load_ids']);
ok(raw_count('raw.pg_orders') === 123, 'the dataset is the table again: 123 rows (the updated orders\' history is gone with the replace)');
$old1 = pg1("SELECT landing_sha256 FROM loads WHERE pull_id = " . (int) $p1['pull_id'] . " AND page_cursor = 'p1'");
$new1 = pg1("SELECT landing_sha256 FROM loads WHERE pull_id = " . (int) $rl['pull_id'] . " AND page_cursor = 'p1'");
ok($old1 !== $new1, 'the reload\'s first page is not the first pull\'s bytes (the marker) — the writer did not mistake it for a repeat');
$r3 = $rec($sunday(1));
ok(($r3['checked'] ?? 0) === 1 && ($r3['reloads'] ?? -1) === 0, 'the next Sunday the counts agree: checked, no reload (' . json_encode($r3) . ')');
$lg = pg1("SELECT (after ->> 'source_count') || '|' || (after ->> 'lake_count') || '|' || (after ->> 'reload') FROM activity_log WHERE action = 'source.reconcile' AND entity_id = $id ORDER BY id DESC LIMIT 1");
ok($lg === '123|123|false', "…and says so: $lg");
$out('INSERT INTO public.orders VALUES (900, \'Late one\', 1.00, NULL, timestamptz \'2026-08-01 00:00:00+00\');');       // behind the watermark: an incremental pull never sees it
$p4 = cli_pull($id);
ok(($p4['status'] ?? '') === 'ok' && ($p4['items_seen'] ?? -1) === 0 && raw_count('raw.pg_orders') === 123, 'a row that appeared behind the watermark is missed by the incremental pull…');
$rep = worker_schedules($sunday(2));
$sr = $rep['ran']['schedules']['sources'] ?? [];
ok(($rep['_rc'] ?? 1) === 0 && ($sr['reconciled']['reloads'] ?? 0) === 1 && ($sr['queued'] ?? 0) === 1 && ($sr['ok'] ?? 0) >= 1, '…the worker\'s pass on the following Sunday reconciles, queues the reload and runs it in the same pass: ' . json_encode($sr));
$lastLoad = (int) pg1("SELECT max(id) FROM loads WHERE dataset_id = $ds");
wait_loads(array_map('intval', pg("SELECT id FROM loads WHERE dataset_id = $ds AND status IN ('queued', 'running')")));
ok(raw_count('raw.pg_orders') === 124, '…and the dataset has the late row: 124');
release_sources();

echo "5. What goes wrong\n";
post_json(27, '/sources/credential.php', ['source' => $id, 'kind' => 'database_password', 'secret' => 'not-the-password']);
$bad = cli_pull($id);
ok(($bad['status'] ?? '') === 'failed' && str_contains((string) $bad['error'], 'password authentication failed') && !str_contains((string) $bad['error'], 'not-the-password') && ($bad['consecutive_failures'] ?? 0) === 1, 'a wrong password: failed, one failure, the sentence says so and does not hold the password — ' . ($bad['error'] ?? ''));
ok((int) pg1("SELECT count(*) FROM source_pulls WHERE policy::text LIKE '%not-the-password%' OR error LIKE '%not-the-password%'") === 0, '…nor does the pull row');
post_json(27, '/sources/credential.php', ['source' => $id, 'kind' => 'database_password', 'secret' => $outPw]);
$r = $mk(['name' => 'Outside nothing', 'connector' => 'postgres', 'host' => '127.0.0.2', 'dbname' => $outDb, 'user' => $outUser, 'sslmode' => 'prefer', 'table' => 'public.not_there', 'key' => 'id', 'target_name' => 'pg_nothing', 'schedule_minutes' => '0']);
$nid = (int) $r['json']['record_id'];
post_json(27, '/sources/credential.php', ['source' => $nid, 'kind' => 'database_password', 'secret' => $outPw]);
$nt = cli_pull($nid);
ok(($nt['status'] ?? '') === 'failed' && str_contains((string) $nt['error'], 'not_there') && raw_count('raw.pg_nothing') === null, 'a table that is not there: failed with the database\'s words, nothing landed — ' . ($nt['error'] ?? ''));
$t = post_json(27, '/sources/test.php', ['source' => $nid]);
ok(($t['json']['state'] ?? '') === 'misconfigured' && str_contains((string) $t['json']['message'], 'no table'), 'Test says so before a pull has to: ' . ($t['json']['message'] ?? ''));
$r = $mk(['name' => 'Outside wrong column', 'connector' => 'postgres', 'host' => '127.0.0.2', 'dbname' => $outDb, 'user' => $outUser, 'sslmode' => 'prefer', 'table' => 'public.orders', 'watermark' => 'modified_on', 'target_name' => 'pg_wrongcol', 'schedule_minutes' => '0']);
ok($r['code'] === 200, '(a watermark column the table lacks is the probe\'s to find)');
post_json(27, '/sources/credential.php', ['source' => (int) $r['json']['record_id'], 'kind' => 'database_password', 'secret' => $outPw]);
$t = post_json(27, '/sources/test.php', ['source' => (int) $r['json']['record_id']]);
ok(($t['json']['state'] ?? '') === 'misconfigured' && str_contains((string) $t['json']['message'], 'modified_on'), 'Test: the table has no column modified_on — ' . ($t['json']['message'] ?? ''));
finish();
