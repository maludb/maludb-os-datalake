<?php
/**
 * Slice 7 proof 2 — metrics (transforms-products-lineage.md "Proof"; query-service §7 `metric`): a metric on a curated dataset is refused; the definition is checked through the
 * service (one aggregate, the columns bound, the grains and dimensions the product has); metric_value by month and by a dimension equals the same SQL run by hand; the service refuses a
 * grain the metric does not allow and a dimension it does not have (`invalid_metric`, audited with the metric's id); the chart renders twelve points; a masked dimension arrives masked; editing the
 * expression drops the certification (editing the title does not); an agent's certify pauses; retire.
 */
require __DIR__ . '/lib.php';
$std = standard_transforms();
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');
$monthly = ds_id('product.s7_monthly');
$def = ['name' => 's7_revenue', 'title' => 'S7 Revenue', 'dataset' => $monthly, 'expression' => 'sum(revenue)', 'time_column' => 'month', 'grains' => ['month', 'quarter', 'year'], 'dimensions' => ['region'], 'format' => 'currency', 'unit' => '$'];

echo "1. Defining a metric\n";
$x = post_json(27, '/metrics/save.php', ['dataset' => ds_id('curated.s7_orders')] + $def);
ok($x['code'] === 422 && field_of($x) === 'dataset' && str_contains(message_of($x), 'product'), 'a metric on a curated dataset is refused: ' . message_of($x));
$x = post_json(27, '/metrics/save.php', ['dataset' => ds_id('product.s7_empty')] + $def);
ok($x['code'] === 422 && field_of($x) === 'dataset' && str_contains(message_of($x), 'no data yet'), 'a product nothing has made yet: refused — "run the transform first"');
ok(post_json(26, '/metrics/save.php', $def)['code'] === 403, 'a Member may not define a metric: 403');
$x = post_json(27, '/metrics/save.php', ['time_column' => 'nowhere'] + $def);
ok($x['code'] === 422 && field_of($x) === 'time_column', 'a time column the product does not have: refused on `time_column`');
$x = post_json(27, '/metrics/save.php', ['dimensions' => 'region,planet'] + $def);
ok($x['code'] === 422 && field_of($x) === 'dimensions' && str_contains(message_of($x), 'planet'), 'a dimension it does not have: refused — ' . message_of($x));
$x = post_json(27, '/metrics/save.php', ['dimensions' => 'month'] + $def);
ok($x['code'] === 422 && field_of($x) === 'dimensions', 'the time column is not also a dimension');
foreach ([['revenue', 'not an aggregate'], ['sum(revenue); DROP TABLE x', 'a second statement'], ['sum(revenue) -- x', 'a comment'], ['(SELECT 1)', 'a subquery'], ['sum(revenue) OVER ()', 'a window'], ['sum(nosuchcolumn)', 'a column the product lacks']] as [$expr, $why]) {
    $x = post_json(27, '/metrics/save.php', ['expression' => $expr] + $def);
    ok($x['code'] === 422 && field_of($x) === 'expression', "the expression ($why) is refused on `expression`: " . message_of($x));
}
$x = post_json(27, '/metrics/save.php', ['filters' => 'sum(revenue) > 1'] + $def);
ok($x['code'] === 422 && field_of($x) === 'filters', 'a filter with an aggregate: refused on `filters`');
$x = post_json(27, '/metrics/save.php', ['filters' => 'nosuch = 1'] + $def);
ok($x['code'] === 422, 'a filter on a column the product lacks: refused (the SELECT does not bind)');
$x = post_json(27, '/metrics/save.php', ['grains' => 'month,fortnight'] + $def);
ok($x['code'] === 422 && field_of($x) === 'grains', 'a grain that is not day, week, month, quarter or year: refused');
ok(pg1("SELECT count(*) FROM metrics WHERE name = 's7_revenue'") === '0', 'none of the refused saves left a row');
$r = post_json(27, '/metrics/save.php', $def);
$m = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $m > 0 && ($r['json']['certification_dropped'] ?? true) === false, 'Marco defines Revenue over product.s7_monthly');
$x = post_json(27, '/metrics/save.php', $def);
ok($x['code'] === 422 && field_of($x) === 'name', 'a name taken: refused on `name`');
$log = pg("SELECT after::text FROM activity_log WHERE id > $a0 AND action = 'metric.save' ORDER BY id");
ok(count($log) === 1 && !str_contains($log[0], 'sum(') && str_contains($log[0], 'product.s7_monthly'), 'metric.save is logged with the product and the cuts — never the expression\'s text');
$mrow = pg("SELECT row_to_json(x)::text FROM (SELECT certified, grains, dimensions, owner_member_id, created_by FROM metrics WHERE id = $m) x");
$mr = json_decode($mrow[0] ?? '{}', true) ?: [];
ok($mr['certified'] === false && $mr['grains'] === ['month', 'quarter', 'year'] && $mr['dimensions'] === ['region'] && $mr['owner_member_id'] === 27, 'a draft, owned by Marco');

