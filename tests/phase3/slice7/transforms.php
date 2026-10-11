<?php
/**
 * Slice 7 proof 1 — transforms (transforms-products-lineage.md "Proof"): a product made by a transform from a curated dataset (the run's snapshot, rows, lineage edge, lineage_runs
 * START and COMPLETE, the columns, the audit of the writer's read); the walls (a Member, an external, a sandbox of someone else, a raw output, a cycle, an input the owner may not read —
 * refused with the key named); new SQL is a new version, refrozen; incremental_merge with a GROUP BY fails the run with incremental_needs_rowwise (never retried, the owner told once a
 * day), a row-wise one merges only what changed and a run with nothing new is `no_op`; after_inputs runs after a load lands and not before; a cron schedule fires once per due minute;
 * a masked input arrives masked in the product (the owner at `read`) and a column that comes straight from a personal one keeps its kind; a sandbox transform; one run at a time;
 * enable, disable and delete (the output stays); an agent in a person's chat turn is refused, in its own duty it saves.
 */
require __DIR__ . '/lib.php';
$a0 = (int) pg1('SELECT COALESCE(max(id), 0) FROM activity_log');
$monthlySql = 'SELECT date_trunc(\'month\', ordered_on) AS month, region, sum(amount) AS revenue, count(*) AS orders FROM curated.s7_orders GROUP BY ALL';

echo "1. A product made by a transform\n";
$r = transform_save_as(27, ['name' => 's7_monthly_t', 'output_dataset' => 'product.s7_monthly', 'sql' => $monthlySql, 'description' => 'Revenue by month and region.']);
$t1 = (int) ($r['json']['record_id'] ?? 0);
ok($r['code'] === 200 && $t1 > 0 && ($r['json']['inputs'] ?? []) === ['curated.s7_orders'] && ($r['json']['version'] ?? 0) === 1 && ($r['json']['refrozen'] ?? false) === true,
   'Marco saves a transform into product.s7_monthly: version 1, its input taken from the parse (curated.s7_orders), frozen');
$row = transform_row($t1);
ok($row['owner_member_id'] === 27 && $row['frozen'] === true && $row['mode'] === 'replace' && $row['schedule'] === null && $row['enabled'] === true && $row['last_run_id'] === null,
   'owned by Marco, frozen, replace, on request, enabled, not run yet');
ok(pg1("SELECT frozen_policy #>> '{datasets,curated.s7_orders,level}' FROM transforms WHERE id = $t1") === 'read_unmasked',
   'what it froze is its owner\'s level over the input (Marco owns the orders: read_unmasked)');
$log = pg("SELECT after::text FROM activity_log WHERE id > $a0 AND action = 'transform.save' ORDER BY id");
$j = json_decode($log[0] ?? '{}', true) ?: [];
ok(count($log) === 1 && ($j['transform_id'] ?? 0) === $t1 && ($j['output'] ?? '') === 'product.s7_monthly' && ($j['inputs'] ?? []) === ['curated.s7_orders'] && ($j['version'] ?? 0) === 1 && !str_contains($log[0], 'FROM'),
   'transform.save is logged with the version, the output and the inputs — never the SQL');
$desc = html_as(27, "/transforms/$t1");
ok($desc['code'] === 200 && str_contains($desc['body'], 'id="transform-sql"') && str_contains($desc['body'], 'GROUP BY ALL') && str_contains($desc['body'], 'Frozen at'), 'the page shows the SQL to someone who reads every input');

[$rq, $run] = run_transform(27, $t1);
$rid = (int) ($run['id'] ?? 0);
ok($rq['code'] === 200 && ($rq['json']['new'] ?? false) === true && $run['status'] === 'ok' && $run['rows_written'] === 56 && $run['version'] === 1 && $run['error'] === null,
   'Run: queued, run by the writer — ok, 56 rows written (14 months × 4 regions)');
ok($run['output_snapshot'] !== null && isset($run['input_snapshots']['curated.s7_orders']) && (int) $run['output_snapshot'] > (int) $run['input_snapshots']['curated.s7_orders'],
   'the run kept the snapshot it read the input at and the snapshot it made');
