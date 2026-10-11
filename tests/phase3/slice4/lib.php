<?php
/**
 * Helpers for the slice 4 proofs (docs/build-specs/access-policies.md "Proof"; access-and-policies.md §8), on top of slice 3's (sql_as,
 * result_of, run_row, qs_any, setting), slice 2's (qs, wait_until) and slice 1's (actor, post_json, get_json, post_form, html_as, pg1, ds_id).
 * Run through tests/phase3/slice4/run.sh: scratch database dl_dev4 with the engine REAL, the app on :9001 (php -S or a real Apache), the fake
 * kernel :9002, the fake MaluDB + MaluMail :9003.
 * THE TEN CALLERS (bin/dev_directory.json): 1 the owner (super-admin, Lake admin — grants.manage, policies.manage, lake.admin_read); 26 Priya a
 * Member (Sales 8); 27 Marco a Data engineer (Finance 7 — catalog.edit; owns sales_orders, products, crm_deals, region_sales); 28 Bea (no role
 * here); 29 Ann (external, a Member); 30 Dana an Analyst (Sales 8); 31 Lee (no role, Sales 8); 40 the Expert, 41 the Watcher (no role), 42 the
 * Steward — agents. And a K7 consumer, `gl`.
 */
require dirname(__DIR__) . '/slice3/lib.php';

const SLICE4_CALLERS = [1, 26, 27, 28, 29, 30, 31, 40, 41, 42];
const SLICE4_DATASETS = ['curated.sales_orders', 'curated.products', 'curated.payroll', 'product.monthly_revenue', 'sandbox.m26_my_upload', 'raw.crm_deals',
                         'curated.region_sales', 'curated.hr_reviews'];

/** The slice's lake: slice 3's, then (as postgres) the finance tag on payroll with a group reading it unmasked, products with Ann's EXPIRED grant,
 *  crm_deals (a personal email by the classifier's rule, a phone proposed at 0.6 — no grant), the `gl` share of revenue; then the tables (seed.py). */
function seed_slice4(): array
{
    $ids = seed_slice3();
    pg(<<<'SQL'
INSERT INTO datasets (key, zone, name, title, description, owner_member_id, steward_member_id, department_id, status, classification_floor)
VALUES ('curated.products', 'curated', 'products', 'SMOKE Products', 'What we sell.', 27, NULL, 7, 'active', 'internal'),
       ('raw.crm_deals', 'raw', 'crm_deals', 'SMOKE CRM deals', 'The deals the CRM shares, raw.', 27, NULL, 8, 'active', 'internal')
ON CONFLICT (key) DO NOTHING;
INSERT INTO tags (slug, label, kind) SELECT 'finance', 'SMOKE Finance', 'managed' WHERE NOT EXISTS (SELECT 1 FROM tags WHERE slug = 'finance');
INSERT INTO record_tags (record_type, record_id, tag_id, added_by)
SELECT 'dataset', d.id, t.id, 1 FROM datasets d, tags t WHERE d.key = 'curated.payroll' AND t.slug = 'finance'
   AND NOT EXISTS (SELECT 1 FROM record_tags x WHERE x.record_type = 'dataset' AND x.record_id = d.id AND x.tag_id = t.id);
INSERT INTO groups (key, name, description, kind, created_by) SELECT 'smoke-finance-analysts', 'SMOKE Finance analysts', 'Read finance data unmasked.', 'custom', 1
 WHERE NOT EXISTS (SELECT 1 FROM groups WHERE key = 'smoke-finance-analysts');
INSERT INTO group_members (group_id, member_id, role) SELECT id, 30, 'member' FROM groups WHERE key = 'smoke-finance-analysts' ON CONFLICT DO NOTHING;
INSERT INTO grants (target_kind, tag_id, principal_kind, principal_id, level, reason, granted_by)
SELECT 'tag', t.id, 'group', g.id, 'read_unmasked', 'SMOKE finance analysts read finance data', 1 FROM tags t, groups g
 WHERE t.slug = 'finance' AND g.key = 'smoke-finance-analysts'
   AND NOT EXISTS (SELECT 1 FROM grants x WHERE x.target_kind = 'tag' AND x.tag_id = t.id AND x.principal_kind = 'group' AND x.principal_id = g.id AND x.revoked_at IS NULL);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, expires_at, reason, granted_by, created_at)
SELECT 'dataset', d.id, 'member', 29, 'read', now() - interval '1 day', 'SMOKE expired yesterday', 1, now() - interval '10 days' FROM datasets d
 WHERE d.key = 'curated.products' AND NOT EXISTS (SELECT 1 FROM grants x WHERE x.dataset_id = d.id AND x.principal_kind = 'member' AND x.principal_id = 29);
INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_ref, level, reason, granted_by)
SELECT 'dataset', d.id, 'application', 'gl', 'read', 'SMOKE the ledger reads revenue', 1 FROM datasets d
 WHERE d.key = 'product.monthly_revenue' AND NOT EXISTS (SELECT 1 FROM grants x WHERE x.dataset_id = d.id AND x.principal_kind = 'application');
