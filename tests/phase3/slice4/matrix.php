<?php
/**
 * Slice 4, proof 1 — THE MATRIX (access-and-policies.md §8): ten callers (and the Expert acting for Priya and for Ann, K38) × eight datasets,
 * every cell read through three doors that must agree with the referee, dl_access_of() — the dataset page (JSON: 404 / the stub / the entry
 * and my_level), mcp_datasets as the records server's read role ('V' / 'S' / nothing), and the query service (`preview`: rows at read and
 * above, masked below read_unmasked, refused at discover) — plus the cells the design names outright, the K7 consumer `gl`, and the
 * fail-closed ceiling of a kernel without K38.
 */
require __DIR__ . '/lib.php';
$ds = [];
foreach (SLICE4_DATASETS as $k) {
    $ds[$k] = ds_id($k);
}
$masking = static fn (int $id): bool => (int) pg1("SELECT count(*) FROM jsonb_object_keys(dl_masks_for($id, 'read'))") > 0;
pg('UPDATE members SET capability = NULL WHERE id = 41');           // the Watcher: never admitted (an earlier run's facts may have vouched for it)

echo "1. Every cell: the page, the read role and the query service agree with dl_access_of()\n";
$callers = [];
foreach (SLICE4_CALLERS as $c) {
    $callers[] = [$c, null, (string) $c];
}
$callers[] = [40, 26, '40 for Priya'];
$callers[] = [40, 29, '40 for Ann'];
$cells = [];
foreach ($callers as [$c, $for, $label]) {
    foreach ($ds as $key => $id) {
        $lvl = ref_level($id, $c, $for);
        $role = read_role_row($id, $c, $for);
        $agent = in_array($c, [40, 41, 42], true);
        $page = $agent ? agent_get($c, "/datasets/$id", $for) : get_json($c, "/datasets/$id");
        $d = $page['json']['data']['dataset'] ?? null;
        $seen = $page['code'] !== 200 ? 'none' : (!empty($d['is_stub']) ? 'stub' : (string) ($d['my_level'] ?? '?'));
        $bad = [];
        if ($lvl === 'none') {
            if ($role === 'S' && $seen !== 'stub') { $bad[] = "read role says stub, page $seen"; }
            if ($role === '' && $seen !== 'none') { $bad[] = "page shows $seen"; }
            if ($role === 'V') { $bad[] = 'read role shows the entry'; }
        } else {
            if ($seen !== $lvl) { $bad[] = "page says $seen"; }
            if ($role !== 'V') { $bad[] = "read role '$role'"; }
            $pv = $agent ? (agent_get($c, "/datasets/$id/preview", $for)['json']['data']['preview'] ?? []) : preview_as($c, $id);
            $okRead = ($pv['status'] ?? '') === 'ok';
            if (dl_level_rank($lvl) >= 2 && !$okRead) { $bad[] = 'preview refused: ' . ($pv['refusal_rule'] ?? '?'); }
            if (dl_level_rank($lvl) < 2 && $okRead) { $bad[] = 'preview answered rows at discover'; }
            if ($okRead && ($pv['rows'] ?? []) !== []) {
                $masked = ($pv['masked_columns'] ?? []) !== [];
                if (dl_level_rank($lvl) >= 3 && $masked) { $bad[] = 'masked at ' . $lvl; }
                if ($lvl === 'read' && $masking($id) && !$masked) { $bad[] = 'unmasked at read'; }
            }
        }
        $cells[$label][$key] = $lvl === 'none' ? ($role === 'S' ? 'S' : '—') : $lvl;
        ok($bad === [], sprintf('%-13s %-24s %-14s %s', $label, $key, $lvl . ($role === 'S' ? ' (stub)' : ''), $bad === [] ? 'agrees' : implode('; ', $bad)));
    }
}

echo "2. The cells the design names\n";
$c = static fn (string $who, string $k): string => $cells[$who][$k] ?? '?';
ok($c('26', 'curated.region_sales') === 'read' && $c('26', 'curated.payroll') === 'discover' && $c('26', 'curated.hr_reviews') === 'S' && $c('26', 'sandbox.m26_my_upload') === 'own',
   'Priya: region sales read (filtered), payroll discover only (Sales may know it exists), HR reviews a stub, her sandbox her own');
$pv = preview_as(26, $ds['curated.region_sales']);
$dep = col_index($pv, 'department_id');
ok(($pv['status'] ?? '') === 'ok' && $dep !== null && count($pv['rows']) > 0 && array_unique(array_map(static fn ($r) => (int) $r[$dep], $pv['rows'])) === [8],
   'Priya\'s region sales are her department\'s rows only (the grant\'s `department_id = ANY({departments})`, bound for her)');
