<?php
/**
 * Proof: the shell's gates and its screens (sso-shell.md "Proof"): rights per role on the six screens and the sixteen placeholders (200 vs 403,
 * refused in words), the placeholders' behaviour (a page naming its slice, 501 for JSON and a POST, `stub` in the registry), the menu and the
 * tab bar per role, no-session answers, the home's tiles for a Member, an engineer and the admin (each tile its slice's line until built,
 * popular this month through the wall), the search box (a dataset by its key's words, a confidential one as a stub to a Member and nothing to an
 * external, facets, the log, escaping), the person's own screens and their nine actions (prefs, notices, tokens, keys with rates, the SQL
 * switch, rotation keeping the old key 24 h), the command bar through the kernel's chat endpoint, the action-token / run-token gate, the
 * mirror (notification preferences on sync and on sign-on, a revoked grant shutting the door). Run after the browser proof (seed_lake() is
 * idempotent).
 */
require __DIR__ . '/lib.php';
$json = ['headers' => ['Accept: application/json']];
$ids = seed_lake();
$orders = $ids['curated.sales_orders'];
$payroll = $ids['curated.payroll'];
$revenue = $ids['product.monthly_revenue'];

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
$who = ['admin' => sign_on(1)[0], 'engineer' => sign_on(27)[0], 'analyst' => sign_on(30)[0], 'member' => sign_on(26)[0], 'bea' => sign_on(28)[0], 'external' => sign_on(29)[0]];
$everyone = implode(' ', array_keys($who));
$matrix = [
    '/' => $everyone, '/search' => $everyone, '/notifications' => $everyone, '/activity' => $everyone, '/settings/' => $everyone, '/settings/tokens/' => $everyone,
    '/browse' => $everyone, '/products' => $everyone, '/metrics' => $everyone, '/ask' => $everyone, '/queries' => $everyone, '/sandbox' => $everyone,
    '/upload' => $everyone, '/quality' => $everyone, '/access' => $everyone, '/glossary' => $everyone,
    '/sql' => 'admin engineer analyst', '/transforms' => 'admin engineer analyst', '/sources' => 'admin engineer', '/loads' => 'admin engineer',
    '/steward' => 'admin engineer', '/admin/settings' => 'admin',
];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 6 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 8)) : ''));
$r = page($who['member'], '/sql');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not write SQL.') && str_contains($r['body'], 'id="message-text"'), 'a refusal says what the person may not do, in words ("You may not write SQL.")');
$r = page($who['analyst'], '/admin/settings');
ok($r['code'] === 403 && str_contains($r['body'], 'You may not change the settings.'), 'an Analyst on Admin: 403 in words');
$badges = array_map('badge_of', $who);
ok($badges === ['admin' => 'Super-admin', 'engineer' => 'Data engineer', 'analyst' => 'Analyst', 'member' => 'Member', 'bea' => 'Member', 'external' => 'Member'],
    'the header badge reads the highest role held (a person whose roles have not arrived is a Member by capability): ' . implode(', ', $badges));

echo "2. The placeholders — a page naming its slice, 501 for JSON and for a POST\n";
$stubs = trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 2) . '/html') . ' 2>/dev/null | wc -l'));
ok((int) $stubs === 16, "sixteen controllers call render_nav_stub — one per menu item a slice builds: $stubs");
$ph = ['browse' => ['/browse', 'Slice 1', 'Browse'], 'glossary' => ['/glossary', 'Slice 1', 'Glossary'], 'upload' => ['/upload', 'Slice 2', 'Upload'],
       'sandbox' => ['/sandbox', 'Slice 2', 'My sandbox'], 'loads' => ['/loads', 'Slice 2', 'Loads'], 'sql' => ['/sql', 'Slice 3', 'SQL'],
       'queries' => ['/queries', 'Slice 3', 'Queries'], 'access' => ['/access', 'Slice 4', 'Access'], 'quality' => ['/quality', 'Slice 5', 'Quality'],
       'sources' => ['/sources', 'Slice 6', 'Sources'], 'products' => ['/products', 'Slice 7', 'Products'], 'metrics' => ['/metrics', 'Slice 7', 'Metrics'],
       'transforms' => ['/transforms', 'Slice 7', 'Transforms'], 'ask' => ['/ask', 'Slice 10', 'Ask'], 'steward' => ['/steward', 'Slice 10', 'Steward'],
       'admin' => ['/admin/settings', 'Slice 10', 'Admin']];