$ds = pg("SELECT row_count || '|' || (last_snapshot_id = {$run['output_snapshot']})::text || '|' || (data_updated_at IS NOT NULL)::text FROM datasets WHERE key = 'product.s7_monthly'");
ok($ds === ['56|true|true'], 'the output\'s engine fields are the writer\'s: rows, last snapshot, data updated');
ok(pg1("SELECT status || '|' || attempts FROM lake_jobs WHERE kind = 'transform' AND ref_id = $t1 ORDER BY id DESC LIMIT 1") === 'done|1', 'the job is done on its first attempt');
ok(pg("SELECT event FROM lineage_runs WHERE job_name = 'transform.s7_monthly_t' AND transform_run_id = $rid ORDER BY id") === ['START', 'COMPLETE'], 'lineage: a START and a COMPLETE (OpenLineage), tied to the run');
ok(pg1("SELECT (facets -> 'sql' ->> 'query') IS NOT NULL FROM lineage_runs WHERE transform_run_id = $rid AND event = 'START'") === 't'
   && pg1("SELECT (outputs -> 0 ->> 'name') || '@' || (outputs -> 0 ->> 'version') FROM lineage_runs WHERE transform_run_id = $rid AND event = 'COMPLETE'") === "product.s7_monthly@{$run['output_snapshot']}",
   'the START carries the SQL facet; the COMPLETE names the output at the snapshot it made');
$edge = pg("SELECT row_to_json(e)::text FROM (SELECT via, transform_id, column_map FROM lineage_edges WHERE from_dataset_id = " . ds_id('curated.s7_orders') . ' AND to_dataset_id = ' . ds_id('product.s7_monthly') . ") e");
$e = json_decode($edge[0] ?? '{}', true) ?: [];
ok(($e['via'] ?? '') === 'transform' && ($e['transform_id'] ?? 0) === $t1 && ($e['column_map']['region'][0]['kind'] ?? '') === 'DIRECT' && ($e['column_map']['revenue'][0]['kind'] ?? '') === 'INDIRECT'
   && ($e['column_map']['revenue'][0]['column'] ?? '') === 'amount', 'the lineage edge curated.s7_orders → product.s7_monthly via the transform, with the column map: region DIRECT, revenue INDIRECT (from amount)');
$cols = live_columns('product.s7_monthly');
ok(array_keys($cols) === ['month', 'region', 'revenue', 'orders'] && $cols['revenue'][0] === 'number' && $cols['orders'][0] === 'integer', 'the output\'s columns are in the catalog with their types: ' . implode(', ', array_keys($cols)));
$qr = pg("SELECT source || '|' || caller_member_id || '|' || status || '|' || array_to_string(datasets, ',') FROM query_runs WHERE source = 'transform' AND caller_member_id = 27 ORDER BY id DESC LIMIT 1");
ok($qr === ['transform|27|ok|curated.s7_orders'], 'the writer\'s read of the input is audited like any read (source transform, Marco the caller)');
$done = pg("SELECT source || '|' || (after ->> 'status') || '|' || (after ->> 'rows_written') FROM activity_log WHERE id > $a0 AND action = 'transform.run_done' ORDER BY id");
ok($done === ['system|ok|56'], 'transform.run_done is logged by the writer: source system, status, rows');
$prev = preview_as(26, ds_id('product.s7_monthly'));
ok(count($prev['rows'] ?? []) === 56, 'Priya (read) previews the product: ' . count($prev['rows'] ?? []) . ' rows');
$list = get_json(27, '/transforms')['json']['data'] ?? [];
$card = array_values(array_filter($list['rows'] ?? [], static fn ($x) => $x['transform_id'] === $t1))[0] ?? [];
ok(($card['last_run']['status'] ?? '') === 'ok' && ($card['last_run']['rows_written'] ?? 0) === 56 && ($card['output']['key'] ?? '') === 'product.s7_monthly' && ($card['inputs'][0]['key'] ?? '') === 'curated.s7_orders' && !array_key_exists('sql', $card),
   'the list says it: last run ok, 56 rows, its output and input — and not the SQL');