echo "2. The value equals the same SQL by hand\n";
$fetch = static function (string $q) use ($m): array {
    $d = get_json(27, "/metrics/$m" . $q)['json']['data'] ?? [];
    return $d;
};
$d = $fetch('?grain=month');
$win = $d['window'] ?? [];
$rows = array_map(static fn (array $r): array => [substr((string) $r['period'], 0, 10), (float) $r['value']], $d['value']['rows'] ?? []);
$hand = result_of(sql_as(27, "SELECT CAST(date_trunc('month', month) AS DATE) AS period, sum(revenue) AS value FROM product.s7_monthly WHERE month >= '{$win['from']}' AND month < '{$win['to']}' GROUP BY ALL ORDER BY period"));
$handRows = array_map(static fn (array $r): array => [substr((string) $r[0], 0, 10), (float) $r[1]], $hand['rows'] ?? []);
ok(count($rows) === 12 && $rows === $handRows, 'by month (twelve periods): the metric\'s rows equal the same SQL run by hand — ' . count($rows) . ' rows');
$d = $fetch('?grain=month&by[]=region');
$byRows = array_map(static fn (array $r): array => [substr((string) $r['period'], 0, 10), $r['region'], (float) $r['value']], $d['value']['rows'] ?? []);
$hand = result_of(sql_as(27, "SELECT CAST(date_trunc('month', month) AS DATE) AS period, region, sum(revenue) AS value FROM product.s7_monthly WHERE month >= '{$win['from']}' AND month < '{$win['to']}' GROUP BY ALL ORDER BY period, region"));
$handBy = array_map(static fn (array $r): array => [substr((string) $r[0], 0, 10), $r[1], (float) $r[2]], $hand['rows'] ?? []);
ok(count($byRows) === 48 && $byRows === $handBy, 'cut by region: 48 rows (12 months × 4 regions), equal to the same SQL by hand');
$q = $fetch('?grain=quarter');
$qh = result_of(sql_as(27, "SELECT CAST(date_trunc('quarter', month) AS DATE) AS period, sum(revenue) AS value FROM product.s7_monthly WHERE month >= '{$q['window']['from']}' AND month < '{$q['window']['to']}' GROUP BY ALL ORDER BY period"));
ok(array_map(static fn (array $r): array => [substr((string) $r['period'], 0, 10), (float) $r['value']], $q['value']['rows'] ?? []) === array_map(static fn (array $r): array => [substr((string) $r[0], 0, 10), (float) $r[1]], $qh['rows'] ?? []),
   'by quarter: equal to the same SQL by hand');
