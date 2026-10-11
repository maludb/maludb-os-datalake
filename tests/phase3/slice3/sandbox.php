<?php
/**
 * Slice 3 — Save as dataset (query-service-editor.md "Proof" 6; divergence 3): sandbox.m{me}_{name} made by the WRITER from MY masked view
 * (the email arrives masked for an Analyst with read), its classification `confidential` from its input, its profile written, its lineage run;
 * the policy frozen at the request (a grant revoked between the request and the run cannot widen it); another Member never sees it; a refused
 * SQL queues nothing; another's sandbox name is never taken; a Member without sandbox.save is refused.
 */
require __DIR__ . '/lib.php';
$GLOBALS['passes'] = $GLOBALS['fails'] = 0;
$name = 'orders_' . run_tag();
$key = "sandbox.m30_$name";

echo "6. Save as dataset — made by the writer from Dana's masked view\n";
$r = post_json(30, '/sql/save-dataset.php', ['name' => $name, 'title' => 'SMOKE my orders', 'sql' => 'SELECT order_id, customer_email, region, amount FROM curated.sales_orders WHERE region = \'EMEA\'']);
$did = (int) ($r['json']['record_id'] ?? 0);
$job = (int) ($r['json']['job_id'] ?? 0);
ok($r['code'] === 200 && $did === ds_id($key) && ($r['json']['key'] ?? '') === $key && $job > 0, "the dataset row made at once ($key), the writer's sandbox_save job queued");
$p = json_decode((string) pg1("SELECT params::text FROM lake_jobs WHERE id = $job"), true) ?: [];
ok(($p['keys'] ?? []) === ['curated.sales_orders'] && ($p['policy']['datasets']['curated.sales_orders']['masks']['customer_email'] ?? '') === 'partial_email'
   && !isset($p['policy']['salt_hex']) && ($p['policy']['source'] ?? '') === 'transform', 'the job carries Dana\'s policy FROZEN at the request (source transform, her masks) — never the salt');
$j = wait_job($job, 60);
ok($j['status'] === 'done' && ($j['result']['rows'] ?? 0) === 375, 'the writer made it: 375 rows (' . ($j['error'] ?: 'no error') . ')');
$row = result_of(sql_as(30, "SELECT customer_email FROM $key ORDER BY order_id LIMIT 1"));
ok(($row['rows'][0][0] ?? '') === 'b***@example.invalid', 'the email arrived masked, as Dana saw it — the stored values are masked');
ok(pg1("SELECT classification || '/' || classification_floor || '/' || owner_member_id FROM datasets WHERE id = $did") === 'confidential/confidential/30',
   'its classification is confidential (the highest of its inputs), its floor too, Dana its owner');
ok(pg1("SELECT personal_kind FROM dataset_columns WHERE dataset_id = $did AND name = 'customer_email'") === 'email'
   && (int) pg1("SELECT count(*) FROM dataset_columns WHERE dataset_id = $did AND profile IS NOT NULL AND NOT is_system") === 4, 'the email column keeps its personal kind; every column profiled');
ok((int) pg1("SELECT count(*) FROM lineage_runs WHERE job_kind = 'sandbox_save' AND job_name = '$key' AND event = 'COMPLETE'") === 1, 'a lineage run of its own (sandbox_save, COMPLETE)');
ok(run_row((int) $j['result']['query_run_id'])['source'] === 'transform' && run_row((int) $j['result']['query_run_id'])['caller_member_id'] === 30,
   'the writer\'s read audited (source transform, Dana the caller)');
ok(count(array_filter(activity('sandbox.save'), static fn ($a) => (int) $a['entity_id'] === $did)) === 2, 'sandbox.save logged twice: queued (Dana), done (the writer)');
ok(get_json(27, "/datasets/$key")['code'] === 404 && result_of(sql_as(27, "SELECT 1 FROM $key"))['refusal']['rule'] === 'no_such_dataset', 'another person never sees it (404; the editor: no such dataset)');

echo "   the policy is the request's\n";
$g = (int) pg1("INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) VALUES ('dataset', " . ds_id('curated.hr_reviews') . ", 'member', 30, 'read', 'SMOKE a moment', 1) RETURNING id");
$r2 = post_json(30, '/sql/save-dataset.php', ['name' => $name . '_b', 'sql' => 'SELECT order_id FROM curated.sales_orders LIMIT 3']);
$j2 = wait_job((int) $r2['json']['job_id'], 60);
pg("UPDATE grants SET revoked_at = now(), revoked_by = 1 WHERE id = $g");
ok($j2['status'] === 'done', '(a second save while an unrelated grant comes and goes: done)');
$frozen = json_decode((string) pg1('SELECT params::text FROM lake_jobs WHERE id = ' . (int) $r2['json']['job_id']), true);
ok(array_keys($frozen['policy']['datasets'] ?? []) === ['curated.sales_orders'], 'the frozen policy names only what the SQL reads (the dry run\'s datasets)');

echo "   refusals\n";
$before = (int) pg1("SELECT count(*) FROM lake_jobs WHERE kind = 'sandbox_save'");
$no = post_json(30, '/sql/save-dataset.php', ['name' => $name . '_c', 'sql' => 'SELECT * FROM curated.payroll']);
ok($no['code'] === 422 && str_contains((string) ($no['json']['error']['fields']['sql'] ?? ''), 'discover') && (int) pg1("SELECT count(*) FROM lake_jobs WHERE kind = 'sandbox_save'") === $before
   && ds_id("sandbox.m30_{$name}_c") === null, 'a SQL the policy refuses: the sentence on `sql`, nothing queued, no row made');
$bad = post_json(30, '/sql/save-dataset.php', ['name' => 'Bad Name', 'sql' => 'SELECT 1 AS one FROM curated.sales_orders']);
ok($bad['code'] === 422 && isset($bad['json']['error']['fields']['name']), 'a name that is not lower-case letters, digits and _: refused on `name`');
ok(post_json(26, '/sql/save-dataset.php', ['name' => 'x', 'sql' => 'SELECT 1 AS one FROM curated.sales_orders'])['code'] === 403, 'Priya (a Member, no sandbox.save): 403');
$self = post_json(30, '/sql/save-dataset.php', ['name' => $name, 'sql' => "SELECT * FROM $key"]);
ok($self['code'] === 422, 'a result cannot replace the dataset it reads');
$again = post_json(30, '/sql/save-dataset.php', ['name' => $name, 'sql' => 'SELECT order_id, region FROM curated.sales_orders WHERE region = \'AMER\'']);
$j3 = wait_job((int) $again['json']['job_id'], 60);
ok($j3['status'] === 'done' && (int) pg1("SELECT row_count FROM datasets WHERE id = $did") === 375 && ($again['json']['record_id'] ?? 0) === $did, 'saving my own name again replaces it (the same dataset, its rows renewed)');
finish();
