<?php
/**
 * Helpers for the slice 7 proofs (docs/build-specs/transforms-products-lineage.md "Proof"), on top of slice 4's (agent_post, agent_get, last_notice, preview_as,
 * ref_level), slice 3's (sql_as, result_of, run_row, qs_any, setting), slice 2's (wait_until, wait_job, qs) and slice 1's (actor, post_json, get_json, html_as,
 * pg1, ds_id, field_of, message_of, run_tag). Run through tests/phase3/slice7/run.sh: scratch database dl_dev7 with the engine REAL (the writer and the query
 * service on their units' command lines, DuckLake on the scratch catalog), the app on :9001 (php -S or a real Apache), the fake kernel :9002, the fake MaluDB +
 * MaluMail :9003.
 * THE CALLERS (bin/dev_directory.json): 1 the owner (Lake admin — every right); 26 Priya a Member (Sales); 27 Marco a Data engineer (owns the orders; transforms.manage,
 * metrics.manage); 28 Bea (no role here); 29 Ann (external); 30 Dana an Analyst (sandbox.transform); 31 Lee (no role); 40 the Expert, 41 the Watcher, 42 the Steward — agents.
 * THE SLICE'S LAKE (seed_slice7(), seed.py): curated.s7_orders (Marco's, everyone reads), curated.s7_customers (the owner's, everyone reads — a personal email),
 * curated.s7_secret (the owner's alone), the products and the other outputs registered EMPTY for the transforms to fill (product.s7_monthly, s7_regions, s7_top, s7_yearly,
 * s7_customers_clean, s7_blend, s7_empty, curated.s7_incr (its key order_id), sandbox.m30_s7_mine), raw.s7_orders_raw (catalog only — the orders' raw input in the lineage).
 */
require dirname(__DIR__) . '/slice4/lib.php';

const SLICE7_CALLERS = [1, 26, 27, 28, 29, 30, 31, 40, 41, 42];

function seed_slice7(): array
{
    $ids = seed_slice4();
    pg(<<<'SQL'
INSERT INTO datasets (key, zone, name, title, description, owner_member_id, steward_member_id, department_id, status, classification_floor, dedupe_key)
VALUES ('curated.s7_orders', 'curated', 's7_orders', 'SMOKE S7 orders', 'Every order of the last fourteen months.', 27, 27, 7, 'active', 'internal', '{order_id}'),
       ('curated.s7_customers', 'curated', 's7_customers', 'SMOKE S7 customers', 'Who buys: a personal email, a city, a region.', 1, NULL, 8, 'active', 'confidential', '{}'),
       ('curated.s7_secret', 'curated', 's7_secret', 'SMOKE S7 secret', 'Nobody but its owner discovers it.', 1, NULL, 5, 'active', 'confidential', '{}'),
       ('raw.s7_orders_raw', 'raw', 's7_orders_raw', 'SMOKE S7 raw orders', 'What the orders arrived as.', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_monthly', 'product', 's7_monthly', 'SMOKE S7 monthly revenue', 'Revenue by month and region.', 27, 27, 7, 'active', 'internal', '{}'),
       ('product.s7_regions', 'product', 's7_regions', 'SMOKE S7 revenue by region', 'Revenue by region, from the monthly product.', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_top', 'product', 's7_top', 'SMOKE S7 top regions', 'The regions that sold, from the regional product.', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_yearly', 'product', 's7_yearly', 'SMOKE S7 yearly revenue', 'Revenue by year (the schedule proofs).', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_customers_clean', 'product', 's7_customers_clean', 'SMOKE S7 customers (product)', 'Customers, as Marco may read them.', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_blend', 'product', 's7_blend', 'SMOKE S7 blend', 'Orders joined to the secret table (the owner''s).', 1, NULL, 5, 'active', 'internal', '{}'),
       ('product.s7_cron', 'product', 's7_cron', 'SMOKE S7 cron', 'Made on a schedule (the cron proof).', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_agent', 'product', 's7_agent', 'SMOKE S7 agent', 'Made by the Expert in its own duty.', 27, NULL, 7, 'active', 'internal', '{}'),
       ('product.s7_empty', 'product', 's7_empty', 'SMOKE S7 empty', 'A product nothing has made yet.', 27, NULL, 7, 'active', 'internal', '{}'),
       ('curated.s7_incr', 'curated', 's7_incr', 'SMOKE S7 incremental', 'The incremental proof''s output.', 27, NULL, 7, 'active', 'internal', '{order_id}'),
       ('sandbox.m30_s7_mine', 'sandbox', 'm30_s7_mine', 'SMOKE S7 Dana''s cut', 'Dana''s own sandbox transform.', 30, NULL, NULL, 'active', 'internal', '{}')
ON CONFLICT (key) DO NOTHING;
INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by)
SELECT 'dataset', d.id, 'everyone', 'read', 'SMOKE for everyone', 1 FROM datasets d
 WHERE d.key IN ('curated.s7_orders', 'curated.s7_customers', 'raw.s7_orders_raw', 'product.s7_monthly', 'product.s7_regions', 'product.s7_top', 'product.s7_yearly',
                 'product.s7_customers_clean', 'product.s7_blend', 'product.s7_empty', 'product.s7_cron', 'product.s7_agent', 'curated.s7_incr')
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'everyone' AND g.revoked_at IS NULL);
INSERT INTO sources (name, connector, settings, schedule_minutes) SELECT 'SMOKE S7 feed', 'http_file', '{"url": "https://files.example.invalid/s7.csv"}', 0
 WHERE NOT EXISTS (SELECT 1 FROM sources WHERE name = 'SMOKE S7 feed');
UPDATE members SET roles = ARRAY['engineer'] WHERE id = 40;
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'dataset', d.id, 'agent', 40, 'write', 'SMOKE the Expert may write the agent product', 1 FROM datasets d WHERE d.key = 'product.s7_agent'
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'agent' AND g.principal_id = 40 AND g.revoked_at IS NULL);
SELECT dl_lineage_edge(NULL, (SELECT id FROM sources WHERE name = 'SMOKE S7 feed'), (SELECT id FROM datasets WHERE key = 'raw.s7_orders_raw'), 'load');
SELECT dl_lineage_edge((SELECT id FROM datasets WHERE key = 'raw.s7_orders_raw'), NULL, (SELECT id FROM datasets WHERE key = 'curated.s7_orders'), 'promote');
SQL);
    $root = dirname(__DIR__, 3);
    exec(escapeshellarg($root . '/services/.venv/bin/python') . ' ' . escapeshellarg(__DIR__ . '/seed.py') . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "seed.py failed:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    pg(<<<'SQL'
UPDATE dataset_columns c SET personal_kind = 'email', classification = 'confidential', classified_by = 'person', classification_confirmed_by = 1, classification_confirmed_at = now()
  FROM datasets d WHERE d.id = c.dataset_id AND d.key = 'curated.s7_customers' AND c.name = 'email' AND c.classification_confirmed_at IS NULL;
SELECT dl_dataset_derive(id) FROM datasets WHERE key = 'curated.s7_customers';
SQL);
    foreach (pg("SELECT key || '=' || id FROM datasets WHERE key LIKE '%s7\\_%' ORDER BY id") as $line) {
        [$k, $v] = explode('=', $line, 2);
        $ids[$k] = (int) $v;
    }
    return $ids;
}