$pr = preview_as(26, $ds['curated.payroll']);
ok(($pr['status'] ?? '') === 'refused' && ($pr['refusal_rule'] ?? '') === 'discover_only', 'Priya\'s payroll preview: refused discover_only, in the policy\'s words');
$dn = preview_as(30, $ds['curated.payroll']);
$sal = col_index($dn, 'salary');
ok($c('30', 'curated.payroll') === 'read_unmasked' && ($dn['masked_columns'] ?? ['x']) === [] && $sal !== null && $dn['rows'][0][$sal] !== null,
   'Dana reads payroll UNMASKED through the group SMOKE Finance analysts (a tag grant on finance) — salaries as they are');
ok($c('29', 'product.monthly_revenue') === '—' && $c('29', 'curated.products') === '—' && $c('29', 'curated.hr_reviews') === '—',
   'Ann (external): nothing — not everyone\'s grant, not the catalog, not a stub; her grant on products expired yesterday');
ok($c('28', 'product.monthly_revenue') === 'read' && $c('28', 'curated.payroll') === 'S' && $c('31', 'curated.region_sales') === 'read',
   'Bea and Lee, whose roles the kernel has not sent, are Members by their capability: what everyone reads, payroll a stub, Lee (Sales) his department\'s region sales');
ok(array_unique(array_values($cells['41'])) === ['—'], 'the Watcher (an agent the kernel vouches no endpoint here for): nothing anywhere — not admitted');
$ex = agent_get(40, '/datasets/' . $ds['curated.payroll'] . '/preview')['json']['data']['preview'] ?? [];
ok($c('40', 'curated.payroll') === 'read' && in_array('member_name', $ex['masked_columns'] ?? [], true) && $ex['rows'][0][col_index($ex, 'salary')] === null,
   'the Expert alone reads payroll through its own grant — masked: names partial, the restricted salary empty');
ok($c('40 for Priya', 'curated.payroll') === 'discover' && $c('40 for Priya', 'curated.region_sales') === 'discover',
   'the Expert acting for Priya: the LOWER level of the two (payroll: its read ∩ her discover; region sales: her read ∩ its discover)');
ok($c('40 for Ann', 'product.monthly_revenue') === '—' && $c('40 for Ann', 'curated.hr_reviews') === '—',
   'the Expert acting for Ann (external): nothing — not even a stub\'s name (the stub honours who it acts for)');
ok($c('42', 'curated.payroll') === 'S' && $c('42', 'product.monthly_revenue') === 'read', 'the Steward alone: payroll a stub (no grant on install, ★1), revenue read (everyone)');
ok($c('1', 'curated.hr_reviews') === 'own' && $c('1', 'raw.crm_deals') === 'discover', 'the super-admin: owns HR reviews; discovers deals (the admin discovers all, reads rows only by break-glass)');

echo "3. The K7 consumer `gl` (the policy the query service compiles for a sibling — its socket door is slice 9's)\n";
$app = json_decode((string) (pg("SET ROLE datalake_query; SELECT dl_query_policy_app('gl', ARRAY['product.monthly_revenue', 'curated.payroll', 'curated.sales_orders'])::text;")[0] ?? '{}'), true) ?: [];
ok(isset($app['datasets']['product.monthly_revenue']) && ($app['datasets']['product.monthly_revenue']['level'] ?? '') === 'read'
   && array_key_exists('row_filter', $app['datasets']['product.monthly_revenue']) && $app['datasets']['product.monthly_revenue']['row_filter'] === null,
   '`gl` reads revenue (shared with it): read, masked as for a Member, never a person\'s row filter');
ok(isset($app['refused']['curated.payroll'], $app['refused']['curated.sales_orders']) && !isset($app['datasets']['curated.payroll']), '…and nothing not shared with it (refused not shared)');

echo "4. K38 on a kernel that does not say who asked: fail closed\n";
$k = agent_get(40, '/datasets/' . $ds['curated.sales_orders'] . '/preview', -1)['json'];
$kr = agent_get(40, '/datasets/' . $ds['product.monthly_revenue'] . '/preview', -1)['json'];
ok(($k['data']['preview']['refusal_rule'] ?? '') === 'k38_pending' && (($kr['data']['preview']['status'] ?? '') === 'ok'),
   'a chat turn whose facts name nobody: orders (personal) refused k38_pending; revenue (internal) read');
finish();
