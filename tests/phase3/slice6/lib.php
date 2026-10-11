<?php
/**
 * Helpers for the slice 6 proofs (docs/build-specs/sources-pulls.md "Proof"), on top of slice 4's (agent_post, last_notice, preview_as), slice 3's (sql_as, result_of), slice 2's (upload_file, wait_load, wait_job, wait_until), slice 1's
 * (actor, post_json, get_json, html_as, pg1, ds_id) and Phase 2's (sign_on, req, pg, the fake kernel's state). Run through tests/phase3/slice6/run.sh: scratch
 * database dl_dev6 with the engine REAL (the writer and the query service on their units' command lines, DuckLake on the scratch catalog), the app on :9001
 * (php -S or a real Apache), the fake kernel :9002 (now answering K7 reads), the fake MaluDB + MaluMail :9003, the FIXTURES SERVER :9004
 * (fixtures_server.php: a site the http_file connector fetches from), an "outside" PostgreSQL database dl_dev6_outside.
 * THE CALLERS (bin/dev_directory.json): 1 the owner (Lake admin); 26 Priya a Member; 27 Marco a Data engineer (holds sources.manage and loads.manage);
 * 28 Bea (no role); 29 Ann (external); 30 Dana an Analyst; 40 the Expert (an agent — given the engineer role here by seed_slice6()).
 * A PULL IS RUN IN A CHILD PHP PROCESS (cli_pull()) — the way the worker runs one: the application's own bootstrap, no session, the base tables — with the
 * test hooks the code allows outside production only ($GLOBALS['__dl_fetch_test'] — the SFTP directory fixture —, $GLOBALS['__dl_now'] — the clock).
 */
require dirname(__DIR__) . '/slice4/lib.php';

const FX_PORT = 9004;

/** The fixtures server's directory, state file and request log (under $DL_DEV_STATE). */
function fx_dir(): string { return need('DL_DEV_STATE') . '/fx'; }
function fx_log_path(): string { return need('DL_DEV_STATE') . '/fx.log'; }
function fx_url(string $name, string $dir = 'data'): string { return 'http://127.0.0.1:' . FX_PORT . "/$dir/$name"; }

/** Set the fixtures server's files (merged into the ones already there): name => [file, etag?, last_modified?, status?, wall?, auth?]; $replace starts again. */
function fx_state(array $files, bool $replace = false): void
{
    $have = $replace || !is_file(need('FX_STATE')) ? [] : ((json_decode((string) file_get_contents(need('FX_STATE')), true) ?: [])['files'] ?? []);
    file_put_contents(need('FX_STATE'), json_encode(['files' => array_merge($have, $files)]));
}

/** The fixtures server's request log as decoded lines; $reset empties it. */
function fx_log(bool $reset = false): array
{
    $lines = is_file(fx_log_path()) ? array_values(array_filter(explode("\n", (string) file_get_contents(fx_log_path())))) : [];
    if ($reset) {
        file_put_contents(fx_log_path(), '');
    }
    return array_map(static fn (string $l): array => json_decode($l, true) ?: [], $lines);
}

/** The slice's synthetic files (idempotent): sales_a.csv (3 rows), sales_b.csv (5 rows), the workbook. */
function fx_files(): string
{
    $dir = fx_dir();
    @mkdir($dir, 0777, true);
    file_put_contents("$dir/sales_a.csv", "order_id,region,amount,customer_email\nSO-001,North,120.50,ann.buyer@example.invalid\nSO-002,South,80.00,bo.buyer@example.invalid\nSO-003,East,45.25,cy.buyer@example.invalid\n");
    file_put_contents("$dir/sales_b.csv", "order_id,region,amount,customer_email\nSO-004,West,60.00,di.buyer@example.invalid\nSO-005,North,300.00,ed.buyer@example.invalid\n");
    copy(dirname(__DIR__, 2) . '/fixtures/orders.xlsx', "$dir/book.xlsx");
    copy(dirname(__DIR__, 2) . '/fixtures/events.ndjson', "$dir/events.ndjson");
    file_put_contents("$dir/semi.csv", "code;label;amount\nA1;First;10,50\nA2;Second;20,00\n");
    return $dir;
}

/** Run PHP code in a child process with the scratch environment and $env on top; [exit code, stdout, stderr]. */
function cli_php(string $code, array $env = [], int $timeout = 300): array
{
    $e = array_merge(getenv(), array_map('strval', $env));
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $e);
    stream_set_timeout($pipes[1], $timeout);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), (string) $out, (string) $err];
}

