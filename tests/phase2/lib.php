<?php
/**
 * Helpers for the Phase 2 proofs (docs/build-specs/sso-shell.md "Proof"). Run them through tests/phase2/run.sh, which builds the scratch
 * database dl_dev2 (with the engine's roles — DL_DEV_ENGINE=1), starts the application (:9001), a fake kernel (:9002), the fake MaluDB +
 * MaluMail (:9003), the writer and the query service, and puts the scratch environment in the process environment. Every proof prints
 * "  ok   …" / "  FAIL …" and ends "all N passed" or "N FAILED".
 * The fixture (bin/dev_directory.json): 1 the owner (super-admin, Lake admin, IT); 26 Priya a Member (Sales); 27 Marco a Data engineer (Finance,
 * its admin); 28 Bea (no roles sent — a Member by capability, Accounting); 29 Ann (external, a Member, read); 30 Dana an Analyst (Sales); 31 Lee
 * (no roles sent, Sales); 40 the Expert (an agent, a Member); 41 the Watcher (an agent, not admitted); 42 the Steward (an agent, a Member).
 */
chdir(dirname(__DIR__, 2));
$GLOBALS['fails'] = 0;
$GLOBALS['passes'] = 0;
const BASE = 'http://127.0.0.1:9001';
const APP = 'datalake';
const LAUNCHER = 'https://app.example.invalid/launcher?app=datalake';
function need(string $k): string { $v = getenv($k); if ($v === false || $v === '') { fwrite(STDERR, "$k is not set — run tests/phase2/run.sh\n"); exit(2); } return $v; }
function ok(bool $c, string $l): void { $GLOBALS[$c ? 'passes' : 'fails']++; echo ($c ? '  ok   ' : '  FAIL ') . $l . "\n"; }
function finish(): never { echo $GLOBALS['fails'] ? "{$GLOBALS['fails']} FAILED ({$GLOBALS['passes']} passed)\n" : "all {$GLOBALS['passes']} passed\n"; exit($GLOBALS['fails'] ? 1 : 0); }
/** The application's own role on the scratch database (the walls judge it as the member set by as_member()). */
function pdo(): PDO {
    static $p = null;
    return $p ??= new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('DB_USER'), need('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function q(string $sql, array $args = []): array { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function one(string $sql, array $args = []) { $st = pdo()->prepare($sql); $st->execute($args); return $st->fetchColumn(); }
/** SQL as postgres on the scratch database (fixtures the application's role may not write: datasets, grants, usage). Returns the output's lines. */
function pg(string $sql): array
{
    $p = proc_open(['sudo', '-n', '-u', 'postgres', 'psql', '-X', '-q', '-At', '-v', 'ON_ERROR_STOP=1', '-d', need('DB_NAME')], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $sql);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $rc = proc_close($p);
    if ($rc !== 0) { fwrite(STDERR, "psql failed: $err\n"); }
    return array_values(array_filter(explode("\n", (string) $out), static fn ($l) => $l !== ''));
}
function fixture(): array { return json_decode(file_get_contents(dirname(__DIR__, 2) . '/bin/dev_directory.json'), true); }
/** dl_has_right() as the database answers it for a member. */
function right_of(int $member, string $right): bool { as_member($member); return (bool) one('SELECT dl_has_right(:r)', ['r' => $right]); }

/** One request. $o: jar, headers[], form[], json[], raw. Returns code, headers (string), body, and the Location. */
function req(string $method, string $path, array $o = []): array
{
    $ch = curl_init((str_starts_with($path, 'http') ? '' : BASE) . $path);
    $h = $o['headers'] ?? [];
    $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEJAR => $o['jar'] ?? '/dev/null', CURLOPT_COOKIEFILE => $o['jar'] ?? '/dev/null'];
    if (isset($o['form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($o['form']); }
    if (isset($o['json'])) { $opt[CURLOPT_POSTFIELDS] = json_encode($o['json']); $h[] = 'Content-Type: application/json'; }
    if (isset($o['raw'])) { $opt[CURLOPT_POSTFIELDS] = $o['raw']; }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return ['code' => $code, 'headers' => $headers, 'body' => substr($r, $hs), 'location' => preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
function jar(): string { return tempnam(sys_get_temp_dir(), 'dljar'); }
function csrf_of(string $html): string { preg_match('/name="csrf-token" id="csrf-token-meta" content="([^"]+)"/', $html, $m); return $m[1] ?? ''; }
function body_json(array $r): array { return json_decode($r['body'], true) ?: []; }

/** A single-use hand-off URL path (/sso?token=&claims=), signed as the kernel signs one. */
function handoff(int $member, array $o = []): string
{
    $key = $o['key'] ?? need('ACTION_TOKEN_KEY');
    $payload = $member . '.' . (time() + ($o['ttl'] ?? 60)) . '.' . ($o['app'] ?? APP) . '.' . bin2hex(random_bytes(16));
    $token = $payload . '.' . hash_hmac('sha256', 'sso:' . $payload, $key);
    $claims = ($o['claims'] ?? (fixture()['claims'][(string) $member] ?? [])) + ['member_id' => $member];
    $text = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');
    return '/sso?' . http_build_query(['token' => $token, 'claims' => $text . '.' . hash_hmac('sha256', $text, $key)] + (isset($o['next']) ? ['next' => $o['next']] : []));
}
/** Sign a member on with a fresh jar; returns [jar, response]. */
function sign_on(int $member, array $o = []): array { $j = jar(); return [$j, req('GET', handoff($member, $o), ['jar' => $j])]; }
function page(string $jar, string $path, array $o = []): array { return req('GET', $path, ['jar' => $jar] + $o); }
function page_csrf(string $jar): string { return csrf_of(req('GET', '/settings/', ['jar' => $jar])['body']); }
function badge_of(string $jar): string { preg_match('/id="header-role-badge">([^<]+)</', req('GET', '/', ['jar' => $jar])['body'], $m); return trim($m[1] ?? ''); }
/** A POST as a signed-on person, JSON answer, CSRF from their own page. */
function post_as(string $jar, string $path, array $form, array $headers = ['Accept: application/json']): array
{
    return req('POST', $path, ['jar' => $jar, 'headers' => array_merge($headers, ['X-CSRF-Token: ' . page_csrf($jar)]), 'form' => $form]);
}
/** A person's action token and an agent's run token with its relay, as the kernel mints them. */
function person_token(int $m, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY')); }
function run_token(int $m, int $runId, int $ttl = 300): string { $p = $m . '.' . (time() + $ttl) . '.' . $runId; return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY')); }
function relay_of(string $tok): string { return hash_hmac('sha256', $tok, need('ACTIONS_RELAY_KEY')); }
function as_agent(string $tok): array { return ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . relay_of($tok)]; }

/** The fake kernel's state file: read, change, write. */
function kernel_state(?callable $change = null): array
{
    $f = need('FAKE_KERNEL_STATE');
    $s = json_decode((string) file_get_contents($f), true) ?: [];
    if ($change) { $s = $change($s) ?? $s; file_put_contents($f, json_encode($s)); }
    return $s;
}
/** The kernel's run facts for a run (K38's keys when $requester is given; a kernel from before K38 when $requester === false). */
function set_run_facts(int $runId, int $agent, string $trigger, int|false|null $requester): void
{
    kernel_state(function (array $s) use ($runId, $agent, $trigger, $requester): array {
        $f = ['valid' => true, 'is_agent' => true, 'member_id' => $agent, 'run_id' => $runId, 'request_id' => 'r-' . $runId, 'trigger' => $trigger, 'is_eval' => false,
              'run_status' => 'running', 'endpoints' => [['id' => 1, 'application' => APP]]];
        if ($requester !== false) {
            $f += ['requested_by_member_id' => $requester, 'requested_by_kind' => $requester === null ? null : 'human', 'requested_by_holds' => $requester !== null];
        }
        $s['facts'][(string) $runId] = $f;
        return $s;
    });
}
function activity(string $action, ?int $since = null): array { return q('SELECT * FROM activity_log WHERE action = :a AND id > :s ORDER BY id', ['a' => $action, 's' => $since ?? 0]); }
function last_activity_id(): int { return (int) one('SELECT COALESCE(max(id), 0) FROM activity_log'); }
/** Act as a member on the proofs' own connection (the database's rules judge them). */
function as_member(int $m): void { pdo()->exec("SELECT set_config('app.member_id', '$m', false), set_config('app.on_behalf_of', '', false)"); }

/**
 * The proofs' SMOKE lake (idempotent; as postgres — the catalog rows the slices' screens will make): `curated.sales_orders` "SMOKE Sales orders"
 * (Marco's, Sales, internal, read by everyone, the expert granted read, popular this month), `product.monthly_revenue` "SMOKE Monthly revenue"
 * (certified, read by everyone, popular), `curated.payroll` "SMOKE Payroll" (the owner's, confidential — a stub to an insider, nothing to an
 * external; the expert granted read for K38's proof), `sandbox.m26_my_upload` (Priya's own). Returns the ids by key.
 */
function seed_lake(): array
{
    pg(<<<'SQL'
INSERT INTO datasets (key, zone, name, title, description, owner_member_id, steward_member_id, department_id, status, classification_floor)
VALUES ('curated.sales_orders', 'curated', 'sales_orders', 'SMOKE Sales orders', 'Every order the sales team took: the customer, the region, the amount and the day.', 27, 27, 8, 'active', 'public'),
       ('product.monthly_revenue', 'product', 'monthly_revenue', 'SMOKE Monthly revenue', 'Revenue by month and region, from the orders.', 27, 27, 7, 'active', 'public'),
       ('curated.payroll', 'curated', 'payroll', 'SMOKE Payroll', 'What each person is paid, by month.', 1, NULL, 5, 'active', 'confidential'),
       ('sandbox.m26_my_upload', 'sandbox', 'm26_my_upload', 'SMOKE My upload', 'Priya''s spreadsheet.', 26, NULL, NULL, 'active', 'public')
ON CONFLICT (key) DO NOTHING;
UPDATE datasets SET certification = 'certified', certified_by = 27, certified_at = now() WHERE key = 'product.monthly_revenue' AND certification <> 'certified';
INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by)
SELECT 'dataset', d.id, 'everyone', 'read', 'SMOKE for everyone', 1 FROM datasets d
 WHERE d.key IN ('curated.sales_orders', 'product.monthly_revenue')
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'everyone' AND g.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'dataset', d.id, 'agent', 40, 'read', 'SMOKE the expert reads it', 1 FROM datasets d
 WHERE d.key IN ('curated.sales_orders', 'curated.payroll')
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'agent' AND g.principal_id = 40 AND g.revoked_at IS NULL);
INSERT INTO dataset_usage_daily (dataset_id, day, queries, people, agents, rows_read)
SELECT d.id, current_date - x.n, x.q, x.p, 0, 100 FROM datasets d CROSS JOIN (VALUES (1, 12, 3), (2, 7, 2)) x(n, q, p) WHERE d.key = 'curated.sales_orders'
UNION ALL
SELECT d.id, current_date - 1, 4, 2, 0, 40 FROM datasets d WHERE d.key = 'product.monthly_revenue'
UNION ALL
SELECT d.id, current_date - 1, 30, 1, 0, 400 FROM datasets d WHERE d.key = 'curated.payroll'
ON CONFLICT (dataset_id, day) DO NOTHING;
SQL);
    $ids = [];
    foreach (pg("SELECT key || '=' || id FROM datasets WHERE key LIKE '%' ORDER BY id") as $line) {
        [$k, $v] = explode('=', $line, 2);
        $ids[$k] = (int) $v;
    }
    return $ids;
}
