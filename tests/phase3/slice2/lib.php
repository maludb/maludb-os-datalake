<?php
/**
 * Helpers for the slice 2 proofs (docs/build-specs/uploads-loads.md "Proof"), on top of slice 1's (tests/phase3/slice1/lib.php: actor,
 * post_json, get_json, post_form, html_as, pg1, ds_id, run_tag) and Phase 2's (sign_on, req, pg, the fake kernel, tokens, seed_lake).
 * Run through tests/phase3/slice2/run.sh: scratch database dl_dev2s with the engine (the writer and the query service real, on their units'
 * command lines), the app on :9001 (php -S or a real Apache), the fake kernel :9002, the fake MaluDB + MaluMail :9003.
 * Actors (bin/dev_directory.json): 1 the owner (super-admin); 26 Priya a Member; 27 Marco a Data engineer; 29 Ann (external);
 * 30 Dana an Analyst; 31 Lee a Member; 40 the Expert (an agent).
 * The fixtures are SYNTHETIC (tests/fixtures/ — example.invalid, the 555-01xx range, published test card numbers) and made at run time under
 * $DL_DEV_STATE/fixtures (fixtures()): never committed beyond tests/fixtures, never changed there.
 */
require dirname(__DIR__) . '/slice1/lib.php';

/** The run's fixture directory, filled once: the engine's fixtures copied, and the slice's own made (a 20 MB CSV, next month's file, a program). */
function fixtures(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $dir = need('DL_DEV_STATE') . '/fixtures';
    @mkdir($dir, 0700, true);
    $src = dirname(__DIR__, 2) . '/fixtures';
    foreach (['customers.csv', 'customers_v2.csv', 'suppliers_cp1252.csv', 'orders.xlsx', 'events.ndjson', 'products.parquet'] as $f) {
        copy("$src/$f", "$dir/$f");
    }
    // one CSV with a refused line AND a Windows-1252 é (a supplier list as a small business exports it)
    file_put_contents("$dir/suppliers_bad_cp1252.csv", mb_convert_encoding("supplier_id,name,contact_email,amount\n007,Café Résumé Supplies,cafe@example.invalid,12.50\n008,Naïve Widgets,naive@example.invalid,9.99,EXTRA\n009,Brûlée Parts,brulee@example.invalid,abc\n010,Zoë Tools,zoe@example.invalid,100\n", 'Windows-1252', 'UTF-8'));
    // next month's file: the same columns as customers.csv, other rows
    file_put_contents("$dir/customers_next_month.csv", "customer_id,name,email,phone,card_number,city,signed_up,balance\n"
        . "00060,Ivy Sample,ivy.sample@example.invalid,+1-555-0110,4111111111111111,Tulsa,2026-05-01,10.00\n"
        . "00061,Jo Example,jo.example@example.invalid,+1-555-0111,5555555555554444,Omaha,2026-05-02,20.00\n");
    // a 20 MB CSV (three parts of 8 MB): synthetic orders
    $big = "$dir/orders_20mb.csv";
    $fh = fopen($big, 'wb');
    fwrite($fh, "order_id,region,amount,customer_email\n");
    $i = 0;
    while (ftell($fh) < 20 * 1048576) {
        $i++;
        fwrite($fh, sprintf("SO-%07d,%s,%d.%02d,c%d@example.invalid\n", $i, ['North', 'South', 'East', 'West'][$i % 4], $i % 1000, $i % 100, $i % 5000));
    }
    fclose($fh);
    // a program: refused at finish
    copy('/bin/true', "$dir/run_me.csv");
    // ★3: a column whose first 60 rows are empty (the sniff proposes nothing — nobody decides it) and then every row an email address: the
    // profile's classifier proposes email at 0.9 and masks it at once; `notes` holds an address in a third of its rows: free text, proposed 0.63, only a proposal
    $lc = "id,contact,notes\n";
    for ($i = 1; $i <= 260; $i++) {
        $lc .= $i <= 60 ? "$i,,\n" : sprintf("%d,c%d@example.invalid,%s\n", $i, $i, $i % 3 === 0 ? "call back c$i@example.invalid tomorrow" : 'no answer');
    }
    file_put_contents("$dir/late_contacts.csv", $lc);
    // a header two lines down (a report title above it)
    file_put_contents("$dir/report_with_title.csv", "Sales report\nGenerated for the proof\nregion,units\nNorth,10\nSouth,7\n");
    return $dir;
}

