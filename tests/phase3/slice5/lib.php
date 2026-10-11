<?php
/**
 * Helpers for the slice 5 proofs (docs/build-specs/contracts-quality.md "Proof"), on top of slice 4's (ref_level, agent_get, agent_post,
 * last_notice, preview_as), slice 3's (sql_as, setting), slice 2's (upload_file, wait_sniff, wait_load, wait_job, qs, fixtures) and slice 1's
 * (actor, post_json, get_json, post_form, html_as, pg, pg1, ds_id). Run through tests/phase3/slice5/run.sh: scratch database dl_dev5 with the
 * engine REAL (the writer and the query service on their units' command lines, DuckLake on the scratch catalog), the app on :9001 (php -S or a
 * real Apache), the fake kernel :9002, the fake MaluDB + MaluMail :9003.
 * THE CALLERS (bin/dev_directory.json): 1 the owner (Lake admin); 26 Priya a Member (Sales 8); 27 Marco a Data engineer (Finance 7 — owns
 * raw.orders and curated.orders); 28 Bea (no role); 29 Ann (external); 30 Dana an Analyst (Sales 8); 31 Lee (no role, Sales 8); 40 the Expert,
 * 41 the Watcher, 42 the Steward — agents.
 * THE SLICE'S LAKE: raw.orders loaded by Marco through the upload door (orders_1.csv: a bad date, an empty amount, a duplicate order, a status
 * outside the list, an amount out of range); curated.orders and curated.orders_v (the versioning proof) registered, read by Sales; curated.statuses
 * (the referential check's target, written by the writer's library); curated.no_contract (promotion refused).
 */
require dirname(__DIR__) . '/slice4/lib.php';

/** The slice's files under $DL_DEV_STATE/fixtures (synthetic, example.invalid). */
function slice5_fixtures(): string
{
    $dir = fixtures();
    $h = "order_id,customer_email,order_date,amount,status,updated_at\n";
    file_put_contents("$dir/orders_1.csv", $h
        . "SO-001,ann.buyer@example.invalid,2026-09-01,120.50,open,2026-09-01T10:00:00\n"
        . "SO-002,bo.buyer@example.invalid,2026-09-02,80.00,shipped,2026-09-02T10:00:00\n"
        . "SO-003,cy.buyer@example.invalid,not-a-date,45.00,open,2026-09-03T10:00:00\n"
        . "SO-004,di.buyer@example.invalid,2026-09-04,,open,2026-09-04T10:00:00\n"
        . "SO-005,ed.buyer@example.invalid,2026-09-05,300.00,cancelled,2026-09-05T10:00:00\n"
        . "SO-002,bo.buyer@example.invalid,2026-09-02,85.00,shipped,2026-09-06T10:00:00\n"
        . "SO-006,fa.buyer@example.invalid,2026-09-06,15.25,returned,2026-09-06T11:00:00\n"
        . "SO-007,gu.buyer@example.invalid,2026-09-07,9999999,open,2026-09-07T10:00:00\n");
    // the next file: one new order, one update of SO-001 (a later updated_at — the latest wins)
    file_put_contents("$dir/orders_2.csv", $h
        . "SO-008,ha.buyer@example.invalid,2026-09-08,42.00,open,2026-09-08T10:00:00\n"
        . "SO-001,ann.buyer@example.invalid,2026-09-01,130.00,shipped,2026-09-08T12:00:00\n");
    // SO-004 corrected (its amount) — a passing run resolves the not_null incident
    file_put_contents("$dir/orders_fix.csv", $h . "SO-004,di.buyer@example.invalid,2026-09-04,61.00,open,2026-09-09T10:00:00\n");
    // SO-009 without an amount — the blocking not_null fails again (certification lost)
    file_put_contents("$dir/orders_gap.csv", $h . "SO-009,ib.buyer@example.invalid,2026-09-10,,open,2026-09-10T10:00:00\n");
    // SO-009 given its amount — the check passes again
    file_put_contents("$dir/orders_gapfix.csv", $h . "SO-009,ib.buyer@example.invalid,2026-09-10,19.90,open,2026-09-10T12:00:00\n");
    // drift: a new column (channel), then a missing one (status), then another new one (gift — rejected)
    file_put_contents("$dir/orders_channel.csv", "order_id,customer_email,order_date,amount,status,updated_at,channel\n"
        . "SO-010,jo.buyer@example.invalid,2026-09-11,12.00,open,2026-09-11T10:00:00,web\n");
    file_put_contents("$dir/orders_channel_b.csv", "order_id,customer_email,order_date,amount,status,updated_at,channel\n"
        . "SO-013,mo.buyer@example.invalid,2026-09-11,22.00,open,2026-09-11T11:00:00,phone\n");                  // the same shape, other bytes (a new landing)
    file_put_contents("$dir/orders_nostatus.csv", "order_id,customer_email,order_date,amount,updated_at,channel\n"
        . "SO-011,ka.buyer@example.invalid,2026-09-12,13.00,2026-09-12T10:00:00,store\n");
    file_put_contents("$dir/orders_json.csv", "order_id,customer_email,order_date,amount,updated_at,channel,promo\n"
        . "SO-014,ne.buyer@example.invalid,2026-09-14,15.00,2026-09-14T10:00:00,web,none\n");                    // json.php: one more change to decide
    file_put_contents("$dir/orders_gift.csv", "order_id,customer_email,order_date,amount,updated_at,channel,gift\n"
        . "SO-012,lu.buyer@example.invalid,2026-09-13,14.00,2026-09-13T10:00:00,web,yes\n");
    return $dir;
}