/**
 * One pull, the worker's way (dl_run_pull in a child process). $o: kind (manual), queued (a source_pulls id), now (an ISO clock), sftp_dir (the SFTP directory
 * fixture the transport seam serves), sftp_log (where the runner logs the plans), no_key (the secrets key is missing), password (the SFTP password to look for on argv).
 * Returns dl_run_pull()'s answer, or ['refused' => the DomainException's sentence], or ['crash' => stderr].
 */
function cli_pull(int $source, array $o = []): array
{
    $code = <<<'PHP'
require getenv('DL6_ROOT') . '/app/bootstrap.php';
require_once getenv('DL6_ROOT') . '/app/features/sources/write.php';
$GLOBALS['__public_door'] = 'cron';
if (getenv('DL6_NOW') !== false && getenv('DL6_NOW') !== '') { $GLOBALS['__dl_now'] = new DateTimeImmutable(getenv('DL6_NOW')); }
if (getenv('DL6_NO_KEY') === '1') { putenv('DL_SECRETS_KEY='); unset($_ENV['DL_SECRETS_KEY']); }
if (getenv('DL6_SFTP_DIR')) {
    $GLOBALS['__dl_fetch_test']['sftp_runner'] = static function (array $plan): array {
        $dir = getenv('DL6_SFTP_DIR');
        $files = $plan['files'];
        $pw = (string) getenv('DL6_SFTP_PASSWORD');
        file_put_contents(getenv('DL6_SFTP_LOG'), json_encode(['op' => $plan['op'], 'tool' => $plan['tool'], 'argv' => $plan['argv'], 'file_names' => array_map('basename', array_keys($files)),
            'password_on_argv' => $pw !== '' && str_contains(implode(' ', $plan['argv']), $pw), 'password_in_netrc' => $pw !== '' && str_contains(implode('', $files), $pw)]) . "\n", FILE_APPEND);
        if ($plan['op'] === 'list') {
            $out = [];
            foreach ((array) glob($dir . '/*') as $f) {
                if (is_file($f)) { $out[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f), 'stamp' => date('M j H:i', filemtime($f))]; }
            }
            return ['status' => 0, 'listing' => $out];
        }
        return copy($dir . '/' . $plan['name'], $plan['dest']) ? ['status' => 0] : ['status' => 1, 'err' => 'no such file'];
    };
}
try {
    $q = getenv('DL6_QUEUED');
    echo json_encode(dl_run_pull(db(), (int) getenv('DL6_SOURCE'), (string) (getenv('DL6_KIND') ?: 'manual'), null, $q !== false && $q !== '' ? (int) $q : null));
} catch (Throwable $e) {
    echo json_encode(['refused' => $e->getMessage()]);
}
PHP;
    [$rc, $out, $err] = cli_php($code, ['DL6_ROOT' => dirname(__DIR__, 3), 'DL6_SOURCE' => $source, 'DL6_KIND' => $o['kind'] ?? 'manual', 'DL6_QUEUED' => $o['queued'] ?? '', 'DL6_NOW' => $o['now'] ?? '',
        'DL6_SFTP_DIR' => $o['sftp_dir'] ?? '', 'DL6_SFTP_LOG' => $o['sftp_log'] ?? '', 'DL6_NO_KEY' => !empty($o['no_key']) ? '1' : '', 'DL6_SFTP_PASSWORD' => $o['password'] ?? '']);
    $j = json_decode($out, true);
    return is_array($j) ? $j : ['crash' => trim($err . ' ' . $out)];
}

/** The worker's schedules pass, as the timer runs it (a child process): the decoded JSON report, with the pass's own part under ['ran']['schedules']. $now = DL_WORKER_NOW. */
function worker_schedules(?string $now = null): array
{
    $env = $now === null ? [] : ['DL_WORKER_NOW' => $now];
    [$rc, $out] = cli_shell('php bin/worker.php --passes=schedules', $env);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $j = json_decode((string) end($lines), true);
    return is_array($j) ? $j + ['_rc' => $rc] : ['_rc' => $rc, '_raw' => $out];
}

/** A shell command in the repository root with the scratch environment and $env on top; [exit code, stdout]. */
function cli_shell(string $cmd, array $env = []): array
{
    $e = array_merge(getenv(), array_map('strval', $env));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $e);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    return [proc_close($p), $out . ($err !== '' ? "\n" . $err : '')];
}

