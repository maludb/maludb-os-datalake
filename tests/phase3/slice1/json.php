<?php
/*
 * Proof: JSON mode and the HTMX/browser contract on the slice's eleven screens and eighteen actions (catalog.md "Proof" 11, 12) — every
 * screen answers {data: …} to a JSON reader (the kernel's actions server, an agent) through a whitelisted presenter; every handler answers
 * {ok, did, record_id, location}; over HTMX a screen is the partial with HX-Title and X-Screen, a handler lands by HX-Location and a refusal
 * lands in #flash; a browser's form POST is redirected to the record with a notice; the registry claims 11 screens and 18 actions built and
 * one approval (dataset_retire) among them.
 */
require __DIR__ . '/lib.php';
$ids = seed_catalog();
$t = run_tag();
$orders = $ids['curated.sales_orders'];
$term = (int) (post_json(27, '/glossary/save.php', ['name' => 'Order ' . $t, 'definition' => 'One sale to one customer.'])['json']['record_id'] ?? 0);
$tag = (int) (post_json(27, '/tags/save.php', ['label' => 'Ops ' . $t])['json']['record_id'] ?? 0);

echo "11. JSON on every screen\n";
$screens = ['browse' => ['/browse', ['rows', 'facets', 'total']], 'dataset-view' => ["/datasets/$orders", ['dataset', 'tabs', 'columns', 'usage', 'history', 'notes', 'follow', 'may']],
    'dataset-add' => ['/datasets/new', ['values', 'zones', 'tiers']], 'dataset-edit' => ["/datasets/$orders/edit", ['values', 'tiers', 'evolution_modes']],
    'column-view' => ["/datasets/$orders/columns/region", ['dataset', 'column', 'profile_history']], 'column-edit' => ["/datasets/$orders/columns/region/edit", ['values', 'reference_columns']],
    'glossary' => ['/glossary', ['rows', 'total']], 'term-view' => ["/glossary/$term", ['term', 'columns', 'may']], 'term-add' => ['/glossary/new', ['values']],
    'term-edit' => ["/glossary/$term/edit", ['values']], 'tags' => ['/tags', ['rows', 'archived', 'kinds']]];
$bad = [];
foreach ($screens as $screen => [$url, $keys]) {
    $r = get_json(27, $url);
    if ($r['code'] !== 200 || array_diff($keys, array_keys($r['json']['data'] ?? [])) !== []) {
        $bad[] = "$screen ({$r['code']})";
    }
}
ok($bad === [], 'the eleven screens answer JSON with their data' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$tabs = get_json(27, "/datasets/$orders")['json']['data']['tabs'] ?? [];
ok(array_column($tabs, 'name') === ['about', 'columns', 'preview', 'quality', 'lineage', 'usage', 'access', 'history']
    && array_column($tabs, 'built') === array_map(static fn (string $t): bool => !str_contains((string) file_get_contents("app/views/datasets/partials/tab-$t.php"), "'datasets/partials/tab-pending.php'"),
        ['about', 'columns', 'preview', 'quality', 'lineage', 'usage', 'access', 'history'])
    && array_column($tabs, 'built', 'name')['about'] === true && array_column($tabs, 'built', 'name')['lineage'] === false, 'the page\'s JSON names its eight tabs and which are built (as their partials say)');
$d = get_json(27, "/datasets/$orders")['json']['data']['dataset'] ?? [];
ok(isset($d['owner']['name'], $d['health'], $d['certification_missing'], $d['tags'], $d['terms'], $d['my_level']) && !array_key_exists('ducklake_table_id', $d) && !array_key_exists('last_snapshot_id', $d),
    'get_dataset\'s shape: the owner by name, health, what certification lacks, tags, terms, my level — never the engine\'s ids');
$u = get_json(27, "/datasets/$orders")['json']['data']['usage'] ?? [];
ok(count($u['daily'] ?? []) === 30 && array_sum(array_column($u['daily'], 'queries')) === 19 && isset($u['frequent_users'], $u['joined_with']), 'dataset_usage: 30 days, every day present (19 queries seeded), frequent users, joined with');
$h = get_json(27, "/datasets/$orders?tab=history")['json']['data']['history'] ?? [];
ok(($h['total'] ?? 0) >= 2 && in_array('schema', array_column($h['rows'] ?? [], 'kind'), true) && !in_array('screen.view', array_column($h['rows'] ?? [], 'action'), true),
    'the History: activity and schema versions merged, never a screen view');

echo "Handlers: JSON, HTMX and a browser\n";
$r = post_json(27, '/datasets/tag.php', ['dataset' => $orders, 'tag' => $tag]);
ok($r['code'] === 200 && $r['json']['ok'] === true && (int) $r['json']['record_id'] === $orders && str_starts_with((string) $r['json']['location'], "/datasets/$orders") && ($r['json']['refresh'] ?? '') === 'datasetChanged',
    'JSON: {ok, did, record_id, location, refresh datasetChanged}');
[$j, $c] = actor(27);
$x = req('POST', '/datasets/untag.php', ['jar' => $j, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $c], 'form' => ['dataset' => $orders, 'tag' => $tag]]);
$loc = preg_match('/^HX-Location: (.+)$/mi', $x['headers'], $m) ? (json_decode(trim($m[1]), true)['path'] ?? '') : '';
ok($x['code'] === 200 && str_starts_with($loc, "/datasets/$orders?") && preg_match('/^HX-Trigger: datasetChanged/mi', $x['headers']) === 1, "HTMX: the handler lands by HX-Location ($loc) and triggers datasetChanged");
$x = req('POST', '/datasets/save.php', ['jar' => $j, 'headers' => ['HX-Request: true', 'X-CSRF-Token: ' . $c], 'form' => ['zone' => 'raw', 'name' => 'x', 'title' => 'x']]);
ok($x['code'] === 422 && preg_match('/^HX-Retarget: #flash/mi', $x['headers']) === 1 && str_contains($x['body'], 'A raw dataset is made by a load'), 'HTMX: a refusal lands in #flash, in words');
$x = post_form(27, '/datasets/follow.php', ['dataset' => $orders, 'return_to' => "/datasets/$orders"]);
ok($x['code'] === 302 && str_starts_with($x['location'], "/datasets/$orders?notice=followed"), 'a browser\'s form POST is redirected to the record with a notice');
ok(str_contains(html_as(27, "/datasets/$orders?notice=followed")['body'], 'You follow it now'), '…which the page says');
post_form(27, '/datasets/unfollow.php', ['dataset' => $orders]);
$x = html_as(27, "/datasets/$orders", ['HX-Request: true']);
ok($x['code'] === 200 && !str_contains($x['body'], '<html') && preg_match('/^X-Screen: dataset-view\r?$/mi', $x['headers']) === 1 && preg_match('/^X-Dataset-Key: curated.sales_orders\r?$/mi', $x['headers']) === 1
    && preg_match('/^HX-Title: /mi', $x['headers']) === 1, 'HTMX: a screen is the partial with HX-Title, X-Screen and X-Dataset-Key');