$bad = [];
foreach ($ph as $id => [$url, $slice, $title]) {
    $b = page($who['admin'], $url)['body'];
    if (!(str_contains($b, 'id="' . $id . '-coming"') && str_contains($b, e_($title) . ' is not built yet') && str_contains($b, $slice . ' builds this screen') && str_contains($b, '<title>' . e_($title) . ' · Data Lake</title>'))) { $bad[] = $url; }
}
ok($bad === [], 'every placeholder: the shell, the header and one card saying which slice builds it' . ($bad ? ' — wrong: ' . implode(', ', $bad) : ''));
$r = page($who['admin'], '/browse', $json);
ok($r['code'] === 501 && (body_json($r)['error']['code'] ?? '') === 'not_built', 'a JSON caller on a placeholder: 501 not_built');
$r = req('POST', '/browse', ['jar' => $who['admin'], 'form' => ['csrf_token' => page_csrf($who['admin'])]]);
ok($r['code'] === 501 && str_contains($r['body'], 'This is built by slice 1.'), 'a POST to a placeholder: 501, in words');
$r = page($who['admin'], '/quality', ['headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="quality-coming"') && !str_contains($r['body'], '<html') && preg_match('/^HX-Title: Quality/mi', $r['headers']) === 1 && preg_match('/^X-Screen: quality\r?$/mi', $r['headers']) === 1,
    'over HTMX a placeholder is the partial with HX-Title and X-Screen, so the highlight and the command bar follow');