/** Wait for every load of a pull (or the given load ids) to finish; the statuses by load id. */
function wait_loads(array $ids, float $seconds = 90.0): array
{
    $out = [];
    foreach ($ids as $id) {
        $out[(int) $id] = wait_load((int) $id, $seconds);
    }
    return $out;
}

/** The fake kernel's K7 shares: ["gl/account_balances" => [mode, doc, rows_key?]]. */
function k7_shares(array $reads): void
{
    kernel_state(function (array $s) use ($reads): array { $s['reads'] = $reads; return $s; });
}

/** The fake kernel's K7 request log (provider, tool, arguments), decoded; $reset empties it. */
function k7_log(bool $reset = false): array
{
    $f = need('FAKE_KERNEL_STATE') . '.reads';
    $lines = is_file($f) ? array_values(array_filter(explode("\n", (string) file_get_contents($f)))) : [];
    if ($reset) {
        file_put_contents($f, '');
    }
    return array_map(static fn (string $l): array => json_decode($l, true) ?: [], $lines);
}

/** A source's id by name (as postgres). */
function source_id(string $name): ?int
{
    $v = pg("SELECT id FROM sources WHERE name = '" . str_replace("'", "''", $name) . "' ORDER BY id DESC LIMIT 1");
    return $v === [] ? null : (int) $v[0];
}

/** The latest pull of a source (as postgres): the row as an array. */
function last_pull(int $source): array
{
    $r = pg("SELECT row_to_json(p) FROM (SELECT id, kind, status, listings_seen, listings_new, listings_changed, http_requests, bytes, error, policy FROM source_pulls WHERE source_id = $source ORDER BY id DESC LIMIT 1) p");
    return $r === [] ? [] : (json_decode($r[0], true) ?: []);
}

/** A source's state columns (as postgres). */
function source_row(int $source): array
{
    $r = pg("SELECT row_to_json(s) FROM (SELECT id, name, connector, role, active, paused_at IS NOT NULL AS paused, paused_reason, consecutive_failures, backoff_until, last_ok_at, last_pull_id, credential_id, schedule_minutes, settings, base_url FROM sources WHERE id = $source) s");
    return $r === [] ? [] : (json_decode($r[0], true) ?: []);
}

/** Rows of a raw dataset read through the query service as Marco: [columns, rows, the response]. */
function raw_rows(string $key, string $select = '*', string $order = '1', int $as = 27): array
{
    $r = sql_as($as, "SELECT $select FROM $key ORDER BY $order");
    $res = result_of($r);
    $cols = array_map(static fn ($c) => is_array($c) ? $c['name'] : $c, $res['columns'] ?? []);
    return [$cols, $res['rows'] ?? [], $r];
}

/** count(*) of a raw dataset through the query service (null when it cannot be read). */
function raw_count(string $key, int $as = 27): ?int
{
    [, $rows] = raw_rows($key, 'count(*) AS n', '1', $as);
    return isset($rows[0][0]) ? (int) $rows[0][0] : null;
}

/** The slice's fixed state (idempotent): the Expert (agent 40) holds the engineer role, the fixtures are written. */
function seed_slice6(): void
{
    fx_files();
    pg("UPDATE members SET roles = ARRAY['engineer'] WHERE id = 40");
    file_put_contents(fx_log_path(), '');
}

/** The ids of the sources the worker would pull at $now (an ISO clock) — sources_due() in a child process. */
function due_ids(string $now): array
{
    [, $o] = cli_php('require getenv("DL6_ROOT") . "/app/bootstrap.php"; require_once getenv("DL6_ROOT") . "/app/features/sources/queries.php";
        echo json_encode(array_map(static fn (array $r): int => (int) $r["id"], sources_due(db(), new DateTimeImmutable(getenv("DL6_NOW")))));', ['DL6_ROOT' => dirname(__DIR__, 3), 'DL6_NOW' => $now]);
    return json_decode($o, true) ?: [];
}

/** Pause every source but these (a proof that runs the worker's whole pass must not pull the other proofs\' sources), and let them go again. */
function isolate_sources(array $keep): void
{
    pg("UPDATE sources SET paused_at = now(), paused_reason = 'proof isolation' WHERE paused_at IS NULL AND id NOT IN (" . implode(',', array_merge([0], $keep)) . ')');
}

function release_sources(): void
{
    pg("UPDATE sources SET paused_at = NULL, paused_reason = NULL WHERE paused_reason = 'proof isolation'");
}
