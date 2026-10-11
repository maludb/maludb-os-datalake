<?php
/**
 * Slice 2 proof — loads (uploads-loads.md "Proof" 4, 7): an engineer's raw load is all text with the provenance columns; its profile written
 * with the email column's top values masked; ★3 — a column nobody decided whose values are 0.9 email addresses is masked at once by the rule,
 * a 0.63 free-text one stays a proposal; a replay of a loaded file answers "already loaded"; a rollback is a new snapshot without the load's
 * rows (the previous one still readable as of); a replay after it brings the rows back; after the landing is purged a replay is refused in
 * words; an agent's rollback pauses (deletion, the registry); the load page and its status door.
 */
require __DIR__ . '/lib.php';
$fx = fixtures();
$tag = run_tag();

echo "1. An engineer's raw load — every column text, the provenance, the profile masked\n";
[$u] = upload_file(27, "$fx/customers.csv");
wait_sniff($u);
$r = map_confirm_as(27, $u, ['target' => 'raw', 'raw_name' => "cust_$tag"]);
$key = "raw.cust_$tag";
ok($r['code'] === 200 && ($r['json']['dataset'] ?? '') === $key, "Marco confirms into a new raw dataset: $key");
$load = (int) $r['json']['record_id'];
$ds = (int) $r['json']['dataset_id'];
ok(wait_load($load) === 'loaded', 'loaded');
$types = pg("SELECT name || ':' || physical_type || ':' || is_system FROM dataset_columns WHERE dataset_id = $ds ORDER BY ordinal");
ok(array_slice($types, 0, 8) === ['customer_id:VARCHAR:false', 'name:VARCHAR:false', 'email:VARCHAR:false', 'phone:VARCHAR:false', 'card_number:VARCHAR:false', 'city:VARCHAR:false', 'signed_up:VARCHAR:false', 'balance:VARCHAR:false'],
    'raw: every column VARCHAR as received (the sniff\'s types kept only as hints)');
ok(array_slice($types, 8) === ['_dl_load_id:BIGINT:true', '_dl_row:BIGINT:true', '_dl_loaded_at:TIMESTAMP WITH TIME ZONE:true', '_dl_landing_sha256:VARCHAR:true', '_dl_rescued:VARCHAR:true'],
    'the five provenance columns, marked system');
ok(pg1("SELECT row_count || '/' || (last_snapshot_id IS NOT NULL) || '/' || (data_updated_at IS NOT NULL) || '/' || ducklake_schema FROM datasets WHERE id = $ds") === '7/true/true/raw', 'the catalog row: 7 rows, the snapshot, "data updated", the engine\'s schema — the writer\'s numbers');
ok((int) pg1("SELECT rows_read FROM loads WHERE id = $load") === 8 && (int) pg1("SELECT rows_rejected FROM loads WHERE id = $load") === 1, 'read 8, refused 1 (the line with an extra field)');
$prof = json_decode((string) pg1("SELECT profile FROM dataset_columns WHERE dataset_id = $ds AND name = 'email'"), true) ?: [];
ok(($prof['masked'] ?? false) === true && str_contains(json_encode($prof['top'] ?? []), '***@example.invalid') && !str_contains(json_encode($prof), 'ada.example@'), 'the email column\'s profile: top values masked (a***@example.invalid) — no raw address');
ok((int) pg1("SELECT count(*) FROM column_profiles WHERE dataset_id = $ds AND load_id = $load") === 8, 'a column_profiles row per column for this load and snapshot');
ok(pg1("SELECT count(*) FROM lineage_runs WHERE load_id = $load AND job_kind = 'load' AND event IN ('START', 'COMPLETE')") === '2', 'one lineage run: START and COMPLETE (OpenLineage\'s shape)');
ok(pg1("SELECT outputs->0->>'name' || '@' || (outputs->0->>'version') FROM lineage_runs WHERE load_id = $load AND event = 'COMPLETE'") === $key . '@' . pg1("SELECT snapshot_id FROM loads WHERE id = $load"), '…its output the dataset at the load\'s snapshot');
$run = activity('load.run');
$last = end($run) ?: [];
ok(($last['source'] ?? '') === 'system' && (int) $last['actor_member_id'] === 27 && (int) $last['dataset_id'] === $ds && (int) $last['entity_id'] === $load, 'load.run logged by the writer: source system, the requester as the actor, the dataset\'s timeline');
ok(pg1("SELECT author || ' / ' || commit_message FROM ducklake.ducklake_snapshot_changes WHERE snapshot_id = (SELECT snapshot_id FROM loads WHERE id = $load)") === "dl:m27 / load $load into $key", 'the DuckLake commit carries the author (dl:m27) and the message');