$v = (int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'metric-list'");
page($who['admin'], '/metrics');
ok((int) one("SELECT count(*) FROM activity_log WHERE action = 'screen.view' AND screen = 'metric-list'") === $v + 1, 'opening a placeholder logs screen.view with the manifest\'s screen id (metric-list)');
$built = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/mcp/action_registry.json'), true);
$sc = $built['screens'] ?? [];
$ac = $built['actions'] ?? [];
$stubIds = array_keys(array_filter($sc, static fn ($s) => !empty($s['stub'])));
$builtIds = array_keys(array_filter($sc, static fn ($s) => !empty($s['built'])));
$builtActions = array_keys(array_filter($ac, static fn ($a) => !empty($a['built'])));
sort($stubIds); sort($builtIds); sort($builtActions);
ok($builtIds === ['activity', 'home', 'notifications', 'search', 'settings', 'settings-tokens'],
    'the registry marks the shell\'s six screens as built, so the voice surface offers no unbuilt screen: ' . implode(', ', $builtIds));
ok(count($stubIds) === 16 && in_array('browse', $stubIds, true) && in_array('admin-settings', $stubIds, true), 'and the sixteen placeholders as stubs (' . count($stubIds) . ')');
ok($builtActions === ['key_mint', 'key_revoke', 'key_rotate', 'key_sql_switch', 'notification_read', 'notifications_read_all', 'prefs_save', 'token_mint', 'token_revoke'],
    'and the shell\'s nine actions as built: ' . implode(', ', $builtActions));

echo "3. The menu and the tab bar per role, and no session\n";
$menu = static fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$groups = static fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
$me = ['my-settings', 'notifications', 'tokens', 'activity'];
$memberMenu = ['home', 'search', 'browse', 'products', 'metrics', 'ask', 'queries', 'sandbox', 'upload', 'quality', 'access', 'glossary'];
ok($menu($who['member']) === array_merge($memberMenu, $me) && $groups($who['member']) === ['Build', 'Govern', 'Me'], 'a Member sees the reading and using items, Upload and Quality, Access and Glossary, then Me: ' . implode(', ', $menu($who['member'])));
ok($menu($who['analyst']) === ['home', 'search', 'browse', 'products', 'metrics', 'ask', 'sql', 'queries', 'sandbox', 'upload', 'transforms', 'quality', 'access', 'glossary', ...$me],
    'an Analyst adds SQL and Transforms (their sandbox\'s)');
ok($menu($who['engineer']) === ['home', 'search', 'browse', 'products', 'metrics', 'ask', 'sql', 'queries', 'sandbox', 'upload', 'sources', 'loads', 'transforms', 'quality', 'access', 'glossary', 'steward', ...$me],
    'a Data engineer adds Sources, Loads and Steward — no Admin');
ok(in_array('admin', $menu($who['admin']), true) && count($menu($who['admin'])) === 22, 'the admin sees all eighteen items and Me');
$tabs = static fn (string $j): array => (preg_match_all('/id="tab-(home|search|ask|me)"/', page($j, '/')['body'], $m) ? $m[1] : []);
ok($tabs($who['member']) === ['home', 'search', 'ask', 'me'] && str_contains(page($who['member'], '/')['body'], 'id="tab-bar"'), 'the phone\'s tab bar is Home · Search · Ask · Me');
$r = page(jar(), '/browse');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a screen goes to the launcher with ?app=datalake');
$r = req('GET', '/browse', $json);
ok($r['code'] === 401 && (body_json($r)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/search?q=orders');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session on the search: the launcher too');

echo "4. Home — popular through the wall, each tile its slice's line, decisions\n";
$d = body_json(page($who['member'], '/', $json))['data'] ?? [];
$pop = array_column($d['popular'] ?? [], 'key');
ok(($d['first_run'] ?? null) === false && $pop === ['curated.sales_orders', 'product.monthly_revenue'], 'Priya\'s home: popular this month, most queried first — and NOT the payroll (30 queries, but she may not discover it): ' . implode(', ', $pop));
ok(($d['pending'] ?? []) == ['products' => 'slice 7', 'recent' => 'slice 3', 'followed' => 'slice 1', 'incidents' => 'slice 5'] && $d['engineer'] === null && $d['admin'] === null,
    'each tile not built yet is null and names its slice; a Member has no engineer or admin tile');
$h = page($who['member'], '/')['body'];
ok(str_contains($h, 'id="home-popular"') && str_contains($h, 'id="home-popular-' . $orders . '"') && !str_contains($h, 'home-popular-' . $payroll) && str_contains($h, 'href="/datasets/curated.sales_orders?back=%2F"'),
    'the popular tile renders its cards, each a link carrying ?back= (click-around)');
ok(str_contains($h, 'id="home-products-pending"') && str_contains($h, 'slice 7') && str_contains($h, 'id="home-incidents-pending"') && str_contains($h, 'id="home-recent-pending"'),
    'the products, incidents and recent tiles say which slice builds them');
ok(str_contains($h, 'id="home-search-btn"') && str_contains($h, 'id="home-ask-btn"') && str_contains($h, 'formaction="/ask"'), 'the home\'s search box has its two buttons, Search and Ask');
$d = body_json(page($who['engineer'], '/', $json))['data'] ?? [];
ok(($d['engineer'] ?? null) === ['failed_loads' => null, 'late' => null, 'proposals' => null] && ($d['pending']['engineer'] ?? '') === 'slice 10' && $d['admin'] === null, 'an engineer\'s tile is there and says slice 10 builds it');
ok(str_contains(page($who['engineer'], '/')['body'], 'id="home-engineer-pending"'), '…on the page too');
$d = body_json(page($who['admin'], '/', $json))['data'] ?? [];
ok(is_array($d['admin'] ?? null) && ($d['pending']['admin'] ?? '') === 'slice 10' && in_array('curated.payroll', array_column($d['popular'] ?? [], 'key'), true), 'the admin\'s tile says slice 10; the payroll is popular for its owner');
pg("INSERT INTO access_requests (requester_id, dataset_id, level, reason) SELECT 26, $orders, 'read_unmasked', 'SMOKE I need the emails' WHERE NOT EXISTS (SELECT 1 FROM access_requests WHERE requester_id = 26 AND dataset_id = $orders AND status = 'pending')");
$d = body_json(page($who['engineer'], '/', $json))['data'] ?? [];
ok(($d['decisions'] ?? []) === ['requests' => 1, 'proposals' => 0], 'Marco, who owns the orders, has one access request to decide');
$h = page($who['engineer'], '/')['body'];
ok(str_contains($h, 'id="home-decisions-requests"') && str_contains($h, 'id="header-decisions-count"'), '…on the home\'s tile and the header\'s decisions badge');
ok((body_json(page($who['member'], '/', $json))['data']['decisions'] ?? []) === ['requests' => 0, 'proposals' => 0], 'Priya, who asked, has none to decide');

echo "5. The search box\n";
$rows = static fn (string $j, string $qs): array => body_json(page($j, '/search?' . $qs, $json))['data']['rows'] ?? [];
$keyed = static fn (array $rs): array => array_map(static fn ($r) => $r['entity_kind'] . '-' . $r['entity_id'] . ':' . $r['visibility'], $rs);
$found = $keyed($rows($who['member'], 'q=' . rawurlencode('sales orders')));
ok(in_array("dataset-$orders:full", $found, true), 'a Member finds curated.sales_orders by its key\'s words: ' . implode(', ', $found));
$pr = $rows($who['member'], 'q=payroll');
ok(count($pr) === 1 && $pr[0]['visibility'] === 'stub' && !array_key_exists('excerpt', $pr[0]) && !array_key_exists('classification', $pr[0]) && !array_key_exists('key', $pr[0])
    && ($pr[0]['owner_name'] ?? '') === 'SMOKE Owner' && str_contains((string) ($pr[0]['request_access'] ?? ''), 'dataset=' . $payroll),
    'the confidential payroll is a STUB to a Member: its name, its owner and Request access — no excerpt, no key, no classification');
$h = page($who['member'], '/search?q=payroll')['body'];
ok(str_contains($h, 'id="search-result-dataset-' . $payroll . '"') && str_contains($h, 'stub-card') && str_contains($h, 'Request access') && !str_contains($h, 'What each person is paid'), '…and on the page a locked card with Request access, never the description');
ok($rows($who['external'], 'q=payroll') === [] && $rows($who['external'], 'q=' . rawurlencode('sales orders')) === [], 'an external finds neither — not even as a stub');
ok($keyed($rows($who['admin'], 'q=payroll')) === ["dataset-$payroll:full"], 'the owner finds it whole');
ok(array_column($rows($who['member'], 'zone=product'), 'entity_id') === [$revenue] && array_column($rows($who['member'], 'certification=certified'), 'entity_id') === [$revenue], 'the facets: zone product, certified — the monthly revenue');
$h = page($who['member'], '/search?q=orders')['body'];
ok(str_contains($h, '<mark>') && str_contains($h, 'class="badge text-bg-primary zone-chip">curated') && str_contains($h, 'curated.sales_orders') && str_contains($h, 'id="search-facet-zone-raw"'),
    'the words matched are marked, the zone chip and the key on the card, the facets as chips');
$since = last_activity_id();
page($who['member'], '/search?q=' . rawurlencode(str_repeat('orders ', 30)));
$a = activity('search.catalog', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && mb_strlen((string) ($la['q'] ?? '')) === 120 && isset($la['count'], $la['stubs']), 'every search is logged search.catalog with the query cut at 120, the count and the stub count');
page($who['member'], '/search?q=payroll');
$a = activity('search.catalog', $since);
ok(json_decode((string) end($a)['after'], true)['stubs'] === 1, '…the payroll search logged one stub');
ok(page($who['member'], '/search?q=a', $json)['code'] === 422 && str_contains(page($who['member'], '/search?q=a')['body'], 'id="search-too-short"'), 'one character: 422 to JSON, in words on the page');
pg("INSERT INTO datasets (key, zone, name, title, owner_member_id, status) VALUES ('curated.smoke_xss', 'curated', 'smoke_xss', 'SMOKE <script>alert(1)</script> orders', 27, 'active') ON CONFLICT (key) DO NOTHING;
    INSERT INTO grants (target_kind, dataset_id, principal_kind, level, reason, granted_by) SELECT 'dataset', id, 'everyone', 'read', 'SMOKE', 1 FROM datasets WHERE key = 'curated.smoke_xss' AND NOT EXISTS (SELECT 1 FROM grants g WHERE g.dataset_id = datasets.id)");
$b = page($who['member'], '/search?q=xss')['body'];
ok(str_contains($b, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($b, '<script>alert(1)</script>'), 'a dataset\'s title is escaped in the results (no markup from the data)');
ok(str_contains(page($who['member'], '/')['body'], 'id="header-search"') && str_contains(page($who['member'], '/')['body'], 'id="header-ask"'), 'the header carries the search box with Search and Ask on every page');

echo "6. The person's own screens and their nine actions\n";
$jm = $who['member'];
$d = body_json(page($jm, '/settings/', $json))['data'] ?? [];
ok(count($d['kinds'] ?? []) === 16 && isset($d['kinds']['check_failed'], $d['kinds']['access_decided'], $d['notify']['digest']), '/settings/: the sixteen kinds the schema knows and my choices');
ok(post_as($jm, '/settings/prefs.php', ['kinds' => ['check_failed', 'nonsense']])['code'] === 422, 'prefs_save refuses an unknown kind: 422');
$r = post_as($jm, '/settings/prefs.php', ['email_enabled' => 'no', 'kinds' => ['check_failed', 'access_decided'], 'digest' => 'yes']);
$p = q('SELECT email_enabled, kinds::text AS kinds, digest FROM notification_prefs WHERE member_id = 26')[0] ?? [];
ok($r['code'] === 200 && (body_json($r)['did'] ?? '') === 'Saved how you are told' && ($p['kinds'] ?? '') === '{check_failed,access_decided}' && $p['digest'] && !$p['email_enabled'], 'prefs_save writes every choice');
$a = activity('settings.prefs');
ok($a !== [] && end($a)['entity_type'] === 'notification_prefs' && str_contains((string) end($a)['after'], 'access_decided'), 'logged settings.prefs (the manifest\'s event) with what changed');
$nid = (int) (pg("SELECT dl_notify(26, 'access_decided', 'SMOKE: your request was decided', NULL, 'dataset', $orders, $orders, 27, 'p2:n1:' || random()::text)")[0] ?? 0);
ok(preg_match('/id="bell-count">1</', page($jm, '/')['body']) === 1 && trim(strip_tags(page($jm, '/notifications?count=1')['body'])) === '1', 'a notice shows on the bell, and the bell\'s own partial counts it');
$r = post_as($jm, '/notifications/read.php', ['notification' => $nid]);
ok($r['code'] === 200 && one('SELECT read_at IS NOT NULL FROM notifications WHERE id = :i', ['i' => $nid]), 'notification_read marks one read');
$n = body_json(page($jm, '/notifications', $json))['data']['notifications'] ?? [];
$mine = array_values(array_filter($n, static fn ($x) => $x['notification_id'] === $nid));
ok(($mine[0]['url'] ?? '') === '/datasets/' . $orders, 'a notification names the screen of its record (the dataset)');
ok(post_as($who['engineer'], '/notifications/read.php', ['notification' => $nid])['code'] === 404, 'another person\'s notice: 404');
pg("SELECT dl_notify(26, 'mention', 'SMOKE: you were mentioned', NULL, NULL, NULL, NULL, 27, 'p2:n2:' || random()::text)");
ok(post_as($jm, '/notifications/read-all.php', ['all' => 'no'])['code'] === 422, 'notifications_read_all wants yes');
$since = last_activity_id();
$r = post_as($jm, '/notifications/read-all.php', ['all' => 'yes']);
ok($r['code'] === 200 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0 && activity('notification.read_all', $since) !== [], 'notifications_read_all marks every unread one read (logged notification.read_all)');
ok(preg_match('/id="bell-count"/', page($jm, '/')['body']) === 0, 'the bell shows no count once all are read');
// tokens
ok(post_as($jm, '/settings/tokens/mint.php', ['label' => 'proof', 'expires_in_days' => '0'])['code'] === 422 && post_as($jm, '/settings/tokens/mint.php', ['label' => ''])['code'] === 422, 'token_mint refuses an expiry of 0 days and a missing label');
$r = post_as($jm, '/settings/tokens/mint.php', ['label' => 'SMOKE Claude Desktop', 'expires_in_days' => '30']);
$mb = body_json($r);
$tid = (int) ($mb['record_id'] ?? 0);
ok($r['code'] === 200 && str_starts_with((string) ($mb['token'] ?? ''), 'mcp_') && $tid > 0 && (int) one('SELECT round(extract(epoch FROM expires_at - now()) / 86400) FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]) === 30,
    'token_mint answers the token ONCE with its record_id, expiring in 30 days');
ok(one('SELECT token_hash FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]) === hash('sha256', (string) $mb['token']) && !str_contains((string) json_encode(q("SELECT after FROM activity_log WHERE action LIKE 'token.%'")), (string) $mb['token']),
    'only the hash is stored and the log never holds the token');
$tl = body_json(page($jm, '/settings/tokens/', $json))['data'] ?? [];
ok(!str_contains(json_encode($tl), (string) $mb['token']) && in_array($tid, array_column($tl['tokens'] ?? [], 'token_id'), true), 'the tokens screen lists it — without its value');
ok(post_as($who['engineer'], '/settings/tokens/revoke.php', ['token' => $tid])['code'] === 404, 'another person cannot revoke it: 404');
$r = post_as($jm, '/settings/tokens/revoke.php', ['token' => $tid]);
ok($r['code'] === 200 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $tid]), 'token_revoke revokes it');
// keys
$r = post_as($jm, '/settings/tokens/key-mint.php', ['label' => 'SMOKE script', 'allow_sql' => 'yes']);
ok($r['code'] === 403 && (body_json($r)['error']['message'] ?? '') === 'Ad-hoc SQL over the API needs the SQL editor.', 'key_mint with the SQL switch is refused without sql.editor, in the spec\'s words');
ok(post_as($jm, '/settings/tokens/key-mint.php', ['label' => 'x', 'rate_per_minute' => '0'])['code'] === 422 && post_as($jm, '/settings/tokens/key-mint.php', ['label' => 'x', 'rate_per_day' => '20000000'])['code'] === 422,
    'and a rate outside its bounds: 422');
$since = last_activity_id();
$r = post_as($jm, '/settings/tokens/key-mint.php', ['label' => 'SMOKE nightly report', 'rate_per_minute' => '10', 'rate_per_day' => '500', 'expires_in_days' => '90']);
$kb = body_json($r);
$kid = (int) ($kb['record_id'] ?? 0);
$krow = q('SELECT scope, rate_per_minute, rate_per_day, allow_sql, token_hash FROM mcp_access_tokens WHERE id = :i', ['i' => $kid])[0] ?? [];
ok($r['code'] === 200 && str_starts_with((string) ($kb['key'] ?? ''), 'dlk_') && ($krow['scope'] ?? '') === 'api' && (int) $krow['rate_per_minute'] === 10 && (int) $krow['rate_per_day'] === 500 && !$krow['allow_sql'],
    'key_mint makes an api key with its rates (10 a minute, 500 a day), shown once');
$la = activity('key.mint', $since);
ok($la !== [] && !str_contains((string) end($la)['after'], (string) $kb['key']) && !str_contains((string) end($la)['after'], (string) $krow['token_hash']) && str_contains((string) end($la)['after'], 'SMOKE nightly report'),
    'key.mint is logged with the label and the limits — never the key or its hash');
$r = post_as($jm, '/settings/tokens/key-mint.php', ['label' => 'SMOKE defaults']);
$dk = (int) (body_json($r)['record_id'] ?? 0);
ok((int) one('SELECT rate_per_minute FROM mcp_access_tokens WHERE id = :i', ['i' => $dk]) === 60 && (int) one('SELECT rate_per_day FROM mcp_access_tokens WHERE id = :i', ['i' => $dk]) === 2000, 'blank rates take the lake\'s defaults (60 a minute, 2,000 a day — the database fills them)');
ok(post_as($jm, '/settings/tokens/key-sql.php', ['api_key' => $kid, 'allow_sql' => 'yes'])['code'] === 403, 'key_sql_switch is refused without sql.editor');
[$jd, ] = sign_on(30);
$r = post_as($jd, '/settings/tokens/key-mint.php', ['label' => 'SMOKE Dana SQL', 'allow_sql' => 'yes']);
$sk = (int) (body_json($r)['record_id'] ?? 0);
ok($r['code'] === 200 && one('SELECT allow_sql FROM mcp_access_tokens WHERE id = :i', ['i' => $sk]) === true, 'an Analyst (sql.editor) mints a key with the SQL switch on');
$r = post_as($jd, '/settings/tokens/key-sql.php', ['api_key' => $sk, 'allow_sql' => 'no']);
ok($r['code'] === 200 && one('SELECT allow_sql FROM mcp_access_tokens WHERE id = :i', ['i' => $sk]) === false && activity('key.sql_switch') !== [], '…and turns it off again (key_sql_switch, logged key.sql_switch)');
ok(post_as($jd, '/settings/tokens/key-sql.php', ['api_key' => $kid, 'allow_sql' => 'yes'])['code'] === 404, '…but not on Priya\'s key: 404');
$h = page($jd, '/settings/tokens/')['body'];
ok(str_contains($h, 'id="key-list"') && str_contains($h, 'id="key-' . $sk . '-sql"') && str_contains($h, 'id="token-list"'), 'the keys screen lists the keys with the switch for a holder of sql.editor');
ok(!str_contains(page($jm, '/settings/tokens/')['body'], 'id="key-' . $kid . '-sql"'), '…and without it for Priya');
$r = post_as($jm, '/settings/tokens/key-rotate.php', ['api_key' => $kid]);
$rb = body_json($r);
$nk = (int) ($rb['record_id'] ?? 0);
$old = q('SELECT expires_at, revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $kid])[0] ?? [];
$hours = (float) one('SELECT extract(epoch FROM expires_at - now()) / 3600 FROM mcp_access_tokens WHERE id = :i', ['i' => $kid]);
ok($r['code'] === 200 && $nk > $kid && str_starts_with((string) ($rb['key'] ?? ''), 'dlk_') && (int) one('SELECT rotated_from FROM mcp_access_tokens WHERE id = :i', ['i' => $nk]) === $kid
    && $hours > 23.9 && $hours <= 24.0 && $old['revoked_at'] === null && (int) one('SELECT rate_per_minute FROM mcp_access_tokens WHERE id = :i', ['i' => $nk]) === 10,
    'key_rotate: a new key with the same limits, rotated_from the old one, which keeps working for 24 hours (' . round($hours, 2) . ' h)');
ok(post_as($jm, '/settings/tokens/key-rotate.php', ['api_key' => $kid])['code'] === 422, 'a key already rotated is not rotated again (its successor is)');
$h = page($jm, '/settings/tokens/')['body'];
ok(str_contains($h, 'id="key-' . $kid . '-replaced"') && !str_contains($h, 'id="key-' . $kid . '-rotate-btn"') && str_contains($h, 'id="key-' . $nk . '-rotate-btn"'), 'the screen marks the old key replaced and offers Rotate only on the new one');
ok(post_as($who['engineer'], '/settings/tokens/key-revoke.php', ['api_key' => $nk])['code'] === 404, 'another person cannot revoke Priya\'s key: 404');
$r = post_as($who['admin'], '/settings/tokens/key-revoke.php', ['api_key' => $dk]);
ok($r['code'] === 200 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $dk]), 'the admin (keys.all) revokes anyone\'s key');
$r = post_as($jm, '/settings/tokens/key-revoke.php', ['api_key' => $nk]);
ok($r['code'] === 200 && one('SELECT revoked_at IS NOT NULL FROM mcp_access_tokens WHERE id = :i', ['i' => $nk]) && activity('key.revoke') !== [], 'key_revoke revokes my own (logged key.revoke)');
$d = body_json(page($jm, '/activity', $json))['data']['rows'] ?? [];
ok($d !== [] && array_filter($d, static fn ($x) => str_contains($x['sentence'], 'minted an API key')) !== [] && array_filter($d, static fn ($x) => str_contains($x['sentence'], 'searched for “')) !== [],
    'My activity reads my trail in words ("minted an API key", "searched for …")');

echo "7. The command bar through the kernel's chat endpoint\n";
$t = page_csrf($jm);
$since = last_activity_id();
$r = req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'Who reads the sales orders?', 'screen' => 'home', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-text"') && str_contains($r['body'], 'Hello from the fake expert'), 'the expert\'s reply is rendered above the bar');
$a = activity('assistant.ask', $since);
$la = $a !== [] ? json_decode((string) end($a)['after'], true) : [];
ok($a !== [] && ($la['length'] ?? 0) === 27 && ($la['run_id'] ?? 0) >= 1 && !str_contains((string) end($a)['after'], 'sales orders'), 'assistant.ask logs the length and the run id, never the words');
$chatLines = file(need('FAKE_KERNEL_STATE') . '.chat');
$chat = json_decode((string) end($chatLines), true);
ok($chat['agent'] === 'expert' && (int) $chat['acting'] === 26, 'the kernel saw the call for the expert as the acting person');
ok(req('POST', '/assistant/ask.php', ['jar' => $jm, 'form' => ['utterance' => 'hi']])['code'] === 403, 'no CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'The expert is busy with another turn.'), 'a refusal is shown in the kernel\'s own words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'Granting Sales read waits for approval.', 'actions' => [['tool' => 'grant_set', 'status' => 'awaiting_approval']], 'approval_request_id' => 12]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'grant Sales read on the orders', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="assistant-reply-approval"') && str_contains($r['body'], 'request #12') && str_contains($r['body'], 'grant_set'), 'an action awaiting approval is shown as such, with the request number');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 3, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Described it.', 'actions' => [['tool' => 'dataset_update', 'status' => 'ok', 'record_id' => 9]]]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'describe the orders', 'csrf_token' => $t]]);
ok($r['code'] === 200 && preg_match('/^HX-Trigger: .*datasetChanged/mi', $r['headers']) === 1, 'a tool that changed a dataset fires datasetChanged for the screens listening');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 4, 'status' => 'succeeded', 'finished' => true, 'reply' => 'Opening your keys.', 'actions' => [], 'navigate' => '/settings/tokens/']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'open my keys', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], '/settings/tokens/') && str_contains($r['body'], 'id="assistant-reply-navigate"'), 'a navigate answer is followed (HX-Location)');
kernel_state(function ($s) { $s['chat'] = ['run_id' => 5, 'status' => 'succeeded', 'finished' => true, 'reply' => 'x', 'actions' => [], 'navigate' => 'https://evil.invalid/x']; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'go', 'csrf_token' => $t]]);
ok($r['code'] === 200 && !str_contains($r['headers'], 'HX-Location'), 'a navigate that is not a local path is ignored');
kernel_state(function ($s) { unset($s['chat']); return $s; });
ok(req('POST', '/assistant/ask.php', ['jar' => $jm, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]])['code'] === 422, 'over 2,000 characters: 422, in words');

