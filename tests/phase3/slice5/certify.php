<?php
/**
 * Slice 5 proof 6 — certification (contracts-quality.md "Proof" 6): an undocumented dataset says what it lacks on the Quality tab; made
 * eligible, certified by an engineer — refused for a Member, refused in the database's words while it lacks something; an agent's certify
 * pauses; a failing blocking check takes the badge by itself, with the reason, the followers told; certified again; certify_remove with a
 * reason; the header's health dot real.
 */
require __DIR__ . '/lib.php';
$cur = ds_id('curated.orders');
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');
$missing = static fn (string $key): array => get_json(27, "/datasets/$key")['json']['data']['dataset']['certification_missing'] ?? ['?'];

echo "1. What it lacks, in words\n";
$m = $missing('curated.orders_v');
ok(in_array('a steward', $m, true) && in_array('a blocking check', $m, true), 'curated.orders_v lacks: ' . implode(', ', $m));
$tab = html_as(27, '/datasets/curated.orders_v?tab=quality');
ok(str_contains($tab['body'], 'id="certification-missing"') && str_contains($tab['body'], 'Not eligible — it lacks: ') && !str_contains($tab['body'], 'id="certify-btn"'),
   'the Quality tab says "Not eligible — it lacks: …" and offers no Certify');
$no = post_json(27, '/quality/certify.php', ['dataset' => 'curated.orders_v']);
ok($no['code'] === 422 && str_contains(message_of($no), 'Not eligible — it lacks: '), 'certifying it anyway: refused in the database\'s words');

echo "2. Made eligible, certified\n";
$up = post_json(27, '/datasets/save.php', ['dataset' => $cur, 'description' => 'Every order a customer placed — typed, deduplicated, the latest version of each.']);
ok($up['code'] === 200, 'Marco describes curated.orders');
foreach ([check_id('curated.orders', 'accepted_values', 'status'), check_id('curated.orders', 'range', 'amount')] as $c) {
    post_json(27, '/quality/checks/disable.php', ['check' => $c]);
}
$m = $missing('curated.orders');
ok($m === [], 'with a steward, a description, every column described, a blocking check, every check passing, a contract and no open error incident: nothing missing'
   . ($m === [] ? '' : ' (' . implode(', ', $m) . ')'));
$tab = html_as(27, '/datasets/curated.orders?tab=quality');
ok(str_contains($tab['body'], 'id="certify-btn"') && str_contains($tab['body'], 'Eligible for certification'), 'the Quality tab offers Certify');
ok(post_json(26, '/quality/certify.php', ['dataset' => 'curated.orders'])['code'] === 403, 'a Member may not certify: 403');
$ok = post_json(27, '/quality/certify.php', ['dataset' => 'curated.orders']);
ok($ok['code'] === 200 && pg1("SELECT certification || '|' || (certified_at IS NOT NULL) || '|' || certified_by FROM datasets WHERE id = $cur") === 'certified|true|27', 'Marco certifies it');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['certify']['approval'] ?? '') === 'other' && ($reg['actions']['contract_publish']['approval'] ?? '') === 'other', 'an agent\'s certify (and contract_publish) pauses for a person (other)');
$d = get_json(26, '/datasets/curated.orders')['json']['data']['dataset'] ?? [];
ok(($d['certification'] ?? '') === 'certified', 'Priya sees the badge');

echo "3. Lost by itself\n";
$n0 = last_notice_id();
load_orders('orders_gap.csv');
[, $pj] = promote_as(27, 'curated.orders');
ok((job_result($pj)['reasons']['not_null:amount'] ?? 0) === 1, 'SO-009 arrives without an amount: the blocking not_null holds it back');
$lost = pg1("SELECT certification || '|' || COALESCE(certification_lost_reason, '') FROM datasets WHERE id = $cur");
ok(str_starts_with((string) $lost, 'none|lacks: ') && str_contains((string) $lost, 'every check passing') && str_contains((string) $lost, 'no open error incident'),
   'the badge is gone by itself, with the reason (' . $lost . ')');
ok(last_notice(26, 'certification_lost', $n0) !== null && last_notice(27, 'certification_lost', $n0) !== null, 'Priya (she follows it) and the owner are told');
$lostLog = activity('certification.lost', $a0);
ok(count($lostLog) === 1 && $lostLog[0]['source'] === 'system', 'certification.lost logged by the writer (the database\'s loss mirrored)');
$page = get_json(26, '/datasets/curated.orders')['json']['data']['dataset'] ?? [];
ok(($page['health'] ?? $page['health_dot'] ?? null) !== 'green', 'the header\'s health dot is no longer green');
$hdr = html_as(26, '/datasets/curated.orders');
ok(str_contains($hdr['body'], 'Certification lost:') && str_contains($hdr['body'], 'Failing'), 'the header says "Certification lost: …" and the dot says Failing');

echo "4. Certified again, then removed by hand\n";
load_orders('orders_gapfix.csv');
promote_as(27, 'curated.orders');
ok($missing('curated.orders') === [], 'SO-009 fixed: the incident resolved by the passing run, nothing missing again');
ok(post_json(27, '/quality/certify.php', ['dataset' => 'curated.orders'])['code'] === 200 && pg1("SELECT certification FROM datasets WHERE id = $cur") === 'certified', 'certified again');
$nr = post_json(27, '/quality/uncertify.php', ['dataset' => 'curated.orders']);
ok($nr['code'] === 422 && field_of($nr) === 'reason', 'removing it says why: refused without a reason');
ok(post_json(26, '/quality/uncertify.php', ['dataset' => 'curated.orders', 'reason' => 'x'])['code'] === 403, 'a Member may not remove it: 403');
$rm = post_json(27, '/quality/uncertify.php', ['dataset' => 'curated.orders', 'reason' => 'Under review after the September migration.']);
ok($rm['code'] === 200 && pg1("SELECT certification || '|' || certification_lost_reason FROM datasets WHERE id = $cur") === 'none|removed: Under review after the September migration.',
   'certify_remove: the badge taken by hand, the reason kept');
$rl = json_decode((string) (activity('certify.remove', $a0)[0]['after'] ?? '{}'), true) ?: [];
ok(count(activity('certify.set', $a0)) === 2 && ($rl['reason_length'] ?? 0) === 43 && !isset($rl['reason']), 'certify.set twice; certify.remove by the reason\'s length');
ok(post_json(27, '/quality/certify.php', ['dataset' => 'curated.orders'])['code'] === 200, '(certified once more for the proofs that follow)');
finish();