echo "2. ★3 — the classifier masks at once at 0.8, proposes below it\n";
[$u3] = upload_file(27, "$fx/late_contacts.csv");
wait_sniff($u3);
$m = map_json(27, $u3)['json']['data']['upload']['sniff']['columns'] ?? [];
ok(count($m) === 3 && ($m[1]['proposed_personal_kind'] ?? null) === null && ($m[2]['proposed_personal_kind'] ?? null) === null, 'the sniff saw nothing personal in the first 50 rows (contact and notes empty there)');
$r = map_confirm_as(27, $u3, ['target' => 'raw', 'raw_name' => "late_$tag"]);
$l3 = (int) $r['json']['record_id'];
$d3 = (int) $r['json']['dataset_id'];
ok(wait_load($l3) === 'loaded', 'loaded 260 rows');
$c = pg("SELECT name || '|' || COALESCE(personal_kind, '-') || '|' || classified_by || '|' || COALESCE(proposed_personal_kind, '-') || '|' || COALESCE(proposed_confidence::text, '-') || '|' || classification FROM dataset_columns WHERE dataset_id = $d3 AND name IN ('contact', 'notes') ORDER BY name");
ok(($c[0] ?? '') === 'contact|email|rule|email|0.900|confidential', 'contact: proposed email at 0.9 → masked AT ONCE (personal_kind email, classified_by rule), confidential');
ok(($c[1] ?? '') === 'notes|-|rule|free_text_personal|0.628|internal' || str_starts_with((string) ($c[1] ?? ''), 'notes|-|rule|free_text_personal|0.6'), 'notes: free text at 0.63 → a proposal only, not masked (' . ($c[1] ?? '') . ')');
ok(pg1("SELECT classification_confirmed_at IS NULL FROM dataset_columns WHERE dataset_id = $d3 AND name = 'contact'") === 't', '…and the masked one still waits for a person to confirm');
pg("INSERT INTO grants (target_kind, dataset_id, principal_kind, principal_id, level, reason, granted_by) VALUES ('dataset', $d3, 'member', 26, 'read', 'SMOKE slice 2', 1)");
$p = get_json(26, "/datasets/$d3/preview")['json']['data']['preview'] ?? [];
$row = $p['rows'][70] ?? [];
ok(in_array('contact', $p['masked_columns'] ?? [], true) && str_contains((string) ($row[1] ?? ''), '***@example.invalid') && !in_array('notes', $p['masked_columns'] ?? [], true), 'Priya (read) previews it: contact masked from its first load, notes not');

echo "3. Replay, rollback, replay again, a purged landing\n";
$r = post_json(27, '/loads/queue.php', ['load' => $load]);
ok($r['code'] === 200 && ($r['json']['refresh'] ?? '') === 'jobQueued' && ($r['json']['record_id'] ?? 0) === $load, 'load_queue: queued again (jobQueued)');
ok(wait_load($load, 60, false) === 'no_op' && (int) pg1("SELECT row_count FROM datasets WHERE id = $ds") === 7, 'the writer: "already loaded" — no_op, nothing added');
$snapBefore = (int) pg1("SELECT snapshot_id FROM loads WHERE id = $load");
$firstSnap = (int) pg1("SELECT min(snapshot_id) FROM lineage_runs l JOIN LATERAL (SELECT (outputs->0->>'version')::bigint AS snapshot_id) s ON true WHERE l.load_id = $load AND l.event = 'COMPLETE'");
$r = post_json(27, '/loads/rollback.php', ['load' => $load]);
ok($r['code'] === 200 && ($r['json']['did'] ?? '') === "Rolling back load $load", 'load_rollback: queued');
$job = (int) $r['json']['job_id'];
$jr = wait_job($job);
wait_until(static fn (): bool => pg1("SELECT status FROM loads WHERE id = $load") === 'rolled_back');
ok($jr['status'] === 'done' && ($jr['result']['removed'] ?? 0) === 7 && pg1("SELECT status FROM loads WHERE id = $load") === 'rolled_back', 'the writer removed its 7 rows; the load says rolled back');
$rb = (int) pg1("SELECT rollback_snapshot_id FROM loads WHERE id = $load");
ok($rb > $firstSnap && (int) pg1("SELECT row_count FROM datasets WHERE id = $ds") === 0, "a NEW snapshot ($rb) without the rows; the catalog says 0 rows");
$asof = get_json(27, "/datasets/$ds/preview?as_of=$firstSnap")['json']['data']['preview'] ?? [];
$now = get_json(27, "/datasets/$ds/preview")['json']['data']['preview'] ?? [];
ok(count($asof['rows'] ?? []) === 7 && ($asof['as_of'] ?? 0) === $firstSnap && count($now['rows'] ?? [1]) === 0, "history is never rewritten: as of snapshot $firstSnap the 7 rows are there, now none");
ok(pg1("SELECT count(*) FROM lineage_runs WHERE load_id = $load AND job_kind = 'rollback' AND event = 'COMPLETE'") === '1', 'the rollback is a lineage run of its own');
$rbLog = array_values(array_filter(activity('load.rollback'), static fn ($a) => (int) $a['entity_id'] === $load));
ok(count($rbLog) === 2 && $rbLog[0]['source'] === 'web' && $rbLog[1]['source'] === 'system' && str_contains((string) $rbLog[1]['after'], '"removed": 7'), 'load.rollback logged twice: queued by the person, done by the writer (the rows removed, the snapshot)');
ok(post_json(27, '/loads/rollback.php', ['load' => $load])['code'] === 422, 'rolling back a rolled-back load: refused');
$r = post_json(27, '/loads/queue.php', ['load' => $load]);
ok($r['code'] === 200 && wait_load($load, 60, false) === 'loaded' && (int) pg1("SELECT row_count FROM datasets WHERE id = $ds") === 7, 'replayed after the rollback: the rows come back from the landing file');
ok(pg1("SELECT rolled_back_at IS NULL FROM loads WHERE id = $load") === 't', '…the load is loaded again (the rollback stays in its history)');
pg("UPDATE loads SET landing_purged_at = now() WHERE id = $load");
$r = post_json(27, '/loads/queue.php', ['load' => $load]);
ok($r['code'] === 422 && message_of($r) === 'Its landing file was purged — the load cannot be replayed', 'after the landing is purged: "Its landing file was purged — the load cannot be replayed"');
$h = html_as(27, "/loads/$load");
ok(str_contains($h['body'], 'id="load-file-purged"') && str_contains($h['body'], 'Landing purged — not replayable'), 'the load page says so');
pg("UPDATE loads SET landing_purged_at = NULL WHERE id = $load");
ok(post_json(26, '/loads/queue.php', ['load' => $load])['code'] === 403, 'a Member without loads.manage: 403');
ok(post_json(26, '/loads/rollback.php', ['load' => $l3])['code'] === 403, '…and cannot roll back either');