/** Upload a file into raw.orders as Marco (append), wait for the load — the load's id. */
function load_orders(string $file, bool $new = false): int
{
    $dir = slice5_fixtures();
    [$u] = upload_file(27, "$dir/$file");
    wait_sniff($u);
    $form = $new ? ['target' => 'raw', 'raw_name' => 'orders'] : ['target' => 'raw', 'target_dataset' => ds_id('raw.orders'), 'mode' => 'append'];
    $r = map_confirm_as(27, $u, $form);
    $load = (int) ($r['json']['record_id'] ?? 0);
    if ($load === 0) {
        fwrite(STDERR, "load_orders($file): " . json_encode($r['json']) . "\n");
        return 0;
    }
    wait_load($load);
    return $load;
}

/** The slice's lake: slice 4's, then raw.orders (through the door), the curated datasets and the statuses table. */
function seed_slice5(): array
{
    $ids = seed_slice4();
    $load = load_orders('orders_1.csv', true);
    pg(<<<'SQL'
INSERT INTO datasets (key, zone, name, title, description, owner_member_id, steward_member_id, department_id, status, classification_floor)
VALUES ('curated.orders', 'curated', 'orders', 'SMOKE Orders', 'Orders.', 27, NULL, 7, 'active', 'internal'),
       ('curated.orders_v', 'curated', 'orders_v', 'SMOKE Orders (versions)', 'The orders, for the versioning proof.', 27, NULL, 7, 'active', 'internal'),
       ('curated.statuses', 'curated', 'statuses', 'SMOKE Order statuses', 'Every status an order may have.', 27, 27, 7, 'active', 'public'),
       ('curated.no_contract', 'curated', 'no_contract', 'SMOKE No contract', 'A curated dataset with no contract.', 27, NULL, 7, 'active', 'internal')
ON CONFLICT (key) DO NOTHING;
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'dataset', d.id, 'department', 8, 'read', 'SMOKE Sales reads the orders', 1 FROM datasets d WHERE d.key IN ('curated.orders', 'raw.orders')
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'department' AND g.principal_id = 8 AND g.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'dataset', d.id, 'agent', 40, 'write', 'SMOKE the Expert may write the orders', 1 FROM datasets d WHERE d.key = 'curated.orders'
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'agent' AND g.principal_id = 40 AND g.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by)
SELECT 'dataset', d.id, 'everyone', 'read', 'SMOKE everyone reads the statuses', 1 FROM datasets d WHERE d.key = 'curated.statuses'
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'everyone' AND g.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'dataset', d.id, 'agent', 42, 'discover', 'SMOKE the Steward may know the orders', 1 FROM datasets d WHERE d.key = 'curated.orders'
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'agent' AND g.principal_id = 42 AND g.revoked_at IS NULL);
INSERT INTO dataset_follows (member_id, dataset_id) SELECT 26, id FROM datasets WHERE key = 'curated.orders' ON CONFLICT DO NOTHING;
SQL);
    $root = dirname(__DIR__, 3);
    exec(escapeshellarg($root . '/services/.venv/bin/python') . ' ' . escapeshellarg(__DIR__ . '/seed.py') . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "seed.py failed:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    foreach (['raw.orders', 'curated.orders', 'curated.orders_v', 'curated.statuses', 'curated.no_contract'] as $k) {
        $ids[$k] = ds_id($k);
    }
    $ids['orders_load_1'] = $load;
    return $ids;
}