/** POST the parts protocol as a member: start (JSON) → each part's raw bytes (X-CSRF-Token) → finish. $o: name, skip_finish, parts (only these part numbers), upload (resume). Returns [upload_id, start, finish]. */
function upload_file(int $m, string $path, array $o = []): array
{
    [$j, $t] = actor($m);
    $name = $o['name'] ?? basename($path);
    $size = filesize($path);
    $start = req('POST', '/upload/start', ['jar' => $j, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t],
        'form' => array_filter(['filename' => $name, 'byte_size' => $size, 'upload' => $o['upload'] ?? null, 'sha256' => $o['sha256'] ?? null], static fn ($v) => $v !== null)]);
    $s = json_decode($start['body'], true) ?: [];
    $start['json'] = $s;
    if (($s['ok'] ?? false) !== true) {
        return [null, $start, null];
    }
    $id = (int) $s['upload_id'];
    $have = array_flip($s['parts_received'] ?? []);
    $fh = fopen($path, 'rb');
    for ($n = 1; $n <= (int) $s['parts_total']; $n++) {
        $bytes = (string) fread($fh, (int) $s['part_bytes']);
        if (isset($have[$n]) || (isset($o['parts']) && !in_array($n, $o['parts'], true))) {
            continue;
        }
        $r = req('POST', "/upload/part?upload=$id&n=$n", ['jar' => $j, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t, 'Content-Type: application/octet-stream'], 'raw' => $bytes]);
        if ($r['code'] !== 200) {
            fclose($fh);
            return [$id, $start, ['code' => $r['code'], 'json' => json_decode($r['body'], true), 'part_failed' => $n]];
        }
    }
    fclose($fh);
    if (!empty($o['skip_finish'])) {
        return [$id, $start, null];
    }
    $f = req('POST', '/upload/finish', ['jar' => $j, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => ['upload' => $id]]);
    $f['json'] = json_decode($f['body'], true) ?: [];
    return [$id, $start, $f];
}

/** Wait for a condition (polling every 200 ms), up to $seconds. */
function wait_until(callable $c, float $seconds = 30.0): bool
{
    $end = microtime(true) + $seconds;
    do {
        if ($c()) {
            return true;
        }
        usleep(200000);
    } while (microtime(true) < $end);
    return (bool) $c();
}

/** The upload's sniff, once the writer read it (as postgres — the proofs' own look). */
function wait_sniff(int $upload, float $seconds = 30.0): string
{
    wait_until(static fn (): bool => in_array(pg1("SELECT sniff_status FROM upload_sessions WHERE id = $upload"), ['done', 'failed'], true), $seconds);
    return (string) pg1("SELECT sniff_status FROM upload_sessions WHERE id = $upload");
}

/** A load's final status, once the writer finished it (and its profile job, when it loaded). */
function wait_load(int $load, float $seconds = 60.0, bool $profile = true): string
{
    wait_until(static fn (): bool => in_array(pg1("SELECT status FROM loads WHERE id = $load"), ['loaded', 'no_op', 'failed', 'rolled_back'], true), $seconds);
    $st = (string) pg1("SELECT status FROM loads WHERE id = $load");
    if ($profile && $st === 'loaded') {
        wait_until(static fn (): bool => (int) pg1("SELECT count(*) FROM lake_jobs WHERE kind = 'profile' AND ref_id = $load AND status IN ('queued', 'running')") === 0
            && (int) pg1("SELECT count(*) FROM lake_jobs WHERE kind = 'profile' AND ref_id = $load") > 0, $seconds);
    }
    return $st;
}

/** A job's final row (as postgres). */
function wait_job(int $job, float $seconds = 60.0): array
{
    wait_until(static fn (): bool => in_array(pg1("SELECT status FROM lake_jobs WHERE id = $job"), ['done', 'failed'], true), $seconds);
    $r = pg("SELECT status || '|' || COALESCE(error, '') || '|' || COALESCE(result::text, '{}') FROM lake_jobs WHERE id = $job");
    [$status, $error, $result] = explode('|', $r[0] ?? '||{}', 3);
    return ['status' => $status, 'error' => $error, 'result' => json_decode($result, true) ?: []];
}

/** The mapping screen's JSON for the uploader. */
function map_json(int $m, int $upload, string $query = ''): array
{
    return get_json($m, "/uploads/$upload/map" . ($query === '' ? '' : '?' . $query));
}

/** Confirm a mapping as a member (JSON): the form fields as posted. */
function map_confirm_as(int $m, int $upload, array $form): array
{
    return post_json($m, "/uploads/$upload/map", ['upload' => $upload] + $form);
}

/** Upload, wait for the sniff, confirm into the sandbox under $name, wait for the load (and its profile). Returns [upload, load, dataset_id, response]. */
function upload_and_load(int $m, string $path, string $name, array $form = [], array $o = []): array
{
    [$u, , $f] = upload_file($m, $path, $o);
    if ($u === null || ($f['code'] ?? 0) !== 200) {
        return [$u, null, null, $f];
    }
    wait_sniff($u);
    $r = map_confirm_as($m, $u, $form + ['target' => 'sandbox', 'name' => $name]);
    $load = (int) ($r['json']['record_id'] ?? 0);
    if ($load > 0) {
        wait_load($load);
    }
    return [$u, $load ?: null, isset($r['json']['dataset_id']) ? (int) $r['json']['dataset_id'] : null, $r];
}

/** A query service request straight on a socket (the proofs' look at the protocol). */
function qs(array $req, int $instance = 1): array
{
    $c = @stream_socket_client('unix://' . need('DL_QUERY_SOCKET') . "-$instance.sock", $en, $es, 2);
    if ($c === false) {
        return ['status' => 'no_socket'];
    }
    stream_set_timeout($c, 40);
    fwrite($c, json_encode(['v' => 1] + $req) . "\n");
    $a = json_decode((string) fgets($c), true) ?: [];
    fclose($c);
    return $a;
}
