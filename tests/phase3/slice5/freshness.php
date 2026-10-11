<?php
/**
 * Slice 5 proof 7 — freshness (contracts-quality.md "Proof" 7; divergence 3): a dataset with a one-day warning whose data is two days old —
 * the worker's freshness pass makes its `freshness` check (source contract, never blocking, warn) and records a failure: an incident, warn; the
 * quality home lists it as late; fresh data passes and resolves it; the pass runs hourly (once an hour unless named); an error interval makes
 * the check an error.
 */
require __DIR__ . '/lib.php';
$raw = ds_id('raw.orders');
$worker = static function (string $passes = 'freshness'): array {
    exec('php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ($passes === '' ? '' : ' --passes=' . $passes) . ' 2>&1', $out, $rc);
    return json_decode((string) end($out), true) ?: ['rc' => $rc, 'out' => $out];
};
pg("UPDATE datasets SET freshness_warn_after = interval '1 day', data_updated_at = now() - interval '2 days' WHERE id = $raw;");

echo "1. Late: the check made, a failure recorded\n";
$w = $worker();
ok(($w['ran']['freshness']['checks_made'] ?? 0) === 1 && ($w['ran']['freshness']['late'] ?? 0) === 1, 'the pass makes one freshness check and finds raw.orders late (' . json_encode($w['ran']['freshness'] ?? $w) . ')');
$fc = check_id('raw.orders', 'freshness');
ok(pg1("SELECT source || '|' || severity || '|' || blocking::text || '|' || run_when FROM checks WHERE id = $fc") === 'contract|warn|false|on_load', 'source contract, warn (no error interval), never blocking, run on every load too');
$inc = open_incident($fc);
ok($inc !== null && $inc[2] === 'warn' && pg1('SELECT kind FROM incidents WHERE id = ' . (int) $inc[0]) === 'freshness', 'an incident: freshness, warn');
ok(str_starts_with((string) pg1("SELECT passed::text || '|' || round(value) || '|' || threshold FROM check_results WHERE check_id = $fc ORDER BY id DESC LIMIT 1"), 'false|48|24'), 'the result: 48 hours old against 24');
$late = get_json(27, '/quality')['json']['data']['late'] ?? [];
ok(count(array_filter($late, static fn (array $l): bool => $l['dataset'] === 'raw.orders' && $l['state'] === 'warn')) === 1, 'the quality home lists raw.orders as late (warn)');
ok(count(array_filter(get_json(29, '/quality')['json']['data']['late'] ?? [], static fn (array $l): bool => $l['dataset'] === 'raw.orders')) === 0, 'Ann (external) does not see it');

echo "2. Hourly\n";
$again = $worker('');
ok(($again['ran']['freshness']['due'] ?? true) === false, 'the next minute\'s pass (all passes) finds it done this hour');

echo "3. Fresh data passes and resolves it\n";
pg("UPDATE datasets SET data_updated_at = now() WHERE id = $raw;");
$w = $worker();
ok(($w['ran']['freshness']['fresh'] ?? 0) >= 1 && open_incident($fc) === null && pg1('SELECT status FROM incidents WHERE id = ' . (int) $inc[0]) === 'resolved', 'fresh again: passed, the incident resolved by the result');

echo "4. An error interval makes it an error\n";
pg("UPDATE datasets SET freshness_error_after = interval '3 days', data_updated_at = now() - interval '4 days' WHERE id = $raw;");
$w = $worker();
$inc2 = open_incident($fc);
ok(pg1("SELECT severity FROM checks WHERE id = $fc") === 'error' && $inc2 !== null && $inc2[2] === 'error', 'four days old against a three-day error: the check and its new incident are errors');
pg("UPDATE datasets SET freshness_warn_after = NULL, freshness_error_after = NULL, data_updated_at = now() WHERE id = $raw;");
pg("UPDATE incidents SET status = 'dismissed', resolved_at = now(), resolution = 'proof over' WHERE check_id = $fc AND status IN ('new', 'acknowledged', 'investigating');");
ok(count(activity('worker.freshness')) >= 3, 'each pass logged (worker.freshness)');
finish();