echo "2. The walls\n";
ok(transform_save_as(26, ['name' => 'x_priya', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT 1 FROM curated.s7_orders'])['code'] === 403, 'a Member may not save a transform: 403');
ok(in_array(transform_save_as(29, ['name' => 'x_ann', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT 1 FROM curated.s7_orders'])['code'], [403, 404], true), 'an external may not: refused');
$x = transform_save_as(27, ['name' => 'x_secret', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT secret_id FROM curated.s7_secret']);
ok($x['code'] === 422 && field_of($x) === 'sql' && str_contains(message_of($x), 'curated.s7_secret'), 'an input its owner may not read is refused at save with the input named: ' . message_of($x));
ok(pg1("SELECT count(*) FROM transforms WHERE name = 'x_secret'") === '0', '…and nothing was saved');
$x = transform_save_as(27, ['name' => 'x_forbidden', 'output_dataset' => 'product.s7_top', 'sql' => "SELECT * FROM read_csv('/etc/passwd')"]);
ok($x['code'] === 422 && field_of($x) === 'sql', 'a SQL the allow-list refuses is the service\'s sentence on `sql`: ' . message_of($x));
$x = transform_save_as(27, ['name' => 'x_none', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT 1 AS one']);
ok($x['code'] === 422 && field_of($x) === 'sql' && str_contains(message_of($x), 'at least one dataset'), 'a SQL that reads no dataset makes nothing from the lake: refused');
$x = transform_save_as(27, ['name' => 'x_raw', 'output_dataset' => 'raw.s7_orders_raw', 'sql' => 'SELECT * FROM curated.s7_orders']);
ok($x['code'] === 422 && field_of($x) === 'output_dataset' && str_contains(message_of($x), 'never raw'), 'a raw output: refused (raw is what a source lands)');
$x = transform_save_as(27, ['name' => 'x_taken', 'output_dataset' => 'product.s7_monthly', 'sql' => 'SELECT * FROM curated.s7_orders']);
ok($x['code'] === 422 && field_of($x) === 'output_dataset' && str_contains(message_of($x), 'already made'), 'a dataset another transform makes: refused (one transform makes one dataset)');
$x = transform_save_as(27, ['name' => 'x_self', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT * FROM product.s7_top']);
ok($x['code'] === 422 && field_of($x) === 'sql' && str_contains(message_of($x), 'its own output'), 'a transform that reads the dataset it makes: refused');
$x = transform_save_as(27, ['name' => 'x_cycle', 'output_dataset' => 'curated.s7_orders', 'sql' => 'SELECT region FROM product.s7_monthly']);
ok($x['code'] === 422 && field_of($x) === 'sql' && str_contains(message_of($x), 'cycle'), 'a cycle (the output is already upstream of the input): refused — ' . message_of($x));
$x = transform_save_as(27, ['name' => 'X Bad Name', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT 1 FROM curated.s7_orders']);
ok($x['code'] === 422 && field_of($x) === 'name', 'a name with capitals and spaces: refused on `name`');
$x = transform_save_as(27, ['name' => 's7_monthly_t', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT 1 FROM curated.s7_orders']);
ok($x['code'] === 422 && field_of($x) === 'name', 'a name taken: refused on `name`');
$x = transform_save_as(27, ['name' => 'x_cron', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT region FROM curated.s7_orders', 'schedule' => '61 * * * *']);
ok($x['code'] === 422 && field_of($x) === 'cron', 'a cron that is not five readable fields: refused on `cron`');
$x = transform_save_as(27, ['name' => 'x_inc', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT region FROM curated.s7_orders', 'mode' => 'incremental_merge']);
ok($x['code'] === 422 && field_of($x) === 'mode' && str_contains(message_of($x), 'key'), 'an incremental merge into a dataset with no key: refused on `mode` — ' . message_of($x));
ok(transform_save_as(30, ['name' => 'x_dana', 'output_dataset' => 'product.s7_top', 'sql' => 'SELECT region FROM curated.s7_orders'])['code'] === 422, 'an Analyst may not make a product (her right is her own sandbox): refused');
$x = transform_save_as(27, ['name' => 'x_danas', 'output_dataset' => 'sandbox.m30_s7_mine', 'sql' => 'SELECT region FROM curated.s7_orders']);
ok($x['code'] === 422 && field_of($x) === 'output_dataset' && str_contains(message_of($x), 'not here'), 'nobody writes another\'s sandbox — it is not even there for them (a hidden dataset never confirms itself): ' . message_of($x));
$x = transform_save_as(27, ['name' => 'x_hidden', 'output_dataset' => 'curated.s7_secret', 'sql' => 'SELECT region FROM curated.s7_orders']);
ok($x['code'] === 422 && field_of($x) === 'output_dataset' && str_contains(message_of($x), 'not here') && !str_contains(message_of($x), 's7_secret'), 'an output Marco cannot discover (the secret table): "not here" — its key is not echoed');
ok(pg1("SELECT count(*) FROM transforms WHERE name LIKE 'x\\_%'") === '0', 'none of the refused saves left a row');

echo "3. New SQL is a new version, refrozen\n";
$sql2 = 'SELECT date_trunc(\'month\', ordered_on) AS month, region, sum(amount) AS revenue, count(*) AS orders, avg(amount) AS average FROM curated.s7_orders GROUP BY ALL';
$edit = transform_save_as(27, ['transform' => $t1, 'name' => 's7_monthly_t', 'output_dataset' => 'product.s7_monthly', 'sql' => $sql2]);
$row = transform_row($t1);
ok($edit['code'] === 200 && ($edit['json']['version'] ?? 0) === 2 && ($edit['json']['refrozen'] ?? false) === true && $row['version'] === 2 && $row['frozen'] === true && str_contains((string) $edit['json']['location'], 'notice=refrozen'),
   'saving new SQL: version 2, refrozen, the page says so');
$same = transform_save_as(27, ['transform' => $t1, 'description' => 'Revenue by month and region, with the average order.']);
ok($same['code'] === 200 && ($same['json']['version'] ?? 0) === 2 && ($same['json']['refrozen'] ?? true) === false, 'saving without touching the SQL keeps version 2 and does not refreeze');
$noSql = transform_save_as(27, ['transform' => $t1, 'sql' => '', 'schedule' => '']);
ok($noSql['code'] === 200 && transform_row($t1)['version'] === 2, 'an emptied SQL on a change keeps the SQL (the form hides it from someone who may not read every input)');
$unfrozenBy = pg("UPDATE transforms SET frozen_policy = NULL, frozen_at = NULL WHERE id = $t1 RETURNING id");
$busy = post_json(27, '/transforms/run.php', ['transform' => $t1]);
ok($busy['code'] === 422 && str_contains(message_of($busy), 'not frozen'), 'a transform whose level is not frozen cannot be run: refused in words');
$again = transform_save_as(27, ['transform' => $t1, 'name' => 's7_monthly_t']);
ok($again['code'] === 200 && ($again['json']['refrozen'] ?? false) === true && transform_row($t1)['frozen'] === true, 'saving it again refreezes it');
[, $run2] = run_transform(27, $t1);
$cols = live_columns('product.s7_monthly');
ok($run2['status'] === 'ok' && $run2['version'] === 2 && isset($cols['average']), 'version 2 runs: the new column "average" is in the catalog');
$sql3 = 'SELECT date_trunc(\'month\', ordered_on) AS month, region, sum(amount) AS revenue, avg(amount) AS average FROM curated.s7_orders GROUP BY ALL';
transform_save_as(27, ['transform' => $t1, 'sql' => $sql3]);
[, $run3] = run_transform(27, $t1);
$gone = pg1("SELECT removed_snapshot_id IS NOT NULL FROM dataset_columns WHERE dataset_id = " . ds_id('product.s7_monthly') . " AND name = 'orders' ORDER BY id DESC LIMIT 1");
ok($run3['status'] === 'ok' && $gone === 't' && !isset(live_columns('product.s7_monthly')['orders']), 'a column the SQL stopped making is marked removed in the catalog at that snapshot');
transform_save_as(27, ['transform' => $t1, 'sql' => $monthlySql]);
[, $run4] = run_transform(27, $t1);
ok($run4['status'] === 'ok' && $run4['version'] === 4 && isset(live_columns('product.s7_monthly')['orders']), 'back to the first SQL (version 4): the column returns');

echo "4. incremental_merge\n";
pg("UPDATE datasets SET dedupe_key = '{order_id}' WHERE key = 'curated.s7_incr'");
$bad = transform_save_as(27, ['name' => 's7_incr_bad', 'output_dataset' => 'curated.s7_incr', 'mode' => 'incremental_merge', 'sql' => 'SELECT region, count(*) AS order_id FROM curated.s7_orders GROUP BY region']);
$tb = (int) ($bad['json']['record_id'] ?? 0);
ok($bad['code'] === 200 && $tb > 0, 'an incremental transform with a GROUP BY is accepted by the form (the writer judges the SQL)');
$n0 = last_notice_id();
[, $rb] = run_transform(27, $tb);
$jb = pg("SELECT status || '|' || attempts FROM lake_jobs WHERE kind = 'transform' AND ref_id = $tb ORDER BY id DESC LIMIT 1");
ok($rb['status'] === 'failed' && str_starts_with((string) $rb['error'], 'incremental_needs_rowwise') && str_contains((string) $rb['error'], 'GROUP BY'), 'the run fails with incremental_needs_rowwise, in words: ' . $rb['error']);
ok($jb === ['failed|1'], 'a decided refusal is failed at once and never retried (attempts 1)');
ok(pg("SELECT event FROM lineage_runs WHERE transform_run_id = {$rb['id']} ORDER BY id") === ['START', 'FAIL'], 'lineage: START then FAIL');
ok(last_notice(27, 'load_failed', $n0) !== null && (last_notice(27, 'load_failed', $n0)[1] ?? '') === 'transform', 'the owner is told (load_failed, about the transform)');
$n1 = last_notice_id();
run_transform(27, $tb);
ok(last_notice(27, 'load_failed', $n1) === null, 'told once a day: a second failure the same day sends no second notice');
$page = html_as(27, "/transforms/$tb?tab=runs");
ok(str_contains($page['body'], 'incremental_needs_rowwise') && str_contains($page['body'], 'badge bg-soft-danger'), 'the Runs tab says so');
transform_save_as(27, ['transform' => $tb, 'sql' => 'SELECT order_id, region, amount FROM curated.s7_orders']);
[, $r1] = run_transform(27, $tb);
$cnt = static fn (): int => (int) sql_rows_value(27, 'SELECT count(*) FROM curated.s7_incr');
ok($r1['status'] === 'ok' && $r1['rows_written'] === 1680 && $cnt() === 1680, 'a row-wise incremental transform: the first run makes the whole dataset (1,680 rows)');
append_orders(25);
[, $r2] = run_transform(27, $tb);
ok($r2['status'] === 'ok' && $r2['rows_written'] === 25 && $cnt() === 1705, 'after 25 more orders land: it merges only the 25 (output 1,705 rows)');
[, $r3] = run_transform(27, $tb);
ok($r3['status'] === 'no_op' && $r3['rows_written'] === 0 && $cnt() === 1705, 'with nothing new it is `no_op`: no row written');
$dup = (int) sql_rows_value(27, 'SELECT count(*) - count(DISTINCT order_id) FROM curated.s7_incr');
ok($dup === 0, 'merged by the output\'s key: no order appears twice');

echo "5. after_inputs\n";
$yearly = 'SELECT year(ordered_on) AS year, sum(amount) AS revenue FROM curated.s7_orders GROUP BY ALL';
$ty = (int) (transform_save_as(27, ['name' => 's7_yearly_t', 'output_dataset' => 'product.s7_yearly', 'sql' => $yearly, 'schedule' => 'after_inputs'])['json']['record_id'] ?? 0);
$w = worker_pass('schedules');
ok(($w['errors'] ?? []) === [] && in_array('s7_yearly_t:after_inputs', $w['ran']['schedules']['transforms']['which'] ?? [], true), 'never run, its input has data: the worker queues its first run');
wait_until(static fn (): bool => pg1("SELECT count(*) FROM transform_runs WHERE transform_id = $ty AND status IN ('ok', 'failed')") === '1', 120);
ok(pg1("SELECT status FROM transform_runs WHERE transform_id = $ty") === 'ok' && pg1("SELECT requested_by IS NULL FROM transform_runs WHERE transform_id = $ty") === 't', 'it ran (the worker\'s run has no requester)');
$w = worker_pass('schedules');
ok(!in_array('s7_yearly_t:after_inputs', $w['ran']['schedules']['transforms']['which'] ?? [], true), 'nothing has landed since: the worker queues nothing — not before');
append_orders(5);
$w = worker_pass('schedules');
ok(in_array('s7_yearly_t:after_inputs', $w['ran']['schedules']['transforms']['which'] ?? [], true), 'five orders land: the worker queues a run — after');
wait_until(static fn (): bool => pg1("SELECT count(*) FROM transform_runs WHERE transform_id = $ty AND status = 'ok'") === '2', 120);
$w = worker_pass('schedules');
ok(!in_array('s7_yearly_t:after_inputs', $w['ran']['schedules']['transforms']['which'] ?? [], true), 'and once it has run, nothing more');
post_json(27, '/transforms/enable.php', ['transform' => $ty, 'enabled' => 'no']);
append_orders(3);
$w = worker_pass('schedules');
ok(!in_array('s7_yearly_t:after_inputs', $w['ran']['schedules']['transforms']['which'] ?? [], true) && transform_row($ty)['enabled'] === false, 'a disabled transform is not run by its inputs changing');
post_json(27, '/transforms/enable.php', ['transform' => $ty, 'enabled' => 'yes']);
$w = worker_pass('schedules');
ok(in_array('s7_yearly_t:after_inputs', $w['ran']['schedules']['transforms']['which'] ?? [], true), 'enabled again: it catches up');
wait_until(static fn (): bool => pg1("SELECT count(*) FROM transform_runs WHERE transform_id = $ty AND status = 'ok'") === '3', 120);

echo "6. A cron schedule\n";
require_once dirname(__DIR__, 3) . '/app/features/transforms/cron.php';
$u = static fn (string $s): DateTimeImmutable => new DateTimeImmutable($s . ' UTC');
ok(cron_valid('*/5 8-18/2 1,15 * 1-5') && !cron_valid('61 * * * *') && !cron_valid('* * * *') && !cron_valid('a b c d e') && cron_valid('0 6 * * 7'), 'cron_valid: steps, ranges and lists read; six fields wrong, sixty-one minutes wrong');
ok(cron_due('*/15 * * * *', null, $u('2026-10-11 12:15:30')) === true && cron_due('*/15 * * * *', null, $u('2026-10-11 12:16:30')) === false, 'cron_due: with no earlier run only the minute it is now counts');
ok(cron_due('*/15 * * * *', $u('2026-10-11 12:15:10'), $u('2026-10-11 12:15:50')) === false && cron_due('*/15 * * * *', $u('2026-10-11 12:14:59'), $u('2026-10-11 12:15:50')) === true,
   'once per due minute: a run made in the minute covers it; one made the minute before does not');
ok(cron_due('0 6 * * *', $u('2026-10-10 06:00:20'), $u('2026-10-11 06:00:05')) === true && cron_due('0 6 * * *', $u('2026-10-11 06:00:20'), $u('2026-10-11 09:00:00')) === false, 'daily at 06:00: due after yesterday\'s run, not after today\'s');
ok(cron_due('30 12 * * 0', null, $u('2026-10-11 12:30:10')) === true && cron_due('30 12 * * 1', null, $u('2026-10-11 12:30:10')) === false, 'the day of the week (2026-10-11 is a Sunday)');
ok(cron_due('0 9 * * *', null, new DateTimeImmutable('2026-10-11 14:00:30 UTC'), 'America/Chicago') === true && cron_due('0 9 * * *', null, new DateTimeImmutable('2026-10-11 09:00:30 UTC'), 'America/Chicago') === false,
   'in the business\'s time zone: 09:00 in Chicago is 14:00 UTC in October (09:00 UTC is 04:00 there)');
// an end-to-end: every minute, once per due minute (the clock is real; both passes within the same minute)
standard_transforms();
if ((int) date('s') > 40) { sleep(62 - (int) date('s')); }
$tc = (int) (transform_save_as(27, ['name' => 's7_top_cron', 'output_dataset' => 'product.s7_cron', 'sql' => 'SELECT region, revenue FROM product.s7_regions', 'schedule' => '* * * * *'])['json']['record_id'] ?? 0);
pg("SET session_replication_role = replica; UPDATE transforms SET updated_at = now() - interval '10 minutes' WHERE id = $tc");
$w1 = worker_pass('schedules');
ok(in_array('s7_top_cron:cron', $w1['ran']['schedules']['transforms']['which'] ?? [], true), 'every minute, saved ten minutes ago: the worker queues it');
wait_until(static fn (): bool => in_array(pg1("SELECT status FROM transform_runs WHERE transform_id = $tc ORDER BY id DESC LIMIT 1"), ['ok', 'failed'], true), 120);
ok(pg1("SELECT status FROM transform_runs WHERE transform_id = $tc ORDER BY id DESC LIMIT 1") === 'ok', 'it ran');
$w2 = worker_pass('schedules');
ok(!in_array('s7_top_cron:cron', $w2['ran']['schedules']['transforms']['which'] ?? [], true) && pg1("SELECT count(*) FROM transform_runs WHERE transform_id = $tc") === '1', 'a second pass in the same minute queues nothing: once per due minute');
pg("SET session_replication_role = replica; DELETE FROM transforms WHERE id = $tc");

echo "7. A masked input arrives masked\n";
$tcu = ensure_transform(27, 's7_customers_t', 'product.s7_customers_clean', 'SELECT customer_id, email, city FROM curated.s7_customers');
ok(pg1("SELECT frozen_policy #>> '{datasets,curated.s7_customers,level}' || '|' || (frozen_policy #>> '{datasets,curated.s7_customers,masks,email}') FROM transforms WHERE id = $tcu") === 'read|partial_email',
   'Marco does not own the customers: his frozen level is read, the email masked partial_email');
$kept = sql_rows_value(27, "SELECT count(*) FROM product.s7_customers_clean WHERE email LIKE 'customer%'");
$vals = sql_rows_value(27, 'SELECT count(DISTINCT email) FROM product.s7_customers_clean');
ok((int) $kept === 0 && (int) $vals === 1 && sql_rows_value(27, 'SELECT min(email) FROM product.s7_customers_clean') === 'c***@example.invalid',
   'the product holds the MASKED email (c***@example.invalid on all 40 rows) — a product never widens what it exposes');
$cc = live_columns('product.s7_customers_clean');
ok(($cc['email'][1] ?? '') === 'email' && ($cc['email'][2] ?? '') === 'confidential' && ($cc['city'][1] ?? 'x') === '', 'its email column inherits the input\'s personal kind and classification (a column straight from a personal one keeps its kind)');
$tb2 = standard_transforms()['blend'];
$cb = live_columns('product.s7_blend');
ok(($cb['email'][1] ?? '') === 'email' && ($cb['email'][2] ?? '') === 'confidential' && ($cb['note'][1] ?? 'x') === '', 'the owner\'s product (built unmasked: the owner owns the customers) still marks the email personal and confidential');
$own = sql_rows_value(1, 'SELECT min(email) FROM product.s7_blend');
$other = sql_rows_value(30, 'SELECT min(email) FROM product.s7_blend');
ok(str_ends_with((string) $own, '@example.invalid') && !str_contains((string) $own, '***') && str_contains((string) $other, '***'), 'the owner reads the real emails; Dana (an Analyst, read) reads them masked — no laundering a mask by building a product');

echo "8. A sandbox transform\n";
$ts = transform_save_as(30, ['name' => 's7_dana_t', 'output_dataset' => 'sandbox.m30_s7_mine', 'sql' => 'SELECT region, sum(amount) AS total FROM curated.s7_orders GROUP BY region']);
$tdana = (int) ($ts['json']['record_id'] ?? 0);
ok($ts['code'] === 200 && $tdana > 0, 'Dana (sandbox.transform) makes a transform into her own sandbox');
[, $rd] = run_transform(30, $tdana);
ok($rd['status'] === 'ok' && $rd['rows_written'] === 4 && sql_rows_value(30, 'SELECT count(*) FROM sandbox.m30_s7_mine') === '4', 'Dana runs it: 4 rows in her sandbox');
ok(post_json(26, '/transforms/run.php', ['transform' => $tdana])['code'] === 403, 'a Member may not run it: 403');
ok(post_json(27, '/transforms/run.php', ['transform' => $tdana])['code'] === 404, 'a data engineer (transforms.manage) cannot even see it: a sandbox is its owner\'s, the transform with it (404)');
ok(post_json(30, '/transforms/run.php', ['transform' => $t1])['code'] === 403, 'Dana may not run Marco\'s transform: 403');

echo "9. One run at a time, enable, delete\n";
$fake = (int) pg1("INSERT INTO transform_runs (transform_id, version, status) VALUES ($tdana, 1, 'queued') RETURNING id");
$jobsBefore = (int) pg1("SELECT count(*) FROM lake_jobs WHERE kind = 'transform'");
$again = post_json(30, '/transforms/run.php', ['transform' => $tdana]);
ok($again['code'] === 200 && ($again['json']['new'] ?? true) === false && ($again['json']['run'] ?? 0) === $fake && (int) pg1("SELECT count(*) FROM lake_jobs WHERE kind = 'transform'") === $jobsBefore,
   'a run already queued is that run: no second row, no second job');
$del = post_json(30, '/transforms/delete.php', ['transform' => $tdana]);
ok($del['code'] === 422 && str_contains(message_of($del), 'queued or running'), 'a transform with a run in flight cannot be deleted: refused in words');
pg("UPDATE transform_runs SET status = 'failed', finished_at = now() WHERE id = $fake");
ok(post_json(26, '/transforms/delete.php', ['transform' => $tdana])['code'] === 403, 'a Member may not delete it: 403');
$d = post_json(30, '/transforms/delete.php', ['transform' => $tdana]);
ok($d['code'] === 200 && pg1("SELECT count(*) FROM transforms WHERE id = $tdana") === '0' && pg1("SELECT count(*) FROM transform_runs WHERE transform_id = $tdana") === '0'
   && sql_rows_value(30, 'SELECT count(*) FROM sandbox.m30_s7_mine') === '4', 'Dana deletes it: the transform and its runs go, her sandbox dataset stays with its 4 rows');
ok(pg1("SELECT count(*) FROM lineage_edges WHERE to_dataset_id = " . ds_id('sandbox.m30_s7_mine') . " AND via = 'transform' AND transform_id IS NULL") === '1', 'its lineage edge stays as history, without the transform');
$logd = json_decode(pg("SELECT before::text FROM activity_log WHERE action = 'transform.delete' ORDER BY id DESC LIMIT 1")[0] ?? '{}', true) ?: [];
ok(($logd['name'] ?? '') === 's7_dana_t' && ($logd['output'] ?? '') === 'sandbox.m30_s7_mine', 'transform.delete is logged with the name and the output');
$dis = post_json(27, '/transforms/enable.php', ['transform' => $t1, 'enabled' => 'no']);
ok($dis['code'] === 200 && transform_row($t1)['enabled'] === false && count(pg("SELECT 1 FROM activity_log WHERE action = 'transform.disable' AND entity_id = $t1")) === 1, 'disable: logged transform.disable');
ok(post_json(27, '/transforms/run.php', ['transform' => $t1])['code'] === 200, 'a person may still run a disabled transform');
post_json(27, '/transforms/enable.php', ['transform' => $t1, 'enabled' => 'yes']);

echo "10. An agent\n";
$ag = agent_post(40, '/transforms/save.php', ['name' => 's7_agent_chat', 'output_dataset' => 'product.s7_agent', 'sql' => 'SELECT region FROM curated.s7_orders'], 27);
ok($ag['code'] === 403 && str_contains(message_of($ag), 'frozen at its owner'), 'an agent in a person\'s chat turn may not save one: refused — the frozen level is the owner\'s alone');
$ag = agent_post(40, '/transforms/save.php', ['name' => 's7_agent_duty', 'output_dataset' => 'product.s7_agent', 'sql' => 'SELECT region, count(*) AS n FROM curated.s7_orders GROUP BY region']);
$ta = (int) ($ag['json']['record_id'] ?? 0);
ok($ag['code'] === 200 && $ta > 0 && transform_row($ta)['owner_member_id'] === 40, 'in its own duty it saves one (the Expert, an engineer): owned by the agent, frozen at the agent\'s level');
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
ok(($reg['actions']['transform_delete']['approval'] ?? '') === 'deletion' && ($reg['actions']['metric_certify']['approval'] ?? '') === 'other' && ($reg['actions']['metric_retire']['approval'] ?? '') === 'deletion'
   && array_key_exists('approval', $reg['actions']['transform_save']) && $reg['actions']['transform_save']['approval'] === null, 'an agent\'s transform_delete and metric_retire pause (deletion), metric_certify pauses (other); transform_save does not');
$none = (int) pg1("SELECT count(*) FROM activity_log WHERE id > $a0 AND action LIKE 'transform.%' AND (after::text ILIKE '%select %' OR before::text ILIKE '%select %')");
ok($none === 0, 'no transform.* activity row holds SQL');
finish();
