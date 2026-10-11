<?php
/**
 * Slice 4, proof 7 — the break-glass (access-policies.md "Proof" 7; access-and-policies §5): the admin opens a dataset with a reason; its owner's
 * bell (and every other Lake admin's); the hour's rows read UNMASKED and audited; after the hour (the clock moved) nothing; people only; logged
 * with the reason's length, never its words.
 */
require __DIR__ . '/lib.php';
$deals = ds_id('raw.crm_deals');
pg("UPDATE admin_reads SET started_at = now() - interval '3 hours', expires_at = now() - interval '2 hours' WHERE expires_at > now()");
$n0 = last_notice_id();
$a0 = last_activity_id();
$pre = preview_as(1, $deals);
ok(($pre['status'] ?? '') === 'refused' && ($pre['refusal_rule'] ?? '') === 'discover_only', 'before: the admin only discovers the deals (no rows)');
ok(post_json(26, '/access/break-glass.php', ['dataset' => $deals, 'reason' => 'Priya is no Lake admin.'])['code'] === 403, 'a Member: 403 (lake.admin_read)');
ok(agent_post(40, '/access/break-glass.php', ['dataset' => $deals, 'reason' => 'An agent asking for the glass.'])['code'] === 403, 'an agent: 403 — people only');
$short = post_json(1, '/access/break-glass.php', ['dataset' => $deals, 'reason' => 'urgent']);
ok($short['code'] === 422 && field_of($short) === 'reason', 'a reason under 10 characters: refused on the reason');
$o = post_json(1, '/access/break-glass.php', ['dataset' => $deals, 'reason' => 'The CRM export failed and the auditors need the deals rows today.']);
$wid = (int) ($o['json']['admin_read_id'] ?? 0);
ok($o['code'] === 200 && $wid > 0 && pg1("SELECT (expires_at - started_at = interval '1 hour')::text FROM admin_reads WHERE id = $wid") === 'true', 'the admin opens the deals for ONE hour');
$bell = last_notice(27, 'admin_read', $n0);
ok($bell !== null && str_contains($bell[0], 'opened SMOKE CRM deals until'), 'Marco, the owner, is told: "' . ($bell[0] ?? '') . '"');
ok(get_json(27, '/notifications')['code'] === 200 && (int) pg1("SELECT count(*) FROM notifications WHERE kind = 'admin_read' AND id > $n0 AND member_id = 1") === 0, '…the admin who opened it is not told about himself');
$log = activity('dataset.admin_read', $a0)[0] ?? [];
ok((int) ($log['dataset_id'] ?? 0) === $deals && str_contains((string) $log['after'], '"reason_length"') && !str_contains((string) $log['after'], 'auditors'),
   'logged dataset.admin_read: the dataset, the window, its end — the reason by its length only');
$in = preview_as(1, $deals);
$ei = col_index($in, 'buyer_email');
ok(($in['status'] ?? '') === 'ok' && ($in['masked_columns'] ?? ['x']) === [] && preg_match('/^buyer\d+@example\.invalid$/', (string) ($in['rows'][0][$ei] ?? '')) === 1,
   'the hour\'s rows: read UNMASKED (the buyer\'s email as it is)');
$qr = run_row((int) ($in['query_run_id'] ?? 0));
ok(($qr['caller_member_id'] ?? 0) === 1 && ($qr['status'] ?? '') === 'ok', 'every query in the hour is audited (query_runs)');
ok(ref_level($deals, 1) === 'read_unmasked' && (get_json(1, '/access/break-glass')['json']['data']['open'][0]['admin_read_id'] ?? 0) === $wid, 'the screen lists the open window with its end');
$again = post_json(1, '/access/break-glass.php', ['dataset' => $deals, 'reason' => 'Opening it a second time while open.']);
ok($again['code'] === 422 && field_of($again) === 'dataset', 'a second while one is open: refused, saying until when');
pg("UPDATE admin_reads SET started_at = now() - interval '61 minutes', expires_at = now() - interval '1 minute' WHERE id = $wid");
$after = preview_as(1, $deals);
ok(($after['status'] ?? '') === 'refused' && ref_level($deals, 1) === 'discover', 'after the hour (the clock moved): nothing — back to discovering it');
ok((get_json(1, '/access/break-glass')['json']['data']['open'] ?? ['x']) === [], 'and the screen lists no open window');
finish();