echo "8. The action-token and run-token gate\n";
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)]]);
ok($r['code'] === 200 && preg_match('/^Set-Cookie:/mi', $r['headers']) === 0, 'a person\'s action token opens a read for one request, with no cookie set');
ok(req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26, -5)]])['code'] === 401, 'an expired action token: 401');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . person_token(26)], 'form' => ['email_enabled' => 'yes']]);
ok($r['code'] === 200 && (body_json($r)['ok'] ?? false) === true && one('SELECT email_enabled::text FROM notification_prefs WHERE member_id = 26') === 'true', 'a write under an action token answers JSON (prefs_save) and stands in for CSRF');
set_run_facts(77, 40, 'duty', null);
$tok = run_token(40, 77);
ok(req('GET', '/', ['headers' => as_agent($tok)])['code'] === 200, 'an admitted agent (the Expert, 40) under a run token reads the home');
$since = last_activity_id();
req('GET', '/activity', ['headers' => array_merge(as_agent($tok), ['X-Screen-View: 1'])]);
$v = activity('screen.view', $since);
ok($v !== [] && (int) end($v)['agent_run_id'] === 77 && end($v)['source'] === 'agent', 'its screen view is logged with the run id and source agent');
ok(req('POST', '/settings/prefs.php', ['headers' => as_agent($tok), 'form' => ['digest' => 'yes']])['code'] === 200, 'an agent may set its own preferences (prefs_save admits agents)');
ok(req('POST', '/settings/tokens/mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'but not mint a person\'s token: 403 (people only)');
ok(req('POST', '/settings/tokens/key-mint.php', ['headers' => as_agent($tok), 'form' => ['label' => 'agent']])['code'] === 403, 'nor an API key: 403 (people only — it never reaches the approval)');
ok(req('POST', '/assistant/ask.php', ['headers' => as_agent($tok), 'form' => ['utterance' => 'hi']])['code'] === 403, 'nor use the command bar: 403');
set_run_facts(78, 40, 'eval', null);
kernel_state(function ($s) { $s['facts']['78']['is_eval'] = true; return $s; });
ok(req('POST', '/settings/prefs.php', ['headers' => as_agent(run_token(40, 78)), 'form' => ['digest' => 'no']])['code'] === 403, 'an evaluation run changes nothing: 403 (the handler\'s second wall)');
ok(req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]])['code'] === 401, 'a run token without its relay signature: 401');
$since = last_activity_id();
ok(req('GET', '/activity', ['headers' => as_agent(run_token(900, 79))])['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 900') === 0, 'an id with no mirror row is refused and never created');
$ref = activity('member.refused', $since);
ok($ref !== [] && (int) end($ref)['entity_id'] === 900, '…and logged member.refused with the id');
ok(in_array(req('GET', '/', ['headers' => as_agent(run_token(41, 80))])['code'], [401, 403], true), 'the Watcher (an agent not admitted here) is refused');