echo "4. An agent's rollback pauses for approval (deletion) — the registry the kernel's actions server reads\n";
$reg = json_decode((string) file_get_contents('mcp/action_registry.json'), true) ?: [];
$rb = $reg['actions']['load_rollback'] ?? [];
ok(($rb['approval'] ?? '') === 'deletion' && ($rb['log_event'] ?? '') === 'load.rollback' && ($rb['agent'] ?? false) === true && ($rb['built'] ?? false) === true,
    'load_rollback in the registry: built, an agent may call it, approval deletion, log event load.rollback');
$man = json_decode((string) file_get_contents('maludb-os.json'), true) ?: [];
$cats = array_column($man['approvals'] ?? [], 'category', 'action');
ok(($cats['load_rollback'] ?? null) === 'deletion' && !isset($cats['load_queue']) && !isset($cats['upload_map_confirm']), 'maludb-os.json approvals[]: load_rollback pauses (deletion); a replay and a mapping do not');

echo "5. The load page, the status door, the ledger\n";
$j = get_json(27, "/loads/$load")['json']['data']['load'] ?? [];
ok(($j['status'] ?? '') === 'loaded' && ($j['file']['sha256_prefix'] ?? '') === substr(hash_file('sha256', "$fx/customers.csv"), 0, 12) && ($j['file']['name'] ?? '') === 'customers.csv'
    && ($j['may']['rollback'] ?? false) === true, 'get_load: the counts, the file (its name, the hash\'s first 12), what Marco may do');
$s = html_as(27, "/loads/$load/status");
ok($s['code'] === 200 && str_contains($s['body'], 'id="load-counts"') && !str_contains($s['body'], 'every 2s'), 'the status door: the final counts, no more polling');
pg("INSERT INTO loads (dataset_id, idempotency_key, status, requested_by) VALUES ($ds, 'proof:$tag:queued', 'queued', 27)");
$q = (int) pg1("SELECT id FROM loads WHERE idempotency_key = 'proof:$tag:queued'");
$s = html_as(27, "/loads/$q/status");
ok(str_contains($s['body'], 'hx-trigger="every 2s"') && str_contains($s['body'], 'Queued — the writer will start it shortly'), 'a queued load: the partial polls every 2 s and says so');
pg("DELETE FROM loads WHERE id = $q");
$l = get_json(27, "/loads?dataset=$key")['json']['data'] ?? [];
ok(($l['total'] ?? 0) >= 1 && ($l['rows'][0]['dataset'] ?? '') === $key && array_key_exists('rows_rescued', $l['rows'][0] ?? []), 'find_loads by dataset: the ledger\'s rows');
ok(get_json(31, "/loads/$load")['code'] === 404, 'a load of a dataset Lee may not read: 404');
ok(get_json(26, "/loads/$l3")['code'] === 200, 'Priya (read on it) may open its load');
finish();
