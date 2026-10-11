<?php
/**
 * Slice 5 — the walls on the quality screens and the quarantine (contracts-quality.md "Who may do what"; access-and-policies §1): for the ten
 * callers, the quarantine answers 200 exactly when the referee (dl_access_of) gives write or own, else 403 (they may know it) or 404 (they may
 * not); the quarantine never shows a value a reader may not read — read, read unmasked, an agent acting for a reader (K38's intersection) and a
 * kernel without K38 are refused; a hidden dataset's incidents and checks are nothing to those who may not see it; the database's quarantine
 * policy compiles the same answer.
 */
require __DIR__ . '/lib.php';
$cur = ds_id('curated.orders');
$hr = ds_id('curated.hr_reviews');
// a hidden incident: a check on curated.hr_reviews (nobody but its owner reads it) and a failing result, as the writer would record it
pg("INSERT INTO checks (dataset_id, kind, params, dimension, severity, source) SELECT $hr, 'row_count', '{\"min\": 1000000}', 'completeness', 'error', 'person'
     WHERE NOT EXISTS (SELECT 1 FROM checks WHERE dataset_id = $hr);
    SELECT dl_check_result_record((SELECT id FROM checks WHERE dataset_id = $hr LIMIT 1), NULL, NULL, 3, '{}', false, 0, '[]'::jsonb, 1, NULL);");
$hinc = (int) pg1("SELECT id FROM incidents WHERE dataset_id = $hr ORDER BY id DESC LIMIT 1");

echo "1. The quarantine for the ten callers (the referee: dl_access_of)\n";
foreach (SLICE4_CALLERS as $m) {
    $level = ref_level($cur, $m);
    $r = $m >= 40 ? agent_get($m, '/datasets/curated.orders/quarantine') : get_json($m, '/datasets/curated.orders/quarantine');
    $want = $m === 41 ? 401 : (dl_level_rank($level) >= 4 ? 200 : (dl_level_rank($level) >= 1 ? 403 : 404));      // 41, the Watcher, is not admitted here at all
    $rows = $r['json']['data']['quarantine']['rows'] ?? null;
    ok($r['code'] === $want && ($want !== 200 || is_array($rows)), "caller $m ($level): " . $r['code'] . ($want === 200 ? ' — the held rows' : ''));
}

echo "2. Never a value a reader may not read\n";
ok(get_json(26, '/datasets/curated.orders/quarantine')['code'] === 403, 'Priya (read): refused — the quarantine is read at write');
$exp = agent_get(40, '/datasets/curated.orders/quarantine', 26, 'chat');
ok($exp['code'] === 403, 'the Expert (write) acting for Priya: the intersection is read — refused');
$duty = agent_get(40, '/datasets/curated.orders/quarantine');
ok($duty['code'] === 200 && is_array($duty['json']['data']['quarantine']['rows'] ?? null), 'the Expert on a duty (itself, write): the held rows');
$nok38 = agent_get(40, '/datasets/curated.orders/quarantine', -1, 'chat');
ok($nok38['code'] === 403 && str_contains(json_encode($nok38['json']), 'k38_pending'), 'on a kernel without K38 (a chat turn, no requester): refused k38_pending — curated.orders holds personal data');
$pol = static fn (int $m, ?int $for = null): array => json_decode((string) pg1("SELECT dl_quarantine_policy($m, " . ($for ?? 'NULL') . ", 'curated.orders')"), true) ?: [];
$p27 = $pol(27);
ok(isset($p27['datasets']['quarantine.orders']) && in_array('order_date', $p27['datasets']['quarantine.orders']['columns'], true) && in_array('_dl_reason', $p27['datasets']['quarantine.orders']['columns'], true)
   && $p27['datasets']['quarantine.orders']['masks'] === [], 'the database compiles Marco\'s quarantine: the contract\'s source columns and the reason, nothing masked at own');
$p26 = $pol(26);
ok(($p26['refused']['curated.orders']['rule'] ?? '') === 'not_readable' && ($p26['datasets'] ?? []) === [], '…Priya\'s: refused, no columns');
$p29 = $pol(29);
ok(($p29['refused']['curated.orders']['rule'] ?? '') === 'no_such_dataset', '…Ann\'s: no such dataset');
$p40 = $pol(40, 26);
ok(($p40['refused']['curated.orders']['rule'] ?? '') === 'not_readable', '…the Expert for Priya: refused (the intersection)');
$direct = qs_any(['kind' => 'preview', 'dataset' => 'curated.orders', 'quarantine' => true, 'caller_member_id' => 30]);
ok(($direct['status'] ?? '') === 'refused' && ($direct['rows'] ?? []) === [] && ($direct['query_run_id'] ?? 0) > 0, 'straight on the socket as Dana: refused, no row — and audited');

echo "3. A hidden dataset's quality is nothing to those who may not see it\n";
ok(get_json(26, "/incidents/$hinc")['code'] === 404, 'Priya: curated.hr_reviews\' incident is a 404');
ok(get_json(1, "/incidents/$hinc")['code'] === 200, 'its owner (the admin) reads it');
$pl = get_json(26, '/incidents?status=open')['json']['data']['incidents'] ?? [];
ok(count(array_filter($pl, static fn (array $i): bool => $i['incident_id'] === $hinc)) === 0, 'it is not in Priya\'s incident list');
ok(count(array_filter(get_json(26, '/quality')['json']['data']['incidents'] ?? [], static fn (array $i): bool => $i['incident_id'] === $hinc)) === 0
   && count(array_filter(get_json(26, '/quality')['json']['data']['failing'] ?? [], static fn (array $c): bool => $c['dataset'] === 'curated.hr_reviews')) === 0,
   'nor on her quality home (failing checks, incidents)');
ok(get_json(26, '/datasets/curated.hr_reviews/checks')['code'] === 404 && get_json(26, '/datasets/curated.hr_reviews/contract')['code'] === 404, 'its checks and contract: 404');
ok(get_json(29, '/datasets/curated.orders/checks')['code'] === 404 && get_json(29, '/quality')['code'] === 200
   && (get_json(29, '/quality')['json']['data']['incidents'] ?? ['x']) === [], 'Ann: the orders\' checks a 404, her quality home empty');
$st = get_json(26, '/datasets/curated.orders?tab=quality');
ok($st['code'] === 200, 'Priya reads curated.orders\' Quality tab');
$tab = html_as(26, '/datasets/curated.orders?tab=quality');
ok(!str_contains($tab['body'], 'id="promote-btn"') && !str_contains($tab['body'], 'id="quality-quarantine-link"') && !str_contains($tab['body'], 'id="certify-btn"'),
   '…without Promote, the quarantine or Certify (not hers)');
$mt = html_as(27, '/datasets/curated.orders?tab=quality');
ok(str_contains($mt['body'], 'id="promote-btn"') && str_contains($mt['body'], 'id="quality-quarantine-link"') && str_contains($mt['body'], 'id="quality-promotion"'),
   'Marco\'s has Promote now, Rebuild, the quarantine and the last promotion');
finish();