$run = run_row((int) $d['value']['query_run_id']);
ok($run['source'] === 'screen' && $run['caller_member_id'] === 27 && $run['status'] === 'ok' && $run['datasets'] === ['product.s7_monthly'] && str_contains((string) $run['sql'], 'date_trunc'), 'the audit row: the screen, Marco, the product, the compiled SELECT');
ok((int) pg1("SELECT metric_id FROM query_runs WHERE id = {$run['id']}") === $m, '…carrying the metric\'s id');

echo "3. The service judges the cut\n";
$base = revenue_metric(['id' => $m]);
$ask = static fn (array $o = [], array $meta = [], int $who = 27): array => metric_req($who, $meta + $base, $o);
$a = $ask(['grain' => 'week']);
ok($a['status'] === 'refused' && $a['refusal_rule'] === 'invalid_metric' && ($a['field'] ?? '') === 'grain' && str_contains($a['message'], 'month, quarter, year'), 'a grain the metric does not allow: invalid_metric on `grain` — ' . $a['message']);
$a = $ask(['by' => ['planet']]);
ok($a['refusal_rule'] === 'invalid_metric' && ($a['field'] ?? '') === 'by', 'a `by` that is not a dimension: invalid_metric on `by`');
$a = $ask(['where' => ['month' => 'x']]);
ok($a['refusal_rule'] === 'invalid_metric' && ($a['field'] ?? '') === 'where', 'a `where` on something that is not a dimension: invalid_metric on `where`');
$a = $ask(['from' => '2026/01/01']);
ok($a['refusal_rule'] === 'invalid_metric' && ($a['field'] ?? '') === 'from', 'a date that is not YYYY-MM-DD: invalid_metric on `from`');
$a = $ask([], ['expression' => 'sum(revenue); SELECT 1']);
ok($a['refusal_rule'] === 'invalid_metric' && ($a['field'] ?? '') === 'expression', 'a second statement in the expression: invalid_metric');
$a = $ask([], ['expression' => "sum((SELECT secret_id FROM curated.s7_secret))"]);
ok($a['refusal_rule'] === 'invalid_metric', 'a subquery in the expression: invalid_metric (it names the product\'s own columns only)');
$a = $ask([], ['expression' => 'sum(revenue)', 'filters' => "region IN (SELECT region FROM curated.s7_secret)"]);
ok($a['refusal_rule'] === 'invalid_metric' && ($a['field'] ?? '') === 'filters', 'a subquery in the filter: invalid_metric');
$a = $ask([], ['expression' => 'count(*)', 'dimensions' => ['region'], 'dataset' => 'curated.s7_secret']);
ok($a['status'] === 'refused' && in_array($a['refusal_rule'], ['not_readable', 'discover_only', 'no_such_dataset'], true), 'a definition over a dataset the caller may not read is refused by the policy, not run: ' . ($a['refusal_rule'] ?? ''));
$a = $ask(['grain' => 'month', 'where' => ['region' => 'EMEA'], 'from' => $win['from'], 'to' => $win['to']]);
$eh = result_of(sql_as(27, "SELECT CAST(date_trunc('month', month) AS DATE), sum(revenue) FROM product.s7_monthly WHERE region = 'EMEA' AND month >= '{$win['from']}' AND month < '{$win['to']}' GROUP BY ALL ORDER BY 1"));
ok($a['status'] === 'ok' && count($a['rows']) === 12 && array_map(static fn ($r) => (float) $r[1], $a['rows']) === array_map(static fn ($r) => (float) $r[1], $eh['rows']), 'a `where` on a dimension (region = EMEA): the same as the hand-written SQL');
$a = $ask(['where' => ['region' => ['EMEA', 'AMER']], 'by' => ['region'], 'grain' => 'year']);
ok($a['status'] === 'ok' && array_unique(array_column($a['rows'], 1)) !== [] && count(array_diff(array_unique(array_column($a['rows'], 1)), ['EMEA', 'AMER'])) === 0, 'a list of values (EMEA, AMER) and a cut by region: only those two');
$a = $ask(['dry_run' => true, 'by' => ['region']]);
ok($a['status'] === 'ok' && $a['rows'] === [] && ($a['query_run_id'] ?? 0) > 0, 'dry_run binds the SELECT and returns no rows (audited)');
$a = $ask([], [], 29);
ok($a['status'] === 'refused' && $a['refusal_rule'] !== 'invalid_metric', 'an external caller is refused by the policy, not by the metric: ' . ($a['refusal_rule'] ?? ''));
$a = $ask([], ['id' => $m, 'time_column' => null, 'grains' => []]);
ok($a['status'] === 'ok' && count($a['rows']) === 1 && ($a['period_column'] ?? true) === false, 'a metric with no time column is one number (no period column)');
$bad = qs_any(['v' => 1, 'kind' => 'metric', 'caller_member_id' => 27]);
ok($bad['status'] === 'refused' && $bad['refusal_rule'] === 'bad_request', 'a request that carries no definition is a bad_request');
$aud = run_row((int) ($ask(['by' => ['planet']])['query_run_id'] ?? 0));
ok($aud['status'] === 'refused' && $aud['refusal_rule'] === 'invalid_metric', 'a refusal of the cut is audited too');

