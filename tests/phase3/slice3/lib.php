<?php
/**
 * Helpers for the slice 3 proofs (docs/build-specs/query-service-editor.md "Proof"; query-service.md §9), on top of slice 2's (upload_file,
 * wait_until, wait_job, qs — the socket's own look) and slice 1's (actor, post_json, get_json, post_form, html_as, pg1, ds_id, seed_catalog).
 * Run through tests/phase3/slice3/run.sh: scratch database dl_dev3 with the engine REAL (the writer, the query service on its sockets), the
 * app on :9001 (php -S or a real Apache), the fake kernel :9002, the fake MaluDB + MaluMail :9003.
 * THE TEN CALLERS (bin/dev_directory.json): 1 the owner (super-admin, Lake admin); 26 Priya a Member (Sales 8); 27 Marco a Data engineer
 * (Finance 7 — owns sales_orders, region_sales, big_events); 28 Bea (no role here); 29 Ann (external, a Member); 30 Dana an Analyst (Sales 8);
 * 31 Lee (no role — a member by capability only, Sales 8); 40 the Expert, 41 the Watcher (no role), 42 the Steward — agents.
 */
require dirname(__DIR__) . '/slice2/lib.php';

const SLICE3_CALLERS = [1, 26, 27, 28, 29, 30, 31, 40, 41, 42];

/** The slice's lake: slice 1's catalog, two more datasets and the grants the walls are proven with (as postgres), then the real tables (seed.py). */
function seed_slice3(): array
{
    $ids = seed_catalog();
    pg(<<<'SQL'
INSERT INTO datasets (key, zone, name, title, description, owner_member_id, steward_member_id, department_id, status, classification_floor)
VALUES ('curated.region_sales', 'curated', 'region_sales', 'SMOKE Sales by region', 'Each department''s sales by region — a department reads its own.', 27, 27, 8, 'active', 'internal'),
       ('curated.big_events', 'curated', 'big_events', 'SMOKE Big events', 'A large table of events (120,000 rows).', 27, 27, 7, 'active', 'public'),
       ('curated.hr_reviews', 'curated', 'hr_reviews', 'SMOKE HR reviews', 'Nobody but its owner reads it (no grant).', 1, NULL, 5, 'active', 'confidential'),
       ('curated.twin_a', 'curated', 'twin_a', 'SMOKE Twin events', 'Events of the twins.', 27, NULL, 7, 'active', 'public'),
       ('curated.twin_b', 'curated', 'twin_b', 'SMOKE Twin events', 'Events of the twins.', 27, NULL, 7, 'active', 'public')
ON CONFLICT (key) DO NOTHING;
UPDATE datasets SET owner_member_id = 27 WHERE key = 'curated.sales_orders' AND owner_member_id IS DISTINCT FROM 27;
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, row_filter, reason, granted_by)
SELECT 'dataset', d.id, 'department', 8, 'read', 'department_id = ANY({departments})', 'SMOKE Sales reads its own rows', 1 FROM datasets d
 WHERE d.key = 'curated.region_sales'
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'department' AND g.principal_id = 8 AND g.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by)
SELECT 'dataset', d.id, 'everyone', 'read', 'SMOKE for everyone', 1 FROM datasets d
 WHERE d.key IN ('curated.big_events', 'curated.twin_a', 'curated.twin_b') AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'everyone' AND g.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'dataset', d.id, 'department', 8, 'discover', 'SMOKE Sales may know payroll exists', 1 FROM datasets d
 WHERE d.key = 'curated.payroll'
   AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = d.id AND g.principal_kind = 'department' AND g.principal_id = 8 AND g.revoked_at IS NULL);
SQL);
    $root = dirname(__DIR__, 3);
    exec(escapeshellarg($root . '/services/.venv/bin/python') . ' ' . escapeshellarg(__DIR__ . '/seed.py') . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "seed.py failed:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    foreach (['curated.region_sales', 'curated.big_events', 'curated.hr_reviews', 'curated.twin_a', 'curated.twin_b'] as $k) {
        $ids[$k] = ds_id($k);
    }
    return $ids;
}

/** A dl_settings value changed for a proof (as postgres) — the old value, to put back. */
function setting(string $name, int|string $value): string
{
    $old = (string) pg1("SELECT $name::text FROM dl_settings WHERE id = 1");
    pg("UPDATE dl_settings SET $name = " . (is_int($value) ? $value : "'" . str_replace("'", "''", $value) . "'") . ' WHERE id = 1');
    return $old;
}

/** Run SQL through the editor's door as a member (JSON). $o: as_of, params (name → value), request_id, dry_run. */
function sql_as(int $m, string $sql, array $o = []): array
{
    $form = ['sql' => $sql] + array_filter(['as_of' => $o['as_of'] ?? null, 'request_id' => $o['request_id'] ?? null, 'dry_run' => isset($o['dry_run']) ? '1' : null],
        static fn ($v) => $v !== null);
    foreach ($o['params'] ?? [] as $k => $v) {
        $form["params[$k]"] = (string) $v;
    }
    return post_json($m, '/sql/run', $form);
}

/** The answer's result (the JSON's `result`). */
function result_of(array $r): array { return $r['json']['data']['result'] ?? []; }

/** A query_runs row by id (as postgres): status, refusal_rule, source, rows_returned, truncated, datasets, saved_query_id, sql, caller, on_behalf_of. */
function run_row(int $id): array
{
    $r = pg("SELECT row_to_json(q)::text FROM (SELECT id, status, refusal_rule, source, rows_returned, truncated, datasets, saved_query_id, sql, caller_member_id,
                    on_behalf_of, masked_columns, row_filters, request_id FROM query_runs WHERE id = $id) q");
    return json_decode($r[0] ?? '{}', true) ?: [];
}

/** The newest query_runs id (as postgres). */
function last_run_id(): int { return (int) pg1('SELECT COALESCE(max(id), 0) FROM query_runs'); }

/** A request straight to the service as a caller (the socket — every instance tried until one is not busy). */
function qs_any(array $req): array
{
    $n = (int) (getenv('DL_QUERY_WORKERS') ?: 2);
    for ($i = 1; $i <= $n; $i++) {
        $a = qs($req, $i);
        if (($a['refusal_rule'] ?? '') !== 'busy') {
            return $a;
        }
    }
    return ['status' => 'refused', 'refusal_rule' => 'busy'];
}