SQL);
    $root = dirname(__DIR__, 3);
    exec(escapeshellarg($root . '/services/.venv/bin/python') . ' ' . escapeshellarg(__DIR__ . '/seed.py') . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "seed.py failed:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
    pg(<<<'SQL'
UPDATE dataset_columns c SET personal_kind = 'email', classified_by = 'rule', proposed_personal_kind = NULL, proposed_confidence = NULL
  FROM datasets d WHERE d.id = c.dataset_id AND d.key = 'raw.crm_deals' AND c.name = 'buyer_email' AND c.classification_confirmed_at IS NULL AND c.personal_kind IS NULL;
UPDATE dataset_columns c SET proposed_personal_kind = 'phone', proposed_confidence = 0.6
  FROM datasets d WHERE d.id = c.dataset_id AND d.key = 'raw.crm_deals' AND c.name = 'contact' AND c.classification_confirmed_at IS NULL;
SQL);
    foreach (['curated.products', 'raw.crm_deals'] as $k) {
        $ids[$k] = ds_id($k);
    }
    return $ids;
}

/** A level's rank (db/008's order) — the proofs' own copy: none 0, discover 1, read 2, read_unmasked 3, write 4, own 5. */
function dl_level_rank(string $l): int { return ['none' => 0, 'discover' => 1, 'read' => 2, 'read_unmasked' => 3, 'write' => 4, 'own' => 5][$l] ?? 0; }

/** The referee: a caller's level on a dataset, and for an agent acting for a person the intersection (dl_access_of, as postgres). */
function ref_level(int $dataset, int $caller, ?int $for = null): string
{
    return (string) (pg1("SELECT level FROM dl_access_of($dataset, $caller, " . ($for === null ? 'NULL' : $for) . ')') ?? 'none');
}

/** mcp_datasets as the records server's read role for a caller (and the person an agent acts for): 'V' (the entry), 'S' (a stub) or '' (nothing). */
function read_role_row(int $dataset, int $caller, ?int $for = null): string
{
    return (string) (pg("SET ROLE datalake_records_ro; SET app.member_id = '$caller'; SET app.on_behalf_of = '" . ($for ?? '') . "';
        SELECT CASE WHEN is_stub THEN 'S' ELSE 'V' END FROM mcp_datasets WHERE dataset_id = $dataset;")[0] ?? '');
}

/** An agent's run, as the kernel would relay it: the run facts (a duty → the agent alone; a chat turn → K38's requester; false → a kernel
 *  without K38) and its headers. */
function agent_headers(int $agent, ?int $requester, string $trigger = 'chat'): array
{
    static $run = null;
    $run ??= 20000 + random_int(1, 5000) * 10;
    $run++;
    set_run_facts($run, $agent, $trigger, $requester === -1 ? false : $requester);
    if ($agent === 41) {                                // the Watcher: the kernel vouches for no endpoint of this application — never admitted here
        kernel_state(function (array $s) use ($run): array { $s['facts'][(string) $run]['endpoints'] = []; return $s; });
    }
    return as_agent(run_token($agent, $run));
}

/** A GET as an agent (JSON): ['code', 'json']. $requester: the person the run acts for, null for a duty, -1 for a kernel without K38. */
function agent_get(int $agent, string $path, ?int $requester = null, string $trigger = 'duty'): array
{
    $r = req('GET', $path, ['headers' => agent_headers($agent, $requester, $requester === null ? $trigger : 'chat')]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}

/** A POST as an agent (JSON), relayed by the kernel's actions server. */
function agent_post(int $agent, string $path, array $form, ?int $requester = null): array
{
    $r = req('POST', $path, ['headers' => agent_headers($agent, $requester, $requester === null ? 'duty' : 'chat'), 'form' => $form]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}

/** A dataset's preview through the query service as a member (JSON: data.preview). */
function preview_as(int $m, int $dataset): array { return get_json($m, "/datasets/$dataset/preview")['json']['data']['preview'] ?? []; }

/** The column index of a name in a preview answer. */
function col_index(array $p, string $name): ?int
{
    foreach ($p['columns'] ?? [] as $i => $c) {
        if (($c['name'] ?? '') === $name) {
            return $i;
        }
    }
    return null;
}

/** The newest notification of a kind for a member (as postgres): [title, record_type, record_id] or null. */
function last_notice(int $m, string $kind, int $since = 0): ?array
{
    $r = pg("SELECT title || '|' || COALESCE(record_type, '') || '|' || COALESCE(record_id::text, '') FROM notifications WHERE member_id = $m AND kind = '$kind' AND id > $since ORDER BY id DESC LIMIT 1");
    return $r === [] ? null : explode('|', $r[0]);
}
function last_notice_id(): int { return (int) pg1('SELECT COALESCE(max(id), 0) FROM notifications'); }