echo "4. The chart\n";
$page = html_as(27, "/metrics/$m");
ok($page['code'] === 200 && str_contains($page['body'], 'id="metric-chart"') && str_contains($page['body'], 'data-points="12"') && substr_count($page['body'], 'class="metric-point"') === 24,
   'the chart renders 12 points (an SVG line, 12 markers) — drawn twice, a wide drawing and a compact one for a phone');
ok(preg_match('/<text class="metric-axis" x="[0-9.]+" y="[0-9.]+" text-anchor="end">0<\/text>/', $page['body']) === 1, 'the value axis starts at zero');
ok(substr_count($page['body'], '<polyline class="metric-line"') === 2 && str_contains($page['body'], 'id="metric-table"') && str_contains($page['body'], 'id="metric-pickers"'), 'one line, the table under it, the pickers above');
$cd = $fetch('?grain=month')['chart'] ?? [];
$vals = array_values(array_filter(array_column($cd['points'] ?? [], 'value'), static fn ($v) => $v !== null));
ok(count($cd['points'] ?? []) === 12 && count($vals) === 12 && $cd['ok'] === true, 'the JSON carries the 12 points');
$qd = $fetch('?grain=year')['chart'] ?? [];
ok(count($qd['points']) === 12 && count(array_filter(array_column($qd['points'], 'value'), static fn ($v) => $v !== null)) === 2, 'at the year grain: twelve slots, the two years with rows have a value, the others are gaps (not zeros)');
$part = req('GET', "/metrics/$m?grain=quarter&by[]=region&partial=result", ['jar' => actor(27)[0], 'headers' => ['HX-Request: true']]);
ok($part['code'] === 200 && str_starts_with(ltrim($part['body']), '<div id="metric-result"') && str_contains($part['body'], 'By region') && !str_contains($part['body'], '<html'), 'the pickers\' HTMX request returns the result alone (chart and table)');
$unknown = $fetch('?grain=week&by[]=planet');
ok(($unknown['grain'] ?? '') === 'month' && ($unknown['by'] ?? ['x']) === [], 'an unknown grain falls back to the first one the metric allows; an unknown dimension is dropped');

