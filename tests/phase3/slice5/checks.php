<?php
/**
 * Slice 5 proof 4 — checks (contracts-quality.md "Proof" 4): the templates; each kind saved with its params in words; the refusals on their
 * fields (a column that is not there, a custom SQL reading another dataset or writing, a blocking warn); a referential check prefilled from the
 * column's declared reference; an agent's blocking check refused in the database's words, its non-blocking one saved; check_run enqueues and
 * the results land through dl_check_result_record; a blocking not_null failing quarantines its rows at the next promotion; update, disable,
 * delete; the screen's strips; a Member reads the checks, may not make one.
 */
require __DIR__ . '/lib.php';
$cur = ds_id('curated.orders');
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');
pg("UPDATE datasets SET steward_member_id = 30 WHERE key = 'curated.orders';");   // Dana stewards the orders (the owner and the steward are told of a failure)

echo "1. The templates\n";
$t = get_json(27, '/datasets/curated.orders/checks/new');
ok($t['code'] === 200 && count($t['json']['data']['kinds'] ?? []) === 10 && in_array('amount', $t['json']['data']['columns'] ?? [], true), 'the check form offers the ten templates and the live columns');
$cards = html_as(27, '/datasets/curated.orders/checks/new');
ok(substr_count($cards['body'], 'id="check-template-') === 10, 'as ten cards (check-template-{kind})');
ok(get_json(26, '/datasets/curated.orders/checks/new')['code'] === 403, 'a Member may not add a check (checks.manage): 403');

echo "2. Each kind saved, its params in words\n";
$save = static fn (array $f, int $m = 27): array => post_json($m, '/quality/checks/save.php', ['dataset' => 'curated.orders'] + $f);
$made = [];
foreach ([
    ['not_null', ['column' => 'amount', 'dimension' => 'completeness', 'severity' => 'error', 'blocking' => '1'], 'not null (amount)'],
    ['accepted_values', ['column' => 'status', 'values' => "open\nshipped\ncancelled", 'dimension' => 'validity', 'severity' => 'warn'], 'one of open, shipped, cancelled (status)'],
    ['range', ['column' => 'amount', 'min' => '0', 'max' => '10000', 'dimension' => 'accuracy', 'severity' => 'warn'], 'between 0 and 10,000 (amount)'],
    ['regex', ['column' => 'order_id', 'pattern' => '^SO-\d{3}$', 'dimension' => 'conformity'], 'matches ^SO-\d{3}$ (order_id)'],
    ['unique', ['column' => 'order_id', 'dimension' => 'uniqueness'], 'unique (order_id)'],
    ['row_count', ['min' => '1', 'change_pct' => '50', 'dimension' => 'completeness', 'run_when' => 'daily'], 'at least 1 rows; row count within ±50 % of the last load'],
    ['custom_sql', ['sql' => 'SELECT * FROM curated.orders WHERE amount < 0', 'dimension' => 'validity'], 'custom SQL finds no failing row'],
    ['schema', ['dimension' => 'conformity', 'run_when' => 'hourly'], 'no breaking schema change waiting'],
] as [$kind, $f, $words]) {
    $r = $save(['kind' => $kind] + $f);
    $id = (int) ($r['json']['record_id'] ?? 0);
    $made[$kind] = $id;
    $c = $id > 0 ? (get_json(27, '/datasets/curated.orders/checks')['json']['data']['checks'] ?? []) : [];
    $mine = array_values(array_filter($c, static fn (array $x): bool => $x['check_id'] === $id))[0] ?? [];
    ok($r['code'] === 200 && ($mine['words'] ?? '') === $words, "$kind saved — \"$words\"");
}
ok(pg1("SELECT blocking::text || '|' || severity || '|' || source FROM checks WHERE id = {$made['not_null']}") === 'true|error|person', 'the not_null is blocking (an error), made by a person');

echo "3. A referential check, prefilled from the column's declared reference\n";
pg("UPDATE dataset_columns SET references_dataset_id = (SELECT id FROM datasets WHERE key = 'curated.statuses'), references_column = 'status'
     WHERE dataset_id = $cur AND name = 'status';");
$pre = get_json(27, '/datasets/curated.orders/checks/new?kind=referential&column=status')['json']['data']['values']['params'] ?? [];
ok(($pre['dataset_key'] ?? '') === 'curated.statuses' && ($pre['column'] ?? '') === 'status', 'the form for status prefills curated.statuses.status');
$r = $save(['kind' => 'referential', 'column' => 'status', 'dataset_key' => 'curated.statuses', 'ref_column' => 'status', 'dimension' => 'consistency']);
$made['referential'] = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && pg1("SELECT params ->> 'dataset_key' || '.' || (params ->> 'column') FROM checks WHERE id = {$made['referential']}") === 'curated.statuses.status',
   'saved: every status must exist in curated.statuses.status');