/** The contract the proofs publish for curated.orders (1.0.0) — the form's fields as an agent or the API would send them. */
function orders_schema(string $raw = 'raw.orders', array $drop = []): array
{
    $cols = [
        ['name' => 'order_id', 'physical_type' => 'VARCHAR', 'required' => true, 'unique' => true, 'primary_key' => true, 'description' => 'The order\'s number.'],
        ['name' => 'customer_email', 'physical_type' => 'VARCHAR', 'description' => 'Who ordered.', 'personal_kind' => 'email', 'classification' => 'confidential'],
        ['name' => 'order_date', 'physical_type' => 'DATE', 'description' => 'The day it was ordered.'],
        ['name' => 'amount', 'physical_type' => 'DECIMAL(12,2)', 'description' => 'What it costs.'],
        ['name' => 'status', 'physical_type' => 'VARCHAR', 'description' => 'Where it is.'],
        ['name' => 'updated_at', 'physical_type' => 'TIMESTAMP', 'description' => 'When the order last changed.'],
    ];
    $out = [];
    foreach ($cols as $c) {
        if (!in_array($c['name'], $drop, true)) {
            $out[] = $c + ['source' => "$raw.{$c['name']}"];
        }
    }
    return $out;
}

/** Save and publish a contract as Marco: [draft response, publish response]. */
function contract_as(int $m, string $key, string $version, array $schema, array $extra = [], bool $publish = true): array
{
    $r = post_json($m, '/quality/contracts/save.php', ['dataset' => $key, 'version' => $version, 'schema' => json_encode($schema), 'ordering_column' => 'updated_at'] + $extra);
    if (!$publish || $r['code'] !== 200) {
        return [$r, null];
    }
    return [$r, post_json($m, '/quality/contracts/publish.php', ['contract' => (int) $r['json']['record_id']])];
}

/** Promote (or rebuild) as a member and wait for the job: [response, the job row]. */
function promote_as(int $m, string $key, bool $rebuild = false): array
{
    $r = post_json($m, $rebuild ? '/quality/rebuild.php' : '/quality/promote.php', ['dataset' => $key]);
    $job = (int) ($r['json']['job_id'] ?? 0);
    return [$r, $job > 0 ? wait_job($job, 90) : []];
}

/** A job's result as an array. */
function job_result(array $j): array { return is_array($j['result'] ?? null) ? $j['result'] : (json_decode((string) ($j['result'] ?? '{}'), true) ?: []); }

/** The curated table's rows as postgres sees nothing — read through the query service as the owner (Marco): [order_id => row]. */
function curated_rows(string $key = 'curated.orders'): array
{
    $a = sql_as(27, "SELECT * FROM $key ORDER BY 1");
    $res = result_of($a);
    $cols = array_map(static fn ($c) => is_array($c) ? $c['name'] : $c, $res['columns'] ?? []);
    $out = [];
    foreach ($res['rows'] ?? [] as $row) {
        $r = array_combine($cols, $row);
        $out[(string) $r[$cols[0]]] = $r;
    }
    return $out;
}

/** A check's id by dataset key and kind (and column), as postgres. */
function check_id(string $key, string $kind, ?string $column = null): ?int
{
    $v = pg1("SELECT k.id FROM checks k JOIN datasets d ON d.id = k.dataset_id WHERE d.key = '$key' AND k.kind = '$kind'" . ($column === null ? '' : " AND k.column_name = '$column'") . ' ORDER BY k.id DESC LIMIT 1');
    return $v === null ? null : (int) $v;
}

/** The open incident of a check (as postgres): [id, status, severity] or null. */
function open_incident(int $check): ?array
{
    $v = pg("SELECT id || '|' || status || '|' || severity FROM incidents WHERE check_id = $check AND status IN ('new', 'acknowledged', 'investigating')");
    return $v === [] ? null : explode('|', $v[0]);
}
