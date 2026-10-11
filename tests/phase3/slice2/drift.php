<?php
/**
 * Slice 2 proof — drift (uploads-loads.md "Proof" 5; lake-engine §3): a second file with a column the dataset never saw — under `rescue` (the
 * default) its values go into _dl_rescued and a schema version waits for a person (never applied silently); under `add` the column is added and
 * waits too; under `fail` the load fails and says why, a `load` incident opens, the job is refused without a retry and the requester and the
 * followers hear `load_failed`. A follower hears `schema_changed`.
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$tag = run_tag();
/** An engineer's raw dataset loaded once with customers.csv; returns [dataset id, key]. */
$base = static function (string $name) use ($fx): array {
    [$u] = upload_file(27, "$fx/customers.csv");
    wait_sniff($u);
    $r = map_confirm_as(27, $u, ['target' => 'raw', 'raw_name' => $name]);
    wait_load((int) $r['json']['record_id']);
    return [(int) $r['json']['dataset_id'], (string) $r['json']['dataset']];
};
/** customers_v2.csv (+ loyalty_tier) into it: the load's id. */
$second = static function (string $key) use ($fx): int {
    [$u] = upload_file(27, "$fx/customers_v2.csv");
    wait_sniff($u);
    $r = map_confirm_as(27, $u, ['target' => 'raw', 'target_dataset' => $key]);
    return (int) ($r['json']['record_id'] ?? 0);
};

echo "1. rescue (the default) — the new field kept in _dl_rescued; a schema version pending\n";
[$ds, $key] = $base("drift_rescue_$tag");
pg("INSERT INTO dataset_follows (member_id, dataset_id) VALUES (26, $ds) ON CONFLICT DO NOTHING; INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) VALUES ('dataset', $ds, 'member', 26, 'read', 'SMOKE slice 2 drift', 1)");
$l = $second($key);
ok($l > 0 && wait_load($l) === 'loaded', 'the second file loaded');
ok((int) pg1("SELECT rows_rescued FROM loads WHERE id = $l") === 2 && (int) pg1("SELECT rows_loaded FROM loads WHERE id = $l") === 2, 'two rows, both with a rescued field');
$sv = pg("SELECT version || '|' || decision || '|' || breaking || '|' || changes::text FROM schema_versions WHERE dataset_id = $ds ORDER BY version");
ok(count($sv) === 2 && str_starts_with($sv[0], '1|none|false') && str_starts_with($sv[1], '2|pending|false') && str_contains($sv[1], '"column": "loyalty_tier"'), 'schema version 1 (none), version 2 PENDING: loyalty_tier added (to _dl_rescued), not breaking');
ok((int) pg1("SELECT count(*) FROM dataset_columns WHERE dataset_id = $ds AND name = 'loyalty_tier'") === 0, 'nothing applied silently: the dataset has no loyalty_tier column');
ok(str_contains((string) pg1("SELECT schema_seen::text FROM loads WHERE id = $l"), '"rescued": ["loyalty_tier"]'), 'the load\'s schema seen names the rescued field');
$d = get_json(27, "/loads/$l")['json']['data']['load']['schema_seen']['drift'] ?? [];
ok(($d['rescued'] ?? []) === ['loyalty_tier'], 'get_load says it');
$h = html_as(27, "/loads/$l");
ok(str_contains($h['body'], 'id="load-drift-pending"') && str_contains($h['body'], '1 column rescued into _dl_rescued: loyalty_tier'), 'the load page: the drift in words, "a schema version waits for a decision"');
$rescued = (string) pg1("SELECT count(*) FROM activity_log WHERE action = 'drift.detect' AND dataset_id = $ds");
ok($rescued === '1', 'drift.detect logged once, on the dataset\'s timeline');
ok((int) pg1("SELECT count(*) FROM notifications WHERE member_id = 26 AND kind = 'schema_changed' AND record_id = $ds") === 1, 'Priya, who follows it, heard schema_changed');
$p = get_json(26, "/datasets/$ds/preview?prov=1")['json']['data']['preview'] ?? [];
ok(in_array('_dl_rescued', $p['masked_columns'] ?? [], true), '_dl_rescued is masked below read_unmasked (fields no contract has classified yet)');

echo "2. add — the new column added to the dataset, the version pending\n";
[$da, $keyA] = $base("drift_add_$tag");
pg("UPDATE datasets SET evolution_mode = 'add' WHERE id = $da");
$l = $second($keyA);
ok(wait_load($l) === 'loaded' && (int) pg1("SELECT rows_rescued FROM loads WHERE id = $l") === 0, 'loaded, nothing rescued');
ok(pg1("SELECT physical_type FROM dataset_columns WHERE dataset_id = $da AND name = 'loyalty_tier'") === 'VARCHAR', 'loyalty_tier added as a raw (text) column');
ok(pg1("SELECT decision FROM schema_versions WHERE dataset_id = $da AND version = 2") === 'pending', 'and its schema version waits for a person');

echo "3. fail — the load fails and says why; an incident; no retry\n";
[$df, $keyF] = $base("drift_fail_$tag");
pg("UPDATE datasets SET evolution_mode = 'fail' WHERE id = $df");
$l = $second($keyF);
ok(wait_load($l) === 'failed', 'the load failed');
ok(pg1("SELECT error FROM loads WHERE id = $l") === 'new columns: loyalty_tier — the dataset refuses drift', 'loads.error: "new columns: loyalty_tier — the dataset refuses drift"');
$job = pg("SELECT status || '|' || attempts || '|' || error FROM lake_jobs WHERE kind = 'load' AND ref_id = $l");
ok(($job[0] ?? '') === 'failed|1|new columns: loyalty_tier — the dataset refuses drift', 'the job: failed at once, one attempt, no retry (a decided refusal)');
ok(pg1("SELECT kind || '|' || status || '|' || severity FROM incidents WHERE dataset_id = $df AND load_id = $l") === 'load|new|error', 'a load incident opened on the dataset');
ok((int) pg1("SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'load_failed' AND record_id = $l") === 1, 'the requester heard load_failed');
ok(pg1("SELECT count(*) FROM lineage_runs WHERE load_id = $l AND event = 'FAIL'") === '1' && (int) pg1("SELECT row_count FROM datasets WHERE id = $df") === 7, 'its lineage run FAILED; the dataset unchanged (7 rows)');
ok((int) pg1("SELECT count(*) FROM activity_log WHERE action = 'load.fail' AND entity_id = $l AND source = 'system'") === 1, 'load.fail logged by the writer');
finish();
