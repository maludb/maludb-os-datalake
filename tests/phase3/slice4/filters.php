<?php
/**
 * Slice 4 — row filters (access-policies.md divergence 1, ★13; query-service §7 `check_row_filter`) and K38's intersection through them: the
 * Check door binds the tokens for the granter and asks the query service — columns, literals and comparisons only, then bound and RUN by DuckDB
 * (a cast that fails on the values is caught at the granter's save, its value never shown); an agent granted read on region sales reads every
 * row alone, only Priya's department's rows acting for her, nothing for a person who holds no role here.
 */
require __DIR__ . '/lib.php';
$deals = ds_id('raw.crm_deals');
$region = ds_id('curated.region_sales');

echo "1. The Check door (the grant form's dry run)\n";
$check = static fn (int $m, string $f, int $d = 0): array => post_json($m, '/access/grants/check.php', ['dataset' => $d ?: $deals, 'row_filter' => $f]);
$a = $check(27, 'department_id = ANY({departments})');
ok(($a['json']['data']['valid'] ?? false) === true && ($a['json']['data']['row_filter'] ?? '') === 'department_id = ANY(ARRAY[7]::BIGINT[])' && ($a['json']['data']['checked_against'] ?? '') === 'lake',
   'valid — the tokens bound for the granter (Marco, Finance: ARRAY[7]) and checked against the lake\'s table');
foreach ([['department_id IN (SELECT 1)', 'Subquery', 'a subquery'], ["upper(buyer_email) = 'X'", 'Upper', 'a function'],
          ['deal_id IN (SELECT deal_id FROM curated.sales_orders)', 'Subquery', 'another table'], ['nope = 1', 'not a column', 'a column the dataset lacks']] as [$f, $word, $what]) {
    $x = $check(27, $f)['json']['data'] ?? [];
    ok(($x['valid'] ?? true) === false && str_contains((string) ($x['error'] ?? ''), 'A row filter uses the dataset\'s columns, literals and comparisons only') && str_contains((string) $x['error'], $word),
       "refused: $what — \"" . mb_substr((string) ($x['error'] ?? ''), 74, 60) . '"');
}
$cast = $check(27, 'deal_id = 5')['json']['data'] ?? [];
ok(($cast['valid'] ?? true) === false && str_contains((string) $cast['error'], 'fails on the dataset\'s values') && !str_contains((string) $cast['error'], 'D-0'),
   'a text key compared with a number: refused when RUN (DuckDB\'s cast) — and the value it failed on is never shown');
$cmp = $check(27, "amount >= 100 AND department_id IN (7, 8) AND NOT (deal_id LIKE 'D-00%') AND amount BETWEEN 1 AND 99999");
ok(($cmp['json']['data']['valid'] ?? false) === true, 'comparisons, IN, LIKE, NOT, BETWEEN, AND: valid');
ok($check(30, 'department_id = 8')['code'] === 404 && $check(26, 'department_id = 8', ds_id('curated.sales_orders'))['code'] === 403,
   'a non-owner may not check: Dana on deals (a stub to her — nothing to a handler, 404); Priya on orders she reads (403)');
$html = post_form(27, '/access/grants/check.php', ['dataset' => $deals, 'row_filter' => 'department_id IN (SELECT 1)'], ['HX-Request: true']);
ok($html['code'] === 200 && str_contains($html['body'], 'id="grant-form-row-filter-error"') && str_contains($html['body'], 'id="grant-form-field-row_filter"'), 'HTMX: the field comes back with the node it refused');
$g0 = (int) pg1('SELECT count(*) FROM grants');
$bad = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $deals, 'principal_kind' => 'department', 'principal' => 8, 'level' => 'read', 'row_filter' => 'lower(buyer_email) = \'x\'']);
ok($bad['code'] === 422 && field_of($bad) === 'row_filter' && (int) pg1('SELECT count(*) FROM grants') === $g0, 'grant_set with a function in the filter: refused on the field — nothing saved');

echo "2. K38 through a row filter: an agent reads no more than the person it acts for\n";
pg("UPDATE grants SET revoked_at = now() WHERE dataset_id = $region AND principal_kind = 'agent' AND principal_id = 40 AND revoked_at IS NULL");
$ag = post_json(27, '/access/grants/save.php', ['target' => 'dataset', 'dataset' => $region, 'principal_kind' => 'agent', 'principal' => 40, 'level' => 'read', 'reason' => 'SMOKE the Expert answers on region sales']);
ok($ag['code'] === 200, 'Marco grants the Expert read on region sales (no filter — ★1: an agent reads nothing until granted)');
$depts = static function (array $p): array { $i = col_index($p, 'department_id'); return $i === null ? [] : array_values(array_unique(array_map(static fn ($r) => (int) $r[$i], $p['rows'] ?? []))); };
$alone = agent_get(40, "/datasets/$region/preview")['json']['data']['preview'] ?? [];
$forP = agent_get(40, "/datasets/$region/preview", 26)['json']['data']['preview'] ?? [];
$d1 = $depts($alone); sort($d1);
ok(($alone['status'] ?? '') === 'ok' && $d1 === [7, 8], 'the Expert alone (a duty) reads every department\'s rows');
ok(($forP['status'] ?? '') === 'ok' && $depts($forP) === [8], 'the Expert in Priya\'s chat turn reads Priya\'s department\'s rows ONLY — her filter applies to it');
$qr = run_row((int) ($forP['query_run_id'] ?? 0));
ok(($qr['caller_member_id'] ?? 0) === 40 && ($qr['on_behalf_of'] ?? 0) === 26 && isset($qr['row_filters']['curated.region_sales']), '…audited as the Expert on Priya\'s behalf, the bound filter in the run');
$forB = agent_get(40, "/datasets/$region/preview", 28)['json']['data']['preview'] ?? [];
ok(($forB['status'] ?? '') === 'refused' && ($forB['refusal_rule'] ?? '') === 'discover_only', 'acting for Bea (who may only discover it): the lower level — refused discover_only');
pg('UPDATE members SET capability = NULL WHERE id = 31');
$forL = agent_get(40, "/datasets/$region/preview", 31);
pg("UPDATE members SET capability = 'write' WHERE id = 31");
ok($forL['code'] === 404, 'acting for a person who holds no role here (Lee, his access withdrawn): nothing — the intersection is empty (404)');
$forA = agent_get(40, "/datasets/$region", 29);
ok($forA['code'] === 404, 'acting for Ann (external): nothing, not even the entry');
pg("UPDATE grants SET revoked_at = now() WHERE dataset_id = $region AND principal_kind = 'agent' AND principal_id = 40 AND revoked_at IS NULL");
finish();