$r = $save(['kind' => 'referential', 'column' => 'status', 'dataset_key' => 'curated.hr_reviews', 'ref_column' => 'x', 'dimension' => 'consistency']);
ok($r['code'] === 422 && field_of($r) === 'dataset_key', 'a dataset Marco may not see cannot be referred to: refused on the dataset');

echo "4. Refusals on their fields\n";
$r = $save(['kind' => 'not_null', 'column' => 'shipped_on', 'dimension' => 'completeness']);
ok($r['code'] === 422 && field_of($r) === 'column', 'a column that is not there');
$r = $save(['kind' => 'custom_sql', 'sql' => 'SELECT * FROM curated.orders o JOIN curated.statuses s ON s.status = o.status WHERE s.label IS NULL', 'dimension' => 'validity']);
ok($r['code'] === 422 && field_of($r) === 'sql' && str_contains(message_of($r), 'reads only its own dataset'), 'a custom SQL reading another dataset: "A custom check reads only its own dataset"');
$r = $save(['kind' => 'custom_sql', 'sql' => 'DELETE FROM curated.orders', 'dimension' => 'validity']);
ok($r['code'] === 422 && field_of($r) === 'sql', 'a custom SQL that writes: refused by the allow-list on the SQL');
$r = $save(['kind' => 'range', 'column' => 'amount', 'min' => '5', 'max' => '1', 'dimension' => 'accuracy']);
ok($r['code'] === 422 && field_of($r) === 'max', 'a range whose maximum is below its minimum');
$r = $save(['kind' => 'not_null', 'column' => 'status', 'dimension' => 'completeness', 'severity' => 'warn', 'blocking' => '1']);
ok($r['code'] === 422 && field_of($r) === 'blocking', 'a blocking check that is only a warning: "A blocking check is an error"');

echo "5. The Steward (an agent with check_create granted)\n";
$ab = agent_post(42, '/quality/checks/save.php', ['dataset' => 'curated.orders', 'kind' => 'not_null', 'column' => 'customer_email', 'dimension' => 'completeness', 'blocking' => '1']);
ok($ab['code'] === 403 && str_contains((string) ($ab['json']['error']['message'] ?? ''), 'An agent proposes a blocking check; a person makes it blocking'), 'its blocking check: refused in the database\'s words (403)');
$an = agent_post(42, '/quality/checks/save.php', ['dataset' => 'curated.orders', 'kind' => 'not_null', 'column' => 'customer_email', 'dimension' => 'completeness']);
$aid = (int) ($an['json']['record_id'] ?? 0);
ok($an['code'] === 200 && pg1("SELECT source || '|' || blocking::text FROM checks WHERE id = $aid") === 'agent|false', 'its non-blocking one: saved, source agent');
ok(agent_post(42, '/quality/checks/save.php', ['check' => $aid, 'severity' => 'warn'])['code'] === 403, '…but changing a check is checks.manage\'s (403)');

echo "6. check_run: the results land, through the database\n";
$run = post_json(27, '/quality/checks/run.php', ['dataset' => 'curated.orders']);
$job = wait_job((int) ($run['json']['job_id'] ?? 0), 90);
ok($run['code'] === 200 && ($job['status'] ?? '') === 'done' && (job_result($job)['checks'] ?? 0) === 10, 'Run all: a checks job, done — ten results (' . json_encode(job_result($job)) . ')');
$last = static fn (int $id): ?string => pg1("SELECT CASE WHEN error IS NOT NULL THEN 'error' WHEN passed THEN 'passed' ELSE 'failed' END || ':' || COALESCE(value::text, '-') FROM check_results WHERE check_id = $id ORDER BY id DESC LIMIT 1");
ok($last($made['not_null']) === 'failed:1' && $last($made['accepted_values']) === 'failed:1' && $last($made['range']) === 'failed:1', 'failing: not_null (SO-004), accepted values (returned), range (9,999,999)');
ok($last($made['regex']) === 'passed:0' && $last($made['unique']) === 'passed:0' && $last($made['custom_sql']) === 'passed:0' && $last($made['referential']) === 'passed:0'
   && $last($made['schema']) === 'passed:0' && $last($made['row_count']) === 'passed:7', 'passing: the pattern, unique, the custom SQL, the reference, the schema, the row count (7)');