$x = html_as(27, '/browse', ['HX-Request: true']);
ok(preg_match('/^X-Screen: browse\r?$/mi', $x['headers']) === 1 && !str_contains($x['body'], '<html'), '…Browse too');
$noCsrf = req('POST', '/datasets/follow.php', ['jar' => $j, 'headers' => ['Accept: application/json'], 'form' => ['dataset' => $orders]]);
ok($noCsrf['code'] === 403, 'a POST without the CSRF token: 403');
ok(req('GET', '/datasets/save.php', ['jar' => $j])['code'] === 405, 'a GET to a handler: 405');
ok(req('POST', '/datasets/follow.php', ['headers' => ['Accept: application/json'], 'form' => ['dataset' => $orders]])['code'] === 401, 'no session: 401');

echo "12. The registry\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$mine = ['browse', 'dataset-view', 'dataset-add', 'dataset-edit', 'column-view', 'column-edit', 'glossary', 'term-view', 'term-add', 'term-edit', 'tags'];
$acts = ['dataset_create', 'dataset_update', 'dataset_tag', 'dataset_untag', 'dataset_deprecate', 'dataset_retire', 'dataset_follow', 'dataset_unfollow', 'column_update', 'note_add', 'note_delete',
    'term_create', 'term_update', 'term_approve', 'term_deprecate', 'tag_create', 'tag_update', 'tag_archive'];
ok(count(array_filter($mine, static fn (string $s): bool => !empty($reg['screens'][$s]['built']) && empty($reg['screens'][$s]['stub']))) === 11, '11 screens built (none a stub)');
ok(count(array_filter($acts, static fn (string $a): bool => !empty($reg['actions'][$a]['built']))) === 18, '18 actions built');
$paused = array_values(array_filter($acts, static fn (string $a): bool => !empty($reg['actions'][$a]['approval'])));
ok($paused === ['dataset_retire'] && $reg['actions']['dataset_retire']['approval'] === 'deletion', '1 approval in step: dataset_retire (deletion)');
ok(($reg['actions']['term_approve']['agent'] ?? $reg['actions']['term_approve']['agent_ok'] ?? null) !== null, 'term_approve is marked for people in the registry');
$logs = array_map(static fn (string $a): string => (string) $reg['actions'][$a]['log_event'], $acts);
ok($logs === ['dataset.create', 'dataset.update', 'dataset.tag', 'dataset.untag', 'dataset.deprecate', 'dataset.retire', 'dataset.follow', 'dataset.unfollow', 'column.update', 'note.add', 'note.delete',
    'term.create', 'term.update', 'term.approve', 'term.deprecate', 'tag.create', 'tag.update', 'tag.archive'], 'every action\'s log event is the spec\'s');
$built = array_keys(array_filter($reg['screens'], static fn ($s) => !empty($s['stub'])));
$owed = (int) trim((string) shell_exec('grep -rl "render_nav_stub(" html 2>/dev/null | wc -l'));
ok(!in_array('browse', $built, true) && !in_array('glossary', $built, true) && count($built) === $owed && $owed <= 14, 'Browse and Glossary are no longer placeholders: the stubs left are the placeholders still owed (' . count($built) . ')');
finish();