/** Append N orders to curated.s7_orders (a new snapshot — an input "changing"), through the writer's own library. */
function append_orders(int $n): void
{
    $root = dirname(__DIR__, 3);
    exec(escapeshellarg($root . '/services/.venv/bin/python') . ' ' . escapeshellarg(__DIR__ . '/seed.py') . ' --append ' . $n . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "seed.py --append failed:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
}

/** Save a transform as a member (JSON): the response ('json'.'record_id' is the transform's id). */
function transform_save_as(int $m, array $form): array { return post_json($m, '/transforms/save.php', $form); }

/** Queue a run and wait for it to finish: [the response, the transform_runs row as an array]. */
function run_transform(int $m, int $transform, float $wait = 120.0): array
{
    $r = post_json($m, '/transforms/run.php', ['transform' => $transform]);
    $run = (int) ($r['json']['data']['run'] ?? $r['json']['run'] ?? 0);
    if ($run === 0) {
        $run = (int) pg1("SELECT max(id) FROM transform_runs WHERE transform_id = $transform");
    }
    wait_until(static fn (): bool => in_array(pg1("SELECT status FROM transform_runs WHERE id = $run"), ['ok', 'no_op', 'failed'], true), $wait);
    return [$r, run_row_of($run)];
}

/** A transform_runs row (as postgres). */
function run_row_of(int $run): array
{
    $r = pg("SELECT row_to_json(r)::text FROM (SELECT id, transform_id, version, status, input_snapshots, output_snapshot, rows_written, error, requested_by, started_at, finished_at FROM transform_runs WHERE id = $run) r");
    return json_decode($r[0] ?? '{}', true) ?: [];
}

/** A transform's row (as postgres). */
function transform_row(int $id): array
{
    $r = pg("SELECT row_to_json(t)::text FROM (SELECT id, name, output_dataset_id, input_dataset_ids, mode, schedule, version, owner_member_id, enabled, frozen_policy IS NOT NULL AS frozen, last_run_id FROM transforms WHERE id = $id) t");
    return json_decode($r[0] ?? '{}', true) ?: [];
}

/** The key of every column of a dataset the catalog has live (as postgres): name → [logical_type, personal_kind, classification]. */
function live_columns(string $key): array
{
    $out = [];
    foreach (pg("SELECT c.name || '|' || COALESCE(c.logical_type, '') || '|' || COALESCE(c.personal_kind, '') || '|' || c.classification FROM dataset_columns c JOIN datasets d ON d.id = c.dataset_id
                  WHERE d.key = '$key' AND c.removed_snapshot_id IS NULL ORDER BY c.ordinal") as $l) {
        [$n, $t, $p, $c] = explode('|', $l);
        $out[$n] = [$t, $p, $c];
    }
    return $out;
}

/** A metric request straight on the socket as a caller (every instance tried): the service's answer. */
function metric_req(int $caller, array $metric, array $o = []): array
{
    return qs_any(['v' => 1, 'kind' => 'metric', 'caller_member_id' => $caller, 'source' => $o['source'] ?? 'screen', 'request_id' => 'm7-' . bin2hex(random_bytes(4)),
        'metric' => $metric, 'grain' => $o['grain'] ?? null, 'from' => $o['from'] ?? null, 'to' => $o['to'] ?? null, 'by' => $o['by'] ?? [], 'where' => (object) ($o['where'] ?? []),
        'dry_run' => !empty($o['dry_run'])]);
}

/** The metric proofs' definition over product.s7_monthly. */
function revenue_metric(array $over = []): array
{
    return $over + ['id' => null, 'dataset' => 'product.s7_monthly', 'expression' => 'sum(revenue)', 'time_column' => 'month', 'grains' => ['month', 'quarter', 'year'],
                    'dimensions' => ['region'], 'filters' => null];
}

/** The first of the month n months before this one, as Y-m-d. */
function month_start(int $monthsAgo): string { return (new DateTimeImmutable('first day of this month'))->modify("-$monthsAgo months")->format('Y-m-d'); }


/** The worker, as the cron runs it: a child PHP process (the scratch environment, an optional fake clock). Returns the report's `ran` (pass → counts) and `errors`. */
function worker_pass(string $passes = 'schedules', ?string $now = null): array
{
    $env = array_merge(getenv(), $now === null ? [] : ['DL_WORKER_NOW' => $now]);
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', 'bin/worker.php', '--passes=' . $passes], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    proc_close($p);
    $lines = array_values(array_filter(explode("\n", trim((string) $out))));
    $rep = json_decode((string) end($lines), true) ?: ['errors' => ['no report: ' . $err . $out]];
    return $rep;
}

/** A transform by name, made and run once if it is not there yet (as $by): its id. Fails the proof loudly when the save or the run fails. */
function ensure_transform(int $by, string $name, string $output, string $sql, array $extra = [], bool $run = true): int
{
    $have = pg1("SELECT id FROM transforms WHERE name = '$name'");
    if ($have !== null) {
        return (int) $have;
    }
    $r = transform_save_as($by, ['name' => $name, 'output_dataset' => $output, 'sql' => $sql] + $extra);
    $id = (int) ($r['json']['record_id'] ?? 0);
    if ($id === 0) {
        fwrite(STDERR, "ensure_transform($name): " . json_encode($r['json']) . "\n");
        exit(1);
    }
    if ($run) {
        [, $row] = run_transform($by, $id);
        if (($row['status'] ?? '') !== 'ok') {
            fwrite(STDERR, "ensure_transform($name): the run is " . json_encode($row) . "\n");
            exit(1);
        }
    }
    return $id;
}

/** The slice's standard transforms, made once (idempotent): monthly (Marco), regions and top (Marco, a chain), blend (the owner, over the secret table and the customers). */
function standard_transforms(): array
{
    $t = [];
    $t['monthly'] = ensure_transform(27, 's7_monthly_t', 'product.s7_monthly',
        'SELECT date_trunc(\'month\', ordered_on) AS month, region, sum(amount) AS revenue, count(*) AS orders FROM curated.s7_orders GROUP BY ALL');
    $t['regions'] = ensure_transform(27, 's7_regions_t', 'product.s7_regions', 'SELECT region, sum(revenue) AS revenue FROM product.s7_monthly GROUP BY region');
    $t['top'] = ensure_transform(27, 's7_top_t', 'product.s7_top', 'SELECT region, revenue FROM product.s7_regions WHERE revenue > 0');
    $t['blend'] = ensure_transform(1, 's7_blend_t', 'product.s7_blend',
        'SELECT o.order_id, o.region, s.note, c.email FROM curated.s7_orders o JOIN curated.s7_secret s ON s.secret_id = o.order_id LEFT JOIN curated.s7_customers c ON c.customer_id = o.order_id');
    return $t;
}


/** The first cell of the first row of a SELECT run through the editor's door as a member — as a string, or null. */
function sql_rows_value(int $m, string $sql): ?string
{
    $res = result_of(sql_as($m, $sql));
    $v = $res['rows'][0][0] ?? null;
    return $v === null ? null : (string) $v;
}