echo "5. Walls: a masked dimension arrives masked\n";
$bl = ds_id('product.s7_blend');
$mb = (int) (post_json(1, '/metrics/save.php', ['name' => 's7_blend_orders', 'title' => 'S7 Blend orders', 'dataset' => $bl, 'expression' => 'count(*)', 'dimensions' => 'region,email', 'grains' => 'month', 'format' => 'integer'])['json']['record_id'] ?? 0);
ok($mb > 0, 'the owner defines a count over the blend, cut by region and email (no time column)');
$own = get_json(1, "/metrics/$mb?by[]=email")['json']['data'] ?? [];
$other = get_json(26, "/metrics/$mb?by[]=email")['json']['data'] ?? [];
$ownRow = $own['value']['rows'][0]['email'] ?? '';
$otherRow = $other['value']['rows'][0]['email'] ?? '';
ok(str_ends_with((string) $ownRow, '@example.invalid') && !str_contains((string) $ownRow, '***') && str_contains((string) $otherRow, '***') && ($other['value']['masked_columns'] ?? []) !== [],
   'cut by email: the owner reads the addresses, Priya reads them masked — and the answer says a column was masked for her');
$h = html_as(26, "/metrics/$mb?by[]=email");
ok(str_contains($h['body'], 'masked for you'), 'the page marks the masked column for her');
$ann = get_json(29, "/metrics/$m");
ok(in_array($ann['code'], [403, 404], true), 'an external may not open the metric (the product is not hers to discover): ' . $ann['code']);
ok(get_json(26, "/metrics/$m")['code'] === 200, 'Priya, who may read the product, opens it');
$list = get_json(26, '/metrics')['json']['data'] ?? [];
ok(in_array($m, array_column($list['rows'] ?? [], 'metric_id'), true) && !array_key_exists('expression', ($list['rows'][0] ?? [])), 'the list shows it (and not the expression)');
$pick = req('GET', '/pick/?source=metric&q=revenue', ['jar' => actor(26)[0], 'headers' => ['HX-Request: true']]);
ok($pick['code'] === 200 && str_contains($pick['body'], 'S7 Revenue'), 'the metric picker finds it by title');

echo "6. Certification\n";
$c = post_json(26, '/metrics/certify.php', ['metric' => $m]);
ok($c['code'] === 403, 'a Member may not certify: 403');
$c = post_json(27, '/metrics/certify.php', ['metric' => $m]);
ok($c['code'] === 200 && pg1("SELECT certified::text || '|' || certified_by FROM metrics WHERE id = $m") === 'true|27', 'Marco certifies it');
$cl = html_as(26, "/metrics/$m");
ok(str_contains($cl['body'], 'id="metric-view-state"') && str_contains($cl['body'], 'Certified') && !str_contains($cl['body'], 'id="metric-' . $m . '-certify"'), 'everyone sees the badge; only a manager has the button');
$t = post_json(27, '/metrics/save.php', ['metric' => $m, 'title' => 'S7 Revenue (renamed)']);
ok($t['code'] === 200 && ($t['json']['certification_dropped'] ?? true) === false && pg1("SELECT certified FROM metrics WHERE id = $m") === 't', 'renaming it keeps the badge');
$t = post_json(27, '/metrics/save.php', ['metric' => $m, 'grains' => 'month,quarter,year,week']);
ok($t['code'] === 200 && pg1("SELECT certified FROM metrics WHERE id = $m") === 't', 'adding a grain keeps the badge (the definition is the same number)');
$t = post_json(27, '/metrics/save.php', ['metric' => $m, 'expression' => 'sum(revenue) * 1.1']);
ok($t['code'] === 200 && ($t['json']['certification_dropped'] ?? false) === true && pg1("SELECT certified FROM metrics WHERE id = $m") === 'f' && str_contains((string) $t['json']['location'], 'notice=dropped'),
   'editing the expression drops the certification, by itself, and the page says so');