echo "9. The mirror — preferences before anyone looks, a revoked grant\n";
ok((int) one("SELECT count(*) FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL AND NOT EXISTS (SELECT 1 FROM notification_prefs p WHERE p.member_id = m.id)") === 0, 'every admitted member has notification preferences (made by the sync)');
pg('DELETE FROM notification_prefs WHERE member_id = 30');
sign_on(30);
ok((int) one('SELECT count(*) FROM notification_prefs WHERE member_id = 30') === 1, 'a sign-on makes them too (Dana\'s were removed, she signed on, they are back)');
pg("INSERT INTO groups (key, name, kind, department_id, created_by) SELECT 'sales', 'Sales', 'department', 8, 1 WHERE NOT EXISTS (SELECT 1 FROM groups WHERE key = 'sales')");
sign_on(31);
ok((int) one("SELECT count(*) FROM group_members gm JOIN groups g ON g.id = gm.group_id WHERE g.key = 'sales' AND gm.member_id = 31 AND gm.left_at IS NULL") === 1, 'a sign-on brings the person into their department\'s mirror group');
[$jBea, ] = sign_on(28);
ok(req('GET', '/', ['jar' => $jBea])['code'] === 200, 'Bea is let in while her grant stands');
kernel_state(function ($s) { $s['incremental'] = ['schema' => 'os.directory-changes/1', 'since' => '2026-01-01T00:00:00.000000Z', 'next' => '2026-01-03T00:00:00.000000Z', 'full' => false, 'members' => [], 'departments' => [], 'memberships' => [], 'deleted_departments' => [], 'scopes' => [],
    'access' => [['member_id' => 28, 'capability' => null, 'roles' => [], 'scopes' => []]]]; return $s; });
exec('php bin/directory_sync.php 2>&1', $syncOut, $rc);
ok($rc === 0 && req('GET', '/', ['jar' => $jBea])['code'] === 302, 'a grant the kernel revoked shuts the door within one sync');
ok(one('SELECT ended_by FROM member_sessions WHERE member_id = 28 ORDER BY created_at DESC LIMIT 1') === 'directory', '…the session ended by the directory');
kernel_state(function ($s) { unset($s['incremental']); return $s; });
finish();

function e_(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
