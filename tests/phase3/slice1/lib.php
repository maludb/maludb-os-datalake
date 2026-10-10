<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/catalog.md "Proof"), on top of Phase 2's (tests/phase2/lib.php: sign_on, req, page, pg,
 * the fake kernel, person and run tokens, seed_lake). Run through tests/phase3/slice1/run.sh: scratch database dl_dev1, the Phase 2 servers
 * (:9001 the app — php -S or a real Apache —, :9002 the fake kernel, :9003 the fake MaluDB + MaluMail).
 * Actors (bin/dev_directory.json): 1 the owner (super-admin, Lake admin); 26 Priya a Member (Sales); 27 Marco a Data engineer (Finance);
 * 29 Ann (external, a Member); 30 Dana an Analyst (Sales); 31 Lee (a Member by capability, Sales); 40 the Expert and 42 the Steward (agents, Members).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

/** A signed-on actor: [jar, csrf], cached per member for the process. */
function actor(int $m): array
{
    static $cache = [];
    if (!isset($cache[$m])) {
        [$j, ] = sign_on($m);
        $cache[$m] = [$j, page_csrf($j)];
    }
    return $cache[$m];
}
/** A JSON POST as a member (CSRF in the header): the response with the decoded body under 'json'. */
function post_json(int $m, string $path, array $form): array
{
    [$j, $t] = actor($m);
    $r = req('POST', $path, ['jar' => $j, 'headers' => ['Accept: application/json', 'X-CSRF-Token: ' . $t], 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** A browser-like POST (a form, no JSON): the redirect and the body. */
function post_form(int $m, string $path, array $form, array $headers = []): array
{
    [$j, $t] = actor($m);
    return req('POST', $path, ['jar' => $j, 'headers' => $headers, 'form' => $form + ['csrf_token' => $t]]);
}
/** A JSON GET as a member. */
function get_json(int $m, string $path): array
{
    [$j, ] = actor($m);
    $r = req('GET', $path, ['jar' => $j, 'headers' => ['Accept: application/json']]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
/** An HTML page as a member. */
function html_as(int $m, string $path, array $headers = []): array
{
    [$j, ] = actor($m);
    return req('GET', $path, ['jar' => $j, 'headers' => $headers]);
}
/** The field a 422 named (error.fields' first key) and its sentence. */
function field_of(array $r): ?string { $f = $r['json']['error']['fields'] ?? []; return is_array($f) && $f !== [] ? (string) array_key_first($f) : null; }
function message_of(array $r): string { return (string) ($r['json']['error']['message'] ?? ''); }
/** A unique suffix per run, so the proofs can run twice on one database. */
function run_tag(): string { static $t = null; return $t ??= substr(bin2hex(random_bytes(3)), 0, 6); }
/** A dataset's id by key, as postgres (the proofs' own lookups never go through a wall). */
function ds_id(string $key): ?int { $v = pg("SELECT id FROM datasets WHERE key = '" . str_replace("'", "''", $key) . "'"); return $v === [] ? null : (int) $v[0]; }
/** One value as postgres. */
function pg1(string $sql): ?string { $v = pg($sql); return $v === [] ? null : $v[0]; }

/**
 * The slice's fixtures (idempotent, as postgres — what the writer and later slices would have made): columns with profiles on the SMOKE lake
 * (seed_lake()), a personal email column whose OLD profile still holds a raw value (the page must mask it), a confidential column, a system
 * column, two schema versions and a profile history on sales_orders.region, the payroll's columns, monthly revenue's region (a reference's
 * target). Returns seed_lake()'s ids.
 */
function seed_catalog(): array
{
    $ids = seed_lake();
    pg(<<<'SQL'
INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, logical_type, is_key, is_system, classification, personal_kind, profile, profiled_at, business_name, description)
SELECT d.id, c.name, c.ord, c.ptype, c.ltype, c.is_key, c.is_system, c.class, c.pkind, c.profile::jsonb, now() - interval '1 hour', c.bname, c.descr
  FROM datasets d CROSS JOIN (VALUES
    ('order_id', 0, 'BIGINT', 'integer', true, false, 'internal', NULL, '{"type":"BIGINT","null_pct":0,"distinct_approx":1200,"distinct_pct":100,"min":"1","max":"1200","top":[]}', 'Order number', 'The order''s number, one per order.'),
    ('customer_email', 1, 'VARCHAR', 'string', false, false, 'confidential', 'email', '{"type":"VARCHAR","null_pct":2.5,"distinct_approx":900,"distinct_pct":75,"min":"alice@example.com","max":"zoe@example.com","top":[{"value":"alice@example.com","count":12}]}', NULL, NULL),
    ('region', 2, 'VARCHAR', 'string', false, false, 'internal', NULL, '{"type":"VARCHAR","null_pct":0,"distinct_approx":4,"distinct_pct":0.333,"min":"AMER","max":"EMEA","top":[{"value":"EMEA","count":600},{"value":"AMER","count":400}]}', NULL, NULL),
    ('margin', 3, 'DECIMAL(12,2)', 'number', false, false, 'confidential', NULL, '{"type":"DECIMAL","null_pct":1,"distinct_approx":800,"distinct_pct":66,"min":"0.10","max":"412.00","top":[{"value":"12.00","count":40}]}', NULL, NULL),
    ('_dl_load_id', 9, 'BIGINT', NULL, false, true, 'internal', NULL, NULL, NULL, NULL)
  ) c(name, ord, ptype, ltype, is_key, is_system, class, pkind, profile, bname, descr)
 WHERE d.key = 'curated.sales_orders'
   AND NOT EXISTS (SELECT 1 FROM dataset_columns x WHERE x.dataset_id = d.id AND x.name = c.name);
INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, logical_type, classification, personal_kind)
SELECT d.id, c.name, c.ord, c.ptype, c.ltype, c.class, c.pkind
  FROM datasets d CROSS JOIN (VALUES ('member_name', 0, 'VARCHAR', 'string', 'confidential', 'name'), ('salary', 1, 'DECIMAL(12,2)', 'number', 'restricted', NULL)) c(name, ord, ptype, ltype, class, pkind)
 WHERE d.key = 'curated.payroll' AND NOT EXISTS (SELECT 1 FROM dataset_columns x WHERE x.dataset_id = d.id AND x.name = c.name);
INSERT INTO dataset_columns (dataset_id, name, ordinal, physical_type, logical_type, classification)
SELECT d.id, c.name, c.ord, c.ptype, c.ltype, 'internal'
  FROM datasets d CROSS JOIN (VALUES ('month', 0, 'DATE', 'date'), ('region', 1, 'VARCHAR', 'string'), ('revenue', 2, 'DECIMAL(14,2)', 'number')) c(name, ord, ptype, ltype)
 WHERE d.key = 'product.monthly_revenue' AND NOT EXISTS (SELECT 1 FROM dataset_columns x WHERE x.dataset_id = d.id AND x.name = c.name);
INSERT INTO schema_versions (dataset_id, version, columns, changes, detected_by, decision)
SELECT d.id, v.n, '[]'::jsonb, v.ch::jsonb, 'load', v.dec FROM datasets d CROSS JOIN (VALUES (1, '[]', 'none'), (2, '[{"column":"region","kind":"added"}]', 'accepted')) v(n, ch, dec)
 WHERE d.key = 'curated.sales_orders' AND NOT EXISTS (SELECT 1 FROM schema_versions s WHERE s.dataset_id = d.id AND s.version = v.n);
INSERT INTO column_profiles (dataset_id, column_name, profile, profiled_at)
SELECT d.id, 'region', p.profile::jsonb, now() - p.ago FROM datasets d CROSS JOIN (VALUES
    ('{"null_pct":4,"distinct_pct":0.5,"min":"AMER","max":"EMEA"}', interval '2 days'), ('{"null_pct":0,"distinct_pct":0.333,"min":"AMER","max":"EMEA"}', interval '1 hour')) p(profile, ago)
 WHERE d.key = 'curated.sales_orders' AND NOT EXISTS (SELECT 1 FROM column_profiles x WHERE x.dataset_id = d.id AND x.column_name = 'region');
INSERT INTO column_profiles (dataset_id, column_name, profile, profiled_at)
SELECT d.id, 'customer_email', '{"null_pct":3,"distinct_pct":70,"min":"bob@example.com","max":"yan@example.com","top":[{"value":"bob@example.com","count":3}]}'::jsonb, now() - interval '3 days'
  FROM datasets d WHERE d.key = 'curated.sales_orders' AND NOT EXISTS (SELECT 1 FROM column_profiles x WHERE x.dataset_id = d.id AND x.column_name = 'customer_email');
SQL);
    return $ids;
}
/** The slugs a dataset carries (as postgres). */
function tags_of(int $datasetId): array { return pg("SELECT unnest(dl_tags_of('dataset', $datasetId))"); }