post_json(27, '/metrics/save.php', ['metric' => $m, 'expression' => 'sum(revenue)']);
post_json(27, '/metrics/certify.php', ['metric' => $m]);
$t = post_json(27, '/metrics/save.php', ['metric' => $m, 'filters' => "region <> 'APAC'"]);
ok(($t['json']['certification_dropped'] ?? false) === true, 'a fixed filter changes the number: the badge drops');
post_json(27, '/metrics/save.php', ['metric' => $m, 'filters' => '']);
$un = post_json(27, '/metrics/certify.php', ['metric' => $m]);
$rm = post_json(27, '/metrics/certify.php', ['metric' => $m, 'certify' => 'no']);
ok($un['code'] === 200 && $rm['code'] === 200 && pg1("SELECT certified FROM metrics WHERE id = $m") === 'f' && count(pg("SELECT 1 FROM activity_log WHERE action = 'metric.uncertify' AND entity_id = $m")) === 1, 'certify = no removes the badge (metric.uncertify logged)');
post_json(27, '/metrics/certify.php', ['metric' => $m]);
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['metric_certify']['approval'] ?? '') === 'other' && ($reg['actions']['metric_retire']['approval'] ?? '') === 'deletion', 'an agent\'s metric_certify pauses for a person (other), its metric_retire too (deletion)');

echo "7. Products and the home\n";
pg("UPDATE datasets SET certification = 'certified', certified_by = 27, certified_at = now() WHERE key = 'product.s7_monthly'");     // earned through the Quality tab in slice 5's proofs; set here as postgres
$gal = get_json(26, '/products')['json']['data'] ?? [];
$keys = array_column($gal['rows'] ?? [], 'key');
ok(in_array('product.s7_monthly', $keys, true) && in_array('product.monthly_revenue', $keys, true) && $keys[0] === 'product.s7_monthly', 'the gallery lists the products Priya may discover, the certified one first (' . count($keys) . ')');
$row = array_values(array_filter($gal['rows'], static fn ($r) => $r['key'] === 'product.s7_monthly'))[0] ?? [];
ok(($row['metrics'] ?? 0) >= 1 && ($row['query_url'] ?? '') === '/sql?dataset=product.s7_monthly' && ($row['owner']['name'] ?? '') !== '', 'a product\'s card has its metrics\' count, its owner and Query this');
ok(!in_array('product.s7_blend_hidden', $keys, true) && !in_array('curated.s7_orders', $keys, true), 'only the product zone');
$certOnly = get_json(26, '/products?certified=yes')['json']['data'] ?? [];
ok(array_column($certOnly['rows'] ?? [], 'key') === ['product.s7_monthly'], '?certified=yes: only the certified (' . implode(', ', array_column($certOnly['rows'] ?? [], 'key')) . ')');
$ext = get_json(29, '/products');
ok($ext['code'] === 403 || ($ext['json']['data']['total'] ?? -1) === 0, 'an external sees no product she may not discover');
$home = get_json(26, '/')['json']['data'] ?? [];
ok(is_array($home['products'] ?? null) && ($home['products'][0]['key'] ?? '') === 'product.s7_monthly' && !isset($home['pending']['products']), 'the home\'s Certified products tile is built (and no longer pending)');

echo "8. Retire\n";
$rt = post_json(1, '/metrics/retire.php', ['metric' => $mb]);
ok($rt['code'] === 200 && pg1("SELECT (retired_at IS NOT NULL)::text || '|' || certified FROM metrics WHERE id = $mb") === 'true|false', 'the owner retires the blend metric');
$list = get_json(1, '/metrics')['json']['data'] ?? [];
ok(!in_array($mb, array_column($list['rows'] ?? [], 'metric_id'), true) && get_json(1, "/metrics/$mb")['code'] === 404, 'a retired metric leaves the list and its page');
$x = post_json(1, '/metrics/save.php', ['name' => 's7_blend_orders', 'title' => 'again', 'dataset' => $bl, 'expression' => 'count(*)', 'grains' => 'month']);
ok($x['code'] === 422 && field_of($x) === 'name', 'its name stays taken (history)');
$cnt = (int) pg1("SELECT count(*) FROM activity_log WHERE id > $a0 AND action LIKE 'metric.%' AND (after::text ILIKE '%sum(%' OR before::text ILIKE '%sum(%' OR after::text ILIKE '%count(%')");
ok($cnt === 0, 'no metric.* activity row holds an expression');
finish();
