<?php
/**
 * Proof: the record picker as the Phase 2 shell carries it (design-system record-picker.md): the modal once and the script once on a full
 * page, outside the swap target; the registry with the shell's two sources — `member` (people and agents; an external sees only themselves,
 * the view's wall) and `department`; searching, filtering by a declared param, the label of a chosen record; the endpoint's refusals as
 * fragments (404 an unknown source, the launcher for no session, JSON 401); nothing logged; nothing in the server's log.
 */
require __DIR__ . '/lib.php';
$root = dirname(__DIR__, 2);

echo "1. The shell carries the modal and the script, once\n";
[$jAdmin, ] = sign_on(1);
[$jAnn, ] = sign_on(29);
foreach (['admin' => $jAdmin, 'external' => $jAnn] as $who => $j) {
    $b = page($j, '/')['body'];
    ok(substr_count($b, 'id="record-picker"') === 1 && substr_count($b, 'id="record-picker-results"') === 1 && substr_count($b, 'id="record-picker-search"') === 1, "$who: a full page has the #record-picker modal once, with its search box and results");
    ok(substr_count($b, '/assets/js/record-picker.js') === 1 && strpos($b, 'common-init.min.js') < strpos($b, 'record-picker.js'), "$who: record-picker.js is loaded once, after common-init.min.js");
    ok(strpos($b, '</main>') < strpos($b, 'id="record-picker"'), "$who: the modal sits after </main>, outside #page-content, so a swap keeps it");
}
$h = page($jAdmin, '/browse', ['headers' => ['HX-Request: true']])['body'];
ok(!str_contains($h, 'id="record-picker"') && !str_contains($h, 'record-picker.js'), 'an HTMX swap (the partial) carries neither the modal nor the script — they live in the shell');
$js = req('GET', '/assets/js/record-picker.js');
$tmp = tempnam(sys_get_temp_dir(), 'rp') . '.js';
file_put_contents($tmp, $js['body']);
exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
unlink($tmp);
ok($js['code'] === 200 && $rc === 0, 'record-picker.js is served and parses (node --check)');
ok(str_contains(req('GET', '/assets/css/app-overrides.css')['body'], 'RECORD PICKER'), 'app-overrides.css has the RECORD PICKER section');

echo "2. The two sources of the shell\n";
$r = page($jAdmin, '/pick/?source=member');
ok($r['code'] === 200 && str_contains($r['body'], 'id="record-picker-list"') && str_contains($r['body'], 'SMOKE Priya') && str_contains($r['body'], 'SMOKE Expert') && !str_contains($r['body'], 'SMOKE Watcher'),
    'member: the people and the agents admitted here (the Watcher, not admitted, is not offered)');
ok(str_contains($r['body'], '>Agent</span>') && str_contains($r['body'], '>External</span>'), 'an agent and an external are marked');
$r = page($jAdmin, '/pick/?source=member&q=' . rawurlencode('mar'));
ok(str_contains($r['body'], 'SMOKE Marco') && !str_contains($r['body'], 'SMOKE Priya'), 'the search box narrows (mar → Marco)');
$r = page($jAdmin, '/pick/?source=member&agents=1');
ok(str_contains($r['body'], 'SMOKE Expert') && !str_contains($r['body'], 'SMOKE Priya'), 'the declared param agents=1 offers agents only');
$r = page($jAdmin, '/pick/?source=member&q=' . rawurlencode('nobody-like-this'));
ok(str_contains($r['body'], 'id="record-picker-empty"') && str_contains($r['body'], 'No person matches'), 'no match says so');
$r = page($jAnn, '/pick/?source=member');
ok(str_contains($r['body'], 'SMOKE Ann') && !str_contains($r['body'], 'SMOKE Priya'), 'an external is offered only themselves (mcp_members\' wall)');
$r = page($jAdmin, '/pick/?source=department');
ok($r['code'] === 200 && str_contains($r['body'], '>Sales<') && str_contains($r['body'], '>Finance<'), 'department: the live departments of the mirror');
$label = trim((string) shell_exec('php -r ' . escapeshellarg('require "app/bootstrap.php"; $_SESSION = ["member_id" => 26]; db_apply_context(db()); echo picker_label(db(), "department", 8) ?? "null", "|", picker_label(db(), "department", 999999) ?? "null";') . ' 2>&1'));
ok($label === 'Sales|null', 'picker_label() names a chosen record, and nothing for an id that names nothing: ' . $label);

echo "3. The endpoint's refusals\n";
foreach (['/pick/?source=anything', '/pick/', '/pick/?source=../../etc/passwd'] as $u) {
    $r = page($jAdmin, $u);
    ok($r['code'] === 404 && str_contains($r['body'], 'That list does not exist.') && !str_contains($r['body'], '<html'), "GET $u: 404 with the fragment, not a page");
}
$r = page(jar(), '/pick/?source=member');
ok($r['code'] === 302 && $r['location'] === LAUNCHER, 'no session: a redirect to the launcher with ?app=datalake');
ok(page(jar(), '/pick/?source=member', ['headers' => ['Accept: application/json']])['code'] === 401, 'no session, JSON: 401');
$before = last_activity_id();
page($jAdmin, '/pick/?source=member&q=pri');
ok(last_activity_id() === $before, 'a search is not an action: nothing is written to the activity log');
$log = '';
foreach ([need('DL_DEV_STATE') . '/app.log', need('DL_DEV_STATE') . '/apache/apache.err'] as $f) { if (is_file($f)) { $log .= file_get_contents($f); } }
ok(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)|Uncaught/i', $log), 'no PHP warning, notice or uncaught error in the server log');
finish();