ok(pg1("SELECT failing_keys::text FROM check_results WHERE check_id = {$made['not_null']} ORDER BY id DESC LIMIT 1") === '["SO-004"]', 'a failing result keeps the failing KEYS (order_id SO-004) — never a value');
ok(count(activity('check.result', $a0)) === 10 && count(activity('check.run', $a0)) === 2, 'each result logged by the writer (check.result), the run twice (queued, done)');
$one = post_json(27, '/quality/checks/run.php', ['dataset' => 'curated.orders', 'check' => $made['unique']]);
$j1 = wait_job((int) ($one['json']['job_id'] ?? 0), 60);
ok(($j1['status'] ?? '') === 'done' && (job_result($j1)['checks'] ?? 0) === 1, 'one check run alone: one result');
ok(post_json(26, '/quality/checks/run.php', ['dataset' => 'curated.orders'])['code'] === 403, 'a Member may not run checks (an engineer or the steward): 403');
$dj = post_json(30, '/quality/checks/run.php', ['dataset' => 'curated.orders', 'check' => $made['regex']]);
ok($dj['code'] === 200, 'Dana, the dataset\'s steward, may run them');
wait_job((int) ($dj['json']['job_id'] ?? 0), 60);

echo "7. A blocking not_null quarantines at the next promotion\n";
[, $pj] = promote_as(27, 'curated.orders', true);
$pr = job_result($pj);
ok(($pr['reasons']['not_null:amount'] ?? 0) === 1 && ($pr['rows_quarantined'] ?? 0) === 2 && !isset(curated_rows()['SO-004']), 'the rebuild holds SO-004 back with not_null:amount (two held back now)');
$qr = get_json(27, '/datasets/curated.orders/quarantine')['json']['data']['quarantine']['reasons'] ?? [];
ok(($qr['not_null:amount'] ?? 0) === 1 && ($qr['cast:order_date'] ?? 0) === 1, 'the quarantine counts each reason');
ok($last($made['not_null']) === 'failed:1', 'the promotion records the not_null\'s result on the rows it read: failed, one row');
ok(($pr['warnings']['accepted_values:status'] ?? 0) === 1 && ($pr['warnings']['range:amount'] ?? 0) === 1, 'the non-blocking row checks only count (returned, 9,999,999) — those rows are promoted');

echo "8. Update, disable, delete; the strips\n";
$u = post_json(27, '/quality/checks/save.php', ['check' => $made['row_count'], 'min' => '1', 'max' => '100000', 'change_pct' => '50']);
ok($u['code'] === 200 && str_contains((string) json_encode(get_json(27, '/datasets/curated.orders/checks')['json']), 'between 1 and 100,000 rows'), 'check_update: the new params in words');
$d = post_json(27, '/quality/checks/disable.php', ['check' => $aid]);
ok($d['code'] === 200 && pg1("SELECT enabled::text FROM checks WHERE id = $aid") === 'false', 'check_disable turns it off');
$e = post_json(27, '/quality/checks/disable.php', ['check' => $aid, 'enable' => '1']);
ok($e['code'] === 200 && pg1("SELECT enabled::text FROM checks WHERE id = $aid") === 'true', '…and back on');
$x = post_json(27, '/quality/checks/delete.php', ['check' => $aid]);
ok($x['code'] === 200 && pg1("SELECT count(*) FROM checks WHERE id = $aid") === '0', 'check_delete removes it with its results');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['check_delete']['approval'] ?? '') === 'deletion', 'an agent\'s check_delete pauses for a person (deletion — the registry the kernel\'s hook reads)');
$list = get_json(26, '/datasets/curated.orders/checks')['json']['data'] ?? [];
$nn = array_values(array_filter($list['checks'] ?? [], static fn (array $x): bool => $x['check_id'] === $made['not_null']))[0] ?? [];
ok(count($list['checks'] ?? []) === 9 && array_column($nn['strip'] ?? [], 'state') === ['failed', 'failed'] && ($list['may']['manage'] ?? true) === false,
   'Priya reads the checks with their strips (not_null: failed, failed) and may not change them');
$h = html_as(27, '/datasets/curated.orders/checks');
ok(str_contains($h['body'], 'id="check-strip-' . $made['not_null'] . '"') && substr_count($h['body'], 'class="check-cell') >= 63, 'the screen: a 7-cell strip per check');
ok(count(activity('check.create', $a0)) === 10 && count(activity('check.update', $a0)) === 1 && count(activity('check.disable', $a0)) === 2 && count(activity('check.delete', $a0)) === 1,
   'check.create / update / disable / delete logged');
finish();
